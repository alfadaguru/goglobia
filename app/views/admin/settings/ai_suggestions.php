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

echo crud()->table('ai_suggestions')
    ->col('suggestions')
    ->title('AI Suggestions')
    ->perPage(10)
    ->order('id', 'DESC')
    ->actions([
        'view' => false,
        'delete' => true,
        'edit' => true,
        'status' => false,
        'search' => true,
    ])
    ->action_urls([
        'add' => admin.'/settings/ai-suggestions/add',
        'edit' => root.admin.'/settings/ai-suggestions/edit/{id}',
    ])
    ->id_column('id')
    ->row([
        'suggestions' => function ($row) {
            $text = htmlspecialchars((string) ($row['suggestions'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html = preg_replace_callback(
                '/:([a-z0-9_]+):(?:#([0-9A-Fa-f]{3,8}):)?/i',
                static function ($m) {
                    $icon = $m[1];
                    $color = !empty($m[2]) ? '#' . $m[2] : '#0058E6';
                    $colorEsc = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
                    return '<span class="material-symbols-outlined text-base align-middle" style="color:' . $colorEsc . '">' . $icon . '</span>';
                },
                $text
            );
            return '<span class="inline-flex flex-wrap items-center gap-1">' . $html . '</span>';
        },
    ])
    ->render();

?>

</div>
