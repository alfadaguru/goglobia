<?php
// ============================================================================
// SUPPLIER PAYOUT SETTINGS ROUTES
// Suppliers configure bank details for payout processing
// ============================================================================

@$SECURE or die('Access Denied!');

// ============================================================================
// GET /supplier/settings/payout
// Show payout settings form
// ============================================================================
$router->get('/supplier/settings/payout', function () use ($SECURE, $db) {
    
    SUPPLIER_OR_STAFF_AUTH($db);
    
    $userId = $_SESSION['user_id'] ?? null;
    
    // Get user's current payout settings
    $user = $db->get('users', [
        'payout_bank_code',
        'payout_account_number',
        'payout_account_name',
    ], ['user_id' => $userId]);
    
    $bankCode = $user['payout_bank_code'] ?? '';
    $accountNumber = $user['payout_account_number'] ?? '';
    $accountName = $user['payout_account_name'] ?? '';
    
    // Get list of banks from Paystack (should be cached)
    $banks = [];
    $banksJson = $db->get('settings', ['paystack_banks'], ['id' => 1]);
    if ($banksJson && !empty($banksJson['paystack_banks'])) {
        $banks = json_decode($banksJson['paystack_banks'], true) ?: [];
    }
    
    $title = 'Payout Settings';
    $description = 'Configure your bank account for payouts';
    $header = true;
    $footer = true;
    
    require_once views . "includes/header.php";
    ?>
    
    <div class="max-w-2xl mx-auto px-4 py-8">
        
        <h1 class="text-2xl font-bold text-gray-900 mb-6">Payout Settings</h1>
        
        <div class="alert-info mb-6">
            <p class="text-sm">Configure your bank account details. Your payout requests will be transferred here.</p>
        </div>
        
        <form action="<?= root ?>supplier/settings/payout" method="POST" class="card p-6 space-y-4">
            <?= CSRF::tokenField() ?>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Bank</label>
                <select name="payout_bank_code" class="input w-full" required>
                    <option value="">-- Select your bank --</option>
                    <?php foreach ($banks as $bank): ?>
                    <option value="<?= htmlspecialchars($bank['code']) ?>" 
                            <?= $bankCode === $bank['code'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($bank['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">Select your bank from the list. Banks are Paystack-provided.</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Account Number</label>
                <input type="text" name="payout_account_number" class="input w-full" 
                       value="<?= htmlspecialchars($accountNumber) ?>" 
                       placeholder="0123456789" 
                       pattern="[0-9]+" 
                       required>
                <p class="text-xs text-gray-500 mt-1">Your bank account number. Must be valid for your bank.</p>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Account Name</label>
                <input type="text" name="payout_account_name" class="input w-full" 
                       value="<?= htmlspecialchars($accountName) ?>" 
                       placeholder="Your Full Name" 
                       required>
                <p class="text-xs text-gray-500 mt-1">Account holder name as it appears at your bank.</p>
            </div>
            
            <div class="pt-4">
                <button type="submit" class="btn primary">Save Payout Settings</button>
            </div>
        </form>
        
        <div class="card p-6 mt-6 space-y-3">
            <h2 class="text-sm font-semibold text-gray-800">How payouts work</h2>
            <ol class="text-sm text-gray-600 space-y-2 list-decimal ml-4">
                <li>Guest completes booking and payment</li>
                <li>Your payout request is created automatically</li>
                <li>Admin reviews and approves your payout</li>
                <li>Once approved, funds are transferred to your bank account</li>
                <li>You'll receive an email when the transfer is initiated</li>
            </ol>
        </div>
        
    </div>
    
    <?php
    require_once views . "includes/footer.php";
});

// ============================================================================
// POST /supplier/settings/payout
// Save payout settings
// ============================================================================
$router->post('/supplier/settings/payout', function () use ($SECURE, $db) {
    
    SUPPLIER_OR_STAFF_AUTH($db);
    
    $userId = $_SESSION['user_id'] ?? null;
    
    try {
        $bankCode = trim((string) ($_POST['payout_bank_code'] ?? ''));
        $accountNumber = trim((string) ($_POST['payout_account_number'] ?? ''));
        $accountName = trim((string) ($_POST['payout_account_name'] ?? ''));
        
        // Validate
        if (!$bankCode || !$accountNumber || !$accountName) {
            throw new Exception('All fields are required');
        }
        
        // Validate bank code exists
        $banksJson = $db->get('settings', ['paystack_banks'], ['id' => 1]);
        $banks = $banksJson && !empty($banksJson['paystack_banks']) 
            ? json_decode($banksJson['paystack_banks'], true) 
            : [];
        
        $validBank = false;
        foreach ($banks as $bank) {
            if ($bank['code'] === $bankCode) {
                $validBank = true;
                break;
            }
        }
        
        if (!$validBank) {
            throw new Exception('Invalid bank selected');
        }
        
        // Validate account number (numbers only)
        if (!preg_match('/^[0-9]{10,}$/', $accountNumber)) {
            throw new Exception('Account number must be at least 10 digits');
        }
        
        // Validate account name length
        if (strlen($accountName) > 191) {
            throw new Exception('Account name is too long');
        }
        
        // Update user record
        $db->update('users', [
            'payout_bank_code' => $bankCode,
            'payout_account_number' => $accountNumber,
            'payout_account_name' => $accountName,
        ], ['user_id' => $userId]);
        
        $_SESSION['message'] = [
            'type' => 'success', 
            'text' => 'Payout settings saved successfully'
        ];
        
        header('Location: ' . root . 'supplier/settings/payout');
        exit;
        
    } catch (\Throwable $e) {
        error_log('Payout settings save error: ' . $e->getMessage());
        $_SESSION['message'] = [
            'type' => 'error', 
            'text' => 'Error: ' . $e->getMessage()
        ];
        
        header('Location: ' . root . 'supplier/settings/payout');
        exit;
    }
});

