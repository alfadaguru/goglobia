<?php
// Check if editing
$isEdit = isset($role_id) && $role_id;
$role = null;
$rolePermissions = [];
$isAdminRole = false;

if ($isEdit) {
    $role = $db->get('users_roles', '*', ['id' => $role_id]);
    if (!$role) {
        echo '<div class="container my-6"><div class="alert alert-error"><span class="material-symbols-outlined">error</span><span>' . T::role_not_found . '</span></div></div>';
        return;
    }

    // Check if this is the admin role
    $isAdminRole = (strtolower($role['type_name']) === 'admin');

    // Parse JSON permissions if they exist
    if (!empty($role['permissions'])) {
        $rolePermissions = json_decode($role['permissions'], true) ?? [];
    }
}

// Define available pages/modules
$availablePages = [
    ['id' => 'dashboard', 'name' => T::dashboard, 'slug' => 'dashboard'],
    ['id' => 'users', 'name' => T::users, 'slug' => 'users'],
    ['id' => 'users_roles', 'name' => T::users_roles, 'slug' => 'users/roles'],
    ['id' => 'bookings', 'name' => T::bookings, 'slug' => 'bookings'],
    ['id' => 'transactions', 'name' => T::transactions, 'slug' => 'transactions'],
    ['id' => 'settings', 'name' => T::settings, 'slug' => 'settings'],
    ['id' => 'modules', 'name' => T::modules, 'slug' => 'modules'],
    ['id' => 'cms', 'name' => T::cms_pages, 'slug' => 'cms'],
    ['id' => 'logs', 'name' => T::system_logs, 'slug' => 'logs'],
    ['id' => 'languages', 'name' => T::languages, 'slug' => 'languages'],
    ['id' => 'currencies', 'name' => T::currencies, 'slug' => 'currencies'],
    ['id' => 'payment_gateways', 'name' => T::payment_gateways, 'slug' => 'payment-gateways'],
    ['id' => 'notification_templates', 'name' => T::notification_templates, 'slug' => 'notification-templates'],
    ['id' => 'reports', 'name' => T::reports, 'slug' => 'reports'],
];

// Handle form submission
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'save_role' && !$isAdminRole) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = T::invalid_csrf_token;
    } else {
        $data = [
            'type_name' => trim($_POST['type_name'] ?? ''),
        ];

        if (empty($data['type_name'])) {
            $error = T::role_name_is_required;
        } else {
            // Build permissions JSON
            $permissions = [];
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                foreach ($_POST['permissions'] as $page_id => $actions) {
                    $permissions[$page_id] = [];
                    if (isset($actions['page_access'])) $permissions[$page_id]['page_access'] = '';
                    if (isset($actions['add'])) $permissions[$page_id]['add'] = '';
                    if (isset($actions['edit'])) $permissions[$page_id]['edit'] = '';
                    if (isset($actions['view'])) $permissions[$page_id]['view'] = '';
                    if (isset($actions['delete'])) $permissions[$page_id]['delete'] = '';
                }
            }

            $data['permissions'] = json_encode($permissions);

            if ($isEdit) {
                $result = $db->update('users_roles', $data, ['id' => $role_id]);
                if ($result !== false) {
                    $success = T::role_updated_successfully;
                    $role = $db->get('users_roles', '*', ['id' => $role_id]);
                    if (!empty($role['permissions'])) {
                        $rolePermissions = json_decode($role['permissions'], true) ?? [];
                    }
                } else {
                    $error = T::failed_to_update_role;
                }
            } else {
                if ($db->has('users_roles', ['type_name' => $data['type_name']])) {
                    $error = T::role_name_already_exists;
                } else {
                    $result = $db->insert('users_roles', $data);
                    if ($result->rowCount() > 0) {
                        $newRoleId = $db->id();
                        $success = T::role_created_successfully;
                        $redirectToEdit = true;
                    } else {
                        $error = T::failed_to_create_role;
                    }
                }
            }
        }
    }
}

