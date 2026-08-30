<?php
// app/views/admin/umrah/umrah.php
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
        ->table('umrah')
        ->title(T::umrah_management ?? 'Umrah Management')
        ->col('id,img,name,location,days,adult_price,umrah_type_id')
        ->relation('umrah_type_id', 'umrah_settings', 'setting_label', 'id', ['setting_type' => 'umrah_type'])
        ->label([
            'id'            => T::umrah_id   ?? 'Umrah ID',
            'name'          => T::umrah_name ?? 'Package Name',
            'img'           => T::image      ?? 'Image',
            'location'      => T::location   ?? 'Location',
            'days'          => T::duration   ?? 'Days',
            'adult_price'   => T::price      ?? 'Price',
            'umrah_type_id' => T::umrah_type ?? 'Umrah Type'
        ])
        ->row([
            'img'  => '<img src="'.root.'{{img}}" alt="Umrah" class="w-10 h-10 rounded-full object-cover border border-gray-200" onerror="this.onerror=null;this.src=\''.root.'uploads/no_img.jpg\'">',
            'name' => '<a href="'.root.admin.'/umrah/edit/{{id}}" target="_self" class="text-black hover:underline">{{name}}</a>',
            'adult_price' => '
                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <span class="material-symbols-outlined text-sm">payments</span>
                    {{currency}} {{adult_price}}
                </span>
            ',
            'days' => '{{days}}D / {{nights}}N'
        ])
        ->order('id', 'DESC')
        ->actions([
            'add'      => true,
            'view'     => false,
            'edit'     => true,
            'delete'   => true,
            'status'   => true,
            'featured' => true,
            'search'   => true,
        ])
        ->action_urls([
            'add'  => 'admin/umrah/add',
            'edit' => 'umrah/edit/{id}',
            'view' => root.'umrah/detail/{{slug}}'
        ])
        ->col_width('images', '60px')
        ->render();
    ?>
</div>
