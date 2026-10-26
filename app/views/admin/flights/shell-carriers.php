<?php
@$SECURE or die('Access Denied!');
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 text-sm text-blue-900">
        <strong>Shell-carrier rules.</strong> Some suppliers return fares under a ticketing/virtual
        carrier code (e.g. Adivaha <code>A1</code> = "Atifly") that is not the airline actually flown.
        A rule here tells flight search to show the real operating carrier instead — scoped per
        <strong>supplier</strong> and <strong>country</strong>, so it never affects other providers or markets.
        Disable a rule to instantly stop correcting that code.
    </div>

    <?php
    echo crud()
        ->table('flight_shell_carriers')
        ->title('Flight Shell-Carrier Rules')
        ->col('id,shell_code,supplier,country,match_mode,note')
        ->label([
            'id'         => 'ID',
            'shell_code' => 'Shell Code',
            'supplier'   => 'Supplier',
            'country'    => 'Country',
            'match_mode' => 'Match',
            'note'       => 'Note',
            'status'     => 'Active',
        ])
        ->row([
            'shell_code' => '<span class="inline-flex items-center px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800">{{shell_code}}</span>',
            'supplier'   => '<span class="text-sm text-gray-700">{{supplier}}</span>',
            'country'    => '<span class="text-sm text-gray-700">{{country}}</span>',
            'match_mode' => '<span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700">{{match_mode}}</span>',
            'note'       => '<span class="text-xs text-gray-500">{{note}}</span>',
        ])
        ->order('id', 'DESC')
        ->actions([
            'add'    => true,
            'view'   => false,
            'edit'   => true,
            'delete' => true,
            'status' => true,   // toggles the `active` column via CRUD::handleAjax
            'featured' => false,
            'search' => true,
        ])
        ->action_urls([
            'add'  => 'admin/flight-shell-carriers/add',
            'edit' => 'flight-shell-carriers/edit/{id}',
        ])
        ->render();
    ?>
</div>
