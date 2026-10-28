<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Stored-guest detail (no-email guest). Provided by the route: $guest (stays_guests row),
// $bookings (their bookings by guest_id), $linkedCustomer (the payer contact, or null).
$guest = (isset($guest) && is_array($guest)) ? $guest : [];
$bookings = (isset($bookings) && is_array($bookings)) ? $bookings : [];
$linkedCustomer = (isset($linkedCustomer) && is_array($linkedCustomer)) ? $linkedCustomer : null;
$base = root . 'supplier/guests';
?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">
  <nav class="text-xs text-gray-500"><a href="<?= $base ?>" class="hover:underline">Guests</a> <span class="mx-1">/</span> <span class="text-gray-700"><?= htmlspecialchars((string) ($guest['display_name'] ?? '')) ?></span></nav>

  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div>
      <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) ($guest['display_name'] ?? 'Guest')) ?></h1>
      <p class="text-sm text-gray-600">Guest (stayer) record — this guest has no email on file.</p>
    </div>
    <?php if ($linkedCustomer): ?>
      <a href="<?= root ?>supplier/customers/<?= (int) $linkedCustomer['id'] ?>" class="btn secondary text-sm inline-flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base">account_circle</span> Payer: <?= htmlspecialchars((string) $linkedCustomer['display_name']) ?>
      </a>
    <?php endif; ?>
  </div>

  <div class="card p-5 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
    <div><div class="text-xs text-gray-500">Phone</div><div class="text-gray-900 tabular-nums"><?= htmlspecialchars((string) ($guest['phone'] ?? '')) ?: '—' ?></div></div>
    <div><div class="text-xs text-gray-500">Email</div><div class="text-gray-900"><?= htmlspecialchars((string) ($guest['email'] ?? '')) ?: '—' ?></div></div>
    <div><div class="text-xs text-gray-500">ID document</div><div class="text-gray-900"><?= htmlspecialchars((string) ($guest['id_doc'] ?? '')) ?: '—' ?></div></div>
    <div><div class="text-xs text-gray-500">Nationality</div><div class="text-gray-900"><?= htmlspecialchars((string) ($guest['nationality'] ?? '')) ?: '—' ?></div></div>
    <div><div class="text-xs text-gray-500">First seen</div><div class="text-gray-900"><?= htmlspecialchars(substr((string) ($guest['created_at'] ?? ''), 0, 10)) ?: '—' ?></div></div>
  </div>

  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-900">Stays (<?= count($bookings) ?>)</h2></div>
    <?php if (empty($bookings)): ?>
      <p class="px-4 py-6 text-sm text-gray-400">No bookings recorded for this guest yet.</p>
    <?php else: ?>
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100">
          <th class="px-4 py-2 font-medium">Invoice</th><th class="px-4 py-2 font-medium">Dates</th>
          <th class="px-4 py-2 font-medium">Status</th><th class="px-4 py-2 font-medium">Total</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($bookings as $b): ?>
            <?php $bd = json_decode((string) ($b['booking_data'] ?? ''), true); $bd = is_array($bd) ? $bd : []; ?>
            <tr>
              <td class="px-4 py-2 font-mono text-xs"><a href="<?= root ?>supplier/reservations/<?= rawurlencode((string) $b['invoice_id']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars((string) $b['invoice_id']) ?></a></td>
              <td class="px-4 py-2 text-gray-600 text-xs whitespace-nowrap"><?= htmlspecialchars((string) ($bd['checkin'] ?? '')) ?><?= !empty($bd['checkout']) ? ' → ' . htmlspecialchars((string) $bd['checkout']) : '' ?></td>
              <td class="px-4 py-2"><span class="text-xs"><?= htmlspecialchars(ucfirst((string) ($b['booking_status'] ?? ''))) ?></span></td>
              <td class="px-4 py-2 tabular-nums"><?= htmlspecialchars((string) ($b['currency_markup'] ?? '')) ?> <?= number_format((float) ($b['price_markup'] ?? 0), 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
