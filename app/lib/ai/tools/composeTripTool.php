<?php

namespace App\lib\ai;

/**
 * GIA tool: reprice/validate an existing saved trip draft (R2 — reversible).
 *
 * This does NOT invent pricing. It reuses the platform's authoritative reprice
 * engine `aiTripRevalidatePackage($db, &$draft)` (app/routes/ai/tripRevalidateHelper.php,
 * a pure helper — no routes registered, safe to require) to refresh the live price
 * of each item in a draft stored in `logs_bookings`. It returns the current total,
 * whether the price changed, and the per-item summary — the grounded basis for a
 * checkout snapshot. No money moves.
 *
 * Input: { draft_hash }. The draft must already exist (created by the existing
 * /api/ai/trip/save-draft flow). GIA composes by repricing what the user selected.
 */
class composeTripTool implements giaToolInterface
{
    public function name(): string
    {
        return 'reprice_trip';
    }

    public function description(): string
    {
        return 'Refresh the live price of a saved trip draft and report the current total, '
            . 'the currency, whether the price changed since it was saved, and a per-item '
            . 'summary. Call this before creating a checkout so the quote is current. '
            . 'Read-only pricing: it does not book or charge anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'draft_hash' => [
                    'type' => 'string',
                    'description' => 'The hash of the saved trip draft to reprice.',
                ],
            ],
            'required' => ['draft_hash'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // reversible: refreshes a draft's prices; no commitment
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Pricing is unavailable.'];
        }
        $hash = trim((string) ($input['draft_hash'] ?? ''));
        if ($hash === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $hash)) {
            return ['ok' => false, 'error_code' => 'AI_BAD_DRAFT', 'message' => 'A valid trip draft reference is required.'];
        }

        // Load the draft (same table/shape the AI-trip flow uses).
        try {
            $draft = $db->get('logs_bookings', ['id', 'data', 'user_id'], ['hash' => $hash]);
        } catch (\Throwable $e) {
            error_log('[gia] reprice_trip load: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_DRAFT_ERROR', 'message' => 'Could not load your trip draft.'];
        }
        if (!$draft || empty($draft['data'])) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_NOT_FOUND', 'message' => 'That trip draft was not found or has expired.'];
        }
        $draftData = json_decode((string) $draft['data'], true);
        if (!is_array($draftData) || empty($draftData['items'])) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_EMPTY', 'message' => 'That draft has no trip items to price.'];
        }

        // Reuse the authoritative reprice engine (pure helper; already loaded by the
        // AI-trip routes, but require defensively in case this tool runs first).
        $helper = dirname(__DIR__, 2) . '/routes/ai/tripRevalidateHelper.php';
        if (!function_exists('aiTripRevalidatePackage') && is_file($helper)) {
            require_once $helper;
        }
        if (!function_exists('aiTripRevalidatePackage')) {
            return ['ok' => false, 'error_code' => 'AI_REPRICE_UNAVAILABLE', 'message' => 'Pricing engine is unavailable.'];
        }

        try {
            $summary = \aiTripRevalidatePackage($db, $draftData); // reprices $draftData in place
        } catch (\Throwable $e) {
            error_log('[gia] reprice_trip engine: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_REPRICE_ERROR', 'message' => 'Could not refresh prices right now. Please try again.'];
        }

        // Persist the repriced draft back (so create_checkout freezes the fresh quote).
        try {
            $db->update('logs_bookings', [
                'data' => json_encode($draftData),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['hash' => $hash]);
        } catch (\Throwable $e) {
            error_log('[gia] reprice_trip persist: ' . $e->getMessage());
        }

        $items = is_array($summary['items'] ?? null) ? $summary['items'] : ($draftData['items'] ?? []);
        $compactItems = [];
        foreach ($items as $it) {
            $compactItems[] = [
                'module'   => (string) ($it['module'] ?? $it['kind'] ?? ''),
                'supplier' => (string) ($it['supplier'] ?? ''),
                'title'    => (string) ($it['title'] ?? ''),
                'price'    => round((float) ($it['price'] ?? 0), 2),
            ];
        }

        $ok = (bool) ($summary['status'] ?? false);
        return [
            'ok' => $ok,
            'data' => [
                'draft_hash'    => $hash,
                'currency'      => (string) ($summary['currency'] ?? ($draftData['currency'] ?? 'USD')),
                'total'         => round((float) ($summary['new_total'] ?? ($draftData['total'] ?? 0)), 2),
                'old_total'     => round((float) ($summary['old_total'] ?? 0), 2),
                'price_changed' => (bool) ($summary['price_changed'] ?? false),
                'item_count'    => count($compactItems),
                'items'         => $compactItems,
            ],
            'message' => $ok
                ? ((bool) ($summary['price_changed'] ?? false)
                    ? 'Prices refreshed — the total changed since you saved this trip.'
                    : 'Prices are current.')
                : (string) ($summary['message'] ?? 'Some items could not be priced.'),
            'error_code' => $ok ? '' : 'AI_REPRICE_INVALID',
        ];
    }
}
