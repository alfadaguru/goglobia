<?php
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 5) . '/modules/ferries/kikoto/api.php';

// Decode stored booking data
$bookingData = is_string($booking['booking_data'] ?? '') ? (json_decode($booking['booking_data'], true) ?: []) : ($booking['booking_data'] ?? []);
$travellers  = is_string($booking['travellers']    ?? '') ? (json_decode($booking['travellers'],    true) ?: []) : ($booking['travellers']    ?? []);

// Helper: resolve ISO2 country code to full country name
$countryNameCache = [];
$resolveCountryName = function(string $iso) use ($db, &$countryNameCache): string {
    if (!$iso) return '';
    $iso = strtoupper($iso);
    if (!isset($countryNameCache[$iso])) {
        $row = $db->get('countries', ['name'], ['iso' => $iso]);
        $countryNameCache[$iso] = $row['name'] ?? $iso;
    }
    return $countryNameCache[$iso];
};

$confirmed   = $bookingData['confirmed'] ?? [];
// Sailings: from confirmed response OR build from draft selected_sailing
if (!empty($confirmed['sailings'])) {
    $sailings = $confirmed['sailings'];
} else {
    $draftSailing = $bookingData['draft']['selected_sailing'] ?? [];
    $sailings = !empty($draftSailing) ? [$draftSailing] : [];
    // Add return sailing if present
    if (!empty($bookingData['draft']['return_sailing'])) {
        $sailings[] = $bookingData['draft']['return_sailing'];
    }
}
$locators    = $bookingData['locators']  ?? [];
$reference   = $bookingData['reference'] ?? $booking['pnr'] ?? '';
$locator     = implode(', ', $locators) ?: ($booking['pnr'] ?? '');
$status      = strtolower($booking['booking_status'] ?? 'pending');

// Pets / vehicles / bonuses — prefer top-level booking_data, fallback to draft
$invoiceVehicles = $bookingData['vehicles'] ?? $bookingData['draft']['vehicles'] ?? [];
$invoicePets     = $bookingData['pets']     ?? $bookingData['draft']['pets']     ?? [];
if (!is_array($invoiceVehicles)) $invoiceVehicles = [];
if (!is_array($invoicePets))     $invoicePets     = [];

$invoiceBonusIds = _kikoto_normalize_bonus_ids(
    $bookingData['bonuses'] ?? $bookingData['draft']['bonuses'] ?? []
);
if (empty($invoiceBonusIds) && !empty($bookingData['draft']) && is_array($bookingData['draft'])) {
    $invoiceBonusIds = _kikoto_extract_bonuses_from_draft($bookingData['draft']);
}
$invoiceBonusDetails = $bookingData['bonus_details'] ?? $bookingData['draft']['bonus_details'] ?? [];
$invoiceBonuses = _kikoto_resolve_bonus_labels($invoiceBonusIds, is_array($invoiceBonusDetails) ? $invoiceBonusDetails : []);
$invoiceCoupon = trim((string)($bookingData['coupon'] ?? $bookingData['draft']['coupon'] ?? ''));
$hasBonusOrCoupon = !empty($invoiceBonuses) || $invoiceCoupon !== '';

$ticketTypesById = [];
foreach (($bookingData['draft']['selected_sailing']['shipping_company']['ticket_types'] ?? []) as $tt) {
    if (is_array($tt) && isset($tt['id'])) {
        $ticketTypesById[(int)$tt['id']] = $tt;
    }
}
$resolveTicketLabel = function (int $typeId) use ($ticketTypesById): string {
    if ($typeId <= 0) return '';
    $tt = $ticketTypesById[$typeId] ?? null;
    if (!$tt) return (string)$typeId;
    $name = trim((string)($tt['name'] ?? ''));
    $desc = trim((string)($tt['description'] ?? ''));
    return $desc !== '' ? ($name . ' — ' . $desc) : ($name !== '' ? $name : (string)$typeId);
};

