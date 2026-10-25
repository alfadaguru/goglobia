<?php
// app/views/admin/employees/staff-manage.php — add / edit an employee (users_roles).
// Pick an existing user (reusing the api/users/search?q= endpoint), assign a role
// (from users_roles) + optional shift group + nudge phone. Saving promotes the
// user to role='employee' with that role_id. Posts to admin/employees/staff/save.
@$SECURE or die('Access Denie'.'d!');

$uid = (string) ($_GET['uid'] ?? '');

// Edit: load the existing user (the employee IS the user).
$user = $uid !== '' ? $db->get('users', ['user_id', 'first_name', 'last_name', 'email', 'phone', 'role', 'role_id', 'shift_group', 'nudge_phone'], ['user_id' => $uid]) : null;
$isEdit = (bool) $user;

// Role dropdown — assignable roles from users_roles. Exclude the all-access Admin
// role (admins are managed in Users) and the legacy Customer/Supplier/Agent seeds
// that aren't admin-panel employee roles.
$allRoles = $db->select('users_roles', ['id', 'type_name', 'slug', 'base_type'], ['ORDER' => ['id' => 'ASC']]) ?: [];
$roles = [];
foreach ($allRoles as $r) {
    $slug = strtolower((string) ($r['slug'] ?? ''));
    $bt   = strtolower((string) ($r['base_type'] ?? ''));
    $nm   = strtolower((string) ($r['type_name'] ?? ''));
    if ($bt === 'admin' || $nm === 'admin') { continue; }      // all-access, not assignable here
    if (in_array($slug, ['customer', 'supplier', 'agent'], true)) { continue; }
    if (in_array($nm, ['customer', 'supplier', 'agent'], true)) { continue; }
    $roles[] = $r;
}

$userLabel = '';
if ($user) {
    $full = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $userLabel = ($full !== '' ? $full : $user['user_id']) . ' (' . ($user['email'] ?? '') . ')';
}
?>

