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

    echo crud()->table('countries')
        // ->col('id,name')
        ->title(T::countries)
        ->actions([
            'view' => false,
            'delete' => true,
            'edit' => true,
            'status' => true,
        ])
        // ->where(['user_id' => $user_id])
        ->action_urls([
            'add' => admin . '/settings/countries/add',
            'edit' => root . admin . '/settings/countries/edit/{id}',
        ])
        ->id_column('id')
        // ->col_width('col-name', '120px')
        ->render();

    ?>

</div>