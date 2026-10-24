<?php

namespace App\lib\ai;

/**
 * GIA tool: full account awareness (R0 — read-only, scoped to the signed-in /
 * identified user). Returns ALL of the traveller's bookings across every service
 * (central `bookings` + the separate `umrah_bookings`), split into upcoming vs
 * past, plus wallet balance, loyalty points, name and currency.
 *
 * GIA uses this to answer "what trips do I have", "when's my next flight",
 * "what's my wallet balance", "status of my umrah booking" from REAL data — never
 * invented. The account is taken from the trusted context (account_id), never from
 * model input, so GIA can only ever read the current user's own account.
 */
class userAccountTool implements giaToolInterface
{
    public function name(): string
    {
        return 'get_my_account';
    }

    public function description(): string
    {
        return 'Get the signed-in traveller\'s own account overview: all their bookings across '
            . 'every service (flights, stays, tours, cars, bus, rail, visa, eSIM, ferries and umrah), '
            . 'split into upcoming and past, plus wallet balance, loyalty points, name and currency. '
            . 'Use this to answer anything about "my bookings", "my trips", "my next flight", '
            . '"my wallet" or "my points". Only works when the traveller is identified; never guess.';
    }

    public function inputSchema(): array
    {
        // No inputs — the account is the trusted identified user, not a parameter.
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function riskClass(): string
    {
        return 'R0'; // read-only
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Account details are unavailable right now.'];
        }
        $userId = (string) ($context['account_id'] ?? '');
        if ($userId === '') {
            return [
                'ok' => false,
                'error_code' => 'AI_NOT_IDENTIFIED',
                'message' => 'I can only show your bookings once I know who you are. '
                    . 'On the website you need to be signed in; on WhatsApp/Telegram I may need to link your account first.',
            ];
        }

        require_once dirname(__DIR__) . '/gia_account.php';

        try {
            $snap = \gia_user_snapshot($db, $userId);
        } catch (\Throwable $e) {
            error_log('[gia] get_my_account: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_ACCOUNT_ERROR', 'message' => 'Could not load your account just now.'];
        }

        if (empty($snap['found'])) {
            return ['ok' => false, 'error_code' => 'AI_ACCOUNT_NOT_FOUND', 'message' => 'I could not find your account details.'];
        }

        return [
            'ok'   => true,
            'data' => [
                'name'     => $snap['name'],
                'currency' => $snap['currency'],
                'wallet'   => $snap['wallet'],
                'loyalty'  => $snap['loyalty'],
                'counts'   => $snap['counts'],
                'upcoming' => $snap['upcoming'],
                'past'     => $snap['past'],
            ],
            'message' => \gia_user_summary_line($snap),
        ];
    }
}
