<?php
// app/views/admin/cars/cars.php
@$SECURE or die('Access Denied!');
?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div
            class="alert <?= $_SESSION['message']['type'] === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-red-50 text-red-700 border-red-200' ?> p-4 rounded-lg border mb-6 flex items-start gap-3 shadow-sm">
            <span
                class="material-symbols-outlined mt-0.5 text-xl"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div class="text-sm font-medium"><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php
    echo crud()
        ->table('cars')
        ->title(T::cars_management ?? 'Cars Management')
        ->col('id,img,name,brand,model,year,service_type,car_type_id')
        ->relation('car_type_id', 'cars_settings', 'setting_label', 'id', ['setting_type' => 'car_type'])
        ->label([
            'id' => T::car_id ?? 'ID',
            'name' => T::car_name ?? 'Car Name',
            'brand' => T::brand ?? 'Brand',
            'model' => T::model ?? 'Model',
            'year' => T::year ?? 'Year',
            'img' => T::image ?? 'Image',
            'service_type' => T::service_type ?? 'Service',
            'car_type_id' => T::car_type ?? 'Type',
        ])
        ->row([
            'img' => '
                <div class="w-12 h-12 rounded-full overflow-hidden bg-slate-100 border border-slate-200 shadow-sm flex-shrink-0">
                    <img src="' . root . '{{img}}" alt="Car" class="w-full h-full object-contain" onerror="this.onerror=null;this.src=\'' . root . 'uploads/no_img.jpg\'">
                </div>
            ',
            'name' => '
                <div class="flex flex-col">
                    <a href="' . root . admin . '/cars/edit/{{id}}" class="font-bold text-slate-800 hover:text-blue-600 transition-colors">{{name}}</a>
                    <span class="text-xs text-slate-500 font-medium tracking-tight">{{brand}} {{model}} ({{year}})</span>
                </div>
            ',
            'service_type' => '
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700 capitalize">
                    {{service_type}}
                </span>
            '
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => true,
            'view' => false,
            'edit' => true,
            'delete' => true,
            'search' => true,
        ])
        ->action_urls([
            'add' => 'admin/cars/add',
            'edit' => 'cars/edit/{id}'
        ])
        ->render();
    ?>
</div>