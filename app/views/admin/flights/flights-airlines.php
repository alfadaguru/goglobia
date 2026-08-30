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
        ->table('flights_airlines')
        ->title(T::airlines_management)
        ->col('id,name,code,iata,country')
        ->label([
            'id' => T::id,
            'name' => T::airline_name,
            'code' => T::code,
            'iata' => T::iata,
            'country' => T::country,
            'status' => T::status
        ])  
        ->row([
            'name' => '<span class="font-semibold text-gray-900">{{name}}</span>',
            'code' => '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">{{code}}</span>',
            'iata' => '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">{{iata}}</span>',
            'country' => '<span class="text-sm text-gray-700">{{country}}</span>'
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
            'add' => 'admin/flights-airlines/add',
            'edit' => 'flights-airlines/edit/{id}',
            'view' => 'flights-airlines/view/{id}'
        ])
        ->render();
    ?>
</div>