<?php
// app/views/admin/cruises/list.php — admin list of own-inventory cruises
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
        ->table('cruises')
        ->title('Cruises')
        ->col('id,name,cruise_line,destination,nights,price,featured')
        ->label([
            'id'          => 'ID',
            'name'        => 'Cruise',
            'cruise_line' => 'Line',
            'destination' => 'Destination',
            'nights'      => 'Nights',
            'price'       => 'Price',
            'featured'    => 'Featured',
        ])
        ->row([
            'name'  => '<a href="' . root . admin . '/cruises/edit/{{id}}" class="text-black hover:underline">{{name}}</a>',
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
            'add'  => 'admin/cruises/add',
            'edit' => 'cruises/edit/{id}',
        ])
        ->render();
    ?>
</div>
