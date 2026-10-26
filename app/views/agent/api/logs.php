<?php
@$SECURE or die('Access Denied!');
?>
<div class="px-4 py-6 max-w-6xl mx-auto">
  <h1 class="text-2xl font-semibold text-slate-900 mb-1">API Console</h1>
  <p class="text-sm text-slate-500 mb-5">Every API request made with your keys (most recent first). <?= (int)$usageCount ?> total.</p>

  <?php require views . 'agent/api/_nav.php'; ?>

  <div class="section">
    <div class="section-header"><h2>Request log</h2></div>
    <div class="table-container">
      <table class="table">
        <thead><tr><th>When</th><th>Endpoint</th><th>Key #</th><th>IP</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (empty($logs)): ?>
            <tr><td colspan="5" class="text-slate-400">No API requests recorded yet.</td></tr>
          <?php else: foreach ($logs as $l):
            $sc = (int)($l['status_code'] ?? 0);
            $cls = $sc >= 200 && $sc < 300 ? 'badge-success' : ($sc >= 400 ? 'badge-error' : 'badge-gray');
          ?>
            <tr>
              <td class="text-xs text-slate-500"><?= htmlspecialchars($l['created_at'] ?? '') ?></td>
              <td class="text-xs"><code><?= htmlspecialchars($l['endpoint'] ?? '') ?></code></td>
              <td class="text-xs text-slate-500"><?= !empty($l['key_id']) ? '#'.(int)$l['key_id'] : '—' ?></td>
              <td class="text-xs text-slate-500"><?= htmlspecialchars($l['ip'] ?? '') ?></td>
              <td><span class="badge badge-sm <?= $cls ?>"><?= $sc ?: '—' ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
