<?php
// app/views/admin/employees/roles-manage.php — create/edit a role (users_roles).
// A checkbox grid of the full area x action catalogue (single source of truth =
// admin_area_catalogue() in app/lib/admin_rbac.php). A granted action posts as
// permissions[<area>][<action>]; the save route stores it as '' (presence =
// granted). The Admin role is all-access and its grid is locked.
// Gated by the route via admin_can('users_roles','edit'); POST -> /employees/roles/save.
@$SECURE or die('Access Denie'.'d!');

$rid = (int) ($_GET['id'] ?? 0);
$r = $rid > 0 ? $db->get('users_roles', '*', ['id' => $rid]) : null;
if ($rid > 0 && !$r) {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Role not found.'];
    redirect(root . admin . '/employees/roles');
    return;
}

$isAdmin  = $r && ((strtolower((string) ($r['base_type'] ?? '')) === 'admin')
    || (strtolower((string) ($r['type_name'] ?? '')) === 'admin')); // always-all, locked grid
$isSystem = $r && ((strtolower((string) ($r['kind'] ?? '')) === 'system') || ((int) $rid <= 5));

// Current grants for the checkbox state: $current[$area][$action] = ''.
$current = [];
if ($r && !empty($r['permissions'])) {
    $decoded = json_decode((string) $r['permissions'], true);
    if (is_array($decoded)) { $current = $decoded; }
}

$catalogue = function_exists('admin_area_catalogue') ? admin_area_catalogue() : [];

// Human labels for areas + actions. Any area/action not listed falls back to a
// title-cased version of the slug, so the grid always covers the full catalogue.
$areaLabels = [
    'dashboard' => 'Dashboard', 'users' => 'Users', 'users_roles' => 'Roles',
    'employees' => 'Employees', 'bookings' => 'Bookings', 'transactions' => 'Transactions',
    'settings' => 'Settings', 'modules' => 'Modules', 'cms' => 'CMS pages',
    'logs' => 'System logs', 'languages' => 'Languages', 'currencies' => 'Currencies',
    'payment_gateways' => 'Payment gateways', 'notification_templates' => 'Notification templates',
    'reports' => 'Reports', 'suppliers' => 'Suppliers',
    'support_dashboard' => 'Support dashboard', 'support_desk' => 'Live chat desk',
    'support_tickets' => 'Support tickets', 'support_attendance' => 'Attendance',
    'support_shifts' => 'Shifts', 'support_campaigns' => 'Ad campaigns',
    'support_targets' => 'Targets', 'referrals' => 'Referral program',
    'discounts' => 'Discounts', 'promo_codes' => 'Promo codes', 'blogs' => 'Blog',
    'menus' => 'Menus', 'seo' => 'SEO', 'money' => 'Loyalty & wallet',
    'gia' => 'GIA assistant', 'database' => 'Database tools', 'agent_api' => 'Agent API',
    'supplier_payouts' => 'Supplier payouts', 'transactions' => 'Transactions',
    'modules' => 'Modules', 'campaigns' => 'Email campaigns',
    'notification_templates' => 'Notification templates',
];
$actionLabels = [
    'page_access' => 'Open', 'view' => 'View', 'add' => 'Add', 'edit' => 'Edit',
    'delete' => 'Delete', 'view_all' => 'View all', 'refund' => 'Refund',
    'cancel' => 'Cancel', 'void' => 'Void',
];
$label = function (string $slug, array $map): string {
    return $map[$slug] ?? ucwords(str_replace('_', ' ', $slug));
};

$v = function ($k, $d = '') use ($r) { return htmlspecialchars((string) ($r[$k] ?? $d), ENT_QUOTES); };
?>

