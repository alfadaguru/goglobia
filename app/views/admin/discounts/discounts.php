<?php
// app/views/admin/discounts/discounts.php — Discounts list.
@$SECURE or die('Access Denied!');
?>
<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4 flex items-center gap-2 p-3 rounded-lg"
             style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="mb-3 text-sm text-gray-500">
        Automatic discounts apply to matching bookings with no code needed. For portal-supplier
        listings the discount is <strong>platform-funded</strong> (the supplier is still paid in full);
        for our own &amp; API inventory it can never take the price below supplier cost.
    </div>

    <?php
    echo crud()
        ->table('discounts')
        ->title('Discounts')
        ->col('id,name,discount_type,discount_value,scope_type,scope_module,priority,used_count,status')
        ->label([
            'id' => 'ID', 'name' => 'Name', 'discount_type' => 'Type', 'discount_value' => 'Value',
            'scope_type' => 'Scope', 'scope_module' => 'Service', 'priority' => 'Priority',
            'used_count' => 'Used', 'status' => 'Active',
        ])
        ->row([
            'name' => function ($row) {
                $d = $row['description'] ? '<div class="text-xs text-gray-400">' . htmlspecialchars((string) $row['description']) . '</div>' : '';
                return '<div class="font-semibold text-gray-900">' . htmlspecialchars((string) $row['name']) . '</div>' . $d;
            },
            'discount_type' => function ($row) {
                $map = ['percentage' => 'bg-blue-100 text-blue-800', 'fixed' => 'bg-green-100 text-green-800',
                        'tiered' => 'bg-amber-100 text-amber-800', 'override' => 'bg-violet-100 text-violet-800'];
                $c = $map[$row['discount_type']] ?? 'bg-gray-100 text-gray-700';
                return '<span class="inline-flex px-2 py-1 text-xs rounded-full ' . $c . '">' . ucfirst((string) $row['discount_type']) . '</span>';
            },
            'discount_value' => function ($row) {
                if ($row['discount_type'] === 'tiered') { return '<span class="text-xs text-gray-500">by tiers</span>'; }
                $v = $row['discount_value'];
                return in_array($row['discount_type'], ['percentage','override'], true)
                    ? '<span class="font-semibold">' . number_format((float) $v, 0) . '%</span>'
                    : '<span class="font-semibold">' . htmlspecialchars((string) ($row['currency'] ?: '')) . ' ' . number_format((float) $v, 2) . '</span>';
            },
            'scope_type' => function ($row) {
                $s = (string) $row['scope_type'];
                $extra = '';
                if ($s === 'provider') { $extra = $row['scope_provider'] ?: $row['scope_owner']; }
                return '<span class="text-sm font-medium">' . ucfirst($s) . '</span>' . ($extra ? '<div class="text-xs text-gray-400">' . htmlspecialchars((string) $extra) . '</div>' : '');
            },
            'scope_module' => function ($row) {
                return $row['scope_module'] ? '<span class="text-sm">' . ucfirst((string) $row['scope_module']) . '</span>' : '<span class="text-gray-300">—</span>';
            },
            'used_count' => function ($row) { return '<span class="font-medium">' . (int) $row['used_count'] . '</span>'; },
        ])
        ->order('priority', 'DESC')
        ->actions([
            'add' => true, 'view' => false, 'edit' => true, 'delete' => true, 'status' => true, 'search' => true,
        ])
        ->action_urls([
            'add'  => 'admin/discounts/add',
            'edit' => 'discounts/edit/{id}',
        ])
        ->col_width('discount_type', '110px')
        ->col_width('discount_value', '100px')
        ->col_width('scope_type', '130px')
        ->col_width('priority', '90px')
        ->col_width('status', '90px')
        ->render();
    ?>
</div>
