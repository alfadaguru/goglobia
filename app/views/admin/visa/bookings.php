<?php
// app/views/admin/visa/bookings.php
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
        ->table('visa_bookings')
        ->title(T::visa_bookings_management ?? 'Visa Bookings Management')
        ->col('id,booking_reference,customer_name,customer_email,from_country,to_country,visa_type,travelers_count,total_price,booking_status,payment_status,created_at')
        ->label([
            'id' => T::id ?? 'ID',
            'booking_reference' => T::reference ?? 'Reference',
            'customer_name' => T::customer ?? 'Customer',
            'customer_email' => T::email ?? 'Email',
            'from_country' => T::from ?? 'From',
            'to_country' => T::to ?? 'To',
            'visa_type' => T::visa_type ?? 'Visa Type',
            'travelers_count' => T::travelers ?? 'Travelers',
            'total_price' => T::amount ?? 'Amount',
            'booking_status' => T::status ?? 'Status',
            'payment_status' => T::payment ?? 'Payment',
            'created_at' => T::date ?? 'Date'
        ])
        ->row([
            'booking_reference' => '<a href="'.root.admin.'/visa-bookings/view/{{id}}" class="text-blue-600 hover:underline font-semibold">{{booking_reference}}</a>',
            'customer_name' => '<div class="flex flex-col"><span class="font-medium text-gray-900">{{customer_name}}</span><span class="text-xs text-gray-500">{{customer_email}}</span></div>',
            'total_price' => '<span class="font-semibold text-gray-900">{{currency}} {{total_price}}</span>',
            'travelers_count' => '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800"><span class="material-symbols-outlined text-sm">group</span>{{travelers_count}}</span>',
            'booking_status' => '
                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full
                    {{booking_status === \'pending\' ? \'bg-yellow-100 text-yellow-800\' : \'\'}}
                    {{booking_status === \'processing\' ? \'bg-blue-100 text-blue-800\' : \'\'}}
                    {{booking_status === \'approved\' ? \'bg-green-100 text-green-800\' : \'\'}}
                    {{booking_status === \'rejected\' ? \'bg-red-100 text-red-800\' : \'\'}}
                    {{booking_status === \'completed\' ? \'bg-purple-100 text-purple-800\' : \'\'}}
                    {{booking_status === \'cancelled\' ? \'bg-gray-100 text-gray-800\' : \'\'}}">
                    <span class="material-symbols-outlined text-sm">
                        {{booking_status === \'pending\' ? \'schedule\' : \'\'}}
                        {{booking_status === \'processing\' ? \'pending\' : \'\'}}
                        {{booking_status === \'approved\' ? \'check_circle\' : \'\'}}
                        {{booking_status === \'rejected\' ? \'cancel\' : \'\'}}
                        {{booking_status === \'completed\' ? \'task_alt\' : \'\'}}
                        {{booking_status === \'cancelled\' ? \'block\' : \'\'}}
                    </span>
                    {{booking_status}}
                </span>
            ',
            'payment_status' => '
                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full
                    {{payment_status === \'pending\' ? \'bg-orange-100 text-orange-800\' : \'\'}}
                    {{payment_status === \'paid\' ? \'bg-green-100 text-green-800\' : \'\'}}
                    {{payment_status === \'failed\' ? \'bg-red-100 text-red-800\' : \'\'}}
                    {{payment_status === \'refunded\' ? \'bg-gray-100 text-gray-800\' : \'\'}}">
                    <span class="material-symbols-outlined text-sm">
                        {{payment_status === \'pending\' ? \'schedule\' : \'\'}}
                        {{payment_status === \'paid\' ? \'paid\' : \'\'}}
                        {{payment_status === \'failed\' ? \'error\' : \'\'}}
                        {{payment_status === \'refunded\' ? \'undo\' : \'\'}}
                    </span>
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
