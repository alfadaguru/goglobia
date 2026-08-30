<?php
@$SECURE or die('Access Denied!');
$currency = $db->get('currencies', 'name', ['default' => '1']);
$checkin  = date('d-m-Y', strtotime('+1 day'));
$checkout = date('d-m-Y', strtotime('+2 days'));
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
        ->table('stays')
        ->title(T::hotels_management)
        ->col('id,img,name,location,stars,rating,user_id')
        ->relation('user_id', 'users', 'first_name', 'user_id')
        ->label([
            'id' => T::hotel_id,
            'name' => T::hotel_name,
            'img' => T::image,
            'location' => T::location,
            'stars' => T::stars,
            'rating' => T::rating,
            'available_rooms' => T::available_rooms,
            'user_id' => T::owner
        ])
        ->row([
            'img' => '<img src="'.root.'{{img}}" alt="Hotel" class="w-10 h-10 rounded-full object-cover border border-gray-200" onerror="this.onerror=null;this.src=\''.root.'uploads/no_img.jpg\'">',
            'name' => '<a href="'.root.admin.'/stays/edit/{{id}}" target="_self" class="text-black hover:underline">{{name}}</a>',
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => true,
            'view' => false,
            'edit' => true,
            'delete' => true,
            'status' => true,
            'featured' => true,
            'search' => true,
        ])
        ->action_urls([
            'add' => 'admin/stays/add',
            'edit' => 'stays/edit/{id}',
            'view' => root.'stay/{id}'
        ])
        ->custom_button([
            'icon'     => 'visibility',
            'url'      => root.'stay/{slug}/{id}/hotels/_/'.$checkin.'/'.$checkout.'/AS/1/2-0',
            'title'    => 'View Hotel',
            'class'    => '!text-green-600 hover:!bg-green-100 !px-[10px] !h-[32px] !rounded-xl',
            'target'   => '_blank',
            'position' => 'before_edit',
        ])
        ->custom_button([
            'icon'     => 'calendar_month',
            'url'      => root.admin.'/stays/calendar/{id}',
            'title'    => 'Rates Calendar',
            'class'    => '!text-blue-600 hover:!bg-blue-100 !px-[10px] !h-[32px] !rounded-xl',
            'target'   => '_blank',
            'position' => 'before_edit',
        ])
        ->extra_fetch('slug')
        ->col_width('img', '60px')
        ->render();
    ?>
</div>