<?php
// GET USER_ID FROM URL PARAMETER
$user_id = ($_GET['user_id'] ?? 0);
$user = null;

// FETCH USER DETAILS IF USER_ID PROVIDED
if ($user_id) {
    $user = $db->get('users', ['user_id', 'first_name', 'last_name', 'email'], ['user_id' => $user_id]);
    if (!$user) {
        $user_id = 0;
        $user = null;
    }
}
?>

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
                    <?= T::transactions_management ?>
                </h2>
                <?php if ($user): ?>
                    <p class="text-sm text-slate-600 mt-1">
                        <?= T::for_user ?>: <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                        (<?= htmlspecialchars($user['email']) ?>)
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TRANSACTIONS TABLE -->
    <?php
    // Set conditions based on whether user_id is provided
    if (empty($user_id) || $user_id == 0) {
        $condition = [];
    } else {
        $condition = ['user_id' => $user_id];
    }

    echo crud()->table('transactions')
        ->col('trx_id,client_email,type,amount,description,gateway_id,created_at')
        ->where($condition)
        ->title($user_id ? T::user_transaction_history : T::all_transactions)
        ->relation('gateway_id', 'payment_gateways', 'name', 'id')
        ->label([
            'trx_id' => T::transaction_id,
            'type' => T::type,
            'amount' => T::amount,
            'description' => T::description,
            'payment_gateway' => T::payment_gateway,
            'date' => T::transaction_date,
            'gateway_id' => T::payment_gateway,
            'client_email' => T::user,
        ])
        ->order('id', 'ASC')

        // ->row(['client_email' => '<strong>{{client_email}}</strong>'])
    
        ->row([
            'client_email' => function ($row) use ($db) {
                $user = $db->get('users', '*', ['email' => $row['client_email']]);
                if (!empty($user)) {
                    return $user['first_name'] . ' ' . $user['last_name'] . ' <br /> ' . htmlspecialchars($user['email']) . '';
                } else {
                    return htmlspecialchars($row['client_email'] ?? 'N/A');
                }
            },
            'amount' => function ($row) use ($db) {
                $val = $db->get('transactions', '*', ['id' => $row['id']]);
                return '<strong>' . number_format($val['amount'], 2) . ' ' . htmlspecialchars($val['currency'] ?? '') . '</strong>';
            }
        ])



        ->order('id', 'ASC')
        ->actions([
            'add' => false,
            'view' => false,
            'edit' => false,
            'delete' => false,
            'status' => false,
            'search' => true,
            'bulk_delete' => false,
        ])
        ->render();
    ?>
</div>