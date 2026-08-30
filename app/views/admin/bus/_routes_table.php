<?php @$SECURE or die('Access Denied!');
// EXPECTS: $b (bus row), $curr (currency code). Renders the routes crud() table only.
$__curr = htmlspecialchars($curr ?? 'USD');
echo crud()
    ->table('bus_routes')
    ->title((T::bus ?? 'Bus') . ' ' . (T::routes ?? 'Routes'))
    ->col('id,origin,destination,departure_time,arrival_time,seat_class,total_seats,adult_price,child_price')
    ->where(['bus_id' => (int)$b['id']])
    ->label([
        'id'             => T::id ?? 'ID',
        'origin'         => T::origin ?? 'Origin',
        'destination'    => T::destination ?? 'Destination',
        'departure_time' => T::departure ?? 'Departure',
        'arrival_time'   => T::arrival ?? 'Arrival',
        'seat_class'     => T::seat_class ?? 'Class',
        'total_seats'    => T::seats ?? 'Seats',
        'adult_price'    => (T::adult ?? 'Adult') . ' ' . (T::price ?? 'Price'),
        'child_price'    => (T::child ?? 'Child') . ' ' . (T::price ?? 'Price'),
    ])
    ->row([
        'adult_price' => $__curr . ' {{number_format(adult_price, 2)}}',
        'child_price' => $__curr . ' {{number_format(child_price, 2)}}',
    ])
    ->order('id', 'DESC')
    ->actions(['add' => false, 'view' => false, 'edit' => false, 'delete' => false, 'status' => true, 'featured' => false, 'search' => true])
    ->custom_button([
        'label' => T::edit ?? 'Edit', 'icon' => 'edit', 'url' => '#',
        'onclick' => "window.__busMgr && window.__busMgr.openEdit('{id}'); return false;",
        'class' => 'text-blue-600 hover:bg-blue-100', 'title' => T::edit ?? 'Edit',
    ])
    ->custom_button([
        'label' => T::delete ?? 'Delete', 'icon' => 'delete', 'url' => '#',
        'onclick' => "window.__busMgr && window.__busMgr.del('{id}'); return false;",
        'class' => 'text-red-600 hover:bg-red-100', 'title' => T::delete ?? 'Delete',
    ])
    ->render();
