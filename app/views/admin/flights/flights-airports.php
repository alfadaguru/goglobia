<?php
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
        ->table('flights_airports')
        ->title(T::airports_management)
        ->col('id,airport,city,country,code')
        ->label([
            'id' => T::id,
            'airport' => T::airport_name,
            'city' => T::city,
            'country' => T::country,
            'code' => T::iata_code,
            'status' => T::status
        ])  
        ->row([
            'airport' => '<span class="font-semibold text-gray-900">{{airport}}</span>',
            'city' => '<span class="text-sm text-gray-700">{{city}}</span>',
            'country' => '<span class="text-sm text-gray-600">{{country}}</span>',
            'code' => '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">{{code}}</span>'
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => true,
            'view' => true,
            'edit' => true,
            'delete' => true,
            'status' => true,
            'featured' => false,
            'search' => true,
        ])
        ->action_urls([
            'add' => 'admin/flights-airports/add',
            'edit' => 'flights-airports/edit/{id}',
            'view' => 'flights-airports/view/{id}'
        ])
        ->render();
    ?>
</div>