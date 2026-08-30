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

echo crud()->table('logs_transactions')
    // ->col('webhook_name,event,status,message,executed_at,ip_address')
    ->title(T::transactions . ' ' . T::logs)
    ->perPage(50)
    ->action_urls([
        'view' => root.admin.'/reports/transactions/view/{id}'
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
    ->order('id', 'ASC')
    ->label([
        'webhook_name' => 'Webhook',
        'event' => 'Event',
        'status' => 'Status',
        'message' => 'Message',
        'executed_at' => 'Executed At',
        'ip_address' => 'IP Address'
    ])
    ->col_width('webhook_name', '150px')
    ->col_width('status', '100px')
    ->col_width('ip_address', '140px')
    ->col_width('executed_at', '180px')
    ->render();

?>

</div>
