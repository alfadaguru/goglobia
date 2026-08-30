<?php
@$SECURE or die('Access Denied!');

// Guard: the metadata view is created in the route, but fall back cleanly if it
// could not be created (e.g. missing CREATE VIEW privilege).
$viewReady = (bool) $db->query(
    "SELECT 1 FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'database_tables'"
)->fetchColumn();
?>
<div class="container my-4">

    <?php if (!$viewReady): ?>
        <div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
            <span class="material-symbols-outlined text-gray-300 text-5xl block mb-3">database_off</span>
            <h3 class="text-lg font-medium text-gray-900 mb-1">Catalogue unavailable</h3>
            <p class="text-sm text-gray-500">The <span class="font-mono">database_tables</span> view could not be created. Check the database user's CREATE VIEW privilege.</p>
        </div>
    <?php else:
        echo crud()->table('database_tables')
            ->title(T::database)
            ->col('name,rows_count,size,engine,collation')
            ->label(['rows_count' => 'Rows', 'size' => 'Size'])
            ->row(['name' => '<a href="'.root.admin.'/settings/database/{{name}}" class="font-mono font-medium text-indigo-600 hover:text-indigo-800 hover:underline">{{name}}</a>'])
            ->id_column('name')
            ->order('name', 'ASC')
            ->perPage(100)
            ->actions([
                'add'         => false,
                'edit'        => false,
                'view'        => true,
                'delete'      => false,
                'status'      => false,
                'bulk_delete' => false,
                'search'      => true,
            ])
            ->action_urls(['view' => root.admin.'/settings/database/{name}'])
            ->action_icons(['view' => 'visibility'])
            ->render();
    endif; ?>
</div>
