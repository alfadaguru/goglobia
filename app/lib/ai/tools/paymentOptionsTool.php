<?php

namespace App\lib\ai;

/**
 * GIA tool: report the real payment options available for a booking (R0 —
 * read-only, no money moves). World-class-GIA GA7.
 *
 * Surfaces the EXISTING, scope-gated Pay-Small-Small (deposit + instalments) and
 * Pay-Later (pay by a deadline) engines so GIA can tell a traveller "you can pay
 * 25% now and the rest over N parts" at checkout — a top competitive lever
 * (Wakanow's Pay Small Small). It ONLY reads the configured rules
 * (pay_small_small_summary_for / pay_later_is_enabled_for); it never creates a
 * plan or moves money — selecting an option still goes through the normal,
 * proven checkout. Figures come straight from the rule engine, never invented.
 */
class paymentOptionsTool implements giaToolInterface
{
    public function name(): string
    {
        return 'get_payment_options';
    }

    public function description(): string
    {
        return 'Report the real payment options for a booking by its invoice reference: pay in '
            . 'full, Pay Small Small (a deposit now + the rest in scheduled parts) and/or Pay Later '
            . '(pay by a deadline) — only when actually enabled for that service. Use at checkout to '
            . 'offer instalments. Quote ONLY the figures this returns; never invent a plan or amount.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_id' => ['type' => 'string', 'description' => 'The booking invoice reference.'],
            ],
            'required' => ['invoice_id'],
        ];
    }

    public function riskClass(): string
    {
        return 'R0';
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Payment options are unavailable right now.'];
        }
        $invoiceId = preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            return ['ok' => false, 'error_code' => 'AI_BAD_INVOICE', 'message' => 'Which booking? I need its reference.'];
        }

        $b = $db->get('bookings', ['module_type', 'module', 'price_markup', 'currency_markup'], ['invoice_id' => $invoiceId]);
        if (!$b) {
            return ['ok' => false, 'error_code' => 'AI_ORDER_NOT_FOUND', 'message' => 'I couldn\'t find that booking.'];
        }
        // The service drives which rules apply. For GIA ai_trip bookings the real
        // service is in `module` (e.g. flights/umrah); module_type is 'ai_trip'.
        $service  = strtolower(trim((string) ($b['module'] ?: $b['module_type'] ?? '')));
        $supplier = ''; // resolver maps '' to the configured supplier for the scope
        $total    = (float) ($b['price_markup'] ?? 0);
        $currency = (string) ($b['currency_markup'] ?? '');

        $options = ['pay_in_full' => true];

        // Pay Small Small (deposit + instalments) — honest figures from the engine.
        if (function_exists('pay_small_small_summary_for')) {
            try {
                $pss = pay_small_small_summary_for($db, $service, $supplier, $total);
                if (is_array($pss)) {
                    $options['pay_small_small'] = [
                        'first_amount' => $pss['first_amount'] ?? null,
                        'remaining'    => $pss['remaining'] ?? null,
                        'parts'        => $pss['parts'] ?? null,
                        'label'        => (string) ($pss['label'] ?? ''),
                        'currency'     => $currency,
                    ];
                }
            } catch (\Throwable $e) {
                error_log('[gia] get_payment_options pss: ' . $e->getMessage());
            }
        }

        // Pay Later (pay by a deadline) — availability flag only.
        if (function_exists('pay_later_is_enabled_for')) {
            try {
                if (pay_later_is_enabled_for($db, $service, $supplier)) {
                    $options['pay_later'] = ['available' => true];
                }
            } catch (\Throwable $e) {
                error_log('[gia] get_payment_options paylater: ' . $e->getMessage());
            }
        }

        $hasInstalments = isset($options['pay_small_small']) || isset($options['pay_later']);
        return [
            'ok'   => true,
            'data' => ['invoice_id' => $invoiceId, 'total' => $total, 'currency' => $currency, 'options' => $options],
            'message' => $hasInstalments
                ? 'Instalment / pay-later options are available for this booking — offer them alongside paying in full.'
                : 'Only pay-in-full is available for this booking.',
        ];
    }
}
