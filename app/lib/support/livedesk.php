<?php
// NOTE: LIBRARY loaded from config.php during bootstrap (before $SECURE is set),
// so it must NOT carry the `@$SECURE or die` route/view guard — that kills the
// whole app at boot. No other app/lib file carries it.

// ============================================================================
// FILE: app/lib/support/livedesk.php
// LIVE CHAT DESK ENGINE — the one channel-aware staff-reply path for human
// support agents working live GIA conversations across every surface (web
// widget + WhatsApp + Telegram + Messenger).
//
// WHY THIS EXISTS: admin/gia/conversation/staff-reply (giaRoutes.php:237) only
// pushed staff replies to the WEB widget via Pusher — a channel conversation on
// WhatsApp/Telegram/Messenger NEVER received the human's message. This engine
// fixes that gap: support_desk_reply() delivers per-channel, calling
// gia_channel_send() for messaging channels and gia_realtime_staff_reply() for
// the web widget.
//
// Owner rule — EXPLICIT NAMED HANDOFF: when a staff member takes over, the
// CUSTOMER is told, by name, that a human joined, and GIA stays paused until
// release. support_desk_takeover() announces + delivers that message, and every
// staff reply records which support_staff.id authored it (gia_messages.staff_id).
//
// All functions are global + function_exists-guarded. Medoo $db only.
// ============================================================================

