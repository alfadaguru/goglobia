<?php @$SECURE or die('Access Denied!'); ?>

<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div>
    <h1 class="text-2xl font-bold text-gray-900">Staff</h1>
    <p class="text-sm text-gray-600">Invite team members and assign them a role.</p>
  </div>

  <!-- Invite form -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-800 mb-3">Invite a team member</h2>
    <?php if (empty($roles)): ?>
      <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
        Create at least one <a href="<?= root ?>supplier/roles" class="underline font-medium">role</a> first, so you can assign it when inviting staff.
      </p>
    <?php else: ?>
      <form action="<?= root ?>supplier/staff/invite" method="POST" class="flex flex-col sm:flex-row gap-3 sm:items-end">
        <?= CSRF::tokenField() ?>
        <div class="form-control flex-1">
          <label class="block text-xs font-medium text-gray-600 mb-1">Email address</label>
          <input type="email" name="email" required class="input" placeholder="person@example.com">
        </div>
        <div class="form-control">
          <label class="block text-xs font-medium text-gray-600 mb-1">Role</label>
          <select name="role_id" class="select input">
            <option value="0">No role (no access until assigned)</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name'] ?? '') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn emerald">
          <span class="flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">send</span> Send invite</span>
        </button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Team list -->
  <?php if (empty($staff)): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">group</span>
      <p class="mt-2 text-sm">No team members yet.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-4 py-3 font-medium">Email</th>
            <th class="px-4 py-3 font-medium">Role</th>
            <th class="px-4 py-3 font-medium">Status</th>
            <th class="px-4 py-3 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($staff as $m): ?>
            <?php
              $st = $m['status'] ?? 'invited';
              $stColor = [
                'active'  => 'bg-green-50 text-green-700',
                'invited' => 'bg-blue-50 text-blue-700',
                'suspended' => 'bg-amber-50 text-amber-700',
                'revoked' => 'bg-red-50 text-red-700',
              ][$st] ?? 'bg-gray-100 text-gray-600';
            ?>
            <tr>
              <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($m['invited_email'] ?? '') ?></td>
              <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($roleNames[(int) ($m['role_id'] ?? 0)] ?? '—') ?></td>
              <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stColor ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
              <td class="px-4 py-3">
                <?php if (in_array($st, ['invited', 'active', 'suspended'], true)): ?>
                  <form action="<?= root ?>supplier/staff/revoke" method="POST" class="inline"
                        onsubmit="return confirm('Revoke this person\'s access?');">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <button type="submit" class="text-red-600 hover:underline text-xs font-medium">Revoke</button>
                  </form>
                <?php else: ?>
                  <span class="text-gray-400 text-xs">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
