<div class="container my-4">

<!-- Success/Error Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
        <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
        <div><?= $_SESSION['message']['text'] ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<?php

echo crud()->table('deposit')
    ->col('id,user_id,status,amount,currency,payment_method,transaction_id,details,created_at')
    ->title(T::deposit_reports ?? 'Deposit Reports')
    ->perPage(50)
    ->action_urls([
        'view' => root.admin.'/reports/deposit/view/{id}'
    ])
    ->actions([
        'add' => false,
        'edit' => false,
        'status' => false,
        'view' => false,
        'delete' => false,
        'search' => true,
        'bulk_delete' => false
    ])
    ->order('id', 'DESC')
    ->label([
        'id' => T::transaction_id ?? 'ID',
        'user_id' => T::user_id ?? 'User ID',
        'amount' => T::amount ?? 'Amount',
        'currency' => T::currency ?? 'Currency',
        'payment_method' => T::payment_method ?? 'Payment Method',
        'transaction_id' => 'Trx ID',
        'status' => T::status ?? 'Status',
        'details' => T::details ?? 'Details',
        'created_at' => T::date ?? 'Date',
    ])
    ->row([
        'id' => '#{{id}}',
        'amount' => '{{currency}} <strong>{{number_format(amount, 2)}}</strong>',
        'created_at' => function ($row) {
            return date('M d, Y g:i A', strtotime($row['created_at']));
        },
        'status' => function ($row) {
            $statusClass = 'bg-gray-100 text-gray-800';
            $status = strtolower($row['status']);
            if ($status === 'pending') {
                $statusClass = 'bg-red-100 text-red-800 border-red-300 uppercase text-xs font-semibold border';
            } elseif ($status === 'approved' || $status === 'success') {
                $statusClass = 'bg-green-100 text-green-800 border-green-300 uppercase text-xs font-semibold border';
            } elseif ($status === 'rejected') {
                $statusClass = 'bg-gray-100 text-gray-800 border-gray-300 uppercase text-xs font-semibold border';
            }
            return '<span class="inline-block px-2 py-1 rounded ' . $statusClass . '">' . htmlspecialchars(ucfirst($row['status'])) . '</span>';
        }
    ])
    ->col_width('id', '100px')
    ->col_width('status', '120px')
    ->col_width('amount', '130px')
    ->col_width('created_at', '180px')
    ->render();

?>

</div>
