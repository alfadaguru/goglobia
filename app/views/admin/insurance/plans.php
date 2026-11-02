<?php
// app/views/admin/insurance/plans.php — admin list of own-inventory insurance plans
@$SECURE or die('Access Denied!');
?>
<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= htmlspecialchars((string) $_SESSION['message']['text']) ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php
    echo crud()
        ->table('insurance_plans')
        ->title('Insurance Plans')
        ->col('id,name,provider_label,price,duration_days,region,featured')
        ->label([
            'id'             => 'ID',
            'name'           => 'Plan',
            'provider_label' => 'Provider',
            'price'          => 'Price',
            'duration_days'  => 'Days',
            'region'         => 'Region',
            'featured'       => 'Featured',
        ])
        ->row([
            'name'  => '<a href="' . root . admin . '/insurance/edit/{{id}}" class="text-black hover:underline">{{name}}</a>',
            'price' => '
                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <span class="material-symbols-outlined text-sm">payments</span>
                    {{currency}} {{price}}
                </span>
            ',
        ])
        ->order('featured', 'DESC')
        ->actions([
            'add'      => true,
            'view'     => false,
            'edit'     => true,
            'delete'   => true,
            'status'   => true,
            'featured' => true,
            'search'   => true,
        ])
        ->action_urls([
            'add'  => 'admin/insurance/add',
            'edit' => 'insurance/edit/{id}',
        ])
        ->render();
    ?>
</div>
