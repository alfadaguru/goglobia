<?php
// app/views/admin/visa/visa.php
@$SECURE or die('Access Denied!');
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php
    echo crud()
        ->table('visa')
        ->title(T::visa_management ?? 'Visa Management')
        ->col('id,img,from_country_id,to_country_id,status')
        ->relation('from_country_id', 'countries', 'nicename', 'id')
        ->relation('to_country_id', 'countries', 'nicename', 'id')
        ->label([
            'id' => T::id ?? 'ID',
            'img' => T::image ?? 'Image',
            'from_country_id' => T::from_country ?? 'From Country',
            'to_country_id' => T::to_country ?? 'To Country',
            'status' => T::status ?? 'Status'
        ])
        ->row([
            'id' => '<a href="'.root.admin.'/visa/edit/{{id}}" target="_self" class="text-black hover:underline">{{id}}</a>',
            'img' => '<img src="'.root.'{{img}}" alt="Visa" class="w-10 h-10 rounded-full object-cover border border-gray-200" onerror="this.onerror=null;this.src=\''.root.'uploads/no_img.jpg\'">',
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => true,
            'view' => false,
            'edit' => true,
            'delete' => true,
            'search' => true,
        ])
        ->action_urls([
            'add' => 'admin/visa/add',
            'edit' => 'visa/edit/{id}'
        ])
        ->col_width('image', '60px')
        ->render();
    ?>
</div>
