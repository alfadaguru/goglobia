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

echo crud()->table('locations')
    ->col('city,country')
    ->title(T::locations_management ?? 'Locations Management')
    ->actions([
        'view' => false,
        'delete' => true,
        'edit' => true,
        'status' => true,
        'default' => true,
    ])
    ->action_urls([
        'add' => admin.'/settings/locations/add',
        'edit' => root.admin.'/settings/locations/edit/{id}',
    ])
    ->col_width('city', '100px')
    // ->col_width('country', '120px')
    ->id_column('id')
    ->render();

?>

</div>