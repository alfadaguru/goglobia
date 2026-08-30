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

echo crud()->table('cms')
    ->col('page_name,slug_url,position,order')
    ->title('CMS Pages')
    ->actions([
        'view' => false,
        'delete' => true,
        'edit' => true,
        'status' => true,
        'default' => true,
    ])
    ->row([
        'slug_url' => function($row) {
            if (empty($row['slug_url'])) {
                return '<span class="text-rose-600 font-semibold italic flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">warning</span> 
                            No Slug Set
                        </span>';
            }
            return '<code class="bg-slate-100 px-2 py-0.5 rounded text-xs text-slate-700">' . htmlspecialchars($row['slug_url']) . '</code>';
        }
    ])
    ->custom_button([
        'label' => '',
        'icon' => 'visibility',
        'url' => root.'page/{slug_url}',
        'target' => '_blank',
        'class' => 'text-purple-600 hover:bg-purple-100',
        'title' => T::translate ?? 'Translate'
    ])
    ->action_urls([
        'add' => admin.'/cms/pages/add',
        'edit' => root.admin.'/cms/pages/edit/{id}',
    ])
    ->id_column('id')
    ->render();

?>

</div>