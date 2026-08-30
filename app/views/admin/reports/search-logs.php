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

echo crud()->table('logs_searches')
    ->col('user_id,module,request,ip,created_at')
    ->title('Search Logs')
    ->perPage(50)
    ->action_urls([
        'view' => root.admin.'/reports/search-logs/view/{id}'
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
        'user_id' => 'User ID',
        'module' => 'Module',
        'request' => 'Search Request',
        'ip' => 'IP Address',
        'created_at' => 'Created At'
    ])
    ->col_width('user_id', '100px')
    ->col_width('module', '120px')
    ->col_width('ip', '140px')
    ->col_width('created_at', '180px')
    ->render();

?>

</div>
