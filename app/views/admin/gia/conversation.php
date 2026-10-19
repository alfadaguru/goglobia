<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin GIA conversation drill-down. Expects: $conv, $messages, $events, $lead.
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="container mx-auto px-4 py-4">

  <div class="mb-4 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <i class="material-symbols-outlined text-blue-600">forum</i>
      <div>
        <h1 class="text-xl font-bold text-gray-900">Conversation #<?php echo (int)$conv['id']; ?></h1>
        <p class="text-sm text-gray-600">
          <?php echo $e(ucfirst((string)($conv['context'] ?: 'general'))); ?> ·
          <?php echo $e($conv['surface'] ?? ''); ?> ·
          started <?php echo $e($conv['started_at'] ?? ''); ?>
        </p>
      </div>
    </div>
    <a href="<?php echo root.admin; ?>/gia" class="text-sm text-blue-600 hover:underline">&larr; Back to GIA reports</a>
  </div>

  <!-- Who + rollups -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Messages</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($conv['msg_count'] ?? 0)); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Searches</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($conv['search_count'] ?? 0)); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Bookings</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($conv['booking_count'] ?? 0)); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Revenue</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo (float)($conv['revenue'] ?? 0) > 0 ? $e($conv['revenue_currency'] ?: '') . ' ' . number_format((float)$conv['revenue'], 2) : '—'; ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Tokens (in/out)</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($conv['token_in'] ?? 0)); ?> / <?php echo number_format((int)($conv['token_out'] ?? 0)); ?></p>
    </div>
  </div>

  <?php if ($lead): ?>
    <div class="card p-0 mb-4">
      <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">contact_mail</span> Lead</div></div>
      <div class="card-body text-sm text-gray-700">
        <span class="font-semibold"><?php echo $e($lead['name'] ?: '—'); ?></span>
        &nbsp;·&nbsp; <?php echo $e($lead['email'] ?: ''); ?>
        <?php if (!empty($lead['phone'])): ?>&nbsp;·&nbsp; <?php echo $e($lead['phone']); ?><?php endif; ?>
        &nbsp;·&nbsp; <span class="text-[11px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-700"><?php echo $e(ucfirst((string)$lead['status'])); ?></span>
        <?php if (!empty($lead['user_id'])): ?>&nbsp;·&nbsp; <span class="text-xs text-gray-500">user: <?php echo $e($lead['user_id']); ?></span><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Transcript -->
    <div class="card p-0 lg:col-span-2">
      <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">chat</span> Transcript</div></div>
      <div class="card-body space-y-2" style="max-height:70vh; overflow-y:auto">
        <?php if (empty($messages)): ?>
          <p class="text-sm text-gray-400">No messages recorded.</p>
        <?php else: foreach ($messages as $m): $isUser = ($m['role'] ?? '') === 'user'; ?>
          <div class="flex <?php echo $isUser ? 'justify-end' : 'justify-start'; ?>">
            <div class="max-w-[80%] px-3 py-2 rounded-2xl text-sm <?php echo $isUser ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-800'; ?>">
              <div class="whitespace-pre-wrap"><?php echo nl2br($e($m['text'] ?? '')); ?></div>
              <div class="text-[10px] mt-1 <?php echo $isUser ? 'text-blue-100' : 'text-gray-400'; ?>"><?php echo $e($m['created_at'] ?? ''); ?></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Events timeline -->
    <div class="card p-0">
      <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">timeline</span> Events</div></div>
      <div class="card-body" style="max-height:70vh; overflow-y:auto">
        <?php if (empty($events)): ?>
          <p class="text-sm text-gray-400">No events.</p>
        <?php else: foreach ($events as $ev): ?>
          <div class="flex items-start gap-2 py-1.5 border-b last:border-0">
            <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-700"><?php echo $e($ev['type'] ?? ''); ?></span>
            <div class="text-xs text-gray-600 flex-1">
              <?php if (!empty($ev['module'])): ?><span class="capitalize font-medium"><?php echo $e($ev['module']); ?></span> <?php endif; ?>
              <?php if (($ev['amount'] ?? null) !== null && (float)$ev['amount'] > 0): ?>
                <span class="text-gray-800"><?php echo $e($ev['currency'] ?: ''); ?> <?php echo number_format((float)$ev['amount'], 2); ?></span>
              <?php endif; ?>
              <?php if (!empty($ev['ref'])): ?><div class="text-[10px] text-gray-400 truncate">ref: <?php echo $e($ev['ref']); ?></div><?php endif; ?>
              <div class="text-[10px] text-gray-400"><?php echo $e($ev['created_at'] ?? ''); ?></div>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

</div>
