<div class="container my-4">

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- SECURITY NOTICE -->
    <div class="alert alert-info mb-4">
        <span class="material-symbols-outlined">info</span>
        <div>
            <strong>Payment Gateway Rules:</strong>
            <ul style="margin: 10px 0 0 20px; list-style: disc;">
                <li>Default gateway must be enabled. Cannot set a disabled gateway as default.</li>
                <li>Cannot disable a gateway that is currently set as default. Please set another gateway as default first.</li>
            </ul>
        </div>
    </div>

    <?php

    $conditions = [
        'active' => 1,
    ];

    echo crud()->table('payment_gateways')
        ->col('name,currency,order,dev_mode')
        ->order('order', 'ASC')
        ->title(T::payment_gateways)
        ->actions([
            'view' => false,
            'delete' => false,
            'add' => false,
            'edit' => true,
            'status' => true,
            'default' => true,
            'bulk_delete' => false,
        ])
        ->where($conditions ?? [])
        ->action_urls([
            'edit' => root . admin . '/settings/gateway/edit/{id}',
        ])
        ->id_column('id')
        ->col_width('currency', '80px')
        ->col_width('order', '90px')
        ->col_width('dev_mode', '120px')
        ->row([
            'order' => function ($row) {
                // Inline order editor — saving reloads so rows re-sort by order.
                $id  = (int) $row['id'];
                $val = (int) ($row['order'] ?? 0);
                return '<input type="number" min="0" value="' . $val . '"'
                    . ' class="input" style="width:72px;height:34px;padding:4px 8px;text-align:center;"'
                    . ' onchange="gatewayOrderChange(' . $id . ', this.value)"'
                    . ' onclick="event.stopPropagation();">';
            },
            'name' => function ($row) {
                // Show each gateway's logo (or an initials badge) beside its name.
                return '<div style="display:flex;align-items:center;gap:10px;">'
                    . gateway_icon_html($row, 30)
                    . '<span class="font-medium">' . htmlspecialchars(getGatewayDisplayName($row)) . '</span>'
                    . '</div>';
            },
            'dev_mode' => function ($row) {
                if ($row['dev_mode'] == 1) {
                    $statusClass = 'bg-green-100 text-green-800 border-green-300 uppercase text-xs font-semibold border w-full text-center';
                    $label = T::enabled ?? 'Enabled';
                } else {
                    $statusClass = 'bg-slate-100 text-slate-800 border-slate-300 uppercase text-xs font-semibold border w-full text-center';
                    $label = T::disabled ?? 'Disabled';
                }
                return '<span class="inline-block px-2 py-1 rounded ' . $statusClass . '">' . htmlspecialchars($label) . '</span>';
            },
        ])
        ->render();

    ?>

    <script>
    function gatewayOrderChange(id, value) {
        fetch('<?= root . admin ?>/settings/gateway/ajax-update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ajax_update: '1', field: 'order', value: value, gateway_id: id })
        }).then(function (r) { return r.json(); })
          .then(function () { location.reload(); })
          .catch(function () { location.reload(); });
    }
    </script>

</div>