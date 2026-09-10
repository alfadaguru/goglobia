<?php
// UMRAH v2 — booking confirmation / trip view (docs §8/§23 minimal dashboard).
// Expects: $ub (umrah_bookings row + decoded snapshot), $installments.
@$SECURE or die('Access Denied!');
$wa = preg_replace('/[^0-9]/', '', (string) ($GLOBALS['app']['contact_phone'] ?? ''));
$waLink = $wa ? ('https://wa.me/' . $wa) : '#';
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$snap = $ub['snapshot'] ?? [];
$dep = $snap['departure'] ?? [];
$confirmed = ($ub['booking_status'] === 'confirmed');
$nextDue = null;
foreach ($installments as $i) { if (in_array($i['status'], ['pending','overdue'], true)) { $nextDue = $i; break; } }
?>
<div class="bg-white">
  <div class="container py-8 max-w-3xl">

    <div class="card border">
      <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-3xl <?= $confirmed ? 'text-green-600' : 'text-amber-500' ?>">
          <?= $confirmed ? 'check_circle' : 'schedule' ?>
        </span>
        <div>
          <h1 class="text-xl font-bold text-gray-900"><?= $confirmed ? 'Booking Confirmed' : 'Booking Created' ?></h1>
          <p class="text-sm text-gray-600">Reference: <span class="font-mono font-semibold"><?= htmlspecialchars($ub['booking_ref']) ?></span></p>
        </div>
      </div>

      <?php if ($confirmed): ?>
        <div class="alert-success mt-4"><span class="material-icon material-symbols-outlined">lock</span>
          <p>Your qualifying payment has <strong>locked the package price</strong> for this booking.</p></div>
      <?php else: ?>
        <div class="alert-warning mt-4"><span class="material-icon material-symbols-outlined">payments</span>
          <p>Your seat is held. Complete the payment below to confirm and lock your price.</p></div>
      <?php endif; ?>

      <div class="grid grid-cols-2 gap-4 mt-5 text-sm">
        <div><div class="text-gray-500">Departure</div><div class="font-semibold"><?= htmlspecialchars(($dep['departure_date'] ?? '') . ' → ' . ($dep['return_date'] ?? '')) ?></div></div>
        <div><div class="text-gray-500">Package</div><div class="font-semibold"><?= htmlspecialchars($snap['tier_code'] ?? 'standard') ?></div></div>
        <div><div class="text-gray-500">Travellers</div><div class="font-semibold"><?= (int) $ub['pax'] ?></div></div>
        <div><div class="text-gray-500">Total</div><div class="font-semibold"><?= $fmt($ub['total_price']) ?></div></div>
        <div><div class="text-gray-500">Paid</div><div class="font-semibold"><?= $fmt($ub['amount_paid']) ?></div></div>
        <div><div class="text-gray-500">Balance</div><div class="font-semibold"><?= $fmt($ub['balance']) ?></div></div>
      </div>
    </div>

    <!-- Payment schedule -->
    <div class="section mt-6">
      <div class="section-header"><h2>Payment schedule</h2></div>
      <div class="table-container">
        <table class="table">
          <thead><tr><th>#</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($installments as $i): ?>
              <tr>
                <td><?= (int) $i['seq'] ?></td>
                <td><?= $fmt($i['amount']) ?> <span class="text-xs text-gray-400">(<?= rtrim(rtrim((string)$i['percent'],'0'),'.') ?>%)</span></td>
                <td class="text-sm text-gray-600"><?= $i['due_at'] ? htmlspecialchars(date('d M Y', strtotime($i['due_at']))) : 'At booking' ?></td>
                <td><span class="badge <?= $i['status']==='paid' ? 'badge-success' : ($i['status']==='overdue' ? 'badge-error' : 'badge-gray') ?>"><?= htmlspecialchars($i['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($nextDue): ?>
        <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
          <div class="text-sm text-gray-700">Next payment: <strong><?= $fmt($nextDue['amount']) ?></strong></div>
          <a href="<?= root ?>invoice/umrah/<?= htmlspecialchars($ub['invoice_id']) ?>" class="btn">Pay now</a>
        </div>
      <?php endif; ?>
    </div>

    <!-- Next steps -->
    <div class="section">
      <div class="section-header"><h2>Next steps</h2></div>
      <p class="text-sm text-gray-700">After your qualifying payment clears, you'll be able to complete each pilgrim's details and upload passports from your dashboard. We'll notify you by email/WhatsApp.</p>
      <div class="mt-4 flex flex-wrap gap-3">
        <a href="<?= root ?>bookings" class="btn outline">My bookings</a>
        <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener" class="btn outline">Chat on WhatsApp</a>
      </div>
    </div>

  </div>
</div>
