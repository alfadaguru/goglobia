<?php
// UMRAH v2 admin — bookings list (docs Step 8 / §47). Expects: $bookings.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
?>
<div class="container py-6">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Umrah Bookings</h1>
      <p class="text-sm text-slate-600 mt-1">Most recent 200 bookings.</p>
    </div>
    <a href="<?= root ?>admin/umrah-manager" class="btn outline"><span class="material-symbols-outlined">arrow_back</span><span>Manager</span></a>
  </div>

  <div class="section">
    <div class="table-container">
      <table class="table">
        <thead><tr><th>Ref</th><th>Invoice</th><th>Pax</th><th>Total</th><th>Paid</th><th>Balance</th><th>Booking</th><th>Payment</th><th>Locked</th><th>Created</th></tr></thead>
        <tbody>
          <?php if (empty($bookings)): ?>
            <tr><td colspan="10" class="text-slate-500">No umrah bookings yet.</td></tr>
          <?php else: foreach ($bookings as $b): ?>
            <tr>
              <td class="font-mono text-xs"><?= htmlspecialchars($b['booking_ref']) ?></td>
              <td class="font-mono text-xs"><?= htmlspecialchars((string)$b['invoice_id']) ?></td>
              <td><?= (int)$b['pax'] ?></td>
              <td><?= $fmt($b['total_price']) ?></td>
              <td><?= $fmt($b['amount_paid']) ?></td>
              <td><?= $fmt($b['balance']) ?></td>
              <td><span class="badge <?= $b['booking_status']==='confirmed'?'badge-success':($b['booking_status']==='cancelled'?'badge-error':'badge-gray') ?>"><?= htmlspecialchars($b['booking_status']) ?></span></td>
              <td><span class="badge badge-gray"><?= htmlspecialchars($b['payment_status']) ?></span></td>
              <td><?= $b['price_locked_at'] ? '<span class="material-symbols-outlined text-green-600 text-[18px]">lock</span>' : '—' ?></td>
              <td class="text-xs text-slate-500"><?= htmlspecialchars(date('d M Y', strtotime($b['created_at']))) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
