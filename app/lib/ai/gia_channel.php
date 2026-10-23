<?php
// ============================================================================
// FILE: app/lib/ai/gia_channel.php
// GIA shared INBOUND spine for messaging channels (WhatsApp / Messenger /
// Telegram). The channel webhooks (adapters) verify their own signatures and
// parse their own payloads, then hand a normalized (channel, sender, text) to
// gia_channel_handle() — which does the SAME work the web route does, minus the
// browser session:
//     dedupe retries -> rate-limit sender -> resolve conversation + history ->
//     register tools -> set trusted context + personalize -> giaAgent::run() ->
//     persist the turn -> return the reply text to send back.
//
// Channel-agnostic: every adapter calls one function and gets one reply string.
// FAIL-SOFT: returns a safe, friendly reply on any internal error; never throws
// to the webhook (a 200 ack must still go back to the platform).
// ============================================================================

namespace App\lib\ai;

if (!function_exists('App\\lib\\ai\\gia_channel_supported')) {
    /** Channels this spine serves. */
    function gia_channel_supported(string $channel): bool
    {
        return in_array(strtolower(trim($channel)), ['whatsapp', 'messenger', 'telegram'], true);
    }
}

if (!function_exists('App\\lib\\ai\\gia_channel_seen_before')) {
    /**
     * Idempotency: platforms RETRY a webhook if we don't 200 fast enough, so the
     * same inbound message id can arrive 2-3 times. Record each (channel, msg_id)
     * once; return true if we've already processed it (=> skip, don't re-reply).
     * Best-effort: if the dedupe store is unavailable we process (better a rare
     * duplicate reply than dropping a real message).
     */
    function gia_channel_seen_before($db, string $channel, string $messageId): bool
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return false; // nothing to dedupe on; let it through
        }
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `gia_channel_seen` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `channel` VARCHAR(16) NOT NULL,
                `message_id` VARCHAR(191) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_channel_msg` (`channel`,`message_id`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // Atomic claim: the UNIQUE key makes a duplicate insert fail -> seen.
            $db->insert('gia_channel_seen', ['channel' => strtolower($channel), 'message_id' => $messageId]);
            $id = (int) $db->id();
            return $id <= 0; // insert produced no id => it already existed
        } catch (\Throwable $e) {
            // Unique-violation path (duplicate) or store error. Treat a duplicate as
            // seen; a genuine store error we let through (process) — logged.
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate') !== false || stripos($msg, '1062') !== false) {
                return true;
            }
            error_log('[gia] channel_seen: ' . $msg);
            return false;
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_channel_rate_ok')) {
    /**
     * Per-sender abuse guard (a messaging webhook has no session/IP to key on).
     * Mirrors the web route's tightness: 8 msgs/min, 80/hour per channel sender.
     * Fail-open if the limiter is unavailable (never block a real traveler on a
     * missing cache dir), but log it.
     */
    function gia_channel_rate_ok(string $channel, string $channelUserId): bool
    {
        if (!class_exists('\\RateLimiter')) {
            $rlFile = dirname(__DIR__, 3) . '/modules/RateLimiter.php';
            if (is_file($rlFile)) {
                require_once $rlFile;
            }
        }
        if (!class_exists('\\RateLimiter')) {
            return true; // fail-open
        }
        try {
            $rl = new \RateLimiter(dirname(__DIR__, 3) . '/app/cache/rate_limiter');
            $id = 'gia_ch:' . strtolower($channel) . ':' . preg_replace('/[^A-Za-z0-9]/', '', $channelUserId);
            $burstOk = $rl->attempt($id . ':burst', 8, 60);
            $hourOk  = $rl->attempt($id . ':hour', 80, 3600);
            return $burstOk && $hourOk;
        } catch (\Throwable $e) {
            error_log('[gia] channel_rate: ' . $e->getMessage());
            return true;
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_channel_handle')) {
    /**
     * Run one inbound turn for a messaging channel and return the reply to send.
     *
     * @param object $db
     * @param string $channel        'whatsapp' | 'messenger' | 'telegram'
     * @param string $channelUserId  sender id on that channel (phone/PSID/tg-id)
     * @param string $text           the traveler's message text
     * @param string $messageId      platform message id (for retry dedupe; '' ok)
     * @param array  $opts           reserved (e.g. ['display_name'=>..])
     * @return array{ok:bool,reply:string,skip:bool,conv_key:string,handoff:bool}
     */
    function gia_channel_handle($db, string $channel, string $channelUserId, string $text, string $messageId = '', array $opts = []): array
    {
        $channel = strtolower(trim($channel));
        $channelUserId = trim($channelUserId);
        $text = trim($text);
        if (!function_exists('gia_trace')) { $tf = __DIR__ . '/gia_trace.php'; if (is_file($tf)) { require_once $tf; } }
        if (function_exists('gia_trace')) { \gia_trace('chat_in', __FILE__, 'surface=' . strtoupper($channel) . ' user=' . substr($channelUserId, 0, 10) . ' msg="' . mb_substr($text, 0, 80) . '"'); }
        $fail = function (string $reply, bool $skip = false, bool $handoff = false, string $convKey = '') {
            return ['ok' => false, 'reply' => $reply, 'skip' => $skip, 'conv_key' => $convKey, 'handoff' => $handoff];
        };

        if (!gia_channel_supported($channel) || $channelUserId === '') {
            return $fail('', true); // not ours / unusable — skip silently
        }

        // Feature gate (reuse the same flag as the web widget).
        if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
            return $fail("I'm just getting set up and can't chat just yet. Please check back soon!");
        }

        // Retry dedupe: if we've already handled this exact message id, skip.
        if ($messageId !== '' && gia_channel_seen_before($db, $channel, $messageId)) {
            return $fail('', true);
        }

        // Empty / non-text payloads (stickers, reactions) — acknowledge gently.
        if ($text === '') {
            return $fail("I can help best with text for now — tell me where you'd like to go and I'll find options.");
        }
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 2000);
        }

        // Per-sender abuse guard.
        if (!gia_channel_rate_ok($channel, $channelUserId)) {
            return $fail("You're sending messages very fast — give me a moment to catch up.");
        }

        // --- Load the GIA stack (explicit; this install has no composer autoload). ---
        $aiDir = __DIR__ . '/';
        require_once $aiDir . 'giaToolInterface.php';
        require_once $aiDir . 'giaChatProvider.php';
        require_once $aiDir . 'giaAgent.php';
        require_once $aiDir . 'tools/searchTripTool.php';
        require_once $aiDir . 'tools/fetchLiveOffersTool.php';
        require_once $aiDir . 'tools/selectOptionTool.php';
        require_once $aiDir . 'tools/composeTripTool.php';
        require_once $aiDir . 'tools/createCheckoutTool.php';
        require_once $aiDir . 'tools/createBookingTool.php';
        require_once $aiDir . 'tools/requestApprovalTool.php';
        require_once $aiDir . 'tools/initiatePaymentTool.php';
        require_once $aiDir . 'tools/getOrderStatusTool.php';
        require_once $aiDir . 'tools/requestHumanTool.php';
        require_once $aiDir . 'tools/verifyIdentityTool.php';
        require_once $aiDir . 'tools/umrahTools.php'; // DEDICATED umrah flow
        require_once $aiDir . 'tools/flightsTools.php'; // DEDICATED flights flow
        require_once $aiDir . 'tools/staysTools.php'; // DEDICATED stays flow
        require_once $aiDir . 'gia_trace.php'; // step tracer (breadcrumb log for diagnosis)
        require_once $aiDir . 'gia_analytics.php';
        require_once $aiDir . 'gia_crm.php';
        require_once $aiDir . 'gia_identity.php';

        // --- Resolve identity (Phase 2). Two tiers, kept distinct:
        //     verified => a confirmed account link (trusted for money, Phase 3);
        //     match-only => a best-effort phone match (GREET BY NAME ONLY). ---
        $ident = function_exists('gia_identity_resolve')
            ? gia_identity_resolve($db, $channel, $channelUserId)
            : ['user_id' => '', 'verified' => false, 'match' => false];
        $verifiedUserId = !empty($ident['verified']) ? (string) $ident['user_id'] : '';
        // account_id is for READS (greeting, order status of THIS person). It is the
        // verified user when linked, else the passive match (reads only — never a
        // money authorization, which additionally requires user_approved + verify).
        $accountId = (string) ($ident['user_id'] ?? '');

        $conv = function_exists('gia_conv_get_or_create_channel')
            ? gia_conv_get_or_create_channel($db, $channel, $channelUserId, $verifiedUserId, '')
            : null;
        $convId  = (int) ($conv['id'] ?? 0);
        $convKey = (string) ($conv['conv_key'] ?? '');

        // --- Human handoff: if staff took over, record the message and pause GIA. ---
        if ($convId > 0 && (int) ($conv['staff_active'] ?? 0) === 1) {
            if (function_exists('gia_log_turn')) {
                gia_log_turn($db, $convId, $text, '', []);
            }
            return [
                'ok' => true, 'skip' => false, 'handoff' => true, 'conv_key' => $convKey,
                'reply' => 'Thanks — a member of our team is on this chat and will reply here shortly.',
            ];
        }

        // --- History (persisted transcript; there is no session on a webhook). ---
        $history = ($convId > 0 && function_exists('gia_conv_history'))
            ? gia_conv_history($db, $convId, 20)
            : [];

        // --- APPROVE TAP (money gate) — handled DETERMINISTICALLY, not via the LLM.
        //     A tap sends payload 'approve|<checkout_ref>'. We pay ONLY when the chat
        //     is VERIFIED. We do NOT rely on the model remembering refs (stateless
        //     webhooks don't replay tool results): gia_pay_approve_and_charge()
        //     recovers everything from the signed checkout, mints the snapshot-gated
        //     approval, assigns the wallet gateway, and charges via process_payment.
        //     Then we reply with the real outcome — the model never touches money. ---
        if (preg_match('/^approve\|(gco_[a-f0-9]{20})$/', $text, $am)) {
            if (empty($ident['verified'])) {
                return $fail("Please link your account first so I can take payment securely — say \"verify\" and I'll send a code.", false, false, $convKey);
            }
            $coRef = $am[1];
            if (!function_exists('gia_pay_approve_and_charge')) {
                $pf = $aiDir . 'gia_pay.php';
                if (is_file($pf)) { require_once $pf; }
            }
            // Wallet path: point the booking at the wallet gateway when the verified
            // user has the balance (else process_payment uses the assigned gateway).
            if (function_exists('gia_pay_invoice_for_checkout') && function_exists('gia_pay_wallet_ready')
                && function_exists('gia_pay_assign_wallet_gateway')) {
                $invForCo = gia_pay_invoice_for_checkout($db, $coRef);
                if ($invForCo !== '') {
                    try {
                        $bk = $db->get('bookings', ['price_markup', 'currency_markup'], ['invoice_id' => $invForCo]);
                        $amt = (float) ($bk['price_markup'] ?? 0);
                        $cur = (string) ($bk['currency_markup'] ?? '');
                        if ($accountId !== '' && gia_pay_wallet_ready($db, $accountId, $amt, $cur)) {
                            gia_pay_assign_wallet_gateway($db, $invForCo);
                        }
                    } catch (\Throwable $e) { /* non-fatal; fall through to charge */ }
                }
            }
            $pay = function_exists('gia_pay_approve_and_charge')
                ? gia_pay_approve_and_charge($db, $coRef, $accountId)
                : ['ok' => false, 'error' => 'Payment is unavailable right now.'];

            if (!empty($pay['ok'])) {
                $reply = !empty($pay['already_paid'])
                    ? 'That booking is already paid — you\'re all set. ✈️'
                    : (!empty($pay['redirect_url'])
                        ? "You're approved. Complete payment here: " . (string) $pay['redirect_url']
                        : "Payment started — I'll confirm the moment it's done.");
            } else {
                $code = (string) ($pay['error_code'] ?? '');
                $reply = $code === 'PRICE_CHANGED'
                    ? "The price just changed since we quoted it — let me re-quote before you pay."
                    : (string) ($pay['error'] ?? "I couldn't complete the payment. Please try again.");
            }
            if ($convId > 0 && function_exists('gia_log_turn')) {
                gia_log_turn($db, $convId, $text, $reply, []);
            }
            return ['ok' => true, 'skip' => false, 'handoff' => false, 'conv_key' => $convKey, 'reply' => $reply, 'rich' => ['text' => $reply, 'buttons' => [], 'cards' => []]];
        }

        // --- GIA PAY OPTIONS (bank transfer / card) — handled DETERMINISTICALLY.
        //     A tap sends 'paybank|<invoice>' or 'paycard|<invoice>'. We create the
        //     Paystack per-booking DVA or hosted checkout (tied to that invoice) and
        //     reply with the real account/link. The model never touches money. ---
        if (preg_match('/^(paybank|paycard)\|([A-Za-z0-9]{4,64})$/', $text, $pm)) {
            $payKind = $pm[1];
            $invoiceId = $pm[2];
            require_once $aiDir . 'gia_booking_payment.php';
            $logReply = function ($reply) use ($db, $convId, $text, $convKey) {
                if ($convId > 0 && function_exists('gia_log_turn')) { gia_log_turn($db, $convId, $text, $reply, []); }
                return ['ok' => true, 'skip' => false, 'handoff' => false, 'conv_key' => $convKey, 'reply' => $reply, 'rich' => ['text' => $reply, 'buttons' => [], 'cards' => []]];
            };
            try {
                if ($payKind === 'paybank') {
                    $r = gia_bp_create_dva($db, $invoiceId, $channel, $channelUserId);
                    if (!empty($r['ok'])) {
                        $mins = function_exists('gia_bp_window_minutes') ? gia_bp_window_minutes() : 20;
                        $reply = "Pay by bank transfer — send exactly *" . $r['currency'] . ' ' . number_format((float) $r['amount'], 2) . "* to:\n"
                            . "Bank: " . $r['bank_name'] . "\n"
                            . "Account: " . $r['account_number'] . "\n"
                            . "Name: " . $r['account_name'] . "\n\n"
                            . "This account is for THIS booking only and expires in ~" . $mins . " min. I'll confirm automatically the moment your transfer lands.";
                    } elseif (!empty($r['not_ngn'])) {
                        $reply = (string) $r['message'];
                    } else {
                        $reply = (string) ($r['message'] ?? "I couldn't set up the transfer account. You can pay by card instead.");
                    }
                    return $logReply($reply);
                }
                // paycard
                $r = gia_bp_create_checkout($db, $invoiceId, $channel, $channelUserId);
                if (!empty($r['ok'])) {
                    $mins = function_exists('gia_bp_window_minutes') ? gia_bp_window_minutes() : 20;
                    $reply = "Pay securely online (card, bank, USSD and more): " . $r['authorization_url']
                        . "\n\nAmount: " . $r['currency'] . ' ' . number_format((float) $r['amount'], 2)
                        . ". The link is for this booking and expires in ~" . $mins . " min. I'll confirm the moment it's paid.";
                } else {
                    $reply = (string) ($r['message'] ?? "I couldn't start the online payment. Please try again.");
                }
                return $logReply($reply);
            } catch (\Throwable $e) {
                error_log('[gia] pay option ' . $payKind . ': ' . $e->getMessage());
                return $logReply("Something went wrong setting up that payment. Please try again in a moment.");
            }
        }

        // --- Trusted context. account_id enables READS for the resolved person.
        //     user_approved stays FALSE for the model path: the approve TAP is
        //     handled deterministically above, so the LLM never needs R3. ---
        // Currency: channels have no browser session, so resolve the SITE's default
        // currency from the currencies table (default='1') instead of hardcoding USD
        // — a Nigerian install quotes NGN, matching the website. Falls back to USD.
        $channelCurrency = 'USD';
        try {
            $defCur = $db->get('currencies', 'name', ['default' => '1', 'status' => '1'])
                ?: $db->get('currencies', 'name', ['default' => '1'])
                ?: $db->get('currencies', 'name', ['ORDER' => ['default' => 'DESC']]);
            if (is_string($defCur) && $defCur !== '') { $channelCurrency = strtoupper($defCur); }
        } catch (\Throwable $e) { /* keep USD fallback */ }

        $toolContext = [
            'account_id'         => $accountId,
            'user_approved'      => false,
            'currency'           => $channelCurrency,
            'conv_id'            => $convId,
            'conv_key'           => $convKey,
            'channel'            => $channel,
            'channel_user_id'    => $channelUserId,
            'identity_verified'  => !empty($ident['verified']),
            'last_search_params' => [],
        ];

        try {
            $agent = new giaAgent($db);
            // DEDICATED umrah tools FIRST — umrah never uses the shared search.
            $agent->registerTool(new umrahSearchTool());
            $agent->registerTool(new umrahTiersTool());
            $agent->registerTool(new umrahBookTool());
            // DEDICATED flights tools — flights use their own flow, never search_trip.
            $agent->registerTool(new flightsSearchTool());
            $agent->registerTool(new flightsSelectTool());
            $agent->registerTool(new flightsBookTool());
            $agent->registerTool(new flightsAddPassengerTool());
            // DEDICATED stays tools — stays use their own flow, never search_trip.
            $agent->registerTool(new staysSearchTool());
            $agent->registerTool(new staysRoomsTool());
            $agent->registerTool(new staysBookTool());
            $agent->registerTool(new searchTripTool());
            $agent->registerTool(new fetchLiveOffersTool());
            $agent->registerTool(new selectOptionTool());
            $agent->registerTool(new composeTripTool());
            $agent->registerTool(new createCheckoutTool());
            $agent->registerTool(new createBookingTool());
            // NOTE: requestApprovalTool + initiatePaymentTool are DELIBERATELY NOT
            // registered on channels. Payment here is handled OUTSIDE the model —
            // after create_booking the interface shows the pay options (bank-transfer
            // VA / Paystack checkout) and a tap completes it. Not registering them
            // (rather than only instructing the model) means the model CANNOT waste a
            // turn calling a payment tool or surface a confusing "not permitted" error.
            $agent->registerTool(new requestHumanTool());
            // Account linking (verify_identity) is always available so GIA can
            // offer to link the chat to an account.
            $agent->registerTool(new verifyIdentityTool());
            // get_order_status reads a booking by invoice ref with NO owner scope
            // (shared tool). On a channel we therefore register it ONLY for a
            // VERIFIED traveler — a stranger can't look up arbitrary bookings here.
            // (Web behavior is unchanged; this gate is channel-only.)
            if (!empty($ident['verified'])) {
                $agent->registerTool(new getOrderStatusTool());
            }
            $agent->setToolContext($toolContext);
            // Teach GIA how to act on a tapped option button ('pick|module|ref').
            $agent->addSystemNote(gia_channel_pick_note());
            // Payment on channels is handled OUTSIDE the model: after create_booking,
            // the interface shows an 'Approve & pay' button (or a secure pay link) and
            // the tap completes payment. So GIA must NOT call request_booking_approval
            // or initiate_payment here — stop after create_booking and tell the
            // traveler to tap the button / open the link to pay. If they're not yet
            // account-linked, verify first (verify_identity).
            $agent->addSystemNote(
                "PAYMENT ON THIS CHANNEL: do the flow up to and including create_booking, then STOP and "
                . "invite the traveler to tap the 'Approve & pay' button (or the payment link) shown below "
                . "your message — that completes payment securely. Do NOT call request_booking_approval or "
                . "initiate_payment yourself here; the button handles it. Never ask for card details in chat."
            );
            // Bot disclosure (Meta/WhatsApp policy: automated chats must disclose
            // they're automated). On the FIRST turn of a channel conversation,
            // instruct GIA to make that clear naturally in its opening reply.
            if ($history === []) {
                $agent->addSystemNote(
                    "CHANNEL: this traveler is messaging you on " . ucfirst($channel) . ". In your FIRST "
                    . "reply of this conversation, make clear in a natural, friendly way that they're "
                    . "chatting with GIA, GoGlobia's automated assistant (not a specific human), and that "
                    . "they can ask for a person anytime. Keep replies concise and plain for messaging."
                );
            }
            // Greet by name whenever we resolved a person (verified OR passive
            // phone match) — personalization is read-only, so a match is enough here.
            if ($accountId !== '' && function_exists('gia_user_profile')) {
                $agent->setUserProfile(gia_user_profile($db, $accountId));
            }
            // Identity guidance: if NOT verified on this channel, GIA must link the
            // account (verify_identity) before anything account-specific — it has no
            // way to read "my bookings" here until then.
            if (empty($ident['verified'])) {
                $agent->addSystemNote(
                    "ACCOUNT: this chat is NOT yet linked to a GoGlobia account. If the traveler asks about "
                    . "'my bookings', their order status, or wants to pay from their wallet, first link their "
                    . "account using the verify_identity tool (ask for their account email, send a code, confirm "
                    . "it). You cannot look up their bookings until the chat is verified. You can still help them "
                    . "search and plan new trips without linking."
                );
            }
            // DETERMINISTIC NUMERIC PICK: on WhatsApp a >3-option list is shown as a
            // numbered TEXT list (no tap-buttons), so the traveler types "2". Map that
            // to the exact pick-button id we showed last turn BEFORE the model runs —
            // otherwise the model gets a naked "2" with no ref and just re-searches
            // (the live "pick a number -> same cards reappear" loop).
            $runText = $text;
            $picked = gia_channel_resolve_numeric_pick($db, $convId, $text);
            if ($picked !== '') {
                if (function_exists('gia_trace')) { \gia_trace('numeric_pick', __FILE__, '"' . mb_substr($text, 0, 20) . '" -> ' . $picked); }
                $runText = $picked;
            }
            $result = $agent->run($runText, $history);
        } catch (\Throwable $e) {
            error_log('[gia] channel_handle run: ' . $e->getMessage());
            return $fail('Sorry — I hit a snag just now. Please try again in a moment.', false, false, $convKey);
        }

        // When the agent itself failed (e.g. the AI provider returned a non-2xx
        // like an invalid key 401 or a retired-model 404), it carries the raw
        // reason in `debug`/`error` for operators. The traveller only ever sees
        // the friendly `reply`, but WITHOUT this the real cause never reached any
        // log on the channel path — so a live "having a little trouble" was
        // undiagnosable. Log it (operator-only) so the exact HTTP cause is visible.
        if (($result['ok'] ?? false) !== true && (($result['error'] ?? '') !== '' || ($result['debug'] ?? '') !== '')) {
            error_log('[gia] channel agent failed: ' . (string) ($result['error'] ?? '')
                . ' | ' . (string) ($result['debug'] ?? '')
                . ' | provider=' . (string) ($result['provider'] ?? '')
                . ' model=' . (string) ($result['model'] ?? ''));
        }

        $reply = trim((string) ($result['reply'] ?? ''));
        if ($reply === '') {
            $reply = "I'm here — tell me where you'd like to go and I'll find real options.";
        }

        // --- Build rich choices (tappable buttons/cards) from this turn's tool
        //     outputs, so the channel can render native buttons. A tap sends back
        //     the button id as the next message; gia_channel_pick_note() (added to
        //     the prompt above) tells GIA how to act on it. Payment context lets it
        //     add an Approve-&-pay button (verified + wallet-funded) or a pay-link. ---
        $rich = gia_channel_build_rich($reply, $result['tool_runs'] ?? [], [
            'db'                => $db,
            'identity_verified' => !empty($ident['verified']),
            'account_id'        => $accountId,
            'conv_key'          => $convKey,
        ]);

        // Remember the ordered pick-buttons shown this turn, so a later bare-number
        // reply ("2") resolves to the exact choice instead of re-searching.
        gia_channel_store_choices($db, $convId, $rich);

        // --- Persist the turn (cross-device resume + admin reporting). ---
        if ($convId > 0 && function_exists('gia_log_turn')) {
            gia_log_turn($db, $convId, $text, $reply, is_array($result['usage'] ?? null) ? $result['usage'] : []);
        }

        return ['ok' => true, 'skip' => false, 'handoff' => false, 'conv_key' => $convKey, 'reply' => $reply, 'rich' => $rich];
    }
}

if (!function_exists('App\\lib\\ai\\gia_channel_pick_note')) {
    /** The system note that teaches GIA to act on a tapped-button payload. */
    function gia_channel_pick_note(): string
    {
        return "PICK BUTTONS: some of your replies show tappable option buttons. When the traveler taps "
             . "one, their next message will be EXACTLY one of these — treat each as their choice and act "
             . "immediately, never ask them to retype it:\n"
             . "- 'pick|<module>|<ref>' -> call select_option with that module and ref (id), then continue.\n"
             . "- 'pickdep|umrah|<departure_id>' -> they chose an Umrah DEPARTURE; call umrah_tiers with that "
             . "departure_id to show its tiers + prices.\n"
             . "- 'picktier|umrah|<departure_tier_id>' -> they chose an Umrah TIER; confirm pilgrim count if "
             . "unknown, then call umrah_book with that departure_tier_id to create the held booking.\n"
             . "- 'pickfare|<ref>' -> they chose a FLIGHT fare; call flights_select with that ref to "
             . "reconfirm the price and the required passenger fields, then collect passengers and book.\n"
             . "- 'pickstay|<stay_id>' -> they chose a HOTEL; ask their check-in + check-out dates if you "
             . "don't have them, then call stays_rooms with that stay_id + the dates.\n"
             . "- 'pick|stays|stayroom:<s>:<r>:<o>' -> they chose a ROOM; call stays_book with that ref "
             . "and the check-in/check-out dates to create the held booking.\n"
             . "CRITICAL: a pick payload means the traveler ALREADY chose from the list you showed. "
             . "NEVER run the search again (no flights_search / stays_search / umrah_search) to 'refresh' "
             . "it — act on the ref directly. Re-searching here is a bug the traveler experiences as the "
             . "same list reappearing. The refs you showed are still valid for the select/book step.";
    }
}

if (!function_exists('App\\lib\\ai\\gia_channel_store_choices')) {
    /**
     * Persist the ORDERED pick-button ids this turn showed, so a later bare-number
     * reply ("2") can be resolved to the exact choice. On WhatsApp a list of >3
     * options renders as a NUMBERED TEXT LIST (no tap-buttons), so the user types a
     * number — which otherwise reaches the model as a naked "2" with no ref, and the
     * model just re-searches. Storing the order lets us rewrite "2" -> the 2nd id
     * deterministically BEFORE the model runs. Reuses gia_offer_cache (conv-keyed).
     */
    function gia_channel_store_choices($db, int $convId, array $rich): void
    {
        if ($convId <= 0 || !\function_exists('gia_offer_cache_put')) { return; }
        $ids = [];
        // Cards are shown first (each with one pick button), then standalone buttons —
        // same visual order the traveler sees numbered on the channel.
        foreach (($rich['cards'] ?? []) as $c) {
            foreach (($c['buttons'] ?? []) as $b) {
                $id = (string) ($b['id'] ?? '');
                if ($id !== '' && gia_channel_is_pick_id($id)) { $ids[] = $id; }
            }
        }
        foreach (($rich['buttons'] ?? []) as $b) {
            $id = (string) ($b['id'] ?? '');
            if ($id !== '' && gia_channel_is_pick_id($id)) { $ids[] = $id; }
        }
        if ($ids === []) { return; }
        \gia_offer_cache_put($db, $convId, 'last_choices', ['ids' => $ids]);
    }
}

if (!function_exists('App\\lib\\ai\\gia_channel_is_pick_id')) {
    /** True for the selection button-ids we teach the model to act on. */
    function gia_channel_is_pick_id(string $id): bool
    {
        foreach (['pick|', 'pickfare|', 'pickdep|', 'picktier|', 'pickstay|'] as $p) {
            if (\strncmp($id, $p, \strlen($p)) === 0) { return true; }
        }
        return false;
    }
}

if (!function_exists('App\\lib\\ai\\gia_channel_resolve_numeric_pick')) {
    /**
     * If $text is a bare selection ("2", "option 2", "number 3", "the first one"),
     * map it to the Nth pick-button id stored for this conversation and return that
     * id; else return '' (leave the text untouched). This is the deterministic fix
     * for the WhatsApp "pick a number -> GIA re-searches" loop: the number becomes a
     * real ref the model is already taught to act on, so it never re-searches.
     */
    function gia_channel_resolve_numeric_pick($db, int $convId, string $text): string
    {
        if ($convId <= 0 || !\function_exists('gia_offer_cache_get')) { return ''; }
        $t = \strtolower(\trim($text));
        // Already a pick id (a real button tap) — nothing to resolve.
        if (gia_channel_is_pick_id($t)) { return ''; }
        $n = 0;
        if (\preg_match('/^(?:option|number|no\.?|#)?\s*([1-9][0-9]?)\b/', $t, $m)) {
            $n = (int) $m[1];
        } else {
            $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
                      'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10];
            foreach ($words as $w => $v) {
                if (\preg_match('/\b(?:the\s+)?' . $w . '(?:\s+one)?\b/', $t)) { $n = $v; break; }
            }
        }
        if ($n < 1) { return ''; }
        // Guard: a message with real content beyond the number (dates, names, a city)
        // is NOT a bare pick — leave it for the model. Only treat SHORT replies.
        if (\mb_strlen($t) > 24) { return ''; }
        $store = \gia_offer_cache_get($db, $convId, 'last_choices');
        $ids = \is_array($store['ids'] ?? null) ? $store['ids'] : [];
        if ($n > \count($ids)) { return ''; }
        return (string) $ids[$n - 1];
    }
}

if (!function_exists('App\\lib\\ai\\gia_channel_build_rich')) {
    /**
     * Derive a normalized rich message (text + pickable buttons/cards) from the
     * agent's tool_runs for this turn. Buttons carry id 'pick|<module>|<ref>' so a
     * tap round-trips into select_option (see gia_channel_pick_note). When a
     * booking was just created, it adds an Approve-&-pay button (verified traveler
     * with enough wallet balance) and/or a secure pay-link card. Returns a
     * normalized ['text'=>,'buttons'=>,'cards'=>].
     *
     * @param array $payCtx ['db'=>, 'identity_verified'=>bool, 'account_id'=>string]
     */
    function gia_channel_build_rich(string $text, array $toolRuns, array $payCtx = []): array
    {
        $cards = [];
        $buttons = [];
        // Payment refs seen this turn (to wire the Approve button / pay-link).
        $checkoutRef = ''; $invoiceId = ''; $payAmount = 0.0; $payCurrency = '';
        $umrahCollectBookingId = 0; $umrahCollectPax = 0; $umrahBookingReused = false;
        foreach ($toolRuns as $tr) {
            if (!is_array($tr)) { continue; }
            $name = (string) ($tr['name'] ?? '');
            $data = (isset($tr['output']['data']) && is_array($tr['output']['data'])) ? $tr['output']['data'] : [];
            $ok   = (bool) ($tr['output']['ok'] ?? false);

            // Track payment refs from the booking-flow tools.
            if ($ok && $name === 'create_checkout' && !empty($data['checkout_ref'])) {
                $checkoutRef = (string) $data['checkout_ref'];
            }
            if ($ok && $name === 'request_booking_approval' && !empty($data['checkout_ref'])) {
                $checkoutRef = (string) $data['checkout_ref'];
            }
            // create_booking (generic) OR umrah_book (the dedicated umrah tool) both
            // produce a held unpaid booking with an invoice to pay / collect against.
            if ($ok && ($name === 'create_booking' || $name === 'umrah_book') && !empty($data['invoice_id'])) {
                $invoiceId   = (string) $data['invoice_id'];
                $payAmount   = (float) ($data['amount'] ?? 0);
                $payCurrency = (string) ($data['currency'] ?? '');
                // Umrah needs per-pilgrim passports BEFORE payment — capture the
                // booking id + pax so we can start the collection and DEFER the pay
                // options until the manifest is complete.
                if ((string) ($data['module'] ?? '') === 'umrah' && !empty($data['needs_travellers'])) {
                    $umrahCollectBookingId = (int) ($data['umrah_booking_id'] ?? 0);
                    $umrahCollectPax       = (int) ($data['pax'] ?? 0);
                    // A REUSED booking (double-book guard) already has a live collection
                    // — don't re-start it below (gia_pt_start resets passport progress).
                    $umrahBookingReused    = !empty($tr['output']['reused']);
                }
            }

            // FLIGHTS fare cards: flights_search returns data.searches[].cards[] (and
            // data.flights[]). Render each as a native pickable card; a tap sends
            // 'pickfare|<ref>' -> the model calls flights_select then flights_book.
            if ($ok && $name === 'flights_search' && !empty($data['flights']) && is_array($data['flights'])) {
                foreach ($data['flights'] as $c) {
                    if (!is_array($c)) { continue; }
                    $ref = (string) ($c['ref'] ?? ($c['id'] ?? ''));
                    if ($ref === '') { continue; }
                    $price = $c['price'] ?? null;
                    $cur   = (string) ($c['currency'] ?? '');
                    $sub   = trim((string) ($c['subtitle'] ?? '') . ($price !== null ? '  ' . trim($cur . ' ' . number_format((float) $price, 2)) : ''));
                    $cards[] = [
                        'title'    => (string) ($c['title'] ?? ($c['name'] ?? 'Flight')),
                        'subtitle' => $sub,
                        'image'    => '',
                        'buttons'  => [['id' => 'pickfare|' . $ref, 'title' => 'Select this fare']],
                    ];
                }
            }
            // FLIGHTS manifest complete: flights_add_passenger says needs_payment ->
            // capture the invoice + amount so the pay buttons appear (same as umrah
            // once pilgrims are done). We read the amount from the booking row.
            if ($ok && $name === 'flights_add_passenger' && !empty($data['needs_payment']) && !empty($data['invoice_id'])) {
                $invoiceId = (string) $data['invoice_id'];
                if ($db !== null) {
                    try {
                        $fb = $db->get('bookings', ['price_markup', 'currency_markup'], ['invoice_id' => $invoiceId]);
                        if (is_array($fb)) {
                            $payAmount   = (float) ($fb['price_markup'] ?? 0);
                            $payCurrency = (string) ($fb['currency_markup'] ?? '');
                        }
                    } catch (\Throwable $e) { /* pay buttons still show if amount>0 elsewhere */ }
                }
            }

            // STAYS property + room cards: stays_search returns data.stays[]; stays_rooms
            // returns data.stays_rooms[]. Both render as native pickable cards. A
            // property tap sends 'pickstay|<stay_id>'; a room tap sends the room ref
            // 'pick|stays|stayroom:...' which the model turns into stays_book.
            if ($ok && $name === 'stays_search' && !empty($data['stays']) && is_array($data['stays'])) {
                foreach ($data['stays'] as $c) {
                    if (!is_array($c)) { continue; }
                    $sid = (int) ($c['stay_id'] ?? ($c['id'] ?? 0));
                    if ($sid <= 0) { continue; }
                    $cards[] = [
                        'title'    => (string) ($c['title'] ?? ($c['name'] ?? 'Hotel')),
                        'subtitle' => (string) ($c['subtitle'] ?? ($c['location'] ?? '')),
                        'image'    => (string) ($c['image'] ?? ''),
                        'buttons'  => [['id' => 'pickstay|' . $sid, 'title' => 'See rooms & prices']],
                    ];
                }
            }
            if ($ok && $name === 'stays_rooms' && !empty($data['stays_rooms']) && is_array($data['stays_rooms'])) {
                foreach ($data['stays_rooms'] as $c) {
                    if (!is_array($c)) { continue; }
                    $ref = (string) ($c['ref'] ?? ($c['id'] ?? ''));
                    if (strpos($ref, 'stayroom:') !== 0) { continue; }
                    $price = $c['price'] ?? null;
                    $cur   = (string) ($c['currency'] ?? '');
                    $sub   = trim((string) ($c['subtitle'] ?? '') . ($price !== null ? '  ' . trim($cur . ' ' . number_format((float) $price, 2)) : ''));
                    $cards[] = [
                        'title'    => (string) ($c['title'] ?? ($c['name'] ?? 'Room')),
                        'subtitle' => $sub,
                        'image'    => (string) ($c['image'] ?? ''),
                        'buttons'  => [['id' => 'pick|stays|' . $ref, 'title' => 'Choose this room']],
                    ];
                }
            }
            // STAYS held -> straight to pay (no passenger manifest). Capture invoice +
            // amount so the pay buttons appear.
            if ($ok && $name === 'stays_book' && !empty($data['needs_payment']) && !empty($data['invoice_id'])) {
                $invoiceId   = (string) $data['invoice_id'];
                $payAmount   = (float) ($data['amount'] ?? 0);
                $payCurrency = (string) ($data['currency'] ?? '');
            }

            // Live offers: data.module + data.cards[] (each has ref/title/price/...).
            if ($name === 'fetch_live_offers' && $ok && !empty($data['cards']) && is_array($data['cards'])) {
                $mod = (string) ($data['module'] ?? '');
                foreach ($data['cards'] as $c) {
                    if (!is_array($c)) { continue; }
                    $ref = (string) ($c['ref'] ?? ($c['id'] ?? ''));
                    if ($mod === '' || $ref === '') { continue; }
                    $title = (string) ($c['title'] ?? ($c['name'] ?? 'Option'));
                    $price = $c['price'] ?? null;
                    $cur   = (string) ($c['currency'] ?? '');
                    $sub   = trim((string) ($c['subtitle'] ?? '') . ($price !== null ? '  ' . trim($cur . ' ' . $price) : ''));
                    $cards[] = [
                        'title'    => $title,
                        'subtitle' => $sub,
                        'image'    => (string) ($c['image'] ?? ''),
                        'buttons'  => [['id' => 'pick|' . $mod . '|' . $ref, 'title' => 'Select']],
                    ];
                }
            }
            // STAYS ROOM TYPES: select_option on a property returns data.stays_rooms[]
            // (each room with option rows). Render one card per bookable room-option
            // with a pick button carrying the colon-delimited room ref.
            if ($ok && $name === 'select_option' && !empty($data['stays_rooms']) && is_array($data['stays_rooms'])) {
                $stayId = (int) ($data['stay_id'] ?? 0);
                foreach ($data['stays_rooms'] as $rm) {
                    if (!is_array($rm)) { continue; }
                    $roomId = (int) ($rm['room_id'] ?? 0);
                    $roomName = (string) ($rm['room_name'] ?? 'Room');
                    foreach (($rm['options'] ?? []) as $o) {
                        if (!is_array($o)) { continue; }
                        $optId = (int) ($o['option_id'] ?? 0);
                        if ($stayId <= 0 || $roomId <= 0 || $optId <= 0) { continue; }
                        $price = $o['price'] ?? null;
                        $cur   = (string) ($o['currency'] ?? ($data['currency'] ?? ''));
                        $bits  = [];
                        if (!empty($o['board'])) { $bits[] = (string) $o['board']; }
                        if (!empty($o['free_cancellation'])) { $bits[] = 'Free cancellation'; }
                        if (($o['available'] ?? 0) > 0 && (int) $o['available'] <= 5) { $bits[] = 'Only ' . (int) $o['available'] . ' left'; }
                        $sub = trim(implode(' · ', $bits) . ($price !== null ? '  ' . trim($cur . ' ' . number_format((float) $price, 2)) : ''));
                        $ref = 'stayroom:' . $stayId . ':' . $roomId . ':' . $optId;
                        $cards[] = [
                            'title'    => $roomName,
                            'subtitle' => $sub,
                            'image'    => (string) ($rm['image'] ?? ''),
                            'buttons'  => [['id' => 'pick|stays|' . $ref, 'title' => 'Choose this room']],
                        ];
                    }
                }
            }
            // UMRAH DEPARTURES (dedicated tool): umrah_search returns data.searches[].cards[]
            // where each card is a DEPARTURE carrying departure_id. Build a native card
            // per departure with a pick button that leads to its TIERS (umrah_tiers),
            // NOT select_option (which would mis-treat the id as a tier id).
            if ($ok && $name === 'umrah_search' && isset($data['searches']) && is_array($data['searches'])) {
                foreach ($data['searches'] as $sr) {
                    if (!is_array($sr) || empty($sr['cards']) || !is_array($sr['cards'])) { continue; }
                    foreach ($sr['cards'] as $c) {
                        if (!is_array($c)) { continue; }
                        $depId = (int) ($c['departure_id'] ?? 0);
                        if ($depId <= 0) { continue; }
                        $price = $c['price'] ?? ($c['unit_price'] ?? null);
                        $cur   = (string) ($c['currency'] ?? '');
                        $sub   = trim((string) ($c['subtitle'] ?? '') . ($price !== null ? '  ' . trim($cur . ' ' . number_format((float) $price, 2)) : ''));
                        $cards[] = [
                            'title'    => (string) ($c['name'] ?? ($c['title'] ?? 'Umrah departure')),
                            'subtitle' => $sub,
                            'image'    => (string) ($c['image'] ?? ''),
                            'buttons'  => [['id' => 'pickdep|umrah|' . $depId, 'title' => 'See tiers & prices']],
                        ];
                    }
                }
            }
            // UMRAH TIERS (dedicated tool): umrah_tiers returns data.umrah_tiers[] — the
            // SAME class of bug the web + model side had (the channel never rendered
            // these). Build a native card per tier with a pick button that leads to the
            // held booking (umrah_book) carrying the departure_tier_id.
            if ($ok && $name === 'umrah_tiers' && !empty($data['umrah_tiers']) && is_array($data['umrah_tiers'])) {
                foreach ($data['umrah_tiers'] as $t) {
                    if (!is_array($t)) { continue; }
                    $dtid = (int) ($t['departure_tier_id'] ?? 0);
                    if ($dtid <= 0) { continue; }
                    $price = $t['price'] ?? ($t['unit_price'] ?? null);
                    $cur   = (string) ($t['currency'] ?? '');
                    $sub   = trim((string) ($t['room_sharing'] ?? '') . ($price !== null ? '  ' . trim($cur . ' ' . number_format((float) $price, 2)) : ''));
                    $cards[] = [
                        'title'    => (string) ($t['tier_label'] ?? 'Tier'),
                        'subtitle' => $sub,
                        'image'    => '',
                        'buttons'  => [['id' => 'picktier|umrah|' . $dtid, 'title' => 'Choose ' . (string) ($t['tier_label'] ?? 'tier')]],
                    ];
                }
            }
            // Local search cards (umrah/stays/tours/bus/visa): data.searches[].cards[].
            if ($name === 'search_trip' && isset($data['searches']) && is_array($data['searches'])) {
                foreach ($data['searches'] as $sr) {
                    if (!is_array($sr) || empty($sr['cards']) || !is_array($sr['cards'])) { continue; }
                    $mod = (string) ($sr['module'] ?? ($sr['kind'] ?? ''));
                    foreach ($sr['cards'] as $c) {
                        if (!is_array($c)) { continue; }
                        $ref = (string) ($c['ref'] ?? ($c['id'] ?? ($c['slug'] ?? '')));
                        if ($mod === '' || $ref === '') { continue; }
                        $title = (string) ($c['name'] ?? ($c['title'] ?? 'Option'));
                        $price = $c['price'] ?? ($c['adult_price'] ?? null);
                        $cur   = (string) ($c['currency'] ?? '');
                        $sub   = trim((string) ($c['subtitle'] ?? ($c['location'] ?? '')) . ($price !== null ? '  ' . trim($cur . ' ' . $price) : ''));
                        $cards[] = [
                            'title'    => $title,
                            'subtitle' => $sub,
                            'image'    => (string) ($c['image'] ?? ''),
                            'buttons'  => [['id' => 'pick|' . $mod . '|' . $ref, 'title' => 'Select']],
                        ];
                    }
                }
            }
        }
        // --- GIA PAY OPTIONS: a booking was created this turn with a real invoice.
        //     Offer the two chat-native Paystack options (bank transfer / card).
        //     The taps ('paybank|<invoice>' / 'paycard|<invoice>') are handled
        //     deterministically in gia_channel_handle — the model never moves money.
        //     A verified traveller with enough wallet balance ALSO gets a one-tap
        //     "Approve & pay" (wallet) fast-path when the legacy checkout_ref exists. ---
        // UMRAH GATE: a real umrah needs every pilgrim's passport BEFORE payment.
        // When the booking was just created and still needs travellers, START the
        // pilgrim collection for this conversation and ask for the first passport —
        // do NOT show the pay options yet (they appear once the manifest is complete,
        // from the dispatch intercept after the last pilgrim is saved).
        $db = $payCtx['db'] ?? null;
        if ($invoiceId !== '' && $umrahCollectBookingId > 0 && $umrahCollectPax > 0 && $db !== null) {
            require_once __DIR__ . '/gia_pending_travellers.php';
            $convKey = (string) ($payCtx['conv_key'] ?? '');
            // Skip gia_pt_start if the booking was reused or a collection is already
            // live for this conversation — starting again would reset passport progress.
            $alreadyCollecting = ($convKey !== '' && function_exists('gia_pt_active')
                && is_array(gia_pt_active($db, $convKey)));
            if ($convKey !== '' && function_exists('gia_pt_start')) {
                if (!($umrahBookingReused || $alreadyCollecting)) {
                    gia_pt_start($db, $convKey, 'umrah', $invoiceId, $umrahCollectBookingId, $umrahCollectPax);
                }
                $amtLabel = trim($payCurrency . ' ' . number_format($payAmount, 2));
                $who = $umrahCollectPax === 1 ? "the pilgrim's" : "each pilgrim's";
                $text = trim($text . "\n\nBooking created (" . $invoiceId . ") — total " . $amtLabel . "."
                    . "\nBefore payment I need " . $who . " passport (required for the Saudi visa)."
                    . "\nPlease send a clear PHOTO of the lead pilgrim's passport data page (or type their "
                    . "full name, gender, date of birth, nationality, passport number and expiry).");
                // No pay buttons yet — gated until the manifest is complete.
                if (count($cards) > 10) { $cards = array_slice($cards, 0, 10); }
                return ['text' => $text, 'buttons' => $buttons, 'cards' => $cards];
            }
        }

        if ($invoiceId !== '' && $payAmount > 0) {
            $verified  = !empty($payCtx['identity_verified']);
            $accountId = (string) ($payCtx['account_id'] ?? '');
            if ($db !== null && !function_exists('gia_pay_wallet_ready')) {
                $f = __DIR__ . '/gia_pay.php';
                if (is_file($f)) { require_once $f; }
            }
            $walletReady = $db !== null && $verified && $accountId !== '' && $checkoutRef !== ''
                && function_exists('gia_pay_wallet_ready')
                && gia_pay_wallet_ready($db, $accountId, $payAmount, $payCurrency);

            $amtLabel = trim($payCurrency . ' ' . number_format($payAmount, 2));
            $text = trim($text . "\n\nBooking created (" . $invoiceId . ") — total " . $amtLabel
                . ". Choose how to pay; you have ~20 min before it's released:");

            // Wallet fast-path first (verified + funded), then the two Paystack options.
            if ($walletReady) {
                $buttons[] = ['id' => 'approve|' . $checkoutRef, 'title' => 'Pay from wallet'];
            }
            $buttons[] = ['id' => 'paybank|' . $invoiceId, 'title' => 'Pay by bank transfer'];
            $buttons[] = ['id' => 'paycard|' . $invoiceId, 'title' => 'Pay with card / online'];
        }

        // Cap total cards defensively (renderers also clamp per platform).
        if (count($cards) > 10) { $cards = array_slice($cards, 0, 10); }
        return ['text' => $text, 'buttons' => $buttons, 'cards' => $cards];
    }
}