<div class="container my-4" style="max-width:960px">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/employees/roles" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900"><?= $rid > 0 ? 'Edit role' : 'New role' ?></h1>
    <?php if ($isSystem): ?>
      <span class="ml-2 text-xs font-bold uppercase tracking-wide px-2 py-0.5 rounded-full" style="background:#ede9fe;color:#5b21b6">System</span>
    <?php endif; ?>
  </div>

  <form method="POST" action="<?= root.admin ?>/employees/roles/save">
    <?= CSRF::tokenField() ?>
    <input type="hidden" name="id" value="<?= $rid ?>">

    <div class="rounded-xl border border-gray-100 p-4 bg-gray-50 mb-5">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Role name</label>
          <input type="text" name="name" value="<?= $v('type_name') ?>" required <?= $isAdmin ? 'readonly' : '' ?>
                 class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Slug <span class="text-gray-400 font-normal">(auto)</span></label>
          <input type="text" value="<?= $rid > 0 ? $v('slug') : '' ?>" readonly disabled
                 placeholder="<?= $rid > 0 ? '' : 'derived from the name on save' ?>"
                 class="w-full rounded-lg border border-gray-200 bg-gray-100 px-3 py-2.5 text-sm text-gray-500">
          <?php if ($isSystem): ?>
            <p class="text-xs text-gray-500 mt-1">System role — slug is locked.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="rounded-xl border border-gray-100 p-4">
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-bold text-gray-900">Permissions</h3>
        <?php if (!$isAdmin): ?>
          <div class="flex items-center gap-3 text-xs">
            <button type="button" class="text-blue-600 hover:underline" onclick="document.querySelectorAll('.cap-cb').forEach(c=>c.checked=true)">Select all</button>
            <button type="button" class="text-gray-500 hover:underline" onclick="document.querySelectorAll('.cap-cb').forEach(c=>c.checked=false)">Clear all</button>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($isAdmin): ?>
        <div class="rounded-lg p-3 text-sm" style="background:#ecfdf5;color:#065f46">
          The <strong>Admin</strong> role always has <strong>full access</strong> to every area and cannot be narrowed.
        </div>
      <?php else: ?>
        <p class="text-xs text-gray-500 mb-3">Tick the actions this role may perform in each area. Use the <strong>area toggle</strong> on the left to grant or clear a whole area at once.</p>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <tbody>
              <?php foreach ($catalogue as $area => $actions): $aid = 'cap_' . preg_replace('/[^a-z0-9_]/i', '', $area); ?>
                <tr class="cap-row border-b border-gray-100 align-top">
                  <td class="py-3 pr-4 align-top font-medium text-gray-800 whitespace-nowrap">
                    <label class="inline-flex items-start gap-2 cursor-pointer">
                      <input type="checkbox" class="cap-area-all mt-1" data-area="<?= htmlspecialchars($aid, ENT_QUOTES) ?>"
                             onchange="document.querySelectorAll('.<?= htmlspecialchars($aid, ENT_QUOTES) ?>').forEach(c=>c.checked=this.checked)">
                      <span>
                        <?= htmlspecialchars($label($area, $areaLabels)) ?>
                        <span class="block text-[11px] text-gray-400 font-normal"><code><?= htmlspecialchars($area) ?></code></span>
                      </span>
                    </label>
                  </td>
                  <td class="py-3">
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                      <?php foreach ($actions as $action): ?>
                        <?php $checked = isset($current[$area][$action]); ?>
                        <label class="inline-flex items-center gap-1.5 cursor-pointer px-2 py-1 rounded-md hover:bg-blue-50 transition">
                          <input type="checkbox" class="cap-cb <?= htmlspecialchars($aid, ENT_QUOTES) ?>" name="permissions[<?= htmlspecialchars($area, ENT_QUOTES) ?>][<?= htmlspecialchars($action, ENT_QUOTES) ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                          <span class="text-gray-700"><?= htmlspecialchars($label($action, $actionLabels)) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!$isAdmin): ?>
    <div class="mt-5 flex items-center gap-3">
      <button type="submit" class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-black transition">
        <?= $rid > 0 ? 'Save changes' : 'Create role' ?>
      </button>
      <a href="<?= root.admin ?>/employees/roles" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
    </div>
    <?php else: ?>
    <div class="mt-5">
      <a href="<?= root.admin ?>/employees/roles" class="text-sm text-gray-500 hover:text-gray-700">← Back to roles</a>
    </div>
    <?php endif; ?>
  </form>

  <?php if (!$isAdmin): ?>
  <!-- Zebra striping via raw CSS (the odd:bg-* Tailwind variant isn't in the build). -->
  <style> .cap-row:nth-child(even) { background: #f9fafb; } </style>
  <script>
  (function () {
    // Keep each area's "select whole area" checkbox in sync: it shows checked only
    // when ALL of that area's action checkboxes are ticked (indeterminate otherwise).
    function syncArea(aid) {
      var head = document.querySelector('.cap-area-all[data-area="' + aid + '"]');
      if (!head) return;
      var boxes = document.querySelectorAll('.' + aid);
      if (!boxes.length) return;
      var on = 0;
      boxes.forEach(function (b) { if (b.checked) on++; });
      head.checked = (on === boxes.length);
      head.indeterminate = (on > 0 && on < boxes.length);
    }
    document.querySelectorAll('.cap-area-all').forEach(function (head) {
      var aid = head.getAttribute('data-area');
      syncArea(aid); // initial state (edit mode)
      document.querySelectorAll('.' + aid).forEach(function (b) {
        b.addEventListener('change', function () { syncArea(aid); });
      });
    });
    // Also re-sync every area after the global Select all / Clear all buttons run.
    document.querySelectorAll('button').forEach(function (btn) {
      var t = (btn.textContent || '').trim().toLowerCase();
      if (t === 'select all' || t === 'clear all') {
        btn.addEventListener('click', function () {
          document.querySelectorAll('.cap-area-all').forEach(function (h) { syncArea(h.getAttribute('data-area')); });
        });
      }
    });
  })();
  </script>
  <?php endif; ?>
</div>