// Handle delete role
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'delete_role') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = T::invalid_csrf_token;
    } else {
        $deleteRoleId = $_POST['role_id'] ?? 0;
        if ($deleteRoleId) {
            $db->delete('users_roles', ['id' => $deleteRoleId]);
            $redirectToList = true;
        }
    }
}
?>

<div class="container my-4">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root ?>admin/users/roles" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg text-white hover:text-white transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-1xl font-bold text-slate-800">
                    <?= $isEdit ? T::edit_role . ': ' . htmlspecialchars($role['type_name']) : T::add_new_role ?>
                </h1>
                <p class="text-sm text-slate-600 mt-1">
                    <?= $isEdit ? T::update_role_details_and_permissions : T::create_a_new_role_with_custom_permissions ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($success)): ?>
    <div class="alert alert-success mb-4">
        <span class="material-symbols-outlined">check_circle</span>
        <span><?= $success ?></span>
    </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
    <div class="alert alert-error mb-4">
        <span class="material-symbols-outlined">error</span>
        <span><?= $error ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" id="roleForm">
        <input type="hidden" name="action" value="save_role">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <!-- Main Content -->
            <div class="lg:col-span-2">

                <!-- Basic Information -->
                <div class="card mb-4">
                    <h2 class="text-base font-semibold text-slate-800 mb-4 pb-3 border-b border-slate-200">
                        <span class="flex items-center gap-2">
                            <span class="material-symbols-outlined">badge</span>
                            <?= T::role_information ?>
                        </span>
                    </h2>

                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label for="type_name" class="block text-sm font-medium text-slate-700 mb-2">
                                <?= T::role_name ?> <span class="text-red-500">*</span>
                                <?php if ($isAdminRole): ?>
                                    <span class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800 rounded">
                                        <span class="material-symbols-outlined" style="font-size: 14px;">lock</span>
                                        <?= T::protected_role ?>
                                    </span>
                                <?php endif; ?>
                            </label>
                            <input type="text" id="type_name" name="type_name"
                                   value="<?= htmlspecialchars($role['type_name'] ?? '') ?>"
                                   class="input w-full <?= $isAdminRole ? 'bg-slate-100 cursor-not-allowed' : '' ?>"
                                   placeholder="<?= T::eg_manager_editor_viewer ?>"
                                   <?= $isAdminRole ? 'disabled readonly' : 'required' ?>>
                            <?php if ($isAdminRole): ?>
                                <p class="mt-1 text-xs text-amber-600">
                                    <span class="material-symbols-outlined" style="font-size: 12px; vertical-align: middle;">info</span>
                                    <?= T::admin_role_cannot_be_modified ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Permissions -->
                <div class="card">
                    <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-200">
                        <h2 class="text-base font-semibold text-slate-800">
                            <span class="flex items-center gap-2">
                                <span class="material-symbols-outlined">security</span>
                                <?= T::permissions ?>
                            </span>
                        </h2>
                        <button type="button" id="selectAllBtn" class="text-sm text-blue-600 hover:text-blue-700 font-medium <?= $isAdminRole ? 'opacity-50 cursor-not-allowed pointer-events-none' : '' ?>">
                            <?= T::select_all ?>
                        </button>
                    </div>

                    <div class="overflow-x-auto -mx-4">
                        <table class="min-w-full table-fixed divide-y divide-slate-200">
                            <colgroup>
                                <col style="width: auto;">
                                <col style="width: 120px;">
                                <col style="width: 120px;">
                                <col style="width: 120px;">
                                <col style="width: 120px;">
                                <col style="width: 120px;">
                            </colgroup>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">
                                        <?= T::page_module ?>
                                    </th>
                                    <th class="px-4 py-3 text-start text-xs font-medium text-slate-500 uppercase tracking-wider">
                                        <div class="checkbox-item justify-start">
                                             <div class="checkbox-container">
                                                <input type="checkbox" id="select_all_page_access" class="checkbox-input select-all-action" data-action="page_access" <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                            <label for="select_all_page_access" class="cursor-pointer text-xs"><?= T::access ?></label>
                                        </div>
                                    </th>
                                    <th class="px-4 py-3 text-start text-xs font-medium text-slate-500 uppercase tracking-wider">
                                        <div class="checkbox-item justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="select_all_add" class="checkbox-input select-all-action" data-action="add" <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                            <label for="select_all_add" class="cursor-pointer text-xs"><?= T::add ?></label>
                                        </div>
                                    </th>
                                    <th class="px-4 py-3 text-start text-xs font-medium text-slate-500 uppercase tracking-wider">
                                        <div class="checkbox-item justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="select_all_edit" class="checkbox-input select-all-action" data-action="edit" <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                            <label for="select_all_edit" class="cursor-pointer text-xs"><?= T::edit ?></label>
                                        </div>
                                    </th>
                                    <th class="px-4 py-3 text-start text-xs font-medium text-slate-500 uppercase tracking-wider">
                                        <div class="checkbox-item justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="select_all_view" class="checkbox-input select-all-action" data-action="view" <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                            <label for="select_all_view" class="cursor-pointer text-xs"><?= T::view ?></label>
                                        </div>
                                    </th>
                                    <th class="px-4 py-3 text-start text-xs font-medium text-slate-500 uppercase tracking-wider">
                                        <div class="checkbox-item justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="select_all_delete" class="checkbox-input select-all-action" data-action="delete" <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                            <label for="select_all_delete" class="cursor-pointer text-xs"><?= T::delete ?></label>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-slate-200">
                                <?php foreach ($availablePages as $page): ?>
                                <?php
                                    $pagePerm = $rolePermissions[$page['id']] ?? [];
                                    $hasAccess = isset($pagePerm['page_access']);
                                    $canAdd = isset($pagePerm['add']);
                                    $canEdit = isset($pagePerm['edit']);
                                    $canView = isset($pagePerm['view']);
                                    $canDelete = isset($pagePerm['delete']);
                                ?>
                                <tr class="hover:bg-slate-50 permission-row">
                                    <td class="px-4 py-3 text-sm text-slate-900">
                                        <div class="flex flex-col">
                                            <span class="font-medium"><?= htmlspecialchars($page['name']) ?></span>
                                            <span class="text-xs text-slate-500">/<?= htmlspecialchars($page['slug']) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-start">
                                        <div class="flex items-center justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="perm_<?= $page['id'] ?>_page_access" name="permissions[<?= $page['id'] ?>][page_access]"
                                                       class="checkbox-input permission-checkbox action-page_access" data-row="<?= $page['id'] ?>"
                                                       <?= $hasAccess ? 'checked' : '' ?>
                                                       <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-start">
                                        <div class="flex items-center justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="perm_<?= $page['id'] ?>_add" name="permissions[<?= $page['id'] ?>][add]"
                                                       class="checkbox-input permission-checkbox action-add" data-row="<?= $page['id'] ?>"
                                                       <?= $canAdd ? 'checked' : '' ?>
                                                       <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-start">
                                        <div class="flex items-center justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="perm_<?= $page['id'] ?>_edit" name="permissions[<?= $page['id'] ?>][edit]"
                                                       class="checkbox-input permission-checkbox action-edit" data-row="<?= $page['id'] ?>"
                                                       <?= $canEdit ? 'checked' : '' ?>
                                                       <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-start">
                                        <div class="flex items-center justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="perm_<?= $page['id'] ?>_view" name="permissions[<?= $page['id'] ?>][view]"
                                                       class="checkbox-input permission-checkbox action-view" data-row="<?= $page['id'] ?>"
                                                       <?= $canView ? 'checked' : '' ?>
                                                       <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-start">
                                        <div class="flex items-center justify-start">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="perm_<?= $page['id'] ?>_delete" name="permissions[<?= $page['id'] ?>][delete]"
                                                       class="checkbox-input permission-checkbox action-delete" data-row="<?= $page['id'] ?>"
                                                       <?= $canDelete ? 'checked' : '' ?>
                                                       <?= $isAdminRole ? 'disabled' : '' ?>>
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Sidebar -->
            <div class="lg:col-span-1">

                <!-- Quick Stats -->
                <?php if ($isEdit): ?>
                <div class="card mb-4">
                    <h3 class="text-sm font-semibold text-slate-800 mb-3 pb-2 border-b border-slate-200"><?= T::quick_stats ?></h3>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-600"><?= T::total_modules ?></span>
                            <span class="text-sm font-semibold text-slate-900">
                                <?= count($rolePermissions) ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-slate-600"><?= T::users_with_role ?></span>
                            <span class="text-sm font-semibold text-slate-900">
                                <?= $db->count('users', ['role' => $role['type_name']]) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="card">
                    <div class="flex flex-col gap-2">
                        <button type="submit" id="submitRoleBtn" class="btn w-full <?= $isAdminRole ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= $isAdminRole ? 'disabled' : '' ?>>
                            <span class="material-symbols-outlined text-lg" id="submitIcon">save</span>
                            <span id="submitText"><?= $isEdit ? T::update_role : T::create_role ?></span>
                            <span id="submitLoader" class="hidden">
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                        </button>
                        <a href="<?= root ?>admin/users/roles" class="btn light w-full no-ripple">
                            <span class="material-symbols-outlined text-lg">cancel</span>
                            <?= T::cancel ?>
                        </a>
                        <?php if ($isEdit && !$isAdminRole): ?>
                        <button type="button" id="deleteRoleBtn" onclick="deleteRole(<?= $role['id'] ?>)" class="btn rose w-full">
                            <span class="material-symbols-outlined text-lg" id="deleteIcon">delete</span>
                            <span id="deleteText"><?= T::delete_role ?></span>
                            <span id="deleteLoader" class="hidden">
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </form>

