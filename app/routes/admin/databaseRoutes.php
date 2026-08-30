<?php
/**
 * Database Routes
 * Admin database browser: list all tables + view any table's rows.
 */

@$SECURE or die('Access Denied!');

//==============================================================
// DATABASE TABLES LIST
//==============================================================
$router->get(admin.'/database', function () use ($SECURE, $db) {

    ADMIN_AUTH(); // Admin authentication check

    // Expose the live table catalogue as a SQL view so the shared CRUD library
    // (which reads real DB objects) can render it like any other table.
    try {
        $db->exec("CREATE OR REPLACE VIEW database_tables AS
            SELECT
                TABLE_NAME AS name,
                TABLE_ROWS AS rows_count,
                CASE
                    WHEN (DATA_LENGTH+INDEX_LENGTH) >= 1073741824 THEN CONCAT(ROUND((DATA_LENGTH+INDEX_LENGTH)/1073741824,2),' GB')
                    WHEN (DATA_LENGTH+INDEX_LENGTH) >= 1048576 THEN CONCAT(ROUND((DATA_LENGTH+INDEX_LENGTH)/1048576,2),' MB')
                    WHEN (DATA_LENGTH+INDEX_LENGTH) >= 1024 THEN CONCAT(ROUND((DATA_LENGTH+INDEX_LENGTH)/1024,2),' KB')
                    ELSE CONCAT((DATA_LENGTH+INDEX_LENGTH),' B')
                END AS size,
                ENGINE AS engine,
                TABLE_COLLATION AS collation
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    } catch (Throwable $e) {
        // View may already exist from install/db.sql, or the DB user may lack
        // CREATE VIEW privileges; the view page handles a missing view gracefully.
    }

    // Page metadata
    $title       = 'Database';
    $description = 'Database tables browser';
    $header      = true;
    $footer      = true;

    require_once views."includes/header.php";
    require_once "app/views/".admin."/database.php";
    require_once views."includes/footer.php";
});

//==============================================================
// SINGLE TABLE VIEW  ->  /admin/settings/database/{table_name}
//==============================================================
$router->get(admin.'/settings/database/([a-zA-Z0-9_]+)', function ($table_name) use ($SECURE, $db) {

    ADMIN_AUTH(); // Admin authentication check

    // Validate table name (strict allow-list of chars) and confirm it exists.
    // The route regex + this check restrict $table_name to [a-zA-Z0-9_], so it is
    // safe to embed directly in the information_schema lookups below.
    $exists = false;
    if (preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
        $exists = (bool) $db->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_name}'"
        )->fetchColumn();
    }

    if (!$exists) {
        http_response_code(404);
    }

    // Resolve the primary-key column (fallback to first column) for CRUD operations
    $pkColumn = null;
    if ($exists) {
        $pkColumn = $db->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_name}' AND COLUMN_KEY = 'PRI'
             ORDER BY ORDINAL_POSITION ASC LIMIT 1"
        )->fetchColumn();
        if (!$pkColumn) {
            $pkColumn = $db->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_name}'
                 ORDER BY ORDINAL_POSITION ASC LIMIT 1"
            )->fetchColumn();
        }
    }

    // Expose to the view
    $dbTable  = $table_name;
    $dbTablePk = $pkColumn ?: 'id';

    // Page metadata
    $title       = 'Table: ' . $table_name;
    $description = 'Browse table rows';
    $header      = true;
    $footer      = true;

    require_once views."includes/header.php";
    require_once "app/views/".admin."/database-table.php";
    require_once views."includes/footer.php";
});
