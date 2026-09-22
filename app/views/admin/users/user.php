<?php
// SECURITY (reflected XSS): the ?tab param is echoed into a hidden input's value
// below. It was output raw, so ?tab="><script>… broke out of the attribute and
// executed in the admin's session (session/CSRF-token theft). Constrain it to the
// known tab vocabulary and default anything else to 'profile'.
$__allowedTabs = ['profile', 'account', 'notes', 'wallet', 'credit', 'agency', 'security', 'documents'];
$currentTab = in_array(($_GET['tab'] ?? ''), $__allowedTabs, true) ? $_GET['tab'] : 'profile';

// Fetch user roles for dropdown
$roles = $db->select('users_roles', ['id', 'type_name'], ['ORDER' => ['id' => 'ASC']]);

// Fetch countries for phone code dropdown sorted by ISO code
$countries = $db->select('countries', ['id', 'nicename', 'phonecode', 'iso'], [
    'status' => 'active',
    'ORDER' => ['iso' => 'ASC']  // Sort by ISO code alphabetically
]);

// Check if editing
$isEdit = isset($user_id) && $user_id;
$user = null;
$userLogs = [];
$userBookings = [];
$userNotes = [];

if ($isEdit) {
    $user = $db->get('users', '*', ['user_id' => $user_id]);
    if (!$user) {
        echo '<div class="container my-6"><div class="alert alert-error"><span class="material-symbols-outlined">error</span><span>' . T::user_not_found . '</span></div></div>';
        return;
    }

    // Fetch user activity logs
    $userLogs = $db->select('logs_users', '*', [
        'user_id' => $user_id,
        'ORDER' => ['created_at' => 'DESC'],
        'LIMIT' => 50
    ]);

    // Fetch user bookings
    $userBookings = $db->select('bookings', '*', [
        'email' => $user['email'],
        'ORDER' => ['booking_date' => 'DESC'],
        'LIMIT' => 50
    ]);

    // Fetch user notes
    $userNotes = $db->select('notes', '*', [
        'user_id' => $user_id,
        'ORDER' => ['created_at' => 'DESC']
    ]);

    $userTransactions = $db->select('transactions', '*', [
        'user_id' => $user_id,
        'ORDER' => ['date' => 'DESC'],
        'LIMIT' => 50
    ]);

    // Agent credit reminder summary (assigned / used / threshold / due)
    $agentCreditAssigned = 0;
    $agentCreditUsed = 0;
    $agentCreditThreshold = 0;
    $agentCreditDueDate = null;
    if (($user['role'] ?? '') === 'agent') {
        $agentCreditAssigned = intval($db->sum('credits', 'credits', [
            'user_id' => $user_id,
            'type' => 'credit',
        ]) ?: 0);
        $agentCreditUsed = intval($db->sum('credits', 'credits', [
            'user_id' => $user_id,
            'type' => 'debit',
        ]) ?: 0);
        $usagePct = max(0, min(100, intval($user['credit_usage_reminder_percent'] ?? 0)));
        if ($usagePct > 0 && $agentCreditAssigned > 0) {
            $agentCreditThreshold = (int) floor($agentCreditAssigned * $usagePct / 100);
        }
        $firstUsage = $user['first_credit_usage_date'] ?? null;
        $paymentDays = intval($user['credit_payment_days'] ?? 0);
        if (!empty($firstUsage) && $paymentDays > 0) {
            try {
                $due = new DateTime($firstUsage);
                $due->modify("+{$paymentDays} days");
                $agentCreditDueDate = $due->format('Y-m-d');
            } catch (Throwable $e) {
                $agentCreditDueDate = null;
            }
        }
    }
}

// Session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>

