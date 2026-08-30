<?php @$SECURE or die('Access Denied!');
if (!function_exists('busRoutesCount')) {
    function busRoutesCount($id) {
        return (int) $GLOBALS['db']->count('bus_routes', ['bus_id' => (int)$id]);
    }
}
?>
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
        ->table('bus')
        ->title((T::bus ?? 'Bus') . ' ' . (T::management ?? 'Management'))
        ->col('id,img,name,operator,bus_type')
        ->label([
            'id'       => T::id ?? 'ID',
            'name'     => T::name ?? 'Name',
            'operator' => T::operator ?? 'Operator',
            'bus_type' => T::type ?? 'Type',
            'img'      => T::image ?? 'Image',
        ])
        ->row([
            'name' => '<a href="' . root . admin . '/bus/manage/{{id}}" class="text-black hover:underline font-semibold">{{name}}</a>'
                . '<div class="text-xs text-gray-500 mt-0.5 flex items-center gap-1"><span class="material-symbols-outlined !text-[14px]">route</span>{{busRoutesCount(id)}} ' . (T::routes ?? 'Routes') . '</div>',
            'img'  => '<img src="' . root . '{{img}}" alt="" class="w-10 h-10 rounded-lg object-cover border border-gray-200 bg-gray-50" onerror="this.onerror=null;this.src=\'' . root . 'uploads/no_img.jpg\'">',
        ])
        ->col_width('img', '60px')
        ->order('id', 'DESC')
        ->actions([
            'add'      => true,
            'view'     => false,
            'edit'     => true,
            'delete'   => false,   // CUSTOM DELETE (CASCADES ROUTES + CALENDAR)
            'status'   => true,
            'featured' => true,
            'search'   => true,
        ])
        ->action_urls([
            'add'  => 'admin/bus/manage',
            'edit' => root . admin . '/bus/manage/{id}',
        ])
        ->custom_button([
            'label'   => T::delete ?? 'Delete',
            'icon'    => 'delete',
            'url'     => root . admin . '/bus/delete/{id}',
            'class'   => 'text-red-600 hover:bg-red-100',
            'title'   => T::delete ?? 'Delete',
            'confirm' => T::are_you_sure ?? 'Are you sure?',
        ])
        ->render();
    ?>
</div>
