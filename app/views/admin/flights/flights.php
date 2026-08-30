<?php

// app/views/admin/flights/flights.php
@$SECURE or die('Access Denied!');

$currency = $db->get('currencies', 'name', ['default' => '1']);
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
        ->table('flights')
        ->title(T::flights_management)
        ->col('id,flight_number,airline_id,from_airport_id,to_airport_id,departure_time,flight_type,economy_adult_price,economy_child_price,economy_infant_price')
        ->relation('airline_id', 'flights_airlines', 'name', 'id')
        ->relation('from_airport_id', 'flights_airports', 'city', 'id')
        ->relation('to_airport_id', 'flights_airports', 'city', 'id')
        ->label([
            'id' => T::flight_id,
            'flight_number' => T::flight_number,
            'airline_id' => T::airline,
            'from_airport_id' => T::origin_airport,
            'to_airport_id' => T::destination_airport,
            'flight_type' => T::flight_type,
            'departure_time' => T::departure_time,
            'status' => T::status
        ])
        ->row([
            'departure_time' => '<span class="text-sm">{{departure_time}}</span>',
            'economy_adult_price' => '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">{{economy_adult_price}} {{currency}}</span>',
            'economy_child_price' => '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">{{economy_child_price}} {{currency}}</span>',
            'economy_infant_price' => '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">{{economy_infant_price}} {{currency}}</span>'
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => true,
            'view' => true,
            'edit' => true,
            'delete' => true,
            'status' => true,
            'featured' => false,
            'search' => false,
        ])
        ->action_urls([
            'add' => 'admin/flights/add',
            'edit' => 'flights/edit/{id}',
            'view' => 'flights/view/{id}'
        ])
        ->render();
    ?>
</div>