$passengerCategoryCounts = ['adult' => 0, 'child' => 0, 'infant' => 0];
$resolvePassengerHeading = function (array $t) use (&$passengerCategoryCounts, $ticketTypesById): string {
    return _kikoto_passenger_heading($t, $passengerCategoryCounts, $ticketTypesById);
};
$resolveLinkedPassengerName = function (int $passengerId) use ($travellers): string {
    return _kikoto_resolve_traveller_name($passengerId, $travellers);
};

// Resolve port names from cache
$ferriesPortsCache = dirname(__DIR__, 5) . '/app/cache/kikoto_ports_en.json';
$ferriesPorts = file_exists($ferriesPortsCache) ? json_decode(file_get_contents($ferriesPortsCache), true) : [];
$ferriesPortName = function (int $id) use ($ferriesPorts): string {
    foreach ($ferriesPorts as $p) { if ((int)($p['id'] ?? 0) === $id) return $p['name'] ?? ''; }
    return 'Port ' . $id;
};

$bookingData['base_currency'] = $booking['currency_markup'] ?? 'EUR';
?>

<div class="bg-gray-200 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <!-- Breadcrumb -->
        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>ferries" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?= T::ferries ?? 'Ferries' ?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/></svg>
            <span class="text-gray-700 text-[13px] font-medium">Invoice #<?= $invoiceId ?></span>
        </div>

        <!-- Payment notice flash -->
        <?php if (!empty($_SESSION['payment_notice'])): ?>
        <?php $notice = $_SESSION['payment_notice']; $nt = $notice['type'] ?? 'success'; unset($_SESSION['payment_notice']); ?>
        <div class="alert alert-<?= $nt === 'warning' ? 'warning' : ($nt === 'error' ? 'error' : 'success') ?> mb-5">
            <span class="material-symbols-outlined"><?= $nt === 'warning' ? 'warning' : ($nt === 'error' ? 'error' : 'check_circle') ?></span>
            <div>
                <p class="font-semibold"><?= htmlspecialchars($notice['title'] ?? '') ?></p>
                <p class="text-sm"><?= htmlspecialchars($notice['message'] ?? '') ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status banner -->
        <?php if ($status === 'confirmed' && !empty($locator)): ?>
        <div class="alert alert-success mb-6" id="successMessage" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold"><?= T::booking_confirmed ?? 'Ferry booking confirmed!' ?></p>
                <p class="text-sm">Invoice ID: <strong><?= $invoiceId ?></strong> &nbsp;·&nbsp; Locator: <strong><?= htmlspecialchars($locator) ?></strong></p>
            </div>
        </div>
        <?php elseif ($status === 'pending'): ?>
        <div class="alert alert-warning mb-6">
            <span class="material-symbols-outlined">schedule</span>
            <div>
                <p class="font-semibold"><?= T::pending_payment ?? 'Payment Required' ?></p>
                <p class="text-sm">Complete your payment below to confirm this booking. Your ticket locator will be issued after payment.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            <!-- LEFT -->
            <div class="lg:col-span-2 space-y-3">

                <!-- Invoice / Customer Info -->
                <div class="card p-0">
                    <div class="card-header">
                        <span class="card-header-icon">receipt_long</span>
                        <h3>Invoice Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3">Invoice Information</h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Invoice ID:</span>
                                        <span class="font-medium">#<?= $invoiceId ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Booking Date:</span>
                                        <span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['created_at'])) ?></span>
                                    </div>
                                    <?php if ($reference && $status === 'confirmed'): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Reference:</span>
                                        <span class="font-medium"><?= htmlspecialchars($reference) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($locator && $status === 'confirmed'): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Locator:</span>
                                        <span class="font-medium text-blue-600"><?= htmlspecialchars($locator) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3">Customer Information</h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Name:</span>
                                        <span class="font-medium"><?= htmlspecialchars(trim(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''))) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Email:</span>
                                        <span class="font-medium"><?= htmlspecialchars($booking['email'] ?? '') ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Phone:</span>
                                        <span class="font-medium">+<?= htmlspecialchars($booking['phone_country_code'] ?? '') ?> <?= htmlspecialchars($booking['phone'] ?? '') ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Status:</span>
                                        <span class="badge badge-<?= $status === 'confirmed' ? 'success' : ($status === 'cancelled' ? 'error' : 'warning') ?>">
                                            <?= ucfirst($status) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sailing Details -->
                <div class="card p-0">
                    <div class="card-header">
                        <span class="card-header-icon">directions_boat</span>
                        <h3><?= T::sailing_details ?? 'Sailing Details' ?></h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($sailings)): ?>
                            <?php foreach ($sailings as $i => $s):
                                $acc        = $s['accommodations'][0] ?? [];
                                $depDT      = $s['departure_datetime'] ?? '';
                                $arrDT      = $s['arrival_datetime']   ?? '';
                                $depPort    = $ferriesPortName((int)($s['departure_port_id'] ?? 0));
                                $arrPort    = $ferriesPortName((int)($s['arrival_port_id'] ?? $s['destination_port_id'] ?? 0));
                                $sailDate   = $depDT ? date('D, d M Y', strtotime($depDT)) : '';
                                $depTime    = $depDT ? date('H:i', strtotime($depDT))      : '';
                                $arrTime    = $arrDT ? date('H:i', strtotime($arrDT))      : '';
                            ?>
                            <div class="<?= $i > 0 ? 'mt-5 pt-5 border-t border-gray-200 dark:border-gray-700' : '' ?>">
                                <!-- Route bar -->
                                <div class="flex items-center gap-3 mb-4 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($depTime) ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-snug"><?= htmlspecialchars($depPort) ?></p>
                                        <p class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($sailDate) ?></p>
                                    </div>
                                    <div class="flex flex-col items-center flex-shrink-0 px-2">
                                        <div class="flex items-center gap-1">
                                            <div class="w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                                            <span class="material-symbols-outlined text-blue-500" style="font-size:18px">directions_boat</span>
                                            <div class="w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0 text-right">
                                        <p class="text-xl font-bold text-gray-900 dark:text-white leading-none"><?= htmlspecialchars($arrTime) ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-snug"><?= htmlspecialchars($arrPort) ?></p>
                                    </div>
                                </div>
                                <!-- Accommodation -->
                                <?php if (!empty($acc['title'])): ?>
                                <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                    <span class="material-symbols-outlined text-base">bed</span>
                                    <span><?= htmlspecialchars($acc['title']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-gray-400 text-sm">Sailing details not available.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Passengers -->
                <?php if (!empty($travellers)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <span class="card-header-icon">group</span>
                        <h3><?= T::passengers ?? 'Passengers' ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <?php foreach ($travellers as $idx => $t): ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3"><?= htmlspecialchars($resolvePassengerHeading($t)) ?></p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Name:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars(ucfirst($t['title'] ?? '') . ' ' . ($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?></span>
                                    </div>
                                    <?php if (!empty($t['birthdate'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Date of Birth:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars(date('d M Y', strtotime($t['birthdate']))) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($t['nationality'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Nationality:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars($resolveCountryName($t['nationality'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($t['identity_number'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Document:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars(strtoupper($t['identity_type'] ?? 'Passport')) ?>: <?= htmlspecialchars($t['identity_number']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($t['ticket_type_id'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Ticket Type:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars($resolveTicketLabel((int)$t['ticket_type_id'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Vehicles -->
                <?php if (!empty($invoiceVehicles)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <span class="card-header-icon">directions_car</span>
                        <h3><?= T::vehicles ?? 'Vehicles' ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <?php foreach ($invoiceVehicles as $idx => $v): ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3"><?= T::vehicles ?? 'Vehicle' ?> <?= $idx + 1 ?></p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::vehicle_type ?? 'Type' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars($resolveTicketLabel((int)($v['ticket_type_id'] ?? 0))) ?></span>
                                    </div>
                                    <?php if (!empty($v['license_plate'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::license_plate ?? 'License plate' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars((string)$v['license_plate']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($v['brand'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::brand ?? 'Brand' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars((string)$v['brand']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($v['passenger_id'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::driver ?? 'Driver' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars($resolveLinkedPassengerName((int)$v['passenger_id']) ?: ('Passenger #' . (int)$v['passenger_id'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pets -->
                <?php if (!empty($invoicePets)): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <span class="card-header-icon">pets</span>
                        <h3><?= T::pets ?? 'Pets' ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <?php foreach ($invoicePets as $idx => $pet): ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3"><?= T::pets ?? 'Pet' ?> <?= $idx + 1 ?></p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::pet_type ?? 'Type' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars($resolveTicketLabel((int)($pet['ticket_type_id'] ?? 0))) ?></span>
                                    </div>
                                    <?php if (!empty($pet['name'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::pet_name ?? 'Name' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars((string)$pet['name']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($pet['passenger_id'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::owner ?? 'Owner' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars($resolveLinkedPassengerName((int)$pet['passenger_id']) ?: ('Passenger #' . (int)$pet['passenger_id'])) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Bonuses / Coupon -->
                <?php if ($hasBonusOrCoupon): ?>
                <div class="card p-0">
                    <div class="card-header">
                        <span class="card-header-icon">sell</span>
                        <h3><?= T::bonus_discount ?? 'Bonus / Discount' ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <?php foreach ($invoiceBonuses as $bonus): ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::name ?? 'Name' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars((string)($bonus['name'] ?? ('#' . (int)($bonus['id'] ?? 0)))) ?></span>
                                    </div>
                                    <?php if (!empty($bonus['type'])): ?>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::type ?? 'Type' ?>:</span>
                                        <span class="font-medium ml-1 capitalize"><?= htmlspecialchars((string)$bonus['type']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($bonus['description'])): ?>
                                    <div class="md:col-span-2">
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::description ?? 'Description' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars((string)$bonus['description']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($invoiceCoupon !== ''): ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400"><?= T::coupon_code ?? 'Coupon code' ?>:</span>
                                        <span class="font-medium ml-1"><?= htmlspecialchars($invoiceCoupon) ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
            <div class="lg:col-span-1">
                <?php
                $moduleType = 'ferries';
                // Ensure price is never 0 — summary.php fallback chain skips zeros incorrectly
                if ((float)($booking['price_markup'] ?? 0) <= 0) {
                    $draftAccomm = $bookingData['draft']['selected_sailing']['accommodations'][0] ?? [];
                    $booking['price_markup'] = (float)($draftAccomm['price'] ?? $booking['price_original'] ?? 0);
                }
                include views . 'includes/invoice/summary.php';
                ?>
            </div>

        </div>
    </div>
</div>

<script>
function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) { alert('Please select a payment gateway'); return; }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= root ?>payment/process';
    [['invoice_id','<?= $invoiceId ?>'],['gateway_id',gateway]].forEach(([n,v]) => {
        const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}

function downloadInvoice() {
    window.location.href = '<?= root ?>api/ferries/booking/download-invoice/<?= $invoiceId ?>';
}

function requestCancellation() {
    if (!confirm('Are you sure you want to request cancellation for this booking? Our team will review and process it.')) return;
    fetch('<?= root ?>api/ferries/booking/request-cancellation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invoice_id: '<?= $invoiceId ?>' })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            alert('Cancellation request submitted successfully. Our team will contact you shortly.');
            location.reload();
        } else {
            alert('Error: ' + (d.message || 'Failed to submit cancellation request'));
        }
    }).catch(() => alert('Network error. Please try again.'));
}

document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('successMessage');
    const key = 'success_dismissed_<?= $invoiceId ?>';
    if (localStorage.getItem(key)) { el && (el.style.display = 'none'); return; }
    setTimeout(() => { el && (el.style.display = 'none'); localStorage.setItem(key, '1'); }, 5000);
});
</script>

<style>
@media print {
    nav, .btn, header, footer { display: none !important; }
    .bg-gray-200 { background: white !important; }
}
</style>
