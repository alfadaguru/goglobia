<?php
// promo-codes.php - List View
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
        ->table('promo_codes')
        ->title(T::promo_codes_management)
        ->col('id,code,discount_type,discount_value,module,usage_limit,used_count,start_date,end_date')
        ->label([
            'id' => T::id,
            'code' => T::code,
            'discount_type' => T::type,
            'discount_value' => T::value,
            'module' => T::module,
            'usage_limit' => T::usage_limit,
            'used_count' => T::used,
            'start_date' => T::start_date,
            'end_date' => T::end_date
        ])
        ->row([
            'code' => '
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-indigo-50 border border-indigo-200">
                    <span class="material-symbols-outlined text-indigo-600 text-sm">confirmation_number</span>
                    <span class="font-mono font-semibold text-indigo-700 text-sm">{{code}}</span>
                </span>
            ',
            'discount_value' => function($row) {
                $cur = $row['currency'] ?? 'USD';
                if ($row['discount_type'] === 'percentage') {
                    return '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                        <span class="font-semibold text-blue-700">' . number_format($row['discount_value'], 0) . '%</span>
                    </span>';
                } else {
                    return '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">
                        <span class="font-semibold text-green-700">' . $cur . ' ' . number_format($row['discount_value'], 2) . '</span>
                    </span>';
                }
            },
            'module' => function($row) {
                $module = $row['module'] ?? 'all';
                $icons = [
                    'all' => ['apps', 'bg-gray-100 text-gray-800'],
                    'stays' => ['hotel', 'bg-purple-100 text-purple-800'],
                    'flights' => ['flight_takeoff', 'bg-sky-100 text-sky-800'],
                    'tours' => ['tour', 'bg-orange-100 text-orange-800'],
                    'cars' => ['directions_car', 'bg-teal-100 text-teal-800'],
                    'visa' => ['assignment', 'bg-rose-100 text-rose-800'],
                    'umrah' => ['mosque', 'bg-violet-100 text-violet-800']
                ];
                $icon = $icons[$module] ?? $icons['all'];
                return '<span class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-full ' . $icon[1] . '">
                    <span class="material-symbols-outlined text-sm">' . $icon[0] . '</span>
                    ' . ucfirst($module) . '
                </span>';
            },
            'used_count' => function($row) {
                $used = intval($row['used_count']);
                $limit = $row['usage_limit'] ? intval($row['usage_limit']) : null;
                
                if ($limit) {
                    $pct = round(($used / $limit) * 100);
                    $color = $pct >= 90 ? 'red' : ($pct >= 60 ? 'yellow' : 'green');
                    return '<div class="flex flex-col gap-1">
                        <span class="text-sm font-medium">' . $used . ' / ' . $limit . '</span>
                        <div class="w-full bg-gray-200 rounded-full h-1.5">
                            <div class="bg-' . $color . '-500 h-1.5 rounded-full" style="width: ' . min($pct, 100) . '%"></div>
                        </div>
                    </div>';
                }
                return '<span class="font-medium">' . $used . '</span>';
            },
            'start_date' => function($row) {
                if (empty($row['start_date'])) return '<span class="text-gray-400 text-sm">—</span>';
                return '<span class="text-sm">' . date('M d, Y', strtotime($row['start_date'])) . '</span>';
            },
            'end_date' => function($row) {
                if (empty($row['end_date'])) return '<span class="text-gray-400 text-sm">' . T::no_expiry . '</span>';
                $isExpired = strtotime($row['end_date']) < time();
                $class = $isExpired ? 'text-red-600 font-medium' : 'text-sm';
                return '<span class="' . $class . '">' . date('M d, Y', strtotime($row['end_date'])) . 
                    ($isExpired ? ' <span class="text-xs">(' . T::expired . ')</span>' : '') . '</span>';
            }
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => true,
            'view' => false,
            'edit' => true,
            'delete' => true,
            'status' => true,
            'search' => true,
        ])
        ->action_urls([
            'add' => 'admin/promo-codes/add',
            'edit' => 'promo-codes/edit/{id}'
        ])
        ->col_width('code', '180px')
        ->col_width('discount_type', '120px')
        ->col_width('discount_value', '100px')
        ->col_width('module', '120px')
        ->col_width('used_count', '120px')
        ->col_width('status', '100px')
        ->render();
    ?>
</div>