</div>

<style>
@keyframes buttonPress {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(0.95);
    }
    100% {
        transform: scale(1);
    }
}

@keyframes ripple {
    0% {
        transform: scale(0);
        opacity: 0.6;
    }
    100% {
        transform: scale(4);
        opacity: 0;
    }
}

.btn-animate {
    animation: buttonPress 0.3s ease-in-out;
}

.btn {
    position: relative;
    overflow: hidden;
}

.btn-ripple {
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.5);
    width: 20px;
    height: 20px;
    animation: ripple 0.6s ease-out;
    pointer-events: none;
}
</style>

<script>
// Add ripple effect to all buttons
document.addEventListener('DOMContentLoaded', function() {
    const buttons = document.querySelectorAll('.btn');

    buttons.forEach(button => {
        button.addEventListener('click', function(e) {
            // Skip ripple for cancel button or buttons with no-ripple class
            if (this.classList.contains('no-ripple')) {
                return;
            }

            // Add button press animation
            this.classList.add('btn-animate');
            setTimeout(() => {
                this.classList.remove('btn-animate');
            }, 300);

            // Create ripple effect
            const ripple = document.createElement('span');
            ripple.classList.add('btn-ripple');

            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;

            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';

            this.appendChild(ripple);

            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });

    // Handle form submission with loading state
    const roleForm = document.getElementById('roleForm');
    const submitBtn = document.getElementById('submitRoleBtn');
    const submitIcon = document.getElementById('submitIcon');
    const submitText = document.getElementById('submitText');
    const submitLoader = document.getElementById('submitLoader');

    if (roleForm && submitBtn) {
        roleForm.addEventListener('submit', function(e) {
            // Show loading state
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
            }
            if (submitIcon) submitIcon.classList.add('hidden');
            if (submitText) submitText.classList.add('hidden');
            if (submitLoader) submitLoader.classList.remove('hidden');
        });
    }

    // Select all permissions
    const selectAllBtn = document.getElementById('selectAllBtn');
    const allCheckboxes = document.querySelectorAll('.permission-checkbox');

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
            allCheckboxes.forEach(cb => {
                cb.checked = !allChecked;
            });
            this.textContent = allChecked ? '<?= T::select_all ?>' : '<?= T::deselect_all ?>';

            // Update column checkboxes
            updateAllColumnCheckboxes();
        });
    }

    // Select all by action type (column)
    const actionHeaders = document.querySelectorAll('.select-all-action');
    actionHeaders.forEach(header => {
        header.addEventListener('change', function() {
            const action = this.dataset.action;
            const actionCheckboxes = document.querySelectorAll('.action-' + action);
            actionCheckboxes.forEach(cb => {
                cb.checked = this.checked;
            });

            // Update column checkboxes
            updateAllColumnCheckboxes();
        });
    });    // Handle individual permission checkbox changes
    allCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateAllColumnCheckboxes();
        });
    });

    // Initialize column header checkboxes on page load
    updateAllColumnCheckboxes();

    // Function to update column header checkboxes
    function updateAllColumnCheckboxes() {
        const actions = ['page_access', 'add', 'edit', 'view', 'delete'];

        actions.forEach(action => {
            const columnCheckboxes = document.querySelectorAll('.action-' + action);
            const headerCheckbox = document.querySelector('.select-all-action[data-action="' + action + '"]');

            if (headerCheckbox && columnCheckboxes.length > 0) {
                const allChecked = Array.from(columnCheckboxes).every(cb => cb.checked);
                const anyChecked = Array.from(columnCheckboxes).some(cb => cb.checked);

                headerCheckbox.checked = allChecked;

                // Add indeterminate state if some but not all are checked
                if (anyChecked && !allChecked) {
                    headerCheckbox.indeterminate = true;
                } else {
                    headerCheckbox.indeterminate = false;
                }
            }
        });
    }

    // Update column headers when individual checkboxes change
    allCheckboxes.forEach(checkbox => {
        const originalChangeHandler = checkbox.onchange;
        checkbox.addEventListener('change', function() {
            updateAllColumnCheckboxes();
        });
    });
});

