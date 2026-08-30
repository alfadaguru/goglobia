<div class="container my-4">

<?php

echo crud()->table('users')
    ->col('id,first_name,last_name,email,role,balance')
    ->label([
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'email' => 'Email',
        'role' => 'Role'])
    ->title('Users')
    ->row(['balance' => '{{currency}} <strong>{{number_format(balance, 2)}}</strong>'])
    ->actions([
        'view' => false,
        'delete' => true,
        'edit' => true,
        'status' => true,
        'banned' => true,
    ])
    // ->where(['user_id' => $user_id])
    ->action_urls([
        'add' => admin.'/users/add',
        'edit' => root.admin.'/users/edit/{user_id}',
    ])
    ->id_column('user_id')
    ->col_width('id', '0px')
    ->col_width('first_name', '120px')
    ->col_width('last_name', '120px')
    ->col_width('email', '150px')
    ->col_width('role', '50px')
    ->col_width('currency', '50px')
    ->render();

?>

</div>