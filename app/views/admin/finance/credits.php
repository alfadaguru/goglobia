<?php
// GET USER_ID FROM URL PARAMETER
$user_id = ($_GET['user_id'] ?? 0);
$user = null;
$used_credits = 0;
$assigned_credits = 0;
$usage_percent = 0;
$usage_threshold = 0;
$payment_days = 0;
$first_usage_date = null;
$due_date = null;

// FETCH USER DETAILS IF USER_ID PROVIDED
if ($user_id) {
    $user = $db->get('users', [
        'user_id', 'first_name', 'last_name', 'email', 'credit_limits',
        'credit_payment_days', 'first_credit_usage_date', 'credit_usage_reminder_percent', 'role'
    ], ['user_id' => $user_id]);
    if ($user) {
        $credit_limits = $user['credit_limits'];
        $available_credit = $credit_limits;
        $payment_days = intval($user['credit_payment_days'] ?? 0);
        $first_usage_date = $user['first_credit_usage_date'] ?? null;
        $usage_percent = max(0, min(100, intval($user['credit_usage_reminder_percent'] ?? 0)));

        $used_credits = $db->sum('credits', 'credits', [
            'user_id' => $user_id,
            'type' => 'debit'
        ]) ?? 0;
        $assigned_credits = intval($db->sum('credits', 'credits', [
            'user_id' => $user_id,
            'type' => 'credit'
        ]) ?: 0);
        if ($usage_percent > 0 && $assigned_credits > 0) {
            $usage_threshold = (int) floor($assigned_credits * $usage_percent / 100);
        }
        if (!empty($first_usage_date) && $payment_days > 0) {
            try {
                $due = new DateTime($first_usage_date);
                $due->modify("+{$payment_days} days");
                $due_date = $due->format('Y-m-d');
            } catch (Throwable $e) {
                $due_date = null;
            }
        }
    } else {
        $user_id = 0;
        $user = null;
    }
}

$currency = $db->get('currencies', 'name', ['default' => '1']);
?>

<script>
    function userSearchData() {
        return {
            userSearch: '<?= $user ? htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name']) . ' - ' . $user['email']) : '' ?>',
            userResults: [],
            userSelected: <?= $user ? json_encode([
                'user_id' => $user['user_id'],
                'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                'email' => $user['email'],
                'display_text' => trim($user['first_name'] . ' ' . $user['last_name']) . ' - ' . $user['email'],
                'available_credits' => $available_credit ?? 0,
                'used_credits' => $used_credits ?? 0,
                'credit_limits' => $user['credit_limits'] ?? 0,
                'assigned_credits' => $assigned_credits ?? 0,
                'usage_percent' => $usage_percent ?? 0,
                'usage_threshold' => $usage_threshold ?? 0,
                'payment_days' => $payment_days ?? 0,
                'first_usage_date' => $first_usage_date,
                'due_date' => $due_date,
            ]) : 'null' ?>,
            userLoading: false,
            userHasSearched: false,
            currency: '<?= $currency ?>',

            get userShouldShowDropdown() {
                return this.userHasSearched && !this.userSelected && !this.userLoading && this.userResults.length > 0;
            },
            get userShowNoResults() {
                return this.userHasSearched && !this.userLoading && !this.userSelected && this.userResults.length === 0 && this.userSearch.length >= 2;
            },

            async fetchUsers() {
                const query = this.userSearch.trim();
                if (query.length < 2) {
                    this.userResults = [];
                    this.userHasSearched = false;
                    return;
                }

                this.userLoading = true;
                this.userHasSearched = false;

                try {
                    const formData = new FormData();
                    formData.append('query', query);

                    const res = await fetch('<?= root ?>admin/user-search-suggestion', {
                        method: 'POST',
                        body: formData
                    });

                    if (!res.ok) throw new Error(`HTTP ${res.status}`);

                    const data = await res.json();
                    this.userResults = Array.isArray(data) ? data : [];
                    this.userHasSearched = true;

                } catch (e) {
                    console.error('Error:', e);
                    this.userResults = [];
                    this.userHasSearched = true;
                } finally {
                    this.userLoading = false;
                }
            },

            handleUserInput() {
                if (this.userSelected) {
                    this.userSelected = null;
                }
                this.fetchUsers();
            },

            selectUser(user) {
                const credit_limits = parseFloat(user.credit_limits || user.credits_limit || 0);
                const used_credits = parseFloat(user.used_credits || 0);
                const available_credits = credit_limits;
                const assigned_credits = parseFloat(user.assigned_credits || 0);
                const usage_percent = parseInt(user.usage_percent || 0, 10);
                const usage_threshold = parseInt(user.usage_threshold || 0, 10);

                this.userSelected = {
                    user_id: user.user_id || user.id,
                    name: user.name || user.full_name || (user.first_name + ' ' + user.last_name),
                    email: user.email,
                    display_text: user.display_text || user.name || user.full_name,
                    available_credits: available_credits,
                    used_credits: used_credits,
                    credit_limits: credit_limits,
                    assigned_credits: assigned_credits,
                    usage_percent: usage_percent,
                    usage_threshold: usage_threshold,
                    payment_days: parseInt(user.payment_days || 0, 10),
                    first_usage_date: user.first_usage_date || null,
                    due_date: user.due_date || null
                };
                this.userSearch = this.userSelected.display_text;
                this.userResults = [];
                this.userHasSearched = false;
            },

            clearUser() {
                this.userSelected = null;
                this.userSearch = '';
                this.userResults = [];
                this.userHasSearched = false;
                this.$refs.userInput.focus();
            },

            loading: false,
            submitForm() {
                this.loading = true;
                return true;
            }
        };
    }
