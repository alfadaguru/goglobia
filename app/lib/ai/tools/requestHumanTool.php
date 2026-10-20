<?php

namespace App\lib\ai;

/**
 * GIA tool: hand the conversation to a human. Call this when the traveller asks
 * to speak with a person, is frustrated, or has a need GIA genuinely can't meet.
 *
 * It flags the conversation needs_human and alerts the ops inbox (with a link to
 * the admin conversation so staff can take over). It does NOT itself reply as a
 * human — a staff member does that from the admin. Risk R1 (sends an internal
 * notification; no money, no external customer-facing commitment).
 */
class requestHumanTool implements giaToolInterface
{
    public function name(): string
    {
        return 'request_human';
    }

    public function description(): string
    {
        return 'Flag that this traveller wants or needs a human agent, and alert the '
            . 'team. Call this when they ask to talk to a person, are frustrated, or '
            . 'have a request you cannot handle. Then tell them a team member will join '
            . 'the chat shortly.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'A short note on why a human is needed (what they asked for).',
                ],
            ],
            'required' => [],
        ];
    }

    public function riskClass(): string
    {
        return 'R1'; // internal notification only
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        $convId = (int) ($context['conv_id'] ?? 0);
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => "I can't reach the team right now."];
        }
        if ($convId <= 0) {
            // No persisted conversation to attach to — still acknowledge gracefully.
            return [
                'ok' => true,
                'data' => ['notified' => false],
                'message' => "I'll let the team know. Please leave your email or phone so they can reach you.",
            ];
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if (function_exists('mb_substr')) { $reason = mb_substr($reason, 0, 240); }

        require_once dirname(__DIR__) . '/gia_analytics.php';
        require_once dirname(__DIR__) . '/gia_notify.php';
        try {
            if (function_exists('ensureGiaAnalyticsSchema')) { ensureGiaAnalyticsSchema($db); }
            // Mark the conversation as needing a human (shows in admin + enables handoff).
            $db->update('gia_conversations', ['needs_human' => 1], ['id' => $convId]);
            if (function_exists('gia_log_event')) {
                gia_log_event($db, $convId, 'request_human', ['meta' => $reason !== '' ? substr($reason, 0, 120) : '']);
            }
            $notified = false;
            if (function_exists('App\\lib\\ai\\gia_alert_staff_needs_human')) {
                $notified = \App\lib\ai\gia_alert_staff_needs_human($db, $convId, $reason);
            }
            return [
                'ok' => true,
                'data' => ['notified' => $notified],
                'message' => 'The team has been alerted — a human will join this chat shortly. '
                    . 'If you share your email or phone, they can also reach you directly.',
            ];
        } catch (\Throwable $e) {
            error_log('[gia] request_human: ' . $e->getMessage());
            return ['ok' => true, 'data' => ['notified' => false], 'message' => "I'll let the team know and someone will follow up."];
        }
    }
}
