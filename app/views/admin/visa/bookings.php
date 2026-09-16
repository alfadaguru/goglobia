<?php
// app/views/admin/visa/bookings.php
@$SECURE or die('Access Denied!');

// Visa bookings live in the generic `bookings` table (module='visa'), NOT a
// separate `visa_bookings` table (which never existed and made this page 500).
// invoice_id=reference, first_name/email=customer, price_markup=total,
// currency_markup=currency. Rich detail (countries, visa type) is in booking_data.
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
        ->table('bookings')
        ->title(T::visa_bookings_management ?? 'Visa Bookings Management')
        ->col('id,invoice_id,first_name,email,price_markup,booking_status,payment_status,created_at')
        ->extra_fetch('last_name,currency_markup')
        ->where(['module' => 'visa'])
        ->label([
            'id' => T::id ?? 'ID',
            'invoice_id' => T::reference ?? 'Reference',
            'first_name' => T::customer ?? 'Customer',
            'email' => T::email ?? 'Email',
            'price_markup' => T::amount ?? 'Amount',
            'booking_status' => T::status ?? 'Status',
            'payment_status' => T::payment ?? 'Payment',
            'created_at' => T::date ?? 'Date'
        ])
        ->row([
            'invoice_id' => '<a href="'.root.admin.'/visa-bookings/view/{{id}}" class="text-blue-600 hover:underline font-semibold">{{invoice_id}}</a>',
            'first_name' => '<div class="flex flex-col"><span class="font-medium text-gray-900">{{first_name}} {{last_name}}</span><span class="text-xs text-gray-500">{{email}}</span></div>',
            'price_markup' => '<span class="font-semibold text-gray-900">{{currency_markup}} {{price_markup}}</span>',
            'booking_status' => '
                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full
                    {{booking_status === \'pending\' ? \'bg-yellow-100 text-yellow-800\' : \'\'}}
                    {{booking_status === \'processing\' ? \'bg-blue-100 text-blue-800\' : \'\'}}
                    {{booking_status === \'approved\' ? \'bg-green-100 text-green-800\' : \'\'}}
                    {{booking_status === \'confirmed\' ? \'bg-green-100 text-green-800\' : \'\'}}
                    {{booking_status === \'rejected\' ? \'bg-red-100 text-red-800\' : \'\'}}
                    {{booking_status === \'completed\' ? \'bg-purple-100 text-purple-800\' : \'\'}}
                    {{booking_status === \'cancelled\' ? \'bg-gray-100 text-gray-800\' : \'\'}}">
                    {{booking_status}}
                </span>
            ',
            'payment_status' => '
                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full
                    {{payment_status === \'pending\' ? \'bg-orange-100 text-orange-800\' : \'\'}}
                    {{payment_status === \'unpaid\' ? \'bg-orange-100 text-orange-800\' : \'\'}}
                    {{payment_status === \'paid\' ? \'bg-green-100 text-green-800\' : \'\'}}
                    {{payment_status === \'failed\' ? \'bg-red-100 text-red-800\' : \'\'}}
                    {{payment_status === \'refunded\' ? \'bg-gray-100 text-gray-800\' : \'\'}}">
                    {{payment_status}}
                </span>
            ',
            'created_at' => '<span class="text-sm text-gray-600">{{created_at}}</span>'
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => false,
            'view' => true,
            'edit' => false,
            'delete' => true,
            'status' => false,
            'search' => true,
        ])
        ->action_urls([
            'view' => admin.'/visa-bookings/view/{id}',
            'delete' => admin.'/visa-bookings/delete'
        ])
        ->render();
    ?>
</div>
