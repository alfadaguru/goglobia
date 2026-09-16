<?php
/**
 * Updates Routes
 *
 * The file-updates installer lives at the web root (/updates.php) so it keeps
 * working even when the application itself is down. Only the Database Update
 * tool (live DB vs install/db.sql schema sync) remains here, since it needs
 * the application's DB layer. Every other old admin updates URL redirects to
 * the standalone installer.
 *
 * NOTE: the specific database routes are registered BEFORE the catch-all
 * redirects — the router matches in registration order.
 */

@$SECURE or die('Access Denied!');

//==============================================================
// DATABASE UPDATE PAGE  (compares live DB with install/db.sql)
//==============================================================
$router->get(admin.'/updates/database', function () use ($SECURE,$db) {

    ADMIN_AUTH(); // Admin authentication check

    $title = 'Database Update';
    $description = 'Sync the database schema with install/db.sql';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/".admin."/updates-database.php";
    require_once views."includes/footer.php";
});

//==============================================================
// DATABASE UPDATE — APPLY ONE CHANGE (AJAX, drives the progress bar)
//==============================================================
$router->post(admin.'/updates/database/apply', function () use ($SECURE,$db) {

    ADMIN_AUTH(); // Admin authentication check
    // CSRF: this applies real CREATE TABLE / ALTER TABLE migrations, so it is a
    // state-changing admin action and must not be triggerable cross-site. The SQL
    // itself comes only from install/db.sql (never the client), but a CSRF could
    // still make a logged-in admin run pending migrations at an attacker-chosen
    // moment. The admin UI calls this via fetch(), which app.js auto-attaches the
    // X-CSRF-TOKEN header to, so enforcement here is transparent to the UI.
    CSRF::guard(); // JSON 403 for this AJAX endpoint on a bad/absent token

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    try {
        $kind   = $_POST['kind']   ?? '';
        $table  = $_POST['table']  ?? '';
        $column = $_POST['column'] ?? '';

        // Recompute the diff server-side; only apply items that are genuinely
        // pending (the SQL comes from install/db.sql, never from the client).
        $diff = getDatabaseSchemaDiff($db);

        // Medoo's query() does NOT throw — it returns null and sets ->error.
        // Previously every branch ignored that, so a failed INSERT/ALTER was
        // reported as done and the same change came back on every reload.
        $run = function (string $sql) use ($db): void {
            $stmt = $db->query($sql);
            if ($stmt === null || !empty($db->error)) {
                $err = $db->error ?: ($db->errorInfo[2] ?? 'unknown database error');
                throw new RuntimeException($err);
            }
        };

        if ($kind === 'table') {
            foreach ($diff['tables'] as $t) {
                if ($t['table'] === $table) {
                    $run($t['sql']);
                    echo json_encode(['success' => true, 'message' => "Created table `{$table}`"]);
                    exit;
                }
            }
        } elseif ($kind === 'column') {
            foreach ($diff['columns'] as $c) {
                if ($c['table'] === $table && $c['column'] === $column) {
                    $run($c['sql']);
                    echo json_encode(['success' => true, 'message' => "Added column `{$table}`.`{$column}`"]);
                    exit;
                }
            }
        } elseif ($kind === 'modify') {
            foreach ($diff['modified'] ?? [] as $c) {
                if ($c['table'] === $table && $c['column'] === $column) {
                    $run($c['sql']);
                    echo json_encode(['success' => true, 'message' => "Extended column `{$table}`.`{$column}` (" . implode(', ', $c['added']) . ")"]);
                    exit;
                }
            }
        } elseif ($kind === 'module') {
            $name = $_POST['module'] ?? '';
            foreach ($diff['modules'] as $m) {
                if ($m['name'] !== $name) {
                    continue;
                }

                // The live `type` enum must accept this module's type first: on
                // non-strict MySQL an unknown enum value is silently stored as ''
                // (no error), so both the INSERT and the repair UPDATE would
                // "succeed" while the module keeps coming back as missing.
                {
                    $liveCols = [];
                    $typeEnum = null;
                    $idExtra  = '';
                    $idKey    = '';
                    foreach ($db->query("SHOW COLUMNS FROM `modules`")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                        $liveCols[strtolower($c['Field'])] = $c['Field'];
                        if (strtolower($c['Field']) === 'type') $typeEnum = (string) $c['Type'];
                        if (strtolower($c['Field']) === 'id') { $idExtra = strtolower((string) $c['Extra']); $idKey = (string) $c['Key']; }
                    }
                    $mtype = strtolower((string) $m['type']);
                    if ($mtype !== '' && $typeEnum !== null && preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $typeEnum, $mm)) {
                        $liveValues = array_map('strtolower', $mm[1]);
                        if (!in_array($mtype, $liveValues, true)) {
                            $liveValues[] = $mtype;
                            $list = implode(',', array_map(fn($v) => $db->pdo->quote($v), $liveValues));
                            $run("ALTER TABLE `modules` MODIFY COLUMN `type` enum({$list}) DEFAULT NULL");
                        }
                    }
                }

                if (!empty($m['repair'])) {
                    $run($m['sql']);
                } else {
                    // `id` must auto-increment — a table restored from a bare dump
                    //    (no trailing ALTER) rejects or zero-fills omitted ids.
                    if (isset($liveCols['id']) && strpos($idExtra, 'auto_increment') === false) {
                        if ($idKey !== 'PRI') {
                            $run("ALTER TABLE `modules` ADD PRIMARY KEY (`id`)");
                        }
                        $run("ALTER TABLE `modules` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT");
                    }

                    // 3) Insert only the columns this installation actually has.
                    $values = is_array($m['values'] ?? null) ? $m['values'] : [];
                    $cols = [];
                    $vals = [];
                    foreach ($values as $col => $literal) {
                        if (isset($liveCols[strtolower($col)])) {
                            $cols[] = "`{$liveCols[strtolower($col)]}`";
                            $vals[] = $literal;
                        }
                    }
                    $sql = $cols
                        ? "INSERT INTO `modules` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")"
                        : $m['sql'];
                    $run($sql);

                    // 4) Trust the database, not the call: confirm the row exists.
                    $check = $db->query(
                        "SELECT COUNT(*) FROM `modules` WHERE LOWER(`name`) = " . $db->pdo->quote(strtolower($m['name']))
                        . " AND LOWER(`type`) = " . $db->pdo->quote($mtype)
                    );
                    $count = $check ? (int) $check->fetchColumn() : 0;
                    if ($count < 1) {
                        throw new RuntimeException("Module `{$name}` was not saved — the INSERT did not persist (check the modules table structure)");
                    }
                }

                // Self-clean: exactly one row per name+type. Keep the oldest typed
                // row, drop any other same-typed rows and blank-type leftovers of
                // this name (earlier runs on an outdated enum created those).
                $qn = $db->pdo->quote(strtolower($m['name']));
                $qt = $db->pdo->quote($mtype);
                $keepStmt = $db->query("SELECT MIN(`id`) FROM `modules` WHERE LOWER(`name`) = {$qn} AND LOWER(`type`) = {$qt}");
                $keepId = $keepStmt ? (int) $keepStmt->fetchColumn() : 0;
                if ($keepId > 0) {
                    $run("DELETE FROM `modules` WHERE LOWER(`name`) = {$qn} AND `id` <> {$keepId}"
                       . " AND (LOWER(`type`) = {$qt} OR `type` = '' OR `type` IS NULL)");
                }

                $verb = !empty($m['repair']) ? 'Repaired' : 'Installed';
                echo json_encode(['success' => true, 'message' => "{$verb} module `{$name}`"]);
                exit;
            }
        } elseif ($kind === 'dedupe') {
            $name = strtolower(trim((string) ($_POST['module'] ?? '')));
            $type = strtolower(trim((string) ($_POST['type'] ?? '')));
            foreach ($diff['duplicates'] ?? [] as $d) {
                if (strtolower($d['name']) === $name && strtolower((string) $d['type']) === $type) {
                    $run($d['sql']);
                    $n = count($d['delete_ids']);
                    echo json_encode(['success' => true, 'message' => "Removed {$n} duplicate row" . ($n === 1 ? '' : 's') . " of module `{$d['name']}` ({$d['type']}) — kept #{$d['keep_id']}"]);
                    exit;
                }
            }
        } elseif ($kind === 'gateway') {
            $name = $_POST['gateway'] ?? '';
            foreach ($diff['gateways'] ?? [] as $g) {
                if ($g['name'] !== $name) {
                    continue;
                }

                // Insert only the columns this installation actually has (a client
                // table may lag the shipped schema); the DB assigns the id.
                $liveCols = [];
                $idExtra  = '';
                $idKey    = '';
                foreach ($db->query("SHOW COLUMNS FROM `payment_gateways`")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                    $liveCols[strtolower($c['Field'])] = $c['Field'];
                    if (strtolower($c['Field']) === 'id') { $idExtra = strtolower((string) $c['Extra']); $idKey = (string) $c['Key']; }
                }
                if (isset($liveCols['id']) && strpos($idExtra, 'auto_increment') === false) {
                    if ($idKey !== 'PRI') {
                        $run("ALTER TABLE `payment_gateways` ADD PRIMARY KEY (`id`)");
                    }
                    $run("ALTER TABLE `payment_gateways` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT");
                }

                $values = is_array($g['values'] ?? null) ? $g['values'] : [];
                $cols = [];
                $vals = [];
                foreach ($values as $col => $literal) {
                    if (isset($liveCols[strtolower($col)])) {
                        $cols[] = "`{$liveCols[strtolower($col)]}`";
                        $vals[] = $literal;
                    }
                }
                $sql = $cols
                    ? "INSERT INTO `payment_gateways` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")"
                    : $g['sql'];
                $run($sql);

                $check = $db->query("SELECT COUNT(*) FROM `payment_gateways` WHERE LOWER(`name`) = " . $db->pdo->quote(strtolower($g['name'])));
                if (!$check || (int) $check->fetchColumn() < 1) {
                    throw new RuntimeException("Gateway `{$name}` was not saved — the INSERT did not persist (check the payment_gateways table structure)");
                }

                echo json_encode(['success' => true, 'message' => "Installed gateway `{$name}`"]);
                exit;
            }
        }

        // Not found in the diff → already applied (idempotent success).
        echo json_encode(['success' => true, 'message' => 'Already up to date', 'skipped' => true]);
    } catch (Throwable $e) {
        error_log('DB UPDATE APPLY ERROR: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

//==============================================================
// EVERYTHING ELSE → standalone installer at /updates
//==============================================================
$router->get(admin.'/updates(.*)', function ($rest = '') use ($SECURE) {
    header('Location: ' . root . 'updates');
    exit;
});

$router->post(admin.'/updates(.*)', function ($rest = '') use ($SECURE) {
    header('Location: ' . root . 'updates');
    exit;
});
