<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Claim confirmation / status page. $booking + $claimData set by the route.
$flight    = $claimData['flight'] ?? [];
$passenger = $claimData['passenger'] ?? [];

// The AirHelp sub-state is richer than the bookings ENUM. Prefer the
// airhelp_state stored in booking_response / error_response; fall back to the
// ENUM booking_status.
$airhelpState = '';
$resp = json_decode((string) ($booking['booking_response'] ?? ''), true);
$err  = json_decode((string) ($booking['error_response'] ?? ''), true);
if (is_array($resp) && !empty($resp['airhelp_state'])) {
    $airhelpState = $resp['airhelp_state'];
} elseif (is_array($err) && !empty($err['airhelp_state'])) {
    $airhelpState = $err['airhelp_state'];
}
$status = strtolower($airhelpState ?: (string) ($booking['booking_status'] ?? 'pending'));

$statusMap = [
    'confirmed'           => ['Registered with AirHelp', 'bg-green-100 text-green-800', 'check_circle'],
    'pending'             => ['Submitted — under review', 'bg-amber-100 text-amber-800', 'hourglass_top'],
    'pending_credentials' => ['Submitted — awaiting activation', 'bg-amber-100 text-amber-800', 'hourglass_top'],
    'failed'              => ['Could not be registered', 'bg-red-100 text-red-800', 'error'],
];
[$statusLabel, $statusClass, $statusIcon] = $statusMap[$status] ?? $statusMap['pending'];
?>
<section class="max-w-2xl mx-auto px-4 py-10">
  <div class="bg-white rounded-xl shadow border border-gray-100 p-6">
    <div class="flex items-center justify-between mb-6">
      <div>
        <p class="text-sm text-gray-500">Claim reference</p>
        <h1 class="text-2xl font-bold"><?= htmlspecialchars($booking['invoice_id']) ?></h1>
      </div>
      <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-semibold <?= $statusClass ?>">
        <span class="material-symbols-outlined text-base"><?= $statusIcon ?></span>
        <?= htmlspecialchars($statusLabel) ?>
      </span>
    </div>

    <?php if ($status === 'pending_credentials'): ?>
      <div class="mb-6 rounded-lg bg-amber-50 text-amber-800 px-4 py-3 text-sm">
        Your claim is saved. It will be forwarded to AirHelp as soon as the service is fully activated for this site.
      </div>
    <?php elseif ($status === 'confirmed'): ?>
      <div class="mb-6 rounded-lg bg-green-50 text-green-800 px-4 py-3 text-sm">
        Your claim has been registered with AirHelp. If your flight qualifies, AirHelp will pursue the airline and contact you at
        <strong><?= htmlspecialchars($passenger['email'] ?? $booking['email'] ?? '') ?></strong>.
      </div>
    <?php endif; ?>

    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2">Flight</h2>
    <div class="grid grid-cols-2 gap-3 text-sm mb-6">
      <div><span class="text-gray-500">Flight:</span> <?= htmlspecialchars(($flight['airline_code'] ?? '') . ' ' . ($flight['flight_number'] ?? '')) ?></div>
      <div><span class="text-gray-500">Route:</span> <?= htmlspecialchars(($flight['departure_airport'] ?? '') . ' → ' . ($flight['arrival_airport'] ?? '')) ?></div>
      <div><span class="text-gray-500">Departure:</span> <?= htmlspecialchars($flight['departure_datetime'] ?? '') ?></div>
      <div><span class="text-gray-500">PNR:</span> <?= htmlspecialchars($flight['pnr'] ?? '—') ?></div>
    </div>

    <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-2">Passenger</h2>
    <div class="grid grid-cols-2 gap-3 text-sm">
      <div><span class="text-gray-500">Name:</span> <?= htmlspecialchars(($passenger['first_name'] ?? $booking['first_name'] ?? '') . ' ' . ($passenger['last_name'] ?? $booking['last_name'] ?? '')) ?></div>
      <div><span class="text-gray-500">Email:</span> <?= htmlspecialchars($passenger['email'] ?? $booking['email'] ?? '') ?></div>
    </div>

    <div class="mt-8">
      <a href="<?= root ?>insurance" class="text-green-700 hover:underline text-sm">← Submit another claim</a>
    </div>
  </div>
</section>