<div class="container my-4">

    <form method="POST" id="userProfileForm" action="<?= root ?>admin/users/save"
          x-data="{ loading: false, submitForm(e) { console.log('Form submitting...'); this.loading = true; return true; } }"
          @submit="submitForm($event)">
        <input type="hidden" name="credit_days" id="credit_days_hidden">
        <input type="hidden" name="action" value="save_user">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <?php if ($isEdit): ?>
        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
        <?php endif; ?>
        <input type="hidden" name="current_tab" id="current_tab" value="<?= htmlspecialchars($currentTab, ENT_QUOTES, 'UTF-8') ?>">

        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-5">
                <a href="<?= root ?>admin/users" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg text-white hover:text-white transition-colors">
                    <span class="material-symbols-outlined text-xl">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-xl font-bold text-slate-800">
                        <?= $isEdit ? ($user['first_name'] . ' ' . $user['last_name']) : T::add_new_user ?>
                    </h1>
                    <?php if ($isEdit): ?>
                    <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-base">badge</span>
                            <?= $user['user_id'] ?>
                        </span>
                        <span>•</span>
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-base">mail</span>
                            <?= htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isEdit): ?>

                <div class="flex items-center gap-3">
                    <div class="flex flex-col gap-1 min-w-[150px]">
                        <label for="status" class="text-xs font-medium text-slate-600"><?=T::status?></label>
                        <select id="status" name="status" class="select input text-sm py-1.5 px-3" required>
                            <option value="active" <?= (!isset($user['status']) || strtolower($user['status']) == 'active') ? 'selected' : '' ?>><?=T::active?></option>
                            <option value="inactive" <?= (isset($user['status']) && strtolower($user['status']) == 'inactive') ? 'selected' : '' ?>><?=T::inactive?></option>
                            <option value="pending" <?= (isset($user['status']) && strtolower($user['status']) == 'pending') ? 'selected' : '' ?>><?=T::pending?></option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1 min-w-[150px]">
                        <label for="role" class="text-xs font-medium text-slate-600"><?=T::role?></label>
                        <select id="role" name="role" class="select input text-sm py-1.5 px-3" required>
                            <!-- <option value=""><?=T::select_role?></option> -->
                            <?php foreach ($roles as $roleItem): ?>
                            <option value="<?= strtolower($roleItem['type_name']) ?>" <?= (isset($user['role']) && strtolower($user['role']) == strtolower($roleItem['type_name'])) ? 'selected' : '' ?>>
                                <?= ucfirst($roleItem['type_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Banned Status Select Box -->
                    <div class="flex flex-col gap-1 min-w-[150px]">
                        <label for="banned" class="text-xs font-medium text-slate-600"><?=T::banned_status?></label>
                        <select id="banned" name="banned" class="select input text-sm py-1.5 px-3" required>
                            <option value="0" <?= (!isset($user['banned']) || $user['banned'] == '0' || $user['banned'] === 0) ? 'selected' : '' ?>>No</option>
                            <option value="1" <?= (isset($user['banned']) && ($user['banned'] == '1' || $user['banned'] === 1)) ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                </div>

            <?php else: ?>
                <!-- Show dropdowns for new users too -->
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <div class="flex flex-col gap-1 min-w-[150px]">
                        <label for="status" class="text-xs font-medium text-slate-600"><?=T::status?></label>
                        <select id="status" name="status" class="select input text-sm py-1.5 px-3" required>
                            <option value="active" selected><?=T::active?></option>
                            <option value="inactive"><?=T::inactive?></option>
                            <option value="pending"><?=T::pending?></option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1 min-w-[150px]">
                        <label for="role" class="text-xs font-medium text-slate-600"><?=T::role?></label>
                        <select id="role" name="role" class="select input text-sm py-1.5 px-3" required>
                            <!-- <option value=""><?=T::select_role?></option> -->
                            <?php foreach ($roles as $roleItem): ?>
                            <option value="<?= strtolower($roleItem['type_name']) ?>">
                                <?= ucfirst($roleItem['type_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Banned Status Select Box -->
                    <div class="flex flex-col gap-1 min-w-[150px]">
                        <label for="banned" class="text-xs font-medium text-slate-600"><?=T::banned_status?></label>
                        <select id="banned" name="banned" class="select input text-sm py-1.5 px-3" required>
                            <option value="0" selected>No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>
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

    <!-- Tabs -->
    <div class="bg-white rounded-lg shadow-sm border border-slate-200 mb-4 overflow-hidden">
        <div class="flex border-b border-slate-200 overflow-x-auto scrollbar-thin">
            <a href="#profile" class="tab-link flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-all whitespace-nowrap text-slate-600 border-transparent hover:border-blue-500 hover:text-blue-600 hover:bg-blue-50">
                <span class="material-symbols-outlined text-base">person</span>
                <span><?=T::profile?></span>
            </a>
            <a href="#information" class="tab-link flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-all whitespace-nowrap text-slate-600 border-transparent hover:border-blue-500 hover:text-blue-600 hover:bg-blue-50">
                <span class="material-symbols-outlined text-base">info</span>
                <span><?=T::information?></span>
            </a>
            <a href="#activity" class="tab-link flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-all whitespace-nowrap text-slate-600 border-transparent hover:border-blue-500 hover:text-blue-600 hover:bg-blue-50">
                <span class="material-symbols-outlined text-base">history</span>
                <span><?=T::activity?></span>
                <?php if ($isEdit && count($userLogs) > 0): ?>
                <span class="ml-1 px-2 py-0.5 bg-slate-100 text-slate-700 rounded-full text-xs font-semibold"><?= count($userLogs) ?></span>
                <?php endif; ?>
            </a>
            <a href="#bookings" class="tab-link flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-all whitespace-nowrap text-slate-600 border-transparent hover:border-blue-500 hover:text-blue-600 hover:bg-blue-50">
                <span class="material-symbols-outlined text-base">airplane_ticket</span>
                <span><?=T::bookings?></span>
                <?php if ($isEdit && count($userBookings) > 0): ?>
                <span class="ml-1 px-2 py-0.5 bg-slate-100 text-slate-700 rounded-full text-xs font-semibold"><?= count($userBookings) ?></span>
                <?php endif; ?>
            </a>
            <a href="#transactions" class="tab-link flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-all whitespace-nowrap text-slate-600 border-transparent hover:border-blue-500 hover:text-blue-600 hover:bg-blue-50">
                <span class="material-symbols-outlined text-base">receipt_long</span>
                <span><?=T::transactions?></span>
                <?php if ($isEdit && count($userTransactions) > 0): ?>
                <span class="ml-1 px-2 py-0.5 bg-slate-100 text-slate-700 rounded-full text-xs font-semibold"><?= count($userTransactions) ?></span>
                <?php endif; ?>
            </a>
            <a href="#notes" class="tab-link flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 transition-all whitespace-nowrap text-slate-600 border-transparent hover:border-blue-500 hover:text-blue-600 hover:bg-blue-50">
                <span class="material-symbols-outlined text-base">notes</span>
                <span><?=T::notes?></span>
                <?php if ($isEdit && count($userNotes) > 0): ?>
                <span class="ml-1 px-2 py-0.5 bg-slate-100 text-slate-700 rounded-full text-xs font-semibold"><?= count($userNotes) ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <!-- Main Content -->
        <div class="lg:col-span-8">

            <!-- Profile Tab -->
            <div id="profile-tab" class="tab-content active">
                <div class="card">
                    <div class="border-b border-slate-200 pb-4 mb-4">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-base font-semibold text-slate-800 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">person</span>
                                <?=T::personal_information?>
                            </h2>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="form-control">
                                <label for="first_name" class="form-label required"><?=T::first_name?></label>
                                <input type="text" id="first_name" name="first_name" class="input" value="<?= $user['first_name'] ?? '' ?>" required>
                            </div>
                            <div class="form-control">
                                <label for="last_name" class="form-label required"><?=T::last_name?></label>
                                <input type="text" id="last_name" name="last_name" class="input" value="<?= $user['last_name'] ?? '' ?>" required>
                            </div>
                            <div class="form-control">
                                <label for="email" class="form-label required"><?=T::email?></label>
                                <input type="email" id="email" name="email" class="input" value="<?= $user['email'] ?? '' ?>" required>
                            </div>
                            <div class="form-control">
                                <label for="phone" class="form-label"><?=T::phone?></label>
                                <div class="flex gap-2">
                                    <!-- Country Code Select -->
                                    <select name="phone_country_code" class="select w-28 flex-shrink-0">
                                        <option value=""><?=T::select?></option>
                                        <?php foreach ($countries as $country): ?>
                                            <option value="<?= $country['iso'] ?>" <?= (($user['phone_country_code'] ?? '') == $country['iso'] || ($user['phone_country_code'] ?? '') == $country['phonecode']) ? 'selected' : '' ?>>
                                            <?= $country['iso'] ?>  +<?= $country['phonecode'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <!-- Phone Number Input -->
                                    <input type="text"
                                           id="phone"
                                           name="phone"
                                           class="input"
                                           style="flex: 1;"
                                           value="<?= $user['phone'] ?? '' ?>"
                                           placeholder="123456789"
                                           oninput="this.value = this.value.replace(/^0+/, '').replace(/[^0-9]/g, '')">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg text-yellow-600">lock</span>
                            <?=T::security?>
                        </h2>
                        <div class="form-control">
                            <label for="password" class="form-label <?= !$isEdit ? 'required' : '' ?>">
                                <?=T::password?> <?= $isEdit ? T::leave_blank_to_keep_current : '' ?>
                            </label>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   class="input"
                                   <?= !$isEdit ? 'required' : '' ?>
                                   placeholder="<?= $isEdit ? T::enter_new_password_to_change : T::enter_password ?>"
                                   autocomplete="new-password"
                                   autocorrect="off"
                                   autocapitalize="off"
                                   spellcheck="false"
                                   value="">
                            <?php if (!$isEdit): ?>
                            <p class="text-xs text-slate-500 mt-1"><?=T::password_must_be_at_least?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pb-4 mb-4">
                        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">location_on</span>
                            <?=T::address_information?>
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="form-control md:col-span-2">
                                <label for="address" class="form-label"><?=T::address?></label>
                                <textarea id="address" name="address" class="input" rows="2"><?= $user['address'] ?? '' ?></textarea>
                            </div>
                            <div class="form-control">
                                <label for="city" class="form-label"><?=T::city?></label>
                                <input type="text" id="city" name="city" class="input" value="<?= $user['city'] ?? '' ?>">
                            </div>
                            <div class="form-control">
                                <label for="state" class="form-label"><?=T::state?></label>
                                <input type="text" id="state" name="state" class="input" value="<?= $user['state'] ?? '' ?>">
                            </div>
                            <div class="form-control">
                            <label for="country" class="form-label"><?=T::country?></label>
                            <select id="country" name="country" class="select input">
                                <option value=""><?=T::select_country?></option>
                                <?php foreach ($countries as $country_item): ?>
                                <option value="<?= $country_item['nicename'] ?>"
                                    <?= (isset($user['country']) && $user['country'] == $country_item['nicename']) ? 'selected' : '' ?>>
                                    <?= $country_item['nicename'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                            <div class="form-control">
                                <label for="po_box" class="form-label"><?=T::po_box?></label>
                                <input type="text" id="po_box" name="po_box" class="input" value="<?= $user['po_box'] ?? '' ?>">
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-200">
                        <a href="<?= root ?>admin/users" class="btn white"><?=T::cancel?></a>
                        <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                            <span x-show="!loading" class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">save</span>
                                <?= $isEdit ? T::update_user : T::create_user ?>
                            </span>
                            <span x-show="loading" class="flex items-center gap-2" style="display: none;">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span><?= T::saving ?? 'Saving...' ?></span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </form>

            <!-- Information Tab -->
            <div id="information-tab" class="tab-content">
                <div class="card">
                    <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">info</span>
                        <?=T::user_information?>
                    </h2>
                    <?php if ($isEdit): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?=T::user_id?></div>
                            <div class="font-mono text-sm"><?= $user['user_id'] ?></div>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?=T::email_verified?></div>
                            <div class="flex items-center gap-2">
                                <?php if ($user['email_verified']): ?>
                                <span class="material-symbols-outlined text-green-600 text-base">check_circle</span>
                                <span class="text-green-600 text-sm"><?=T::verified?></span>
                                <?php else: ?>
                                <span class="material-symbols-outlined text-orange-600 text-base">cancel</span>
                                <span class="text-orange-600 text-sm"><?=T::not_verified?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?=T::account_status?></div>
                            <span class="px-2 py-1 rounded-full text-xs font-medium <?= $user['banned'] ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>">
                                <?= $user['banned'] ? T::banned : T::active ?>
                            </span>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?=T::login_attempts?></div>
                            <div class="text-sm"><?= $user['login_attempts'] ?? 0 ?></div>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?=T::created_at?></div>
                            <div class="text-sm"><?= date('M d, Y h:i A', strtotime($user['created_at'])) ?></div>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?=T::updated_at?></div>
                            <div class="text-sm"><?= $user['updated_at'] ? date('M d, Y h:i A', strtotime($user['updated_at'])) : T::never ?></div>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?=T::last_login?></div>
                            <div class="text-sm"><?= $user['last_login'] ? date('M d, Y h:i A', strtotime($user['last_login'])) : T::never ?></div>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <div class="text-xs text-slate-500 uppercase font-medium mb-1"><?=T::timezone?></div>
                            <div class="text-sm"><?= $user['timezone'] ?? T::utc ?></div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8 text-slate-500">
                        <span class="material-symbols-outlined text-4xl">info</span>
                        <p><?=T::user_information_will_be_available?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activity Tab -->
            <div id="activity-tab" class="tab-content">
                <?php if ($isEdit): ?>
                    <?php
                    echo crud()->table('logs_users')
                        ->col('id,type,created_at,user_ip,user_agent')
                        ->title('Activity Logs')
                        ->where(['user_id' => $user_id])
                        ->order('id', 'ASC')
                        ->actions([
                            'add' => false,
                            'view' => false,
                            'edit' => false,
                            'delete' => false,
                            'status' => false,
                            'search' => false,
                        ])
                        ->col_width('user_agent', '200px')
                        ->col_width('user_ip', '120px')
                        ->render();
                    ?>
                <?php else: ?>
                <div class="card">
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-slate-300">history</span>
                        <p class="text-slate-500 text-sm mt-2"><?=T::activity_logs_will_appear?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Bookings Tab -->
            <div id="bookings-tab" class="tab-content">
                <div class="card">
                    <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">airplane_ticket</span>
                        <?=T::bookings?>
                    </h2>
                    <?php if ($isEdit && !empty($userBookings)): ?>
                    <div class="overflow-x-auto -mx-4">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-slate-200">
                                    <th class="text-left py-2 px-4 text-xs font-medium text-slate-600 uppercase"><?=T::invoice?></th>
                                    <th class="text-left py-2 px-4 text-xs font-medium text-slate-600 uppercase"><?=T::date?></th>
                                    <th class="text-left py-2 px-4 text-xs font-medium text-slate-600 uppercase"><?=T::status?></th>
                                    <th class="text-left py-2 px-4 text-xs font-medium text-slate-600 uppercase"><?=T::payment_status?></th>
                                    <th class="text-right py-2 px-4 text-xs font-medium text-slate-600 uppercase"><?=T::amount?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userBookings as $booking): ?>
                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="py-2 px-4 font-medium text-sm"><?= $booking['invoice_id'] ?></td>
                                    <td class="py-2 px-4">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $booking['booking_status'] === 'confirmed' ? 'bg-green-100 text-green-700' : ($booking['booking_status'] === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') ?>">
                                            <?= ucfirst($booking['booking_status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-2 px-4">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $booking['payment_status'] === 'paid' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' ?>">
                                            <?= ucfirst($booking['payment_status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-slate-300">airplane_ticket</span>
                        <p class="text-slate-500 text-sm mt-2"><?= $isEdit ? T::no_bookings_found_user : T::bookings_will_appear ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Transactions Tab -->
            <div id="transactions-tab" class="tab-content">
                    <?php if ($isEdit): ?>
                    <?php
                    echo crud()->table('transactions')
                        ->col('trx_id,type,amount,currency,gateway_id,description,date')
                        ->title('Activity Logs')
                        ->where(['user_id' => $user_id])
                        ->order('id', 'ASC')
                        ->relation('gateway_id', 'payment_gateways', 'name', 'id')
                        ->actions([
                            'add' => false,
                            'view' => false,
                            'edit' => false,
                            'delete' => false,
                            'status' => false,
                            'search' => false,
                            'bulk_delete' => false,
                        ])
                        ->col_width('trx_id', '150px')
                        ->col_width('amount', '120px')
                        ->col_width('currency', '80px')
                        ->col_width('payment_gateway', '200px')
                        ->col_width('status', '100px')
                        ->col_width('date', '120px')
                        ->render();
                    ?>
                <?php else: ?>
                <div class="card">
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-slate-300">history</span>
                        <p class="text-slate-500 text-sm mt-2"><?=T::activity_logs_will_appear?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Notes Tab -->
            <div id="notes-tab" class="tab-content">
                <div class="card">
                    <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">notes</span>
                        <?=T::admin_notes?>
                    </h2>

                    <?php if ($isEdit): ?>
                    <!-- Add Note Form -->
                    <form method="POST" id="addNoteForm" class="mb-6 p-4 bg-slate-50 rounded-lg" action="<?= root ?>admin/users/notes"
                          x-data="{ loading: false, submitForm(e) { this.loading = true; return true; } }"
                          @submit="submitForm($event)">
                        <input type="hidden" name="action" value="add_note">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="user_id" value="<?= $user_id ?>">

                        <div class="form-control mb-3">
                            <label for="note_text" class="form-label required"><?=T::add_a_note?></label>
                            <textarea id="note_text" name="note_text" class="input" rows="3" placeholder="<?=T::add_a_note?>" required></textarea>
                        </div>
                        <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                            <span x-show="!loading" class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-base">add</span>
                                <?=T::add_note?>
                            </span>
                            <span x-show="loading" class="flex items-center gap-2" style="display: none;">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span><?= T::saving ?? 'Saving...' ?></span>
                            </span>
                        </button>
                    </form>

                    <!-- Notes List -->
                    <div class="space-y-4">
                        <?php if ($isEdit && !empty($userNotes)): ?>
                            <?php foreach ($userNotes as $note): ?>
                            <div class="bg-white border border-slate-200 rounded-lg p-4 shadow-sm">
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-slate-400 text-base">person</span>
                                        <span class="text-sm font-medium text-slate-700"><?= T::admin ?></span>
                                        <span class="text-xs text-slate-500">•</span>
                                        <span class="text-xs text-slate-500"><?= date('M d, Y h:i A', strtotime($note['created_at'])) ?></span>
                                    </div>
                                    <form method="POST" class="inline" action="<?= root ?>admin/users/notes"
                                          x-data="{ loading: false, submitForm(e) { if(confirm('<?=T::delete_note_confirmation?>')) { this.loading = true; return true; } return false; } }"
                                          @submit="submitForm($event)">
                                        <input type="hidden" name="action" value="delete_note">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                        <input type="hidden" name="user_id" value="<?= $user_id ?>">
                                        <button type="submit" class="text-slate-400 hover:text-red-500 transition-colors" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                                            <span x-show="!loading" class="material-symbols-outlined text-base">delete</span>
                                            <span x-show="loading" style="display: none;">
                                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            </span>
                                        </button>
                                    </form>
                                </div>
                                <div class="text-sm text-slate-700 whitespace-pre-wrap"><?= htmlspecialchars($note['note']) ?></div>

                                <?php if ($note['updated_at'] != $note['created_at']): ?>
                                <div class="mt-2 pt-2 border-t border-slate-100">
                                    <span class="text-xs text-slate-500">Updated: <?= date('M d, Y h:i A', strtotime($note['updated_at'])) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <span class="material-symbols-outlined text-4xl text-slate-300">notes</span>
                                <p class="text-slate-500 text-sm mt-2"><?=T::no_notes_found?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-4xl text-slate-300">notes</span>
                        <p class="text-slate-500 text-sm mt-2"><?=T::notes_will_appear?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Sidebar -->
        <?php if ($isEdit): ?>
        <div class="lg:col-span-4">

            <!-- Wallet Card -->
            <div class="relative overflow-hidden rounded-2xl mb-4 shadow-sm">
                <!-- Gradient Background -->
                <div class="absolute inset-0 bg-primary"></div>

                <!-- Pattern Overlay -->
                <div class="absolute inset-0 opacity-10 bg-primary"></div>

                <!-- Content -->
                <div class="relative p-6 bg-primary">
                    <!-- Header -->
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-2">
                            <div class="w-10 h-10 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-white text-xl">account_balance_wallet</span>
                            </div>
                            <div>
                                <div class="text-white/80 text-xs font-medium"><?=T::digital_wallet?></div>
                                <div class="text-white text-sm font-semibold"><?= $user['first_name'] ?? 'User' ?> <?= $user['last_name'] ?? '' ?></div>
                            </div>
                        </div>


                    </div>

                    <!-- Balance -->
                    <?php
                        // 1. Get Session Currency and Default (Base) Currency
                        $sess_curr = $_SESSION['app_currency'] ?? 'USD';
                        
                        // Get system default currency (the base for database values)
                        $def_curr_data = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
                        $base_currency = $def_curr_data['name'] ?? 'USD';
                        $base_rate = (float)($def_curr_data['rate'] ?? 1);

                        // Get Session Currency Rate
                        $sess_curr_data = $db->get('currencies', ['rate'], ['name' => $sess_curr]);
                        $sess_rate = (float)($sess_curr_data['rate'] ?? 1);

                        // 2. Convert Balance (Assume DB value is in Default Base Currency)
                        $bal_val = (float)($user['balance'] ?? 0);
                        if ($base_rate > 0) {
                            $bal_val = ($bal_val / $base_rate) * $sess_rate;
                        }

                        // 3. Convert Credits (Assume DB value is in Default Base Currency)
                        $cred_val = (float)($user['credit_limits'] ?? 0);
                        if ($base_rate > 0) {
                            $cred_val = ($cred_val / $base_rate) * $sess_rate;
                        }
                    ?>
                    <div class="mb-8">
                        <div class="flex justify-between items-bottom">
                            <div>
                                <div class="text-white/80 text-xs font-medium mb-1 tracking-wide"><?=T::available_balance?></div>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-white text-xl font-bold tracking-tight"><?= htmlspecialchars($sess_curr) ?></span>
                                    <span class="text-white text-2xl font-bold"><?= number_format($bal_val, 2) ?></span>
                                </div>
                            </div>
                             <a href="<?=root.admin?>/users/manage-funds/<?=$user['user_id']?>" class="flex items-center gap-1.5 px-3 py-1.5 bg-white/20 hover:bg-white/30 backdrop-blur-sm rounded-lg transition-all">
                                <span class="material-symbols-outlined text-white text-sm">account_balance</span>
                                <span class="text-white text-xs font-medium"><?=T::manage_funds?></span>
                            </a>
                            <?php if(($user['role'] ?? '') == 'agent'): ?>
                            <a href="<?=root.admin?>/users/api-access/<?=$user['user_id']?>" class="flex items-center gap-1.5 px-3 py-1.5 bg-white/20 hover:bg-white/30 backdrop-blur-sm rounded-lg transition-all">
                                <span class="material-symbols-outlined text-white text-sm">api</span>
                                <span class="text-white text-xs font-medium">API Access</span>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php if($user['role'] == 'agent'): ?>
                        <hr class="border my-3 border-slate-200">

                        <div class="mt-2 flex justify-between items-bottom">
                            <div>
                            <div class="text-white/80 text-xs font-medium mb-1 tracking-wide"><?=T::available?> <?=T::credits?></div>
                            <div class="flex items-baseline gap-2 justify-start">
                                <span class="text-white text-xl font-bold tracking-tight"><?= htmlspecialchars($sess_curr) ?></span>
                                <span class="text-white text-2xl font-bold"><?= number_format($cred_val, 2) ?></span>
                            </div>
                            </div>

                             <a href="<?=root.admin?>/finance/credits/<?=$user['user_id']?>" class="flex items-center gap-1.5 px-3 py-1.5 bg-white/20 hover:bg-white/30 backdrop-blur-sm rounded-lg transition-all">
                                <span class="material-symbols-outlined text-white text-sm">account_balance</span>
                                <span class="text-white text-xs font-medium"><?=T::manage?> <?=T::credits?></span>
                            </a>

                        </div>

                        <hr class="border my-3 border-slate-200">

                        <div class="backdrop-blur-sm rounded-lg flex items-center justify-start">
                            <div class="gap-1 w-full space-y-3">
                                <div>
                                    <label for="credit_days" class="text-white/80 text-xs font-medium text-left">
                                        <?= T::payment_days ?? 'Payment Days' ?>
                                    </label>
                                    <p class="text-white text-[12px] mb-2"><?= T::payment_days_description ?? 'Number of days allowed for payment after first credit usage' ?></p>
                                    <div class="relative">
                                        <select name="credit_days" id="credit_days" class="select">
                                            <?php for($i = 1; $i <= 99; $i++): ?>
                                                <?php
                                                $selected = ($user['credit_payment_days'] ?? 0) == $i ? 'selected' : '';
                                                ?>
                                                <option value="<?= $i ?>" <?= $selected ?>>
                                                    <?= $i ?> <?= T::day ?? 'day' ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="absolute inset-y-0 right-2 flex items-center pointer-events-none">
                                            <span class="material-symbols-outlined text-white/70 text-sm">expand_more</span>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label for="credit_usage_reminder_percent" class="text-white/80 text-xs font-medium text-left">
                                        Usage reminder %
                                    </label>
                                    <p class="text-white text-[12px] mb-2">Remind when used credits reach this % of total assigned (0 = off). Also reminds when payment days expire.</p>
                                    <div class="relative">
                                        <select name="credit_usage_reminder_percent" id="credit_usage_reminder_percent" class="select">
                                            <?php for ($i = 0; $i <= 100; $i++): ?>
                                                <?php $selPct = intval($user['credit_usage_reminder_percent'] ?? 0) === $i ? 'selected' : ''; ?>
                                                <option value="<?= $i ?>" <?= $selPct ?>><?= $i ?>%</option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="absolute inset-y-0 right-2 flex items-center pointer-events-none">
                                            <span class="material-symbols-outlined text-white/70 text-sm">expand_more</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="rounded-lg bg-white/10 border border-white/20 p-3 space-y-1.5 text-[12px] text-white/90">
                                    <div class="flex justify-between gap-2">
                                        <span class="text-white/70"><?= T::available ?? 'Available' ?></span>
                                        <span class="font-semibold"><?= number_format((float)($user['credit_limits'] ?? 0), 0) ?></span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="text-white/70">Assigned</span>
                                        <span class="font-semibold"><?= number_format($agentCreditAssigned ?? 0, 0) ?></span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="text-white/70"><?= T::used ?? 'Used' ?></span>
                                        <span class="font-semibold"><?= number_format($agentCreditUsed ?? 0, 0) ?></span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="text-white/70">Usage threshold</span>
                                        <span class="font-semibold" data-usage-summary-threshold data-assigned="<?= (int)($agentCreditAssigned ?? 0) ?>">
                                            <?php if (($agentCreditThreshold ?? 0) > 0): ?>
                                                <?= number_format($agentCreditThreshold, 0) ?>
                                                (<span data-usage-summary-pct><?= intval($user['credit_usage_reminder_percent'] ?? 0) ?>%</span>)
                                            <?php else: ?>
                                                Off
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="text-white/70">First usage</span>
                                        <span class="font-semibold">
                                            <?= !empty($user['first_credit_usage_date'])
                                                ? htmlspecialchars(date('d M Y', strtotime($user['first_credit_usage_date'])))
                                                : 'Not used yet' ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="text-white/70">Payment due</span>
                                        <span class="font-semibold">
                                            <?= !empty($agentCreditDueDate)
                                                ? htmlspecialchars(date('d M Y', strtotime($agentCreditDueDate)))
                                                : 'After first use' ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between gap-2 border-t border-white/20 pt-1.5 mt-1">
                                        <span class="text-white/70">Amount owed</span>
                                        <span class="font-bold"><?= number_format($agentCreditUsed ?? 0, 0) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr class="border my-3 border-slate-200">

                        <!-- Markup Configuration -->
                        <div class="backdrop-blur-sm rounded-lg" x-data="{ applyMarkup: '<?= $user['apply_markup'] ?? 'global' ?>' }">
                            
                            <!-- Markup Header -->
                            <div class="flex items-center gap-2 mb-4">
                                <div class="w-10 h-10 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                                    <span class="material-symbols-outlined text-white text-xl">price_change</span>
                                </div>
                                <div>
                                    <div class="text-white/80 text-xs font-medium"><?= T::markup_configuration ?? 'Markup Configuration' ?></div>
                                    <div class="text-white text-sm font-semibold"><?= T::configure_markup ?? 'Configure Markup' ?></div>
                                </div>
                            </div>
                            <div class="gap-1 w-full">
                                
                                <!-- Apply Markup Select -->
                                <div class="relative mb-2 space-y-1">
                                    <label for="apply_markup" class="text-white/80 text-xs font-medium mb-1">
                                        <?= T::apply_markup ?? 'Apply Markup' ?>
                                    </label>

                                    <select name="apply_markup" id="apply_markup" class="select" x-model="applyMarkup">
                                        <option value="global">Global</option>
                                        <option value="custom">Custom</option>
                                    </select>

                                    <div class="absolute inset-y-0 right-2 flex items-center pointer-events-none">
                                        <span class="material-symbols-outlined text-white/70 text-sm">expand_more</span>
                                    </div>
                                </div>


                                <!-- Custom Markup Fields (shown only when custom is selected) -->
                                <div x-show="applyMarkup === 'custom'" x-transition>
                                    <div class="grid grid-cols-2 gap-2">
                                        <!-- Markup Type -->
                                        <div>
                                            <label for="markup_type" class="text-white/80 text-xs font-medium text-left block mb-1">
                                                <?= T::markup_type ?? 'Markup Type' ?>
                                            </label>
                                            <div class="relative">
                                                <select name="markup_type" id="markup_type" class="select">
                                                    <option value="percentage" <?= ($user['markup_type'] ?? 'percentage') == 'percentage' ? 'selected' : '' ?>>
                                                        <?= T::percentage ?? 'Percentage' ?> (%)
                                                    </option>
                                                    <option value="fixed" <?= ($user['markup_type'] ?? 'percentage') == 'fixed' ? 'selected' : '' ?>>
                                                        <?= T::fixed ?? 'Fixed' ?>
                                                    </option>
                                                </select>
                                                <div class="absolute inset-y-0 right-2 flex items-center pointer-events-none">
                                                    <span class="material-symbols-outlined text-white/70 text-sm">expand_more</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Markup Value -->
                                        <div>
                                            <label for="markup_value" class="text-white/80 text-xs font-medium text-left block mb-1">
                                                <?= T::markup_value ?? 'Markup Value' ?>
                                            </label>
                                            <input 
                                                type="number" 
                                                name="markup_value" 
                                                id="markup_value" 
                                                class="input w-full" 
                                                value="<?= $user['markup_value'] ?? '0' ?>"
                                                step="0.01"
                                                min="0"
                                                placeholder="0.00">
                                        </div>
                                    </div>
                                </div>


                                <!-- Submit Button - Always visible -->
                                <div class="mt-3" x-data="{ loading: false }" @reset-markup-loading.window="loading = false">
                                    <button type="button" 
                                            id="updateMarkupBtn"
                                            class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-white/20 hover:bg-white/30 backdrop-blur-sm rounded-lg transition-all text-white text-sm font-medium"
                                            @click="loading = true; updateMarkup()"
                                            :disabled="loading"
                                            :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                                        <span x-show="!loading" class="material-symbols-outlined text-base">save</span>
                                        <span x-show="!loading"><?= T::update ?? 'Update' ?></span>
                                        <span x-show="loading" style="display: none;">
                                            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </span>
                                        <span x-show="loading" style="display: none;"><?= T::updating ?? 'Updating...' ?></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>



                    <!-- Decorative Elements -->
                    <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16"></div>
                    <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full -ml-12 -mb-12"></div>
                </div>
            </div>

            <!-- Statistics -->
            <div class="card mb-3">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200"><?=T::statistics?></h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="flex items-center gap-2 p-2 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg border border-blue-200">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm flex-shrink-0">
                            <span class="material-symbols-outlined text-blue-600 text-base">airplane_ticket</span>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs text-blue-700 font-medium"><?=T::bookings?></div>
                            <div class="text-base font-bold text-blue-900"><?= count($userBookings) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg border border-orange-200">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm flex-shrink-0">
                            <span class="material-symbols-outlined text-orange-600 text-base">history</span>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs text-orange-700 font-medium"><?=T::activities?></div>
                            <div class="text-base font-bold text-orange-900"><?= count($userLogs) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-gradient-to-r from-green-50 to-green-100 rounded-lg border border-green-200">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm flex-shrink-0">
                            <span class="material-symbols-outlined text-green-600 text-base">receipt_long</span>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs text-green-700 font-medium"><?=T::transactions?></div>
                            <div class="text-base font-bold text-green-900"><?= count($userTransactions) ?></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-2 bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg border border-purple-200">
                        <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center shadow-sm flex-shrink-0">
                            <span class="material-symbols-outlined text-purple-600 text-base">notes</span>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs text-purple-700 font-medium"><?=T::notes?></div>
                            <div class="text-base font-bold text-purple-900"><?= count($userNotes) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <!-- <div class="card mb-3">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200"><?=T::quick_actions?></h3>
                <div class="space-y-1.5">
                    <button class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-lg transition-colors">
                        <span class="material-symbols-outlined text-base">mail</span>
                        <span><?=T::send_email?></span>
                    </button>
                    <button class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium text-slate-700 bg-slate-50 hover:bg-slate-100 rounded-lg transition-colors">
                        <span class="material-symbols-outlined text-base">description</span>
                        <span><?=T::generate_invoice?></span>
                    </button>
                    <button class="w-full flex items-center gap-2 px-3 py-2 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">
                        <span class="material-symbols-outlined text-base">delete</span>
                        <span><?=T::delete_user?></span>
                    </button>
                </div>
            </div> -->

            <!-- Contact Information -->
            <!-- <div class="card">
                <h3 class="text-sm font-semibold text-slate-800 mb-2 pb-2 border-b border-slate-200"><?=T::contact_information?></h3>
                <div class="space-y-2">
                    <div class="flex items-start gap-2">
                        <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-blue-600 text-base">mail</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-xs text-slate-500 font-medium"><?=T::email?></div>
                            <a href="mailto:<?= htmlspecialchars(rawurlencode((string) $user['email']), ENT_QUOTES, 'UTF-8') ?>" class="text-sm text-blue-600 hover:text-blue-700 hover:underline break-all"><?= htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8') ?></a>
                        </div>
                    </div>
                    <?php if ($user['phone']): ?>
                    <div class="flex items-start gap-2">
                        <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-green-600 text-base">phone</span>
                        </div>
                        <div class="flex-1">
                            <div class="text-xs text-slate-500 font-medium"><?=T::phone?></div>
                            <a href="tel:<?= htmlspecialchars(rawurlencode((string) $user['phone']), ENT_QUOTES, 'UTF-8') ?>" class="text-xs text-green-600 hover:text-green-700 hover:underline"><?= htmlspecialchars((string) $user['phone'], ENT_QUOTES, 'UTF-8') ?></a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($user['address'] || $user['city'] || $user['country']): ?>
                    <div class="flex items-start gap-2">
                        <div class="w-8 h-8 bg-orange-50 rounded-lg flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-orange-600 text-base">location_on</span>
                        </div>
                        <div class="flex-1">
                            <div class="text-xs text-slate-500 font-medium"><?=T::location?></div>
                            <div class="text-xs text-slate-700">
                                <?php if ($user['address']): ?><?= htmlspecialchars((string) $user['address'], ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
                                <?php if ($user['city']): ?><?= htmlspecialchars((string) $user['city'], ENT_QUOTES, 'UTF-8') ?><?php if ($user['state']): ?>, <?= htmlspecialchars((string) $user['state'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><br><?php endif; ?>
                                <?php if ($user['country']): ?><?= htmlspecialchars((string) $user['country'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div> -->

        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.tab-content {
    display: none;
}
.tab-content.active {
    display: block !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {

    const creditDaysSelect = document.getElementById('credit_days');
    const creditDaysHidden = document.getElementById('credit_days_hidden');
    const usagePercentSelect = document.getElementById('credit_usage_reminder_percent');

    function saveCreditReminderSettings(payload) {
        const userId = '<?= $user["user_id"] ?? "" ?>';
        if (!userId) {
            vt.error('User ID not found');
            return Promise.reject(new Error('missing user'));
        }
        return fetch('<?= root ?>admin/users/update-credit-days', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(Object.assign({
                'user_id': userId,
                'csrf_token': '<?= $_SESSION['csrf_token'] ?>',
                'action': 'update_credit_days'
            }, payload))
        }).then(response => response.json());
    }

    // Credit Days AJAX Implementation
    if (creditDaysSelect) {
        creditDaysSelect.addEventListener('change', function() {
            const selectedDays = this.value;

            if (creditDaysHidden) {
                creditDaysHidden.value = selectedDays;
            }

            creditDaysSelect.disabled = true;
            saveCreditReminderSettings({
                'credit_days': selectedDays,
                'credit_usage_reminder_percent': usagePercentSelect ? usagePercentSelect.value : '0'
            })
            .then(data => {
                if (data.success) {
                    vt.success(data.message || 'Payment days updated successfully!');
                } else {
                    vt.error(data.message || 'Failed to update payment days');
                }
            })
            .catch(error => {
                console.error('Error updating credit days:', error);
                vt.error('Error updating payment days. Please try again.');
            })
            .finally(() => {
                creditDaysSelect.disabled = false;
            });
        });
    }

    // Usage reminder % AJAX (sidebar is outside main form)
    if (usagePercentSelect) {
        usagePercentSelect.addEventListener('change', function() {
            const selectedPercent = this.value;
            usagePercentSelect.disabled = true;
            saveCreditReminderSettings({
                'credit_days': creditDaysSelect ? creditDaysSelect.value : '7',
                'credit_usage_reminder_percent': selectedPercent
            })
            .then(data => {
                if (data.success) {
                    vt.success(data.message || 'Usage reminder % updated successfully!');
                    // Refresh threshold label without full reload
                    const summaryPct = document.querySelector('[data-usage-summary-pct]');
                    const summaryThreshold = document.querySelector('[data-usage-summary-threshold]');
                    if (summaryPct) summaryPct.textContent = selectedPercent + '%';
                    if (summaryThreshold) {
                        const assigned = parseInt(summaryThreshold.getAttribute('data-assigned') || '0', 10);
                        const pct = parseInt(selectedPercent, 10) || 0;
                        if (pct > 0 && assigned > 0) {
                            summaryThreshold.textContent = Math.floor(assigned * pct / 100) + ' (' + pct + '%)';
                        } else {
                            summaryThreshold.textContent = 'Off';
                        }
                    }
                } else {
                    vt.error(data.message || 'Failed to update usage reminder %');
                }
            })
            .catch(error => {
                console.error('Error updating usage reminder %:', error);
                vt.error('Error updating usage reminder %. Please try again.');
            })
            .finally(() => {
                usagePercentSelect.disabled = false;
            });
        });
    }

    // Update Markup Configuration Function
    window.updateMarkup = function() {
        const userId = '<?= $user["user_id"] ?? "" ?>';
        const applyMarkup = document.getElementById('apply_markup').value;
        const markupType = document.getElementById('markup_type').value;
        const markupValue = document.getElementById('markup_value').value;

        if (!userId) {
            vt.error('User ID not found');
            // Reset loading via custom event
            window.dispatchEvent(new CustomEvent('reset-markup-loading'));
            return;
        }

        fetch('<?= root ?>admin/users/update-markup', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                'user_id': userId,
                'apply_markup': applyMarkup,
                'markup_type': markupType,
                'markup_value': markupValue,
                'csrf_token': '<?= $_SESSION['csrf_token'] ?>',
                'action': 'update_markup'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                vt.success(data.message || 'Markup configuration updated successfully!');
            } else {
                vt.error(data.message || 'Failed to update markup configuration');
            }
            // Reset loading state
            window.dispatchEvent(new CustomEvent('reset-markup-loading'));
        })
        .catch(error => {
            console.error('Error updating markup:', error);
            vt.error('Error updating markup configuration. Please try again.');
            // Reset loading state
            window.dispatchEvent(new CustomEvent('reset-markup-loading'));
        });
    };


    // AJAX function retained for compatibility (unused by new listeners)
    function updateCreditDays(userId, creditDays) {
        return saveCreditReminderSettings({
            'credit_days': creditDays,
            'credit_usage_reminder_percent': usagePercentSelect ? usagePercentSelect.value : '0'
        });
    }

    // Tab functionality (existing code)
    const tabLinks = document.querySelectorAll('.tab-link');
    const tabContents = document.querySelectorAll('.tab-content');
    const currentTabInput = document.getElementById('current_tab');

    const hash = window.location.hash.substring(1) || 'profile';
    showTab(hash);
    updateCurrentTabInput(hash);

    tabLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const tabId = this.getAttribute('href').substring(1);
            showTab(tabId);
            updateCurrentTabInput(tabId);
            window.location.hash = tabId;
        });
    });

    window.addEventListener('hashchange', function() {
        const hash = window.location.hash.substring(1) || 'profile';
        showTab(hash);
        updateCurrentTabInput(hash);
    });

    function showTab(tabId) {
        tabContents.forEach(content => {
            content.classList.remove('active');
        });

        tabLinks.forEach(link => {
            link.classList.remove('border-blue-500', 'text-blue-600', 'bg-blue-50');
            link.classList.add('border-transparent', 'text-slate-600');
        });

        const selectedTab = document.getElementById(tabId + '-tab');
        if (selectedTab) {
            selectedTab.classList.add('active');
        }

        const selectedLink = document.querySelector(`a[href="#${tabId}"]`);
        if (selectedLink) {
            selectedLink.classList.add('border-blue-500', 'text-blue-600');
            selectedLink.classList.remove('border-transparent', 'text-slate-600');
        }
    }

    function updateCurrentTabInput(tabId) {
        if (currentTabInput) {
            currentTabInput.value = tabId;
        }
    }

    // Form submission handling
    const userForm = document.getElementById('userProfileForm');
    if (userForm) {
        userForm.addEventListener('submit', function(e) {
            const statusField = document.getElementById('status');
            if (statusField && statusField.value) {
                statusField.value = statusField.value.toLowerCase();
            }
        });
    }

    // Notes form submission with vt notifications
    const addNoteForm = document.getElementById('addNoteForm');
    if (addNoteForm) {
        addNoteForm.addEventListener('submit', function(e) {
            // Alpine.js will handle the loading state
            // vt notification will be handled by the backend response
        });
    }

    // Password field handling
    const passwordField = document.getElementById('password');
    if (passwordField) {
        passwordField.value = '';

        setTimeout(function() {
            passwordField.value = '';
        }, 100);

        passwordField.addEventListener('focus', function() {
            this.value = '';
        });
    }
});
</script>