</script>

<div class="container my-4">
    <!-- SUCCESS/ERROR MESSAGES -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span
                class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="javascript:void(0)" onclick="window.history.back()"
                class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg text-white hover:text-white transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div class="border-slate-200">
                <h2 class="text-lg font-semibold text-slate-800">
                    <?= T::manage_credits ?>
                </h2>
            </div>
        </div>
    </div>

    <div class="card mb-6 overflow-visible">
        <form method="POST" action="<?= root ?>admin/finance/credits" class="p-4" x-data="userSearchData()"
            @submit="loading = true">
            <?= CSRF::tokenField() ?>

            <!-- HIDDEN FIELD FOR USER_ID -->
            <input type="hidden" name="user_id" :value="userSelected ? userSelected.user_id : '<?= $user_id ?>'">

            <div>
                <!-- MAIN CONTENT -->
                <div>
                    <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">credit_card</span>
                        <?= T::credit_details ?>
                    </h2>

                    <!-- TOP 3 FIELDS IN ONE ROW -->
                    <div class="flex flex-wrap gap-4 mb-4">
                        <!-- USER SEARCH -->
                        <div class="form-control flex-1 min-w-[200px]">
                            <label class="form-label required"><?= T::user ?></label>
                            <div class="relative">
                                <div class="input-group relative">
                                    <span class="input-icon-left material-symbols-outlined text-xl">person</span>
                                    <input x-ref="userInput" type="text" class="input-with-icon w-full"
                                        placeholder="<?= T::search_by_name_or_email ?>" x-model="userSearch"
                                        @input.debounce.300ms="handleUserInput()" @keydown.escape="userResults = []"
                                        autocomplete="off" required>

                                    <!-- CLEAR BUTTON -->
                                    <button type="button" x-show="userSelected" @click="clearUser()"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full border-2 border-slate-400 hover:bg-slate-50 flex items-center justify-center transition-all group">
                                        <span
                                            class="material-symbols-outlined text-slate-400 group-hover:text-slate-600 text-base font-light">close</span>
                                    </button>
                                </div>
                                <!-- USER SEARCH DROPDOWN RESULTS -->
                                <div x-show="userShouldShowDropdown || userShowNoResults"
                                    class="absolute left-0 right-0 z-[9999] mt-1 bg-white border border-gray-200 rounded-lg shadow-xl overflow-y-auto max-h-80"
                                    x-cloak>
                                    <div x-show="userShowNoResults" class="p-4 text-center">
                                        <div class="flex flex-col items-center gap-2">
                                            <span
                                                class="material-symbols-outlined text-gray-400 text-3xl">search_off</span>
                                            <p class="text-sm font-medium text-gray-900"><?= T::no_users_found ?></p>
                                            <p class="text-xs text-gray-600"><?= T::try_searching_by_name_email_id ?>
                                            </p>
                                        </div>
                                    </div>

                                    <template x-for="user in userResults" :key="user.user_id || user.id">
                                        <div @click="selectUser(user)"
                                            class="p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                            <div class="flex items-center gap-3">
                                                <div
                                                    class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                    <span
                                                        class="material-symbols-outlined text-blue-600 text-lg">person</span>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center justify-between gap-2 mb-1">
                                                        <span class="text-sm font-semibold text-gray-900"
                                                            x-text="user.name || user.full_name"></span>
                                                    </div>

                                                    <div class="text-xs text-gray-600 mb-1" x-text="user.email"></div>
                                                    <!-- CREDITS INFORMATION -->
                                                    <div class="flex flex-wrap gap-2 text-xs">
                                                        <span class="material-symbols-outlined text-green-600"
                                                            style="font-size: 16px;">credit_card</span>
                                                        <span class="text-green-600 font-medium text-xs"
                                                            x-text="(parseFloat(user.available_credits) || 0).toFixed(2) + ' ' + currency"></span>
                                                        <span class="text-gray-500 text-xs"><?= T::available ?></span>
                                                    </div>

                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- TRANSACTION TYPE -->
                        <div class="form-control flex-1 min-w-[200px]">
                            <label for="transaction_type" class="form-label required">
                                <?= T::transaction_type ?>
                            </label>
                            <select id="transaction_type" name="transaction_type" class="select input w-full" required>
                                <option value="credit"><?= T::credit_add_credits ?></option>
                                <option value="debit"><?= T::debit_remove_credits ?></option>
                            </select>
                        </div>

                        <!-- CREDITS WITH CURRENCY INPUT GROUP -->
                        <div class="form-control flex-1 min-w-[200px]">
                            <label for="credits" class="form-label required"><?= T::credits ?></label>
                            <div class="relative flex">
                                <!-- Currency (Left Side) -->
                                <span
                                    class="inline-flex items-center px-4 text-sm font-medium text-slate-700 bg-slate-50 border border-slate-300 rounded-l-lg border-r-0">
                                    <?= $currency ?>
                                </span>

                                <!-- Credits Input (Right Side) -->
                                <input type="number" name="credits" id="credits" class="input flex-1 rounded-l-none"
                                    style="border-top-left-radius: 0; border-bottom-left-radius: 0;" required min="1"
                                    step="1" placeholder="<?= T::e_g_100 ?>">
                            </div>

                            <!-- Hidden field to submit currency -->
                            <input type="hidden" name="currency" value="<?= $currency ?>">
                        </div>
                    </div>

                    <!-- DESCRIPTION -->
                    <div class="form-control mb-4">
                        <label for="description" class="form-label"><?= T::description ?></label>
                        <textarea name="description" id="description" class="input flex-1 rounded" rows="4"
                            placeholder="<?= T::optional_description ?>"></textarea>
                    </div>
                </div>

                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 pt-3 border-t border-slate-200">
                    <!-- USER CREDITS INFO -->
                    <div x-show="userSelected" class="flex flex-wrap items-center gap-2 text-sm" x-cloak>
                            <div class="flex items-center gap-1 bg-green-50 px-2 py-1 rounded border border-green-200">
                                <span class="material-symbols-outlined text-green-600 text-sm">credit_card</span>
                                <span class="text-green-600 font-bold text-sm me-1" x-text="(userSelected.available_credits || 0) + ' ' + currency"></span>
                                <span class="text-green-700 text-xs"><?= T::available ?></span>
                            </div>
                            <div class="flex items-center gap-1 bg-blue-50 px-2 py-1 rounded border border-blue-200">
                                <span class="material-symbols-outlined text-blue-600 text-sm">add_card</span>
                                <span class="text-blue-600 font-bold text-sm me-1" x-text="(userSelected.assigned_credits || 0) + ' ' + currency"></span>
                                <span class="text-blue-700 text-xs">Assigned</span>
                            </div>
                            <div class="flex items-center gap-1 bg-orange-50 px-2 py-1 rounded border border-orange-200">
                                <span class="material-symbols-outlined text-orange-600 text-sm">shopping_cart</span>
                                <span class="text-orange-600 font-bold text-sm me-1" x-text="(userSelected.used_credits || 0) + ' ' + currency"></span>
                                <span class="text-orange-700 text-xs"><?= T::used ?></span>
                            </div>
                            <div class="flex items-center gap-1 bg-purple-50 px-2 py-1 rounded border border-purple-200">
                                <span class="material-symbols-outlined text-purple-600 text-sm">percent</span>
                                <span class="text-purple-700 text-xs">
                                    <span x-text="(userSelected.usage_percent || 0) + '%'"></span>
                                    <span x-show="(userSelected.usage_threshold || 0) > 0" x-text="' → ' + userSelected.usage_threshold"></span>
                                    <span x-show="!(userSelected.usage_threshold > 0)"> (off)</span>
                                </span>
                            </div>
                            <div class="flex items-center gap-1 bg-slate-50 px-2 py-1 rounded border border-slate-200">
                                <span class="material-symbols-outlined text-slate-600 text-sm">event</span>
                                <span class="text-slate-700 text-xs">
                                    <span x-text="(userSelected.payment_days || 0) + ' days'"></span>
                                    <span x-show="userSelected.due_date" x-text="' · due ' + userSelected.due_date"></span>
                                    <span x-show="!userSelected.due_date"> · after first use</span>
                                </span>
                            </div>
                            <div class="flex items-center gap-1 bg-rose-50 px-2 py-1 rounded border border-rose-200">
                                <span class="material-symbols-outlined text-rose-600 text-sm">payments</span>
                                <span class="text-rose-600 font-bold text-sm me-1" x-text="(userSelected.used_credits || 0) + ' ' + currency"></span>
                                <span class="text-rose-700 text-xs">Amount owed</span>
                            </div>
                    </div>

                    <!-- BUTTONS -->
                    <div class="flex items-center gap-3" :class="userSelected ? '' : 'ml-auto'">
                        <a href="<?= root ?>admin/finance/credits" class="btn white"><?= T::cancel ?></a>
                        <button type="submit" class="btn primary flex items-center gap-2" :disabled="loading">
                            <span class="flex items-center gap-2" :class="loading ? 'hidden' : 'flex'">
                                <span class="material-symbols-outlined text-lg">save</span>
                                <span><?= T::submit_credit ?></span>
                            </span>
                            <span class="flex items-center gap-2" :class="loading ? 'flex' : 'hidden'">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                                <span><?= T::processing ?></span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- CREDITS TABLE -->
    <?php
    $creditsTable = crud()->table('credits')
        ->col('user_id,type,credits,description,created_at')
        ->title('Credits History')
        ->relation('user_id', 'users', 'first_name', 'user_id')
        ->label(['user_id' => 'User Name'])
        ->order('id', 'DESC')
        ->actions([
            'add' => false,
            'view' => false,
            'edit' => false,
            'delete' => false,
            'status' => false,
            'search' => true,
        ]);

    // Only apply where condition if user_id is provided
    if ($user_id) {
        $creditsTable->where(['user_id' => $user_id]);
    }

    echo $creditsTable->render();
    ?>
</div>