<div class="container my-4">

    <?php

    global $crud;
    echo $crud->table('users_roles')
        ->col('id,type_name')
        ->title('Users Roles')
        ->actions([
            'view' => false,
            'delete' => true,
            'edit' => true,
            'status' => false,
        ])
        ->action_urls([
            'add' => admin.'/users/roles/add',
            'edit' => root.admin.'/users/roles/edit/{id}',
        ])
        ->protect([1, 2, 3, 4, 5]) // Protect system roles: Agent, Customer, Employee, Supplier, Admin
        ->render();
    ?>

</div>