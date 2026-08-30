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

echo crud()->table('logs_bookings')
    ->col('hash,data,created_at')
    ->title('Booking Logs')
    ->perPage(50)
    ->action_urls([
        'view' => root.admin.'/reports/booking-logs/view/{id}'
    ])
    ->actions([
        'add' => false,
        'edit' => false,
        'status' => false,
        'view' => true,
        'delete' => true,
        'search' => true,
        'bulk_delete' => true
    ])
    ->order('created_at', 'DESC')
    ->label([
        'hash' => 'Booking Hash',
        'data' => 'Booking Data',
        'created_at' => 'Created At'
    ])
    ->col_width('hash', '150px')
    ->col_width('created_at', '180px')
    ->render();

?>

</div>