if (!function_exists('ensureSupportDeskSchema')) {
    /**
     * Idempotently add the Live Desk columns. Mirrors ensureCoreFixSchema's
     * SHOW COLUMNS + ALTER TABLE loop (functions.php:853) with try/catch +
     * error_log so a DB user without ALTER rights degrades gracefully.
     *   gia_conversations.assigned_staff_id  INT NULL  (support_staff.id owner)
     *   gia_conversations.assigned_at        DATETIME NULL
     *   gia_messages.staff_id                INT NULL  (support_staff.id author of a staff reply; NULL for GIA/user)
     */
    function ensureSupportDeskSchema($db): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $columns = [
            ['gia_conversations', 'assigned_staff_id', "ALTER TABLE `gia_conversations` ADD COLUMN `assigned_staff_id` INT(11) NULL DEFAULT NULL"],
            ['gia_conversations', 'assigned_at',        "ALTER TABLE `gia_conversations` ADD COLUMN `assigned_at` DATETIME NULL DEFAULT NULL"],
            ['gia_messages',      'staff_id',           "ALTER TABLE `gia_messages` ADD COLUMN `staff_id` INT(11) NULL DEFAULT NULL"],
        ];

        foreach ($columns as [$table, $column, $alterSql]) {
            try {
                $exists = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->pdo->quote($column))->fetchAll();
                if (count($exists) === 0) {
                    $db->pdo->exec($alterSql);
                }
            } catch (\Throwable $e) {
                error_log("ensureSupportDeskSchema: could not verify/add {$table}.{$column}: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('support_desk_deliver')) {
    /**
     * INTERNAL: deliver an already-recorded message out to the traveller on the
     * conversation's channel. Shared by support_desk_reply + support_desk_takeover
     * so the per-channel logic lives in exactly one place.
     *   web (channel 'web' or empty) -> Pusher push to the widget (fail-soft; the
     *                                   widget also polls, so a miss self-heals).
     *   whatsapp/telegram/messenger  -> gia_channel_send() outbound; returns bool.
     *
     * @return bool delivered (web: whether the Pusher trigger fired; messaging:
     *              whether the provider accepted the send)
     */
    function support_desk_deliver($db, string $channel, string $convKey, string $channelUserId, string $text): bool
    {
        // Load the delivery helpers the same way giaRoutes.php does (guarded).
        require_once dirname(__DIR__) . '/ai/gia_realtime.php';
        require_once dirname(__DIR__) . '/ai/gia_channel_send.php';

        $channel = strtolower(trim($channel));
        if ($channel === '' || $channel === 'web') {
            if (function_exists('App\\lib\\ai\\gia_realtime_staff_reply') && $convKey !== '') {
                return \App\lib\ai\gia_realtime_staff_reply($db, $convKey, $text);
            }
            return false;
        }

        if (in_array($channel, ['whatsapp', 'telegram', 'messenger'], true)) {
            if ($channelUserId === '' || !function_exists('gia_channel_send')) {
                return false;
            }
            return gia_channel_send($db, $channel, $channelUserId, $text);
        }

        return false;
    }
}

if (!function_exists('support_desk_reply')) {
    /**
     * The ONE channel-aware staff-reply path. Records the reply as a staff-authored
     * assistant message, activates handoff (pauses GIA), and delivers it to the
     * traveller on their actual channel.
     *
     * @param object $db
     * @param int    $convId  gia_conversations.id
     * @param string $text    staff message (clamped to 2000 chars)
     * @param int    $staffId support_staff.id of the author
     * @return array{ok:bool, delivered?:bool, channel?:string, message?:string}
     */
    function support_desk_reply($db, int $convId, string $text, int $staffId): array
    {
        $conv = $db->get('gia_conversations', [
            'id', 'conv_key', 'channel', 'channel_user_id', 'staff_active',
        ], ['id' => $convId]);
        if (!$conv) {
            return ['ok' => false, 'message' => 'Conversation not found.'];
        }

        $text = function_exists('mb_substr') ? mb_substr($text, 0, 2000) : substr($text, 0, 2000);
        if (trim($text) === '') {
            return ['ok' => false, 'message' => 'Empty message.'];
        }

        $channel       = (string) ($conv['channel'] ?? 'web');
        $convKey       = (string) ($conv['conv_key'] ?? '');
        $channelUserId = (string) ($conv['channel_user_id'] ?? '');
        $last480       = function_exists('mb_substr') ? mb_substr($text, 0, 480) : substr($text, 0, 480);

        try {
            $db->insert('gia_messages', [
                'conv_id'  => $convId,
                'role'     => 'assistant',
                'text'     => $text,
                'is_staff' => 1,
                'staff_id' => $staffId,
            ]);
            $db->update('gia_conversations', [
                'staff_active' => 1,
                'needs_human'  => 1,
                'msg_count[+]' => 1,
                'last_message' => $last480,
            ], ['id' => $convId]);
        } catch (\Throwable $e) {
            error_log('[support] desk reply record: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not record the reply.'];
        }

        $delivered = support_desk_deliver($db, $channel, $convKey, $channelUserId, $text);

        return ['ok' => true, 'delivered' => $delivered, 'channel' => ($channel !== '' ? $channel : 'web')];
    }
}

if (!function_exists('support_desk_takeover')) {
    /**
     * Take or release a conversation. Owner's EXPLICIT NAMED HANDOFF rule:
     *   take    -> assign the conversation to $staffId, pause GIA, and TELL the
     *              customer (by name) that a human joined — recorded + delivered
     *              via the same per-channel path as a staff reply.
     *   release -> unassign, resume GIA, and (optionally) tell the customer they
     *              are being handed back to the assistant.
     *
     * @param object $db
     * @param int    $convId
     * @param int    $staffId     support_staff.id performing the action
     * @param string $displayName human-readable staff name to announce to the customer
     * @param bool   $take        true = take over, false = release
     * @return array{ok:bool, mode:string, delivered?:bool, channel?:string, message?:string}
     */
    function support_desk_takeover($db, int $convId, int $staffId, string $displayName, bool $take): array
    {
        $conv = $db->get('gia_conversations', [
            'id', 'conv_key', 'channel', 'channel_user_id', 'staff_active',
        ], ['id' => $convId]);
        if (!$conv) {
            return ['ok' => false, 'mode' => $take ? 'take' : 'release', 'message' => 'Conversation not found.'];
        }

        $channel       = (string) ($conv['channel'] ?? 'web');
        $convKey       = (string) ($conv['conv_key'] ?? '');
        $channelUserId = (string) ($conv['channel_user_id'] ?? '');

        $name = trim($displayName) !== '' ? trim($displayName) : 'a member of our team';

        if ($take) {
            $announce = "Hi, you're now chatting with {$name} from our team. I'll take it from here.";
        } else {
            $announce = "Thanks for chatting. I'm handing you back to our assistant now.";
        }
        $announce = function_exists('mb_substr') ? mb_substr($announce, 0, 2000) : substr($announce, 0, 2000);
        $last480  = function_exists('mb_substr') ? mb_substr($announce, 0, 480) : substr($announce, 0, 480);

        try {
            if ($take) {
                $db->update('gia_conversations', [
                    'staff_active'      => 1,
                    'needs_human'       => 1,
                    'assigned_staff_id' => $staffId,
                    'assigned_at'       => date('Y-m-d H:i:s'),
                ], ['id' => $convId]);
            } else {
                $db->update('gia_conversations', [
                    'staff_active'      => 0,
                    'needs_human'       => 0,
                    'assigned_staff_id' => null,
                ], ['id' => $convId]);
            }

            // Record the system-style announcement as a staff-authored assistant turn.
            $db->insert('gia_messages', [
                'conv_id'  => $convId,
                'role'     => 'assistant',
                'text'     => $announce,
                'is_staff' => 1,
                'staff_id' => $staffId,
            ]);
            $db->update('gia_conversations', [
                'msg_count[+]' => 1,
                'last_message' => $last480,
            ], ['id' => $convId]);
        } catch (\Throwable $e) {
            error_log('[support] desk takeover: ' . $e->getMessage());
            return ['ok' => false, 'mode' => $take ? 'take' : 'release', 'message' => 'Could not update the conversation.'];
        }

        // Deliver the announcement to the customer on their channel (same logic).
        $delivered = support_desk_deliver($db, $channel, $convKey, $channelUserId, $announce);

        return [
            'ok'        => true,
            'mode'      => $take ? 'take' : 'release',
            'delivered' => $delivered,
            'channel'   => ($channel !== '' ? $channel : 'web'),
        ];
    }
}

if (!function_exists('support_desk_queue')) {
    /**
     * Conversations for the Live Desk list, newest/needs-attention first.
     *
     * @param object $db
     * @param string $filter  all | needs_human | assigned (mine) | unassigned |
     *                        channel:whatsapp|telegram|messenger|web
     * @param int    $limit
     * @param int    $staffId used only for the 'assigned' (mine) filter
     * @return array<int,array> conversation rows
     */
    function support_desk_queue($db, string $filter = 'all', int $limit = 50, int $staffId = 0): array
    {
        $limit = $limit > 0 ? min($limit, 200) : 50;

        $where = [
            'ORDER' => ['needs_human' => 'DESC', 'last_at' => 'DESC'],
            'LIMIT' => $limit,
        ];

        $filter = trim($filter);
        if ($filter === 'needs_human') {
            $where['needs_human'] = 1;
        } elseif ($filter === 'assigned' || $filter === 'mine') {
            $where['assigned_staff_id'] = $staffId;
        } elseif ($filter === 'unassigned') {
            $where['assigned_staff_id'] = null;
        } elseif (strpos($filter, 'channel:') === 0) {
            $ch = strtolower(substr($filter, strlen('channel:')));
            if (in_array($ch, ['whatsapp', 'telegram', 'messenger', 'web'], true)) {
                $where['channel'] = $ch;
            }
        }
        // 'all' (or anything unrecognized) => no extra constraint.

        try {
            $rows = $db->select('gia_conversations', [
                'id', 'conv_key', 'channel', 'channel_user_id', 'user_id',
                'last_message', 'msg_count', 'staff_active', 'needs_human',
                'assigned_staff_id', 'last_at',
            ], $where);
        } catch (\Throwable $e) {
            error_log('[support] desk queue: ' . $e->getMessage());
            return [];
        }

        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('support_desk_messages')) {
    /**
     * Transcript for a conversation, oldest-first. When $sinceId > 0, returns only
     * messages with id > $sinceId (incremental poll for the live desk panel).
     *
     * @param object $db
     * @param int    $convId
     * @param int    $sinceId
     * @return array<int,array> message rows
     */
    function support_desk_messages($db, int $convId, int $sinceId = 0): array
    {
        $where = ['conv_id' => $convId];
        if ($sinceId > 0) {
            $where['id[>]'] = $sinceId;
        }

        try {
            $rows = $db->select('gia_messages', [
                'id', 'role', 'text', 'is_staff', 'staff_id', 'created_at',
            ], array_merge($where, [
                'ORDER' => ['id' => 'ASC'],
                'LIMIT' => 500,
            ]));
        } catch (\Throwable $e) {
            error_log('[support] desk messages: ' . $e->getMessage());
            return [];
        }

        return is_array($rows) ? $rows : [];
    }
}
