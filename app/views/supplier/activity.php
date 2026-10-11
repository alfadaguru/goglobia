<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $events = (isset($events) && is_array($events)) ? $events : [];
  // Friendly labels for common event keys; falls back to the raw key.
  $label = function (string $ev): string {
    $map = [
      'booking.confirmed' => 'Booking confirmed', 'payout.paid' => 'Payout sent',
      'night_audit.closed' => 'Night audit closed', 'group.completed' => 'Group completed',
      'event.completed' => 'Event completed', 'grn.received' => 'Goods received',
    ];
    return $map[$ev] ?? ucfirst(str_replace(['.', '_'], ' ', $ev));
  };
  $icon = function (string $ev): string {
    if (strpos($ev, 'payout') === 0) return 'payments';
    if (strpos($ev, 'booking') === 0) return 'event_available';
    if (strpos($ev, 'night_audit') === 0) return 'nightlight';
    if (strpos($ev, 'group') === 0 || strpos($ev, 'event') === 0) return 'groups';
    return 'notifications';
  };
?>
<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Activity</h1>
    <p class="text-sm text-gray-600">Recent events across your properties — bookings, payouts, night audits, and more.</p>
  </div>

  <div class="card divide-y divide-gray-50">
    <?php if (empty($events)): ?>
      <div class="p-8 text-center text-gray-400 text-sm">No activity yet.</div>
    <?php else: foreach ($events as $e): $ev = (string) ($e['event'] ?? ''); ?>
      <div class="flex items-start gap-3 p-4">
        <span class="material-symbols-outlined text-violet-500 text-base mt-0.5"><?= htmlspecialchars($icon($ev)) ?></span>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($label($ev)) ?></div>
          <div class="text-xs text-gray-500">
            <?= htmlspecialchars((string) ($e['subject_type'] ?? '')) ?><?= !empty($e['subject_id']) ? ' #' . htmlspecialchars((string) $e['subject_id']) : '' ?>
          </div>
        </div>
        <div class="text-xs text-gray-400 whitespace-nowrap"><?= htmlspecialchars((string) ($e['created_at'] ?? '')) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
