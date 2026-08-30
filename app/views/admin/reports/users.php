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

echo crud()->table('users')
    ->col('user_id,first_name,last_name,email,phone,role,status,balance,created_at,last_login')
    ->title('Users Reports')
    ->perPage(50)
    ->action_urls([
        'view' => root.admin.'/reports/users/view/{user_id}'
    ])
    ->actions([
        'add' => false,
        'edit' => false,
        'status' => false,
        'view' => true,
        'delete' => false,
        'search' => true,
        'bulk_delete' => false
    ])
    ->order('created_at', 'DESC')
    ->label([
        'user_id' => 'User ID',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'role' => 'Role',
        'status' => 'Status',
        'balance' => 'Balance',
        'created_at' => 'Registered At',
        'last_login' => 'Last Login'
    ])
    ->id_column('user_id')
    ->col_width('user_id', '100px')
    ->col_width('role', '100px')
    ->col_width('status', '100px')
    ->col_width('balance', '120px')
    ->col_width('created_at', '180px')
    ->col_width('last_login', '180px')
    ->row([
        'balance' => 'USD {{number_format(balance, 2)}}'
    ])
    ->render();

?>

</div>
