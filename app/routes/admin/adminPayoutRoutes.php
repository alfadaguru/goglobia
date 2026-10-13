<?php
// ============================================================================
// ADMIN PAYOUT MANAGEMENT ROUTES
// Admin approves/rejects supplier payout requests
// ============================================================================

@$SECURE or die('Access Denied!');

// ============================================================================
// GET /admin/payouts
// List pending payout requests
// ============================================================================
$router->get('/admin/payouts', function () use ($SECURE, $db) {
    
    ADMIN_AUTH($db);
    
    $status = trim((string) ($_GET['status'] ?? 'requested'));
    $validStatuses = ['requested', 'approved', 'processing', 'paid', 'failed', 'rejected', 'cancelled'];
    
    if (!in_array($status, $validStatuses)) {
        $status = 'requested';
    }
    
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    
    // Get payouts with status filter
    $payouts = $db->select('supplier_payouts', [
        '[>]users' => ['owner_user_id' => 'user_id'],
        '[>]organizations' => ['org_id' => 'id'],
    ], [
        'supplier_payouts.id',
        'supplier_payouts.reference',
        'supplier_payouts.amount',
        'supplier_payouts.currency',
        'supplier_payouts.state',
        'supplier_payouts.requested_at',
        'supplier_payouts.decided_at',
        'supplier_payouts.paid_at',
        'users.first_name(user_name)',
        'organizations.name(org_name)',
    ], [
        'supplier_payouts.state' => $status,
        'ORDER' => ['supplier_payouts.requested_at' => 'DESC'],
        'LIMIT' => [$offset, $perPage],
    ]) ?: [];
    
    $total = $db->count('supplier_payouts', ['state' => $status]);
    $totalPages = ceil($total / $perPage);
    
    $title = 'Payout Requests';
    $description = 'Manage supplier payout approvals';
    $header = true;
    $footer = true;
    
    require_once views . "includes/header.php";
    ?>
    
    <div class="max-w-6xl mx-auto px-4 py-8">
        
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Payout Requests</h1>
        </div>
        
        <!-- STATUS FILTER TABS -->
        <div class="flex gap-2 mb-6 border-b border-gray-200">
            <?php foreach ($validStatuses as $st): ?>
            <a href="<?= root ?>admin/payouts?status=<?= $st ?>" 
               class="px-4 py-2 text-sm font-medium <?= $status === $st 
                   ? 'text-blue-600 border-b-2 border-blue-600' 
                   : 'text-gray-600 hover:text-gray-900' ?>">
                <?= ucfirst($st) ?>
            </a>
            <?php endforeach; ?>
        </div>
        
        <!-- PAYOUTS TABLE -->
        <?php if (count($payouts) > 0): ?>
        
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Reference</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Supplier</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Amount</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Requested</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payouts as $payout): ?>
                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <code class="text-xs bg-gray-100 px-2 py-1 rounded">
                                <?= htmlspecialchars($payout['reference']) ?>
                            </code>
                        </td>
                        <td class="px-4 py-3">
                            <?= htmlspecialchars($payout['org_name'] ?? 'Unknown') ?><br>
                            <span class="text-xs text-gray-500"><?= htmlspecialchars($payout['user_name'] ?? '') ?></span>
                        </td>
                        <td class="px-4 py-3 font-mono">
                            <?= htmlspecialchars($payout['currency']) ?> <?= number_format($payout['amount'], 2) ?>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            <?= $payout['requested_at'] ? date('M d, Y H:i', strtotime($payout['requested_at'])) : '—' ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block px-2 py-1 text-xs rounded font-medium
                                <?= $payout['state'] === 'requested' ? 'bg-yellow-100 text-yellow-800' : '' ?>
                                <?= $payout['state'] === 'approved' ? 'bg-blue-100 text-blue-800' : '' ?>
                                <?= $payout['state'] === 'processing' ? 'bg-blue-100 text-blue-800' : '' ?>
                                <?= $payout['state'] === 'paid' ? 'bg-green-100 text-green-800' : '' ?>
                                <?= $payout['state'] === 'failed' ? 'bg-red-100 text-red-800' : '' ?>
                                <?= $payout['state'] === 'rejected' ? 'bg-red-100 text-red-800' : '' ?>">
                                <?= ucfirst($payout['state']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <a href="<?= root ?>admin/payout/<?= (int) $payout['id'] ?>" 
                               class="text-blue-600 hover:underline text-sm">
                                Review
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>
        <div class="mt-4 flex gap-2 justify-center">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= root ?>admin/payouts?status=<?= $status ?>&page=<?= $p ?>" 
               class="px-3 py-1 text-sm <?= $p === $page 
                   ? 'bg-blue-600 text-white rounded' 
                   : 'border border-gray-300 rounded hover:bg-gray-50' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="card p-6 text-center text-gray-500">
            <p>No payout requests with status: <strong><?= ucfirst($status) ?></strong></p>
        </div>
        <?php endif; ?>
        
    </div>
    
    <?php
    require_once views . "includes/footer.php";
});

