<?php @$SECURE or die('Access Denied!'); ?>
<div class="container my-4">
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-success mb-5"><span class="material-symbols-outlined">check_circle</span><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-error mb-5"><span class="material-symbols-outlined">error</span><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php
    echo crud()
        ->table('bus_operators')
        ->title((T::bus ?? 'Bus') . ' ' . (T::operators ?? 'Operators'))
        ->col('id,img,company_name,operator_name,email,phone,markup_b2b,markup_b2c')
        ->label([
            'id'            => T::id ?? 'ID',
            'company_name'  => T::company ?? 'Company',
            'operator_name' => T::name ?? 'Name',
            'email'         => T::email ?? 'Email',
            'phone'         => T::phone ?? 'Phone',
            'markup_b2b'    => (T::markup ?? 'Markup') . ' ' . T::b2b,
            'markup_b2c'    => (T::markup ?? 'Markup') . ' ' . T::b2c,
            'img'           => T::image ?? 'Image',
        ])
        ->extra_fetch('markup_type_b2b,markup_type_b2c')
        ->row([
            'company_name' => '<a href="' . root . admin . '/bus/operators/manage/{{id}}" class="text-black hover:underline font-semibold">{{company_name}}</a>',
            'img'          => '<img src="' . root . '{{img}}" alt="" class="w-10 h-10 rounded-lg object-cover border border-gray-200 bg-gray-50" onerror="this.onerror=null;this.src=\'' . root . 'uploads/no_img.jpg\'">',
            'markup_b2b'   => function ($row) {
                $val  = number_format((float)($row['markup_b2b'] ?? 0), 2);
                return ($row['markup_type_b2b'] ?? 'percentage') === 'fixed' ? $val . ' (' . (T::fixed ?? 'Fixed') . ')' : $val . '%';
            },
            'markup_b2c'   => function ($row) {
                $val  = number_format((float)($row['markup_b2c'] ?? 0), 2);
                return ($row['markup_type_b2c'] ?? 'percentage') === 'fixed' ? $val . ' (' . (T::fixed ?? 'Fixed') . ')' : $val . '%';
            },
        ])
        ->col_width('img', '60px')
        ->order('id', 'DESC')
        ->actions(['add' => true, 'view' => false, 'edit' => true, 'delete' => false, 'status' => true, 'featured' => false, 'search' => true])
        ->action_urls(['add' => 'admin/bus/operators/manage', 'edit' => root . admin . '/bus/operators/manage/{id}'])
        ->custom_button([
            'label' => T::delete ?? 'Delete', 'icon' => 'delete',
            'url' => root . admin . '/bus/operators/delete/{id}',
            'class' => 'text-red-600 hover:bg-red-100', 'title' => T::delete ?? 'Delete',
            'confirm' => T::are_you_sure ?? 'Are you sure?',
        ])
        ->render();
    ?>
</div>
