<?php
// app/views/admin/support/staff-manage.php — add / edit a support staff member.
// Pick an existing user (reusing the api/users/search?q= endpoint the
// ticket-new.php form uses), pick a role (from support_roles), set display name,
// nudge phone (defaults to users.phone), shift group and status. Posts to
// admin/support/staff/save.
@$SECURE or die('Access Denied!');

$id = (int) ($_GET['id'] ?? 0);

// Edit: load the existing staff row + its user for the locked identity display.
$staff = $id > 0 ? $db->get('support_staff', '*', ['id' => $id]) : null;
$user  = $staff ? $db->get('users', ['user_id', 'first_name', 'last_name', 'email', 'phone'], ['user_id' => $staff['user_id']]) : null;

// Role dropdown — all support_roles, newest system roles first by id.
$roles = $db->select('support_roles', ['id', 'name'], ['ORDER' => ['id' => 'ASC']]) ?: [];

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
        <h1 class="text-2xl font-bold text-slate-900"><?= $id > 0 ? 'Edit Support Staff' : 'Add Support Staff' ?></h1>
        <p class="text-sm text-slate-500 mt-1">Elevate an existing user into a support employee.</p>
      </div>
      <a href="<?= root . admin ?>/support/staff"
         class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition w-full sm:w-auto">
        <span class="material-symbols-outlined text-lg">arrow_back</span>
        Back to staff
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

        <form action="<?= root . admin ?>/support/staff/save" method="POST" class="p-6 space-y-6" x-data="{
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
                // Default the phone field to the user's own phone if empty.
                const phoneEl = document.getElementById('phone');
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

            <?php if ($id > 0 && $user): ?>
              <!-- On edit the staff identity is locked to its user. -->
              <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg">
                <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($userLabel) ?></p>
                <p class="text-xs text-slate-500 mt-0.5">The user cannot be changed on an existing staff record.</p>
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
                <option value="<?= (int) $r['id'] ?>" <?= ($staff && (int) $staff['role_id'] === (int) $r['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) $r['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- DISPLAY NAME -->
          <div>
            <label for="display_name" class="block text-sm font-medium text-slate-700 mb-2">Display name</label>
            <input type="text" id="display_name" name="display_name" maxlength="120"
                   value="<?= htmlspecialchars((string) ($staff['display_name'] ?? '')) ?>"
                   placeholder="Defaults to the user's full name" class="input w-full">
          </div>

          <!-- PHONE (for GIA nudges) -->
          <div>
            <label for="phone" class="block text-sm font-medium text-slate-700 mb-2">Phone (for nudges)</label>
            <input type="text" id="phone" name="phone" maxlength="32"
                   value="<?= htmlspecialchars((string) ($staff['phone'] ?? ($user['phone'] ?? ''))) ?>"
                   placeholder="Defaults to the user's phone" class="input w-full">
          </div>

          <!-- SHIFT GROUP -->
          <div>
            <label for="shift_group" class="block text-sm font-medium text-slate-700 mb-2">Shift group</label>
            <input type="text" id="shift_group" name="shift_group" maxlength="60"
                   value="<?= htmlspecialchars((string) ($staff['shift_group'] ?? '')) ?>"
                   placeholder="e.g. Morning, Evening" class="input w-full">
          </div>

          <!-- STATUS -->
          <div>
            <label for="status" class="block text-sm font-medium text-slate-700 mb-2">Status</label>
            <select id="status" name="status" class="select w-full">
              <option value="active" <?= (($staff['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
              <option value="suspended" <?= (($staff['status'] ?? '') === 'suspended') ? 'selected' : '' ?>>Suspended</option>
            </select>
          </div>

          <!-- ACTIONS -->
          <div class="flex items-center justify-end gap-3 pt-5 border-t border-slate-200">
            <a href="<?= root . admin ?>/support/staff"
               class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
              <span class="material-symbols-outlined text-lg">cancel</span>
              Cancel
            </a>
            <button type="submit"
                    class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 transition shadow-sm">
              <span class="material-symbols-outlined text-lg">save</span>
              <?= $id > 0 ? 'Save changes' : 'Add staff' ?>
            </button>
          </div>

          <?= CSRF::tokenField() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
        </form>
      </div>

    </div>
  </div>
</div>

<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
<style>[x-cloak]{display:none !important;}</style>
