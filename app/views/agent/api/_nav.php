<?php
// Shared sub-nav for the API console pages (/agent/api/*). Expects $apiActive
// = the current section key. Rendered inside the agent portal <main>.
@$SECURE or die('Access Denied!');
$apiActive = $apiActive ?? 'overview';
$tabs = [
    'overview' => ['Overview', 'dashboard',   'agent/api'],
    'services' => ['Services', 'apps',         'agent/api/services'],
    'keys'     => ['Keys',     'key',          'agent/api/keys'],
    'webhooks' => ['Webhooks', 'webhook',      'agent/api/webhooks'],
    'logs'     => ['Logs',     'receipt_long', 'agent/api/logs'],
    'docs'     => ['Docs',     'menu_book',    'agent/api/docs'],
];
?>
<div class="flex items-center gap-1 border-b border-slate-200 mb-6 overflow-x-auto">
  <?php foreach ($tabs as $k => [$label, $icon, $url]):
    $on = ($k === $apiActive);
  ?>
    <a href="<?= root . $url ?>" class="inline-flex items-center gap-1.5 px-3 py-2.5 text-sm whitespace-nowrap border-b-2 <?= $on ? 'border-violet-600 text-violet-700 font-semibold' : 'border-transparent text-slate-500 hover:text-slate-700' ?>">
      <span class="material-symbols-outlined text-[18px]"><?= htmlspecialchars($icon) ?></span>
      <span><?= htmlspecialchars($label) ?></span>
    </a>
  <?php endforeach; ?>
</div>
