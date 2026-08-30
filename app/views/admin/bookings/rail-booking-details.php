<?php
@$SECURE or die('Access Denied!');

$railPassengers = is_array($rail_admin['passengers'] ?? null) ? $rail_admin['passengers'] : [];
$detailPassengers = array_values(array_filter($railPassengers, static fn($p) => !empty($p['requires_details'])));
$freePassengers = array_values(array_filter($railPassengers, static fn($p) => empty($p['requires_details'])));
?>

<div class="card p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">directions_railway</span>
            <h3><?= T::train ?? 'Train' ?> <?= T::booking ?? 'Booking' ?></h3>
        </div>
        <div class="text-sm text-slate-600">
            <?= htmlspecialchars((string)($rail_admin['region_label'] ?? '')) ?>
        </div>
    </div>
    <div class="p-6 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
                <div class="text-xs font-medium text-slate-500 mb-1"><?= T::route ?? 'Route' ?></div>
                <div class="text-sm font-semibold text-slate-900">
                    <?= htmlspecialchars((string)($rail_admin['from_name'] ?? '')) ?>
                    <span class="text-slate-400 mx-1">→</span>
                    <?= htmlspecialchars((string)($rail_admin['to_name'] ?? '')) ?>
                </div>
                <div class="text-xs text-slate-500 mt-1 font-mono">
                    <?= htmlspecialchars((string)($rail_admin['from_code'] ?? '')) ?> → <?= htmlspecialchars((string)($rail_admin['to_code'] ?? '')) ?>
                </div>
            </div>
            <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
                <div class="text-xs font-medium text-slate-500 mb-1"><?= T::train ?? 'Train' ?></div>
                <div class="text-sm font-semibold text-slate-900 font-mono"><?= htmlspecialchars((string)($rail_admin['train_no'] ?: '—')) ?></div>
                <div class="text-xs text-slate-500 mt-1"><?= htmlspecialchars((string)($rail_admin['seat_class'] ?? '')) ?></div>
            </div>
            <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
                <div class="text-xs font-medium text-slate-500 mb-1"><?= T::departure ?? 'Departure' ?></div>
                <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)($rail_admin['departure_at'] ?: '—')) ?></div>
                <?php if (!empty($rail_admin['arrival_at'])): ?>
                <div class="text-xs text-slate-500 mt-1"><?= T::arrival ?? 'Arrival' ?>: <?= htmlspecialchars((string)$rail_admin['arrival_at']) ?></div>
                <?php endif; ?>
            </div>
            <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
                <div class="text-xs font-medium text-slate-500 mb-1"><?= T::supplier ?? 'Supplier' ?> ID</div>
                <div class="text-sm font-semibold text-slate-900 font-mono break-all"><?= htmlspecialchars((string)($rail_admin['cus_main_order_id'] ?: '—')) ?></div>
                <?php if (empty($rail_admin['online_refund'])): ?>
                <div class="text-xs text-amber-700 mt-1"><?= T::station_refund_only ?? 'Refunds at station only' ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 rounded-lg border border-purple-100 bg-purple-50/60">
            <div>
                <div class="text-xs font-medium text-slate-600 mb-1"><?= T::contact ?? 'Contact' ?></div>
                <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)($rail_admin['contact_name'] ?: '—')) ?></div>
            </div>
            <div>
                <div class="text-xs font-medium text-slate-600 mb-1"><?= T::email ?? 'Email' ?></div>
                <div class="text-sm text-slate-900 break-all"><?= htmlspecialchars((string)($rail_admin['contact_email'] ?: '—')) ?></div>
            </div>
            <div>
                <div class="text-xs font-medium text-slate-600 mb-1"><?= T::phone ?? 'Phone' ?></div>
                <div class="text-sm text-slate-900"><?= htmlspecialchars((string)($rail_admin['contact_phone'] ?: '—')) ?></div>
            </div>
        </div>

        <?php if ($detailPassengers !== []): ?>
        <div>
            <div class="flex items-center justify-between gap-3 mb-3">
                <h4 class="text-sm font-semibold text-slate-700"><?= T::passengers ?? T::travellers_information ?></h4>
                <span class="text-xs text-slate-500">
                    <?= (int)($rail_admin['billable_count'] ?? count($detailPassengers)) ?> <?= T::ticketed ?? 'ticketed' ?>
                    <?php if ((int)($rail_admin['free_infant_count'] ?? 0) > 0): ?>
                        · <?= (int)$rail_admin['free_infant_count'] ?> <?= T::free ?> <?= (int)$rail_admin['free_infant_count'] > 1 ? T::infants : T::infant ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="space-y-4">
                <?php foreach ($detailPassengers as $passenger): ?>
                <div class="border border-slate-200 rounded-xl overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-2 bg-slate-50 border-b border-slate-200 px-4 py-2.5">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-[#1570ef] text-lg">person</span>
                            <span class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string)$passenger['heading']) ?></span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= htmlspecialchars((string)$passenger['badge_class']) ?>">
                                <?= htmlspecialchars((string)$passenger['type_label']) ?>
                            </span>
                        </div>
                        <?php if (!empty($passenger['seat_display'])): ?>
                        <span class="text-xs font-medium text-emerald-700"><?= htmlspecialchars((string)$passenger['seat_display']) ?></span>
                        <?php elseif (!empty($passenger['seat_status_message'])): ?>
                        <span class="text-xs text-slate-500"><?= htmlspecialchars((string)$passenger['seat_status_message']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1"><?= T::name ?? 'Name' ?></label>
                            <div class="text-sm font-medium text-slate-900"><?= htmlspecialchars((string)($passenger['name'] ?: '—')) ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1"><?= T::gender ?? 'Gender' ?></label>
                            <div class="text-sm text-slate-900"><?= htmlspecialchars((string)($passenger['gender'] ?: '—')) ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1"><?= T::date_of_birth ?? 'Date of birth' ?></label>
                            <div class="text-sm text-slate-900"><?= htmlspecialchars((string)($passenger['birth_date'] ?: '—')) ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1"><?= T::nationality ?? 'Nationality' ?></label>
                            <div class="text-sm text-slate-900"><?= htmlspecialchars((string)($passenger['country'] ?: '—')) ?></div>
                        </div>
                        <?php if ($passenger['metric'] !== null && (int)$passenger['type'] !== 1): ?>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1"><?= htmlspecialchars((string)$passenger['metric_label']) ?></label>
                            <div class="text-sm text-slate-900"><?= (int)$passenger['metric'] ?></div>
                        </div>
                        <?php endif; ?>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1"><?= T::document ?? 'Document' ?></label>
                            <div class="text-sm text-slate-900"><?= htmlspecialchars((string)($passenger['doc_type'] ?: '—')) ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1"><?= T::document ?? 'Document' ?> #</label>
                            <div class="text-sm font-mono text-slate-900 break-all"><?= htmlspecialchars((string)($passenger['doc_no'] ?: '—')) ?></div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1"><?= T::expiry ?? 'Expiry' ?></label>
                            <div class="text-sm text-slate-900"><?= htmlspecialchars((string)($passenger['doc_expiry'] ?: '—')) ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($freePassengers !== []): ?>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($freePassengers as $passenger): ?>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-800 border border-emerald-200">
                <span class="material-symbols-outlined text-sm">child_care</span>
                <?= htmlspecialchars((string)$passenger['heading']) ?>
                <?php if ($passenger['metric'] !== null): ?>
                    · <?= (int)$passenger['metric'] ?> <?= htmlspecialchars((string)$passenger['metric_label']) ?>
                <?php endif; ?>
                · <?= T::free ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
