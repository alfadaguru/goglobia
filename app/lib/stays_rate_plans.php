<?php
// FILE: app/lib/stays_rate_plans.php
// Normalized rate-plan MIRROR for stays (Phase 1 inc S12).
//
// room_options JSON stays the CANONICAL runtime source — the live
// booking/detail/listing path reads it unchanged. These helpers MIRROR each
// option into the relational tables stays_rate_plans + stays_rates so the rate
// model is queryable (future channel manager / OTA mapping / LOS pricing). The
// join key is the STABLE room_options.option_id (app/lib/stays_inventory.php).
//
// Design rule: nothing READS these tables yet, and the sync is defensive/
// non-fatal — so a mirror failure can never affect a booking or a page load.
// Call stays_rate_plan_sync() right after any write to a room's room_options.

if (!function_exists('stays_rate_plan_sync')) {
    /**
     * Mirror a room's room_options into stays_rate_plans + stays_rates.
     * Upserts one plan+rate per option (keyed by the stable option_id), and
     * SOFT-REMOVES (active=0) any plan whose option_id no longer exists on the
     * room. Returns the number of options mirrored, or 0 on any issue.
     */
    function stays_rate_plan_sync($db, int $stayId, int $roomId): int
    {
        if ($stayId <= 0 || $roomId <= 0) { return 0; }
        if (!function_exists('stays_room_option_ids')) { return 0; }

        try {
            // Load the room's options WITH stable ids assigned (idempotent).
            $options = stays_room_option_ids($db, $roomId, $stayId);
            $now = date('Y-m-d H:i:s');

            $liveOptionIds = [];
            foreach ($options as $o) {
                $optionId = (int) ($o['option_id'] ?? 0);
                if ($optionId <= 0) { continue; }
                $liveOptionIds[] = $optionId;

                $planFields = [
                    'name'               => isset($o['name']) ? (string) $o['name'] : null,
                    'board_id'           => isset($o['board_id']) ? (int) $o['board_id'] : null,
                    'refundable'         => !empty($o['refundable']) ? 1 : 0,
                    'cancellation_free'  => !empty($o['cancellation_free']) ? 1 : 0,
                    'breakfast_included' => !empty($o['breakfast_included']) ? 1 : 0,
                    'max_adults'         => max(0, (int) ($o['max_adults'] ?? 0)),
                    'max_children'       => max(0, (int) ($o['max_children'] ?? 0)),
                    'status'             => !empty($o['status']) ? 1 : 0,
                    'active'             => 1,
                    'updated_at'         => $now,
                ];

                $existingPlan = $db->get('stays_rate_plans', ['id'],
                    ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId]);

                if ($existingPlan) {
                    $planId = (int) $existingPlan['id'];
                    $db->update('stays_rate_plans', $planFields, ['id' => $planId]);
                } else {
                    $db->insert('stays_rate_plans', array_merge($planFields, [
                        'stay_id'    => $stayId,
                        'room_id'    => $roomId,
                        'option_id'  => $optionId,
                        'created_at' => $now,
                    ]));
                    $planId = (int) $db->id();
                }
                if ($planId <= 0) { continue; }

                $rateFields = [
                    'price'               => round((float) ($o['price'] ?? 0), 2),
                    'discount_percentage' => round((float) ($o['discount_percentage'] ?? 0), 2),
                    'extra_bed_available' => !empty($o['extra_bed_available']) ? 1 : 0,
                    'extra_bed_charge'    => round((float) ($o['extra_bed_charge'] ?? 0), 2),
                    'available_quantity'  => max(0, (int) ($o['available_quantity'] ?? 0)),
                    'updated_at'          => $now,
                ];
                if ($db->has('stays_rates', ['rate_plan_id' => $planId])) {
                    $db->update('stays_rates', $rateFields, ['rate_plan_id' => $planId]);
                } else {
                    $db->insert('stays_rates', array_merge($rateFields, [
                        'rate_plan_id' => $planId,
                        'stay_id'      => $stayId,
                        'room_id'      => $roomId,
                        'option_id'    => $optionId,
                        'created_at'   => $now,
                    ]));
                }
            }

            // Soft-remove plans whose option_id is gone from the JSON (deleted option).
            $allPlans = $db->select('stays_rate_plans', ['id', 'option_id'],
                ['stay_id' => $stayId, 'room_id' => $roomId, 'active' => 1]) ?: [];
            $liveSet = array_fill_keys($liveOptionIds, true);
            foreach ($allPlans as $p) {
                if (empty($liveSet[(int) $p['option_id']])) {
                    $db->update('stays_rate_plans', ['active' => 0, 'updated_at' => $now], ['id' => (int) $p['id']]);
                }
            }

            return count($liveOptionIds);
        } catch (\Throwable $e) {
            error_log('stays_rate_plan_sync: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('stays_rate_plans_for_room')) {
    /** Active normalized rate plans for a room (joined to their base rate). Read-only
     *  convenience for future consumers; the live path does NOT use this yet. */
    function stays_rate_plans_for_room($db, int $stayId, int $roomId): array
    {
        try {
            return $db->select('stays_rate_plans',
                ['[>]stays_rates' => ['id' => 'rate_plan_id']],
                [
                    'stays_rate_plans.id',
                    'stays_rate_plans.option_id',
                    'stays_rate_plans.name',
                    'stays_rate_plans.board_id',
                    'stays_rate_plans.refundable',
                    'stays_rate_plans.max_adults',
                    'stays_rate_plans.max_children',
                    'stays_rate_plans.status',
                    'stays_rates.price',
                    'stays_rates.discount_percentage',
                    'stays_rates.available_quantity',
                ],
                ['stays_rate_plans.stay_id' => $stayId, 'stays_rate_plans.room_id' => $roomId, 'stays_rate_plans.active' => 1]
            ) ?: [];
        } catch (\Throwable $e) {
            error_log('stays_rate_plans_for_room: ' . $e->getMessage());
            return [];
        }
    }
}
