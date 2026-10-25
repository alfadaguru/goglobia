<?php
// app/views/admin/support/user-profile.php — support view of one user, cross-linking
// their bookings + tickets. Vars: $profile, $bookings, $tickets.
@$SECURE or die('Access Denie'.'d!');

$name = trim(((string) ($profile['first_name'] ?? '')) . ' ' . ((string) ($profile['last_name'] ?? '')));
if ($name === '') { $name = (string) $profile['user_id']; }
$phone = trim(((string) ($profile['phone_country_code'] ?? '')) . ' ' . ((string) ($profile['phone'] ?? '')));
?>

<div class="container my-4" style="max-width:960px">
  <div class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/support/users" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900"><?= htmlspecialchars($name) ?></h1>
    <span style="background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;text-transform:capitalize"><?= htmlspecialchars((string) ($profile['role'] ?? '')) ?></span>
  </div>

  <!-- Identity card -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="rounded-xl border border-gray-100 p-4 bg-white">
      <div class="text-xs text-gray-400 uppercase tracking-wide">Email</div>
      <div class="text-sm font-medium text-gray-900 mt-1 break-words"><?= htmlspecialchars((string) ($profile['email'] ?? '—')) ?></div>
    </div>
    <div class="rounded-xl border border-gray-100 p-4 bg-white">
      <div class="text-xs text-gray-400 uppercase tracking-wide">Phone</div>
      <div class="text-sm font-medium text-gray-900 mt-1"><?= htmlspecialchars(trim($phone) !== '' ? $phone : '—') ?></div>
    </div>
    <div class="rounded-xl border border-gray-100 p-4 bg-white">
      <div class="text-xs text-gray-400 uppercase tracking-wide">Country</div>
      <div class="text-sm font-medium text-gray-900 mt-1"><?= htmlspecialchars((string) ($profile['country'] ?? '—')) ?></div>
    </div>
    <div class="rounded-xl border border-gray-100 p-4 bg-white">
      <div class="text-xs text-gray-400 uppercase tracking-wide">Joined</div>
      <div class="text-sm font-medium text-gray-900 mt-1"><?= !empty($profile['created_at']) ? htmlspecialchars(date('M d, Y', strtotime((string) $profile['created_at']))) : '—' ?></div>
    </div>
  </div>

  <!-- Bookings -->
  <div class="mb-6">
    <h2 class="text-base font-bold text-gray-900 mb-3">Bookings <span class="text-sm font-normal text-gray-400">(<?= count($bookings) ?>)</span></h2>
    <?php if (empty($bookings)): ?>
      <div class="text-sm text-gray-400 border border-dashed border-gray-200 rounded-xl p-6 text-center bg-gray-50">No bookings for this user.</div>
    <?php else: ?>
      <div class="overflow-x-auto border border-gray-100 rounded-xl">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-2.5 font-semibold">Invoice</th><th class="px-4 py-2.5 font-semibold">Module</th>
            <th class="px-4 py-2.5 font-semibold">Status</th><th class="px-4 py-2.5 font-semibold">Payment</th>
            <th class="px-4 py-2.5 font-semibold text-right">Amount</th><th class="px-4 py-2.5 font-semibold">Date</th>
          </tr></thead>
          <tbody>
            <?php foreach ($bookings as $b): ?>
              <tr class="border-b border-gray-50 hover:bg-gray-50">
                <td class="px-4 py-2.5"><a href="<?= root.admin ?>/bookings/<?= urlencode((string) $b['invoice_id']) ?>" class="text-blue-600 hover:underline font-medium"><?= htmlspecialchars((string) $b['invoice_id']) ?></a></td>
                <td class="px-4 py-2.5 text-gray-700 capitalize"><?= htmlspecialchars((string) ($b['module'] ?? '')) ?></td>
                <td class="px-4 py-2.5 text-gray-700 capitalize"><?= htmlspecialchars((string) ($b['booking_status'] ?? '')) ?></td>
                <td class="px-4 py-2.5 text-gray-700 capitalize"><?= htmlspecialchars((string) ($b['payment_status'] ?? '')) ?></td>
                <td class="px-4 py-2.5 text-right text-gray-900"><?= htmlspecialchars((string) ($b['currency_markup'] ?? '')) ?> <?= htmlspecialchars((string) ($b['price_markup'] ?? '')) ?></td>
                <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap"><?= !empty($b['created_at']) ? htmlspecialchars(date('M d, Y', strtotime((string) $b['created_at']))) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Tickets -->
  <div class="mb-6">
    <h2 class="text-base font-bold text-gray-900 mb-3">Support tickets <span class="text-sm font-normal text-gray-400">(<?= count($tickets) ?>)</span></h2>
    <?php if (empty($tickets)): ?>
      <div class="text-sm text-gray-400 border border-dashed border-gray-200 rounded-xl p-6 text-center bg-gray-50">No tickets for this user.</div>
    <?php else: ?>
      <div class="overflow-x-auto border border-gray-100 rounded-xl">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
            <th class="px-4 py-2.5 font-semibold">Ticket</th><th class="px-4 py-2.5 font-semibold">Subject</th>
            <th class="px-4 py-2.5 font-semibold">Priority</th><th class="px-4 py-2.5 font-semibold">Status</th>
            <th class="px-4 py-2.5 font-semibold">Opened</th>
          </tr></thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr class="border-b border-gray-50 hover:bg-gray-50">
                <td class="px-4 py-2.5"><a href="<?= root.admin ?>/support/ticket/<?= urlencode((string) $t['ticket_id']) ?>" class="text-blue-600 hover:underline font-medium"><?= htmlspecialchars((string) $t['ticket_id']) ?></a></td>
                <td class="px-4 py-2.5 text-gray-700"><?= htmlspecialchars((string) ($t['subject'] ?? '')) ?></td>
                <td class="px-4 py-2.5 text-gray-700 capitalize"><?= htmlspecialchars((string) ($t['priority'] ?? '')) ?></td>
                <td class="px-4 py-2.5 text-gray-700 capitalize"><?= htmlspecialchars((string) ($t['status'] ?? '')) ?></td>
                <td class="px-4 py-2.5 text-gray-500 whitespace-nowrap"><?= !empty($t['date']) ? htmlspecialchars(date('M d, Y', strtotime((string) $t['date']))) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
