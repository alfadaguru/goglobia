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

echo crud()->table('languages')
    ->col('name,type,country,lang_code')
    ->title('Languages')
    ->actions([
        'view' => false,
        'delete' => true,
        'edit' => true,
        'status' => true,
        'default' => true,
    ])
    ->action_urls([
        'add' => admin.'/settings/languages/add',
        'edit' => root.admin.'/settings/languages/edit/{id}',
    ])

    ->relation('country', 'countries', 'nicename', 'iso')
    ->col_width('name', '60px')
    ->col_width('type', '30px')
    ->col_width('country', '120px')
    // ->col_width('lang_code', '40px')

    ->id_column('id')
    ->render();

?>

</div>