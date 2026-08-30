<?php
// blogs.php
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
        ->table('blogs')
        ->title(T::blogs_management ?? 'Blogs Management')
        ->col('id,post_img,post_title,post_category,featured,created_at')
        ->relation('post_category', 'blog_categories', 'cat_name', 'id')
        ->label([
            'id' => T::id ?? 'ID',
            'post_img' => T::image ?? 'Image',
            'post_title' => T::title ?? 'Title',
            'post_category' => T::category ?? 'Category',
            'featured' => T::featured ?? 'Featured',
            'status' => T::status ?? 'Status',
            'created_at' => T::created_at ?? 'Created At'
        ])
        ->row([
            'post_img' => '
                <div class="w-12 h-12 rounded-lg overflow-hidden border border-gray-200">
                    <img src="'.root.'{{post_img}}" alt="Blog" class="w-full h-full object-cover" onerror="this.onerror=null;this.src=\''.root.'uploads/no_img.jpg\'">
                </div>
            ',
            'post_title' => '
                <div class="flex flex-col">
                    <div class="text-black font-medium">{{post_title}}</div>
                    <div class="text-xs text-gray-500 mt-1 truncate">{{post_slug}}</div>
                </div>
            ',
            'featured' => function($row) {
                if ($row['featured'] == 1) {
                    return '
                        <span class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">
                            <span class="material-symbols-outlined text-sm">star</span>
                            '. (T::featured ?? 'Featured') .'
                        </span>
                    ';
                } else {
                    return '
                        <span class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800">
                            <span class="material-symbols-outlined text-sm">star_outline</span>
                            '. (T::regular ?? 'Regular') .'
                        </span>
                    ';
                }
            },
            'created_at' => function($row) {
                return date('M d, Y', strtotime($row['created_at']));
            }
        ])
        ->order('id', 'DESC')
        ->actions([
            'add' => true,
            'view' => true,
            'edit' => true,
            'delete' => true,
            'status' => true,
            'featured' => true,
            'search' => true,
        ])
        ->action_urls([
            'view' => root.'blog/{post_slug}',
            'add' => 'admin/blogs/add',
            'edit' => 'blogs/edit/{id}'
        ])
        ->col_width('post_img', '70px')
        ->col_width('featured', '120px')
        ->col_width('status', '100px')
        ->render();
    ?>
</div>