// ============================================================================
// GET /admin/payout/{id}
// Review single payout request
// ============================================================================
$router->get('/admin/payout/([0-9]+)', function ($id) use ($SECURE, $db) {
    
    ADMIN_AUTH($db);
    
    $id = (int) $id;
    
    $payout = $db->get('supplier_payouts', [
        '[>]users' => ['owner_user_id' => 'user_id'],
        '[>]organizations' => ['org_id' => 'id'],
    ], [
        'supplier_payouts.*',
        'users.first_name(user_name)',
        'users.email(user_email)',
        'organizations.name(org_name)',
    ], ['supplier_payouts.id' => $id]);
    
    if (!$payout) {
        http_response_code(404);
        echo 'Payout not found';
        exit;
    }
    
    $title = 'Payout Review';
    $description = 'Review and approve supplier payout';
    $header = true;
    $footer = true;
    
    require_once views . "includes/header.php";
    ?>
    
    <div class="max-w-2xl mx-auto px-4 py-8">
        
        <h1 class="text-2xl font-bold text-gray-900 mb-6">Payout Review</h1>
        
        <div class="card p-6 space-y-6">
            
            <!-- PAYOUT DETAILS -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs text-gray-500">Reference</p>
                    <p class="text-lg font-mono font-semibold"><?= htmlspecialchars($payout['reference']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Status</p>
                    <p class="text-lg font-semibold">
                        <span class="inline-block px-2 py-1 text-sm rounded
                            <?= $payout['state'] === 'requested' ? 'bg-yellow-100 text-yellow-800' : '' ?>
                            <?= $payout['state'] === 'approved' ? 'bg-blue-100 text-blue-800' : '' ?>">
                            <?= ucfirst($payout['state']) ?>
                        </span>
                    </p>
                </div>
            </div>
            
            <!-- SUPPLIER INFO -->
            <div>
                <h2 class="text-sm font-semibold text-gray-800 mb-2">Supplier</h2>
                <p class="text-base"><?= htmlspecialchars($payout['org_name'] ?? 'Unknown') ?></p>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($payout['user_name'] ?? '') ?></p>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($payout['user_email'] ?? '') ?></p>
            </div>
            
            <!-- AMOUNT -->
            <div class="bg-blue-50 p-4 rounded border border-blue-200">
                <p class="text-xs text-gray-600 mb-1">Payout Amount</p>
                <p class="text-3xl font-bold text-blue-600">
                    <?= htmlspecialchars($payout['currency']) ?> <?= number_format($payout['amount'], 2) ?>
                </p>
            </div>
            
            <!-- BANK DETAILS -->
            <div>
                <h2 class="text-sm font-semibold text-gray-800 mb-2">Bank Details</h2>
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Bank Code:</dt>
                        <dd class="font-mono"><?= htmlspecialchars($payout['bank_code'] ?? '') ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Account:</dt>
                        <dd class="font-mono"><?= htmlspecialchars($payout['account_number'] ?? '') ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Name:</dt>
                        <dd><?= htmlspecialchars($payout['account_name'] ?? '') ?></dd>
                    </div>
                </dl>
            </div>
            
            <!-- ACTIONS -->
            <?php if ($payout['state'] === 'requested'): ?>
            <div class="flex gap-3 pt-4 border-t border-gray-200">
                <form action="<?= root ?>admin/payout/<?= (int) $payout['id'] ?>/approve" method="POST" class="flex-1">
                    <?= CSRF::tokenField() ?>
                    <button type="submit" class="btn primary w-full">Approve Payout</button>
                </form>
                <form action="<?= root ?>admin/payout/<?= (int) $payout['id'] ?>/reject" method="POST" class="flex-1">
                    <?= CSRF::tokenField() ?>
                    <textarea name="reason" class="hidden"></textarea>
                    <button type="submit" class="btn secondary w-full" onclick="this.form.reason.value = prompt('Rejection reason:', ''); return this.form.reason.value !== '';">Reject</button>
                </form>
            </div>
            <?php endif; ?>
            
        </div>
        
    </div>
    
    <?php
    require_once views . "includes/footer.php";
});

// ============================================================================
// POST /admin/payout/{id}/approve
// Admin approves payout
// ============================================================================
$router->post('/admin/payout/([0-9]+)/approve', function ($id) use ($SECURE, $db) {
    
    ADMIN_AUTH($db);
    
    $id = (int) $id;
    $adminId = $_SESSION['user_id'] ?? null;
    
    try {
        $payout = $db->get('supplier_payouts', ['id'], ['id' => $id, 'state' => 'requested']);
        if (!$payout) {
            throw new Exception('Payout not found or already processed');
        }
        
        // Update payout to approved
        $db->update('supplier_payouts', [
            'state' => 'approved',
            'decided_by' => $adminId,
            'decided_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Payout approved'];
        
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    
    header('Location: ' . root . 'admin/payout/' . $id);
    exit;
});

// ============================================================================
// POST /admin/payout/{id}/reject
// Admin rejects payout
// ============================================================================
$router->post('/admin/payout/([0-9]+)/reject', function ($id) use ($SECURE, $db) {
    
    ADMIN_AUTH($db);
    
    $id = (int) $id;
    $adminId = $_SESSION['user_id'] ?? null;
    $reason = trim((string) ($_POST['reason'] ?? ''));
    
    try {
        $payout = $db->get('supplier_payouts', ['id'], ['id' => $id, 'state' => 'requested']);
        if (!$payout) {
            throw new Exception('Payout not found or already processed');
        }
        
        // Update payout to rejected
        $db->update('supplier_payouts', [
            'state' => 'rejected',
            'failure_reason' => $reason,
            'decided_by' => $adminId,
            'decided_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Payout rejected'];
        
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    
    header('Location: ' . root . 'admin/payout/' . $id);
    exit;
});

