<?php
// Check if user_id is provided
if (!isset($user_id) || empty($user_id)) {
    echo '<div class="container my-6"><div class="alert alert-error"><span class="material-symbols-outlined">error</span><span>' . T::user_id_required . '</span></div></div>';
    return;
}

// Fetch user details
$user = $db->get('users', '*', ['user_id' => $user_id]);
if (!$user) {
    echo '<div class="container my-6"><div class="alert alert-error"><span class="material-symbols-outlined">error</span><span>' . T::user_not_found . '</span></div></div>';
    return;
}

// Fetch active payment gateways
$paymentGateways = $db->select('payment_gateways', ['id', 'name', 'currency', 'status', 'default'], [
    'status' => 1,
    'active' => 1,
    'ORDER' => ['order' => 'ASC']
]);

// Get currencies from currencies table with country names
$currencies = $db->select('currencies', [
    '[>]countries' => ['country' => 'iso']
], [
    'currencies.id',
    'currencies.name',
    'currencies.country',
    'currencies.default',
    'currencies.rate',
    'countries.nicename'
], [
    'currencies.status' => 1,
    'ORDER' => ['currencies.name' => 'ASC']
]);

// Find default currency
$defaultCurrency = 'USD';
foreach ($currencies as $currency) {
    if ($currency['default'] == '1') {
        $defaultCurrency = $currency['name'];
        break;
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
    <form method="POST" id="addFundsForm" action="<?= root ?>admin/users/process-manage-funds" enctype="multipart/form-data"
          x-data="{ loading: false, submitForm() { this.loading = true; return true; } }"
          @submit="submitForm()">
        <input type="hidden" name="action" value="add_funds">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">

        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-5">
                <a href="<?= root ?>admin/users/edit/<?= $user['user_id'] ?>" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg text-white hover:text-white transition-colors">
                    <span class="material-symbols-outlined text-xl">arrow_back</span>
                </a>
                <div>
                    <h1 class="text-1xl font-bold text-slate-800">
                        <?= T::manage_funds ?>
                    </h1>
                    <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-base">person</span>
                            <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                        </span>
                        <span>•</span>
                        <span class="flex items-center gap-1">
                            <span class="material-symbols-outlined text-base">mail</span>
                            <?= htmlspecialchars($user['email']) ?>
                        </span>
                    </div>
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

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
            <!-- Main Content -->
            <div class="lg:col-span-8">
                <div class="card">
                    <div class="border-b border-slate-200 pb-4 mb-4">
                        <h2 class="text-base font-semibold text-slate-800 mb-3 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">account_balance_wallet</span>
                            <?= T::fund_details ?>
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Amount Input -->
                            <div class="form-control">
                                <label for="amount" class="label">
                                    <span class="material-symbols-outlined text-base">payments</span>
                                    <?= T::amount ?>
                                    <span class="text-red-500">*</span>
                                </label>
                                <input type="number"
                                       id="amount"
                                       name="amount"
                                       class="input"
                                       placeholder="<?= T::enter_amount ?>"
                                       step="0.01"
                                       min="0.01"
                                       required>
                                <small class="text-slate-500 text-xs mt-1"><?= T::minimum_amount_001 ?></small>
                            </div>

                            <!-- Currency Selection -->
                            <div class="form-control">
                                <label for="currency" class="label">
                                    <span class="material-symbols-outlined text-base">currency_exchange</span>
                                    <?= T::currency ?>
                                    <span class="text-red-500">*</span>
                                </label>
                                <select id="currency" name="currency" class="select input" required>
                                    <?php foreach ($currencies as $currency): ?>
                                        <option value="<?= $currency['name'] ?>" <?= $currency['name'] == $defaultCurrency ? 'selected' : '' ?>>
                                            <?= $currency['name'] ?> - <?= htmlspecialchars($currency['nicename'] ?? $currency['country']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-slate-500 text-xs mt-1"><?= T::default_currency_is ?> <?= $defaultCurrency ?></small>
                            </div>

                            <!-- Payment Gateway Selection -->
                            <div class="form-control">
                                <label for="payment_gateway" class="label">
                                    <span class="material-symbols-outlined text-base">payment</span>
                                    <?= T::payment_method ?>
                                    <span class="text-red-500">*</span>
                                </label>
                                <select id="payment_gateway" name="payment_gateway_id" class="select input" required>
                                    <option value=""><?= T::select_payment_method ?></option>
                                    <?php foreach ($paymentGateways as $gateway): ?>
                                        <option value="<?= $gateway['id'] ?>"><?= htmlspecialchars($gateway['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-slate-500 text-xs mt-1"><?= T::select_payment_gateway_for_transaction ?></small>
                            </div>

                            <!-- Transaction Type -->
                            <div class="form-control">
                                <label for="transaction_type" class="label">
                                    <span class="material-symbols-outlined text-base">swap_horiz</span>
                                    <?= T::transaction_type ?>
                                    <span class="text-red-500">*</span>
                                </label>
                                <select id="transaction_type" name="transaction_type" class="select input" required>
                                    <option value=""><?= T::select_transaction_type ?></option>
                                    <option value="credit"><?= T::credit_add_funds ?></option>
                                    <option value="debit"><?= T::debit_remove_funds ?></option>
                                </select>
                                <small class="text-slate-500 text-xs mt-1"><?= T::choose_whether_to_add_or_remove_funds ?></small>
                            </div>

                        <!-- File Attachments Section -->
                            <div class="form-control md:col-span-2">
                                <div class="flex items-center mb-3">

                                        <span class="material-symbols-outlined text-base">attach_file</span>

                                    <h3 class="text-md font-medium text-gray-900"><?= T::attachments ?></h3>
                                </div>

                                <div class="text-center">
                                    <!-- File Preview Container - Initially hidden -->
                                    <div class="mb-3 p-3 border-2 border-dashed border-gray-200 rounded-lg bg-gray-50 min-h-32 hidden" id="file-preview-container">
                                        <div id="file-preview" class="space-y-3"></div>
                                    </div>

                                    <!-- Empty State - REMOVED completely -->

                                    <label class="btn white cursor-pointer">
                                        <span class="material-symbols-outlined mr-1 text-sm">upload</span>
                                        <?= T::choose_files ?>
                                        <input type="file"
                                            id="attachments"
                                            name="attachments[]"
                                            class="hidden"
                                            multiple
                                            accept="image/jpeg,image/png,image/gif,image/jpg,application/pdf">
                                    </label>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <?= T::multiple_files_allowed ?> (JPG, PNG, GIF, PDF) - <?= T::max_file_size_2mb ?> - <?= T::max_5_files ?>
                                    </p>
                                    <div id="file-count" class="text-xs text-blue-600 mt-1 hidden">
                                        <span id="current-file-count">0</span>/5 <?= T::files_selected ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Notes/Description -->
                            <div class="form-control md:col-span-2">
                                <label for="notes" class="label">
                                    <span class="material-symbols-outlined text-base">description</span>
                                    <?= T::notes_description ?>
                                </label>
                                <textarea id="notes"
                                          name="notes"
                                          class="input"
                                          rows="4"
                                          placeholder="<?= T::add_transaction_notes ?>"></textarea>
                                <small class="text-slate-500 text-xs mt-1"><?= T::optional_add_notes_for_this_transaction ?></small>
                            </div>

                            <!-- Reference Number -->
                            <div class="form-control md:col-span-2">
                                <label for="reference" class="label">
                                    <span class="material-symbols-outlined text-base">tag</span>
                                    <?= T::reference_number ?>
                                </label>
                                <input type="text"
                                       id="reference"
                                       name="reference"
                                       class="input"
                                       placeholder="<?= T::enter_reference_number ?>">
                                <small class="text-slate-500 text-xs mt-1"><?= T::optional_transaction_reference ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-200">
                        <a href="<?= root ?>admin/users/edit/<?= $user['user_id'] ?>" class="btn white"><?= T::cancel ?></a>
                        <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                            <span x-show="!loading" class="flex items-center gap-2">
                                <span><?= T::submit ?> <?= T::fund ?></span>
                            </span>
                            <span x-show="loading" class="flex items-center gap-2" style="display: none;">
                                <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span><?= T::processing ?? 'Processing...' ?></span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="lg:col-span-4">
                <!-- Current Wallet Balance -->
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
                                <div class="w-10 h-10 bg-white/10 backdrop-blur-sm rounded-lg flex items-center justify-center">
                                    <span class="material-symbols-outlined text-white text-xl">account_balance_wallet</span>
                                </div>
                                <div>
                                    <div class="text-white font-semibold text-sm"><?= T::current_balance ?></div>
                                    <div class="text-white/70 text-xs"><?= htmlspecialchars($user['first_name']) ?></div>
                                </div>
                            </div>
                            <div class="w-12 h-12 bg-white/10 backdrop-blur-sm rounded-full flex items-center justify-center">
                                <span class="material-symbols-outlined text-white text-2xl">wallet</span>
                            </div>
                        </div>

                        <!-- Balance -->
                        <div class="mb-6">
                            <div class="text-white/80 text-xs font-medium mb-1 tracking-wide"><?= T::available_balance ?></div>
                            <div class="flex items-baseline gap-2">
                                <span class="text-white text-3xl font-bold"><?= number_format($user['balance'] ?? 0, 2) ?></span>
                                <span class="text-white/60 text-sm"><?= $defaultCurrency ?></span>
                            </div>
                        </div>

                        <!-- Decorative Elements -->
                        <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16"></div>
                        <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full -ml-12 -mb-12"></div>
                    </div>
                </div>

                <!-- Transaction Preview -->
                <div class="card mb-3">
                    <h3 class="text-sm font-semibold text-slate-800 mb-3 pb-2 border-b border-slate-200"><?= T::transaction_preview ?></h3>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                            <span class="text-xs font-medium text-slate-600"><?= T::current_balance ?></span>
                            <span class="text-sm font-semibold text-slate-900"><?= number_format($user['balance'] ?? 0, 2) ?> <?= $defaultCurrency ?></span>
                        </div>

                        <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg" id="transaction-amount-preview">
                            <span class="text-xs font-medium text-slate-600"><?= T::transaction_amount ?></span>
                            <span class="text-sm font-semibold text-blue-700">0.00 <?= $defaultCurrency ?></span>
                        </div>

                        <div class="border-t border-slate-200 pt-3"></div>

                        <div class="flex items-center justify-between p-3 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg border border-blue-200" id="new-balance-preview">
                            <span class="text-xs font-semibold text-slate-700"><?= T::new_balance ?></span>
                            <span class="text-base font-bold text-blue-700"><?= number_format($user['balance'] ?? 0, 2) ?> <?= $defaultCurrency ?></span>
                        </div>
                    </div>
                </div>

                <!-- User Information -->
                <div class="card">
                    <h3 class="text-sm font-semibold text-slate-800 mb-3 pb-2 border-b border-slate-200"><?= T::user_information ?></h3>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2">
                            <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-blue-600 text-base">person</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs text-slate-500"><?= T::full_name ?></div>
                                <div class="text-sm font-medium text-slate-900 truncate"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-green-600 text-base">mail</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs text-slate-500"><?= T::email ?></div>
                                <div class="text-sm font-medium text-slate-900 truncate"><?= htmlspecialchars($user['email']) ?></div>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <div class="w-8 h-8 bg-purple-50 rounded-lg flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-purple-600 text-base">badge</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs text-slate-500"><?= T::user_id ?></div>
                                <div class="text-sm font-medium text-slate-900 truncate font-mono"><?= htmlspecialchars($user['user_id']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
/* Remove spinner from number input */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

input[type=number] {
    -moz-appearance: textfield;
    appearance: textfield;
}
</style>

<script>
// Exchange rates and user balance
const exchangeRates = <?= json_encode(array_column(array_map(function($c) { return [$c['name'], (float)$c['rate']]; }, $currencies), 1, 0)) ?>;
const userBalance = <?= floatval($user['balance'] ?? 0) ?>;
const defaultCurrency = '<?= $defaultCurrency ?>';

// File upload preview and validation with image preview
let selectedFiles = [];
const MAX_FILES = 5;
const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2MB in bytes

// Function to create file preview with actual image preview for images
function createFilePreview(file, index) {
    const previewItem = document.createElement('div');
    previewItem.className = 'flex items-center justify-between p-3 bg-white rounded-lg border border-gray-200';
    previewItem.setAttribute('data-file-index', index);

    let previewContent = '';

    // Check if file is an image
    if (file.type.startsWith('image/')) {
        // Create actual image preview
        previewContent = `
            <div class="flex items-center gap-3 flex-1">
                <div class="w-16 h-16 bg-blue-50 rounded-lg flex items-center justify-center overflow-hidden border border-gray-200">
                    <img src="${URL.createObjectURL(file)}" alt="${file.name}" class="w-full h-full object-cover">
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900 truncate">${file.name}</div>
                    <div class="text-xs text-gray-500">${(file.size / 1024 / 1024).toFixed(2)} MB</div>
                    <div class="text-xs text-blue-600 mt-1">Image Preview</div>
                </div>
            </div>
            <button type="button" class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-300 text-gray-500 hover:text-red-600 hover:border-red-300 transition-colors" onclick="removeFile(${index})">
                <span class="material-symbols-outlined text-base">close</span>
            </button>
        `;
    } else {
        // For non-image files, show icon based on type
        let fileIcon = '';
        let iconBg = '';
        if (file.type === 'application/pdf') {
            fileIcon = 'picture_as_pdf';
            iconBg = 'bg-red-100';
        } else {
            fileIcon = 'description';
            iconBg = 'bg-gray-100';
        }

        previewContent = `
            <div class="flex items-center gap-3 flex-1">
                <div class="w-10 h-10 ${iconBg} rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-lg ${file.type === 'application/pdf' ? 'text-red-600' : 'text-gray-600'}">${fileIcon}</span>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-gray-900 truncate">${file.name}</div>
                    <div class="text-xs text-gray-500">${(file.size / 1024 / 1024).toFixed(2)} MB</div>
                </div>
            </div>
            <button type="button" class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-300 text-gray-500 hover:text-red-600 hover:border-red-300 transition-colors" onclick="removeFile(${index})">
                <span class="material-symbols-outlined text-base">close</span>
            </button>
        `;
    }

    previewItem.innerHTML = previewContent;
    return previewItem;
}

// Update file count display
function updateFileCount() {
    const fileCountElement = document.getElementById('file-count');
    const currentCountElement = document.getElementById('current-file-count');

    if (selectedFiles.length > 0) {
        fileCountElement.classList.remove('hidden');
        currentCountElement.textContent = selectedFiles.length;

        // Change color if limit reached
        if (selectedFiles.length >= MAX_FILES) {
            fileCountElement.classList.add('text-red-600');
            fileCountElement.classList.remove('text-blue-600');
        } else {
            fileCountElement.classList.add('text-blue-600');
            fileCountElement.classList.remove('text-red-600');
        }
    } else {
        fileCountElement.classList.add('hidden');
    }
}

// File input change handler
document.getElementById('attachments').addEventListener('change', function(e) {
    const previewContainer = document.getElementById('file-preview');
    const filePreviewContainer = document.getElementById('file-preview-container');
    const files = e.target.files;

    // Check if adding new files would exceed limit
    if (selectedFiles.length + files.length > MAX_FILES) {
        alert(`<?= T::max_files_limit_reached ?> ${MAX_FILES} <?= T::files_allowed ?>`);
        this.value = '';
        return;
    }

    let hasInvalidFile = false;

    for (let i = 0; i < files.length; i++) {
        const file = files[i];

        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'image/jpg'];
        if (!allowedTypes.includes(file.type)) {
            alert('Invalid file type: ' + file.name + '. Only JPG, PNG, GIF, and PDF files are allowed.');
            hasInvalidFile = true;
            continue;
        }

        // Validate file size (2MB max)
        if (file.size > MAX_FILE_SIZE) {
            alert('File too large: ' + file.name + '. Maximum size is 2MB.');
            hasInvalidFile = true;
            continue;
        }

        // Add to selected files
        selectedFiles.push(file);

        // Create preview with actual image preview for images
        const previewItem = createFilePreview(file, selectedFiles.length - 1);
        previewContainer.appendChild(previewItem);
    }

    // Show preview container if files are selected
    if (selectedFiles.length > 0) {
        filePreviewContainer.classList.remove('hidden');
    }

    // Update file count
    updateFileCount();

    // Disable file input if limit reached
    if (selectedFiles.length >= MAX_FILES) {
        this.disabled = true;
        this.nextElementSibling.classList.add('opacity-50', 'cursor-not-allowed');
    }

    // DON'T reset file input - let it keep the files for form submission
    // this.value = ''; // COMMENT THIS LINE
});

// Remove file function
function removeFile(index) {
    // Remove from selected files array
    selectedFiles.splice(index, 1);

    // Refresh preview
    refreshFilePreview();

    // Enable file input if under limit
    const fileInput = document.getElementById('attachments');
    if (selectedFiles.length < MAX_FILES) {
        fileInput.disabled = false;
        fileInput.nextElementSibling.classList.remove('opacity-50', 'cursor-not-allowed');
    }

    // Update the actual file input
    updateActualFileInput();
}

// Update the actual file input with remaining files
function updateActualFileInput() {
    const fileInput = document.getElementById('attachments');
    const newFileList = new DataTransfer();

    selectedFiles.forEach(file => {
        newFileList.items.add(file);
    });

    fileInput.files = newFileList.files;
}

// Refresh file preview with actual image previews
function refreshFilePreview() {
    const previewContainer = document.getElementById('file-preview');
    const filePreviewContainer = document.getElementById('file-preview-container');
    previewContainer.innerHTML = '';

    if (selectedFiles.length === 0) {
        filePreviewContainer.classList.add('hidden');
        updateFileCount();
        return;
    }

    filePreviewContainer.classList.remove('hidden');

    selectedFiles.forEach((file, index) => {
        const previewItem = createFilePreview(file, index);
        previewContainer.appendChild(previewItem);
    });

    // Update file count
    updateFileCount();
}

// Update preview function
function updatePreview() {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const type = document.getElementById('transaction_type').value;
    const currency = document.getElementById('currency').value;

    const rate = exchangeRates[currency] || 1;
    const convertedAmount = amount / rate;

    let newBalance = userBalance;
    let transactionDisplay = '0.00 ' + defaultCurrency;
    let previewClass = 'bg-blue-50';
    let textClass = 'text-blue-700';

    if (amount > 0 && type) {
        if (type === 'credit') {
            newBalance = userBalance + convertedAmount;
            transactionDisplay = '+' + convertedAmount.toFixed(2) + ' ' + defaultCurrency;
            previewClass = 'bg-green-50';
            textClass = 'text-green-700';
        } else if (type === 'debit') {
            newBalance = Math.max(0, userBalance - convertedAmount);
            transactionDisplay = '-' + convertedAmount.toFixed(2) + ' ' + defaultCurrency;
            previewClass = 'bg-red-50';
            textClass = 'text-red-700';
        }
    }

    // Update transaction amount preview
    const transactionPreview = document.getElementById('transaction-amount-preview');
    transactionPreview.className = 'flex items-center justify-between p-3 rounded-lg ' + previewClass;
    transactionPreview.querySelector('span:last-child').textContent = transactionDisplay;
    transactionPreview.querySelector('span:last-child').className = 'text-sm font-semibold ' + textClass;

    // Update new balance preview
    document.getElementById('new-balance-preview').querySelector('span:last-child').textContent = newBalance.toFixed(2) + ' ' + defaultCurrency;
}

// Form submission with confirmation
document.getElementById('addFundsForm').addEventListener('submit', function(e) {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const type = document.getElementById('transaction_type').value;
    const currency = document.getElementById('currency').value;

    if (!amount || amount <= 0) {
        alert('<?= T::please_enter_valid_amount ?>');
        e.preventDefault();
        return;
    }

    if (!type) {
        alert('<?= T::please_select_transaction_type ?>');
        e.preventDefault();
        return;
    }

    // Validate total files size (max 10MB for all files)
    let totalSize = 0;
    for (let i = 0; i < selectedFiles.length; i++) {
        totalSize += selectedFiles[i].size;
    }

    if (totalSize > 10 * 1024 * 1024) {
        alert('Total files size exceeds 10MB limit');
        e.preventDefault();
        return;
    }

    const rate = exchangeRates[currency] || 1;
    const convertedAmount = amount / rate;
    const actionText = type === 'credit' ? '<?= T::credit_add_funds ?>' : '<?= T::debit_remove_funds ?>';

    let confirmMsg = `${actionText}: ${convertedAmount.toFixed(2)}`;
    if (currency !== defaultCurrency) {
        confirmMsg += ` (${amount} ${currency} @ rate ${rate})`;
    }

    if (selectedFiles.length > 0) {
        confirmMsg += `\n\nFiles to upload: ${selectedFiles.length}`;
    }

    confirmMsg += `\n\n<?= T::confirm_transaction ?>?`;

    if (!confirm(confirmMsg)) {
        e.preventDefault();
        return;
    }

    // Add hidden fields for processing
    const hiddenFields = [
        { name: 'converted_amount', value: convertedAmount.toFixed(2) },
        { name: 'exchange_rate', value: rate.toFixed(4) },
        { name: 'final_amount', value: convertedAmount.toFixed(2) }
    ];

    hiddenFields.forEach(field => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = field.name;
        input.value = field.value;
        this.appendChild(input);
    });

    // Ensure files are properly updated before submission
    updateActualFileInput();
});

// Event listeners for real-time preview
document.getElementById('amount').addEventListener('input', updatePreview);
document.getElementById('transaction_type').addEventListener('change', updatePreview);
document.getElementById('currency').addEventListener('change', updatePreview);

// Run preview on page load
updatePreview();
</script>