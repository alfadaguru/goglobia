<div class="container my-4">

<!-- Success/Error Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
        <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
        <div><?= $_SESSION['message']['text'] ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<?php

echo crud()->table('bookings')
    ->col('invoice_id,module,first_name,last_name,email,booking_status,payment_status,price_original,price_markup,tax,currency_markup,created_at')
    ->title('Booking Reports')
    ->perPage(50)
    ->action_urls([
        'view' => root.admin.'/reports/bookings/view/{id}'
    ])
    ->actions([
        'add' => false,
        'edit' => false,
        'status' => false,
        'view' => false,
        'delete' => false,
        'search' => true,
        'bulk_delete' => false
    ])
    // ->order('created_at', 'DESC')
    ->order('id', 'ASC')
    ->label([
        'invoice_id' => 'Invoice ID',
        'module' => 'Module',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'email' => 'Email',
        'booking_status' => 'Booking Status',
        'payment_status' => 'Payment Status',
        'price_original' => 'Base Price',
        'price_markup' => 'Markup',
        'tax' => 'Tax',
        'currency_markup' => 'Currency',
        'created_at' => 'Created At'
    ])
    ->col_width('invoice_id', '120px')
    ->col_width('module', '100px')
    ->col_width('booking_status', '130px')
    ->col_width('payment_status', '130px')
    ->col_width('price_original', '110px')
    ->col_width('price_markup', '100px')
    ->col_width('tax', '80px')
    ->col_width('currency_markup', '90px')
    ->col_width('created_at', '180px')
    ->row([
        'price_original' => '{{currency_markup}} {{number_format(price_original, 2)}}',
        'price_markup' => '{{currency_markup}} {{number_format(price_markup, 2)}}',
        'tax' => '{{currency_markup}} {{number_format(tax, 2)}}'
    ])
    ->render();

?>

</div>