let deleteInProgress = false;

function toggleAllRows(checkbox) {
    const allRowCheckboxes = document.querySelectorAll('.row-checkbox');
    allRowCheckboxes.forEach(rowCheckbox => {
        rowCheckbox.checked = checkbox.checked;
        // Trigger change event to update permissions
        const rowId = rowCheckbox.dataset.row;
        const rowPermissions = document.querySelectorAll(`.permission-checkbox[data-row="${rowId}"]`);
        rowPermissions.forEach(perm => {
            perm.checked = checkbox.checked;
        });
    });
}

function deleteRole(roleId) {
    if (deleteInProgress) {
        return; // Prevent double submission
    }

    if (confirm('<?= T::are_you_sure_you_want_to_delete_this_role ?>')) {
        deleteInProgress = true;

        // Show loading state
        const deleteBtn = document.getElementById('deleteRoleBtn');
        const deleteIcon = document.getElementById('deleteIcon');
        const deleteText = document.getElementById('deleteText');
        const deleteLoader = document.getElementById('deleteLoader');

        if (deleteBtn) {
            deleteBtn.disabled = true;
            deleteBtn.classList.add('opacity-75', 'cursor-not-allowed');
        }
        if (deleteIcon) deleteIcon.classList.add('hidden');
        if (deleteText) deleteText.classList.add('hidden');
        if (deleteLoader) deleteLoader.classList.remove('hidden');

        // Create and submit form
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_role">
            <input type="hidden" name="role_id" value="${roleId}">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Handle redirects after successful operations
<?php if (isset($redirectToEdit) && $redirectToEdit): ?>
    setTimeout(function() {
        const redirectUrl = '<?= root.admin ?>/users/roles/edit/<?= $newRoleId ?>';
        console.log('Redirecting to:', redirectUrl);
        window.location.href = redirectUrl;
    }, 1500);
<?php endif; ?>

<?php if (isset($redirectToList) && $redirectToList): ?>
    setTimeout(function() {
        const redirectUrl = '<?= root.admin ?>/users/roles';
        console.log('Redirecting to:', redirectUrl);
        window.location.href = redirectUrl;
    }, 1000);
<?php endif; ?>
</script>





