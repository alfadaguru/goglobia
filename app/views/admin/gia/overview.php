<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Admin GIA reports overview. Expects (from giaRoutes): $stats, $funnel,
// $topModules, $leads, $conversations. All escaped at output.
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$convRate = ($funnel['chats'] ?? 0) > 0
    ? round(100 * ($funnel['booked'] ?? 0) / $funnel['chats'], 1)
    : 0.0;
?>
<div class="container mx-auto px-4 py-4">

  <div class="mb-4 flex items-center gap-2">
    <i class="material-symbols-outlined text-blue-600">smart_toy</i>
    <div>
      <h1 class="text-xl font-bold text-gray-900">GIA Assistant</h1>
      <p class="text-sm text-gray-600">What your AI travel assistant is doing — conversations, leads, conversions &amp; cost.</p>
    </div>
  </div>

  <!-- Headline stat cards -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Conversations</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($stats['conversations'] ?? 0)); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Leads captured</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($stats['leads'] ?? 0)); ?></p>
      <p class="text-[11px] text-gray-400"><?php echo number_format((int)($stats['leads_linked'] ?? 0)); ?> linked to users</p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Bookings via GIA</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($stats['bookings'] ?? 0)); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Revenue attributed</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((float)($stats['revenue'] ?? 0), 2); ?></p>
    </div>
    <div class="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
      <p class="text-xs text-gray-500">Tokens (in / out)</p>
      <p class="text-lg font-semibold text-gray-900"><?php echo number_format((int)($stats['token_in'] ?? 0)); ?> / <?php echo number_format((int)($stats['token_out'] ?? 0)); ?></p>
    </div>
  </div>

  <!-- Conversion funnel -->
  <div class="card p-0 mb-4">
    <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">filter_alt</span> Conversion funnel</div></div>
    <div class="card-body">
      <?php
        $steps = [
          ['Chats', (int)($funnel['chats'] ?? 0), 'chat'],
          ['Searched', (int)($funnel['searched'] ?? 0), 'search'],
          ['Reached checkout', (int)($funnel['checkout'] ?? 0), 'shopping_cart'],
          ['Booked', (int)($funnel['booked'] ?? 0), 'check_circle'],
        ];
        $max = max(1, (int)($funnel['chats'] ?? 1));
      ?>
      <div class="space-y-2">
        <?php foreach ($steps as $s): $pct = round(100 * $s[1] / $max); ?>
          <div class="flex items-center gap-3">
            <div class="w-40 flex items-center gap-1.5 text-sm text-gray-700">
              <i class="material-symbols-outlined text-[18px] text-gray-400"><?php echo $e($s[2]); ?></i>
              <?php echo $e($s[0]); ?>
            </div>
            <div class="flex-1 bg-gray-100 rounded-full h-5 overflow-hidden">
              <div class="h-5 bg-blue-600 rounded-full" style="width: <?php echo $pct; ?>%"></div>
            </div>
            <div class="w-16 text-right text-sm font-semibold text-gray-900"><?php echo number_format($s[1]); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="text-xs text-gray-500 mt-3">Chat → booking conversion: <span class="font-semibold text-gray-800"><?php echo $convRate; ?>%</span></p>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <!-- Top searched modules -->
    <div class="card p-0">
      <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">trending_up</span> Top searched services</div></div>
      <div class="card-body">
        <?php if (empty($topModules)): ?>
          <p class="text-sm text-gray-400">No searches yet.</p>
        <?php else: $topMax = max(1, (int)($topModules[0]['n'] ?? 1)); ?>
          <div class="space-y-2">
            <?php foreach ($topModules as $tm): $w = round(100 * (int)$tm['n'] / $topMax); ?>
              <div class="flex items-center gap-3">
                <div class="w-24 text-sm text-gray-700 capitalize"><?php echo $e($tm['module']); ?></div>
                <div class="flex-1 bg-gray-100 rounded-full h-4 overflow-hidden">
                  <div class="h-4 bg-emerald-500 rounded-full" style="width: <?php echo $w; ?>%"></div>
                </div>
                <div class="w-12 text-right text-sm font-semibold text-gray-900"><?php echo number_format((int)$tm['n']); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent leads -->
    <div class="card p-0">
      <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">contact_mail</span> Recent leads</div></div>
      <div class="card-body overflow-x-auto">
        <?php if (empty($leads)): ?>
          <p class="text-sm text-gray-400">No leads captured yet.</p>
        <?php else: ?>
          <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b">
              <th class="py-1.5 pr-2">Name</th><th class="py-1.5 pr-2">Contact</th><th class="py-1.5 pr-2">Status</th><th class="py-1.5">When</th>
            </tr></thead>
            <tbody>
            <?php foreach ($leads as $ld): ?>
              <tr class="border-b last:border-0">
                <td class="py-1.5 pr-2 text-gray-800"><?php echo $e($ld['name'] ?: '—'); ?></td>
                <td class="py-1.5 pr-2 text-gray-600">
                  <?php echo $e($ld['email'] ?: ''); ?><?php echo ($ld['email'] && $ld['phone']) ? '<br>' : ''; ?><?php echo $e($ld['phone'] ?: ''); ?>
                </td>
                <td class="py-1.5 pr-2">
                  <?php
                    $st = (string)($ld['status'] ?? 'lead');
                    $cls = $st === 'converted' ? 'bg-green-100 text-green-700' : ($st === 'linked' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600');
                  ?>
                  <span class="text-[11px] px-2 py-0.5 rounded-full <?php echo $cls; ?>"><?php echo $e(ucfirst($st)); ?></span>
                </td>
                <td class="py-1.5 text-gray-500 text-xs"><?php echo $e($ld['created_at'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent conversations -->
  <div class="card p-0">
    <div class="card-header"><div><span class="card-header-icon material-symbols-outlined">forum</span> Recent conversations</div></div>
    <div class="card-body overflow-x-auto">
      <?php if (empty($conversations)): ?>
        <p class="text-sm text-gray-400">No conversations yet.</p>
      <?php else: ?>
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b">
            <th class="py-1.5 pr-2">#</th><th class="py-1.5 pr-2">Context</th><th class="py-1.5 pr-2">Last message</th>
            <th class="py-1.5 pr-2 text-right">Msgs</th><th class="py-1.5 pr-2 text-right">Searches</th>
            <th class="py-1.5 pr-2 text-right">Bookings</th><th class="py-1.5 pr-2 text-right">Revenue</th>
            <th class="py-1.5">Last active</th><th class="py-1.5"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($conversations as $c): ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
              <td class="py-1.5 pr-2 text-gray-500">#<?php echo (int)$c['id']; ?></td>
              <td class="py-1.5 pr-2 text-gray-700 capitalize"><?php echo $e($c['context'] ?: '—'); ?><br><span class="text-[10px] text-gray-400"><?php echo $e($c['surface'] ?? ''); ?></span></td>
              <td class="py-1.5 pr-2 text-gray-600 max-w-xs truncate"><?php echo $e(mb_strimwidth((string)($c['last_message'] ?? ''), 0, 70, '…')); ?></td>
              <td class="py-1.5 pr-2 text-right"><?php echo number_format((int)($c['msg_count'] ?? 0)); ?></td>
              <td class="py-1.5 pr-2 text-right"><?php echo number_format((int)($c['search_count'] ?? 0)); ?></td>
              <td class="py-1.5 pr-2 text-right"><?php echo number_format((int)($c['booking_count'] ?? 0)); ?></td>
              <td class="py-1.5 pr-2 text-right"><?php echo (float)($c['revenue'] ?? 0) > 0 ? $e($c['revenue_currency'] ?: '') . ' ' . number_format((float)$c['revenue'], 2) : '—'; ?></td>
              <td class="py-1.5 text-gray-500 text-xs"><?php echo $e($c['last_at'] ?: $c['started_at'] ?? ''); ?></td>
              <td class="py-1.5"><a class="text-blue-600 hover:underline text-xs" href="<?php echo root.admin; ?>/gia/conversation/<?php echo (int)$c['id']; ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

</div>