<div class="min-h-screen bg-slate-50">

  <!-- PAGE HEADER -->
  <div class="bg-white border-b border-slate-200 px-6 py-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 max-w-3xl mx-auto">
      <div>
        <h1 class="text-2xl font-bold text-slate-900"><?= $isEdit ? 'Edit Employee' : 'Add Employee' ?></h1>
        <p class="text-sm text-slate-500 mt-1">Grant an existing user a role with admin-panel access.</p>
      </div>
      <a href="<?= root . admin ?>/employees/staff"
         class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition w-full sm:w-auto">
        <span class="material-symbols-outlined text-lg">arrow_back</span>
        Back to employees
      </a>
    </div>
  </div>

  <div class="p-6">
    <div class="max-w-3xl mx-auto">

      <?php if (isset($_SESSION['message'])): ?>
        <div class="mb-5 flex items-center gap-2 p-3 rounded-lg"
             style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
          <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
          <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
      <?php endif; ?>

      <div class="bg-white rounded-lg border border-slate-200 shadow-sm">
        <div class="bg-gradient-to-r from-primary to-primary/80 px-6 py-4">
          <h2 class="text-lg font-semibold text-white flex items-center gap-2">
            <span class="material-symbols-outlined">support_agent</span>
            Staff details
          </h2>
        </div>

        <form action="<?= root . admin ?>/employees/staff/save" method="POST" class="p-6 space-y-6" x-data="{
            selectedUser: <?= $user ? 'true' : 'null' ?>,
            searchResults: [],
            searching: false,
            showResults: false,

            async searchUsers(query) {
                if (query.length < 2) { this.searchResults = []; this.showResults = false; return; }
                this.searching = true;
                this.showResults = true;
                try {
                    const response = await fetch('<?= root ?>api/users/search?q=' + encodeURIComponent(query));
                    if (!response.ok) throw new Error('API request failed');
                    const data = await response.json();
                    this.searchResults = data.users || [];
                } catch (error) {
                    console.error('Search error:', error);
                    this.searchResults = [];
                    alert('Failed to search users. Please try again.');
                }
                this.searching = false;
            },

            selectUser(user) {
                this.selectedUser = true;
                this.showResults = false;
                document.getElementById('user_id').value = user.user_id;
                document.getElementById('userSearch').value = user.name + ' (' + user.email + ')';
                // Default the nudge phone to the user's own phone if empty.
                const phoneEl = document.getElementById('nudge_phone');
                if (phoneEl && !phoneEl.value && user.phone) { phoneEl.value = user.phone; }
            },

            clearUser() {
                this.selectedUser = null;
                document.getElementById('user_id').value = '';
                document.getElementById('userSearch').value = '';
                this.searchResults = [];
            }
        }">

          <!-- USER PICKER -->
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-2">
              User <span class="text-red-500">*</span>
            </label>

            <?php if ($isEdit): ?>
              <!-- On edit the employee identity is locked to its user. -->
              <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg">
                <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($userLabel) ?></p>
                <p class="text-xs text-slate-500 mt-0.5">The user cannot be changed on an existing employee.</p>
              </div>
              <input type="hidden" id="user_id" name="user_id" value="<?= htmlspecialchars((string) $user['user_id']) ?>">
            <?php else: ?>
              <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                <input type="text" id="userSearch" @input.debounce.300ms="searchUsers($event.target.value)"
                       @focus="showResults = searchResults.length > 0"
                       placeholder="Search by name or email..." class="input w-full pl-10" autocomplete="off">

                <div x-show="showResults" x-cloak
                     class="absolute z-10 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-64 overflow-y-auto">
                  <div x-show="searching" class="p-4 text-center text-slate-500">
                    <span class="material-symbols-outlined animate-spin">refresh</span>
                    <p class="text-sm mt-2">Searching...</p>
                  </div>
                  <template x-if="!searching && searchResults.length === 0">
                    <div class="p-4 text-center text-slate-500">
                      <span class="material-symbols-outlined text-slate-300">search_off</span>
                      <p class="text-sm mt-2">No results found</p>
                    </div>
                  </template>
                  <template x-for="user in searchResults" :key="user.user_id">
                    <div @mousedown.prevent="selectUser(user)"
                         class="p-3 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0">
                      <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary to-primary/70 flex items-center justify-center text-white font-semibold text-sm">
                          <span x-text="user.name.charAt(0).toUpperCase()"></span>
                        </div>
                        <div class="flex-1 min-w-0">
                          <p class="text-sm font-medium text-slate-900" x-text="user.name"></p>
                          <p class="text-xs text-slate-500 truncate" x-text="user.email"></p>
                        </div>
                      </div>
                    </div>
                  </template>
                </div>
              </div>
              <input type="hidden" id="user_id" name="user_id" required>
            <?php endif; ?>
          </div>

          <!-- ROLE -->
          <div>
            <label for="role_id" class="block text-sm font-medium text-slate-700 mb-2">
              Role <span class="text-red-500">*</span>
            </label>
            <select id="role_id" name="role_id" required class="select w-full">
              <option value="">— select a role —</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['id'] ?>" <?= ($user && (int) ($user['role_id'] ?? 0) === (int) $r['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) $r['type_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-slate-500 mt-1">The role controls which admin areas this employee can access.</p>
          </div>

          <!-- SHIFT GROUP -->
          <div>
            <label for="shift_group" class="block text-sm font-medium text-slate-700 mb-2">Shift group</label>
            <input type="text" id="shift_group" name="shift_group" maxlength="60"
                   value="<?= htmlspecialchars((string) ($user['shift_group'] ?? '')) ?>"
                   placeholder="e.g. Morning, Evening" class="input w-full">
          </div>

          <!-- WHATSAPP NUMBER (used for GIA shift nudges + staff WhatsApp contact) -->
          <div>
            <label for="nudge_phone" class="flex items-center gap-1.5 text-sm font-medium text-slate-700 mb-2">
              <!-- WhatsApp glyph (inline SVG — Material Symbols has no WhatsApp icon) -->
              <svg viewBox="0 0 24 24" width="18" height="18" fill="#25D366" aria-hidden="true" style="flex:none">
                <path d="M19.05 4.91A9.82 9.82 0 0 0 12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.02zM12.05 20.15h-.01a8.23 8.23 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.24-8.23 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.82c0 4.54-3.69 8.24-8.23 8.24zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.13-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43-.14-.01-.31-.01-.48-.01-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.22-.16-.47-.28z"/>
              </svg>
              WhatsApp number
            </label>
            <input type="text" id="nudge_phone" name="nudge_phone" maxlength="32"
                   value="<?= htmlspecialchars((string) ($user['nudge_phone'] ?? '')) ?>"
                   placeholder="e.g. +234 801 234 5678" class="input w-full">
            <p class="text-xs text-slate-500 mt-1">The staff member's WhatsApp number — GIA sends shift nudges here. Leave blank to use the account phone.</p>
          </div>

          <!-- ACTIONS -->
          <div class="flex items-center justify-end gap-3 pt-5 border-t border-slate-200">
            <a href="<?= root . admin ?>/employees/staff"
               class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
              <span class="material-symbols-outlined text-lg">cancel</span>
              Cancel
            </a>
            <button type="submit"
                    class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 transition shadow-sm">
              <span class="material-symbols-outlined text-lg">save</span>
              <?= $isEdit ? 'Save changes' : 'Add employee' ?>
            </button>
          </div>

          <?= CSRF::tokenField() ?>
        </form>
      </div>

    </div>
  </div>
</div>

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
<style>[x-cloak]{display:none !important;}</style>
