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

    // Booking-class badge: shows, per provider, whether it is a real end-to-end
    // API booking, an affiliate/redirect, the platform's own inventory, or a
    // non-functional stub. Value comes from modules.booking_class (seeded from
    // the verified docs/MODULES.md classification by ensureModulesBookingClass).
    // The closure returns fixed, whitelisted HTML — no row data is interpolated
    // into markup, so it cannot be an XSS or eval sink.
    $bookingClassBadge = function ($row) {
        $map = [
            'real'      => ['REAL API BOOKING', 'bg-green-100 text-green-800',  'cloud_done'],
            'affiliate' => ['AFFILIATE / REDIRECT', 'bg-blue-100 text-blue-800', 'open_in_new'],
            'own'       => ['OWN INVENTORY',    'bg-amber-100 text-amber-800',  'inventory_2'],
            'stub'      => ['STUB / NOT WIRED', 'bg-red-100 text-red-800',      'warning'],
        ];
        $code = (string)($row['booking_class'] ?? '');
        if (!isset($map[$code])) {
            return '<span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-500">Unclassified</span>';
        }
        [$label, $classes, $icon] = $map[$code];
        return '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full ' . $classes . '">'
             . '<span class="material-symbols-outlined text-sm leading-none">' . $icon . '</span>'
             . htmlspecialchars($label) . '</span>';
    };

    echo crud()->table('modules')
        ->title(T::modules)
        ->actions([
            'view' => false,
            'delete' => true,
            'edit' => true,
            'status' => true,
        ])
        ->action_urls([
            'add' => admin . '/settings/modules/add',
            'edit' => root . admin . '/settings/modules/edit/{id}',
        ])
        ->id_column('id')
        ->label(['booking_class' => 'Booking Type'])
        ->row(['booking_class' => $bookingClassBadge])
        // ->col_width('col-name', '120px')
        ->render();

    ?>

</div>