<?php
/**
 * STANDALONE UPDATES INSTALLER
 *
 * Lives at the web root and is deliberately 100% independent of the
 * application bootstrap (config.php, router, DB, sessions). If the app
 * fatals, this page still loads, so a fixing update can always be shipped.
 *
 * Public by design: anyone can view pending official updates and install
 * them — nothing else. The repo/token come from the server-side remote
 * credentials endpoint and are never exposed; only commits from that repo,
 * in strict oldest-first order, can be installed.
 */

error_reporting(0);
ini_set('display_errors', '0');
set_time_limit(300);

define('UPD_ROOT', __DIR__);
define('UPD_HISTORY', __DIR__ . '/app/updates.json');
define('UPD_CREDENTIALS_URL', 'https://update.goglobia.com/updates.json');

// Files an update must never overwrite (per-site data/config).
// NOTE: install/db.sql IS shipped to installations now — the Database Update
// tool compares the live DB against it, so clients need the latest seed. It is
// therefore intentionally NOT excluded and NOT treated as internal-only.
// .htaccess is deliberately NOT excluded: the repo copy is generic (no
// per-site paths) and it must reach installations so routes like /updates
// keep working when the rewrite rules change.
$UPD_EXCLUDED = [
    'app/views/admin/updates-config.php',
    'app/updates.json',
    '.env',
    'uploads/',
    'cache/',
    'backups/',
];

// Commits whose EVERY file matches one of these are internal repo housekeeping:
// they are hidden from the updates list and auto-skipped in the install queue.
// (Empty — install/db.sql now ships to clients, so nothing is hidden by default.)
$UPD_INTERNAL_ONLY = [];

//==============================================================
// HELPERS (self-contained ports of the admin updater's logic)
//==============================================================

function upd_credentials() {
    if (!function_exists('curl_init')) return [];
    $ch = curl_init(UPD_CREDENTIALS_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'v10-Updates-Client',
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Cache-Control: no-cache'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return [];
    $d = json_decode($body, true);
    if (is_array($d) && isset($d['credentials']) && is_array($d['credentials'])) $d = $d['credentials'];
    if (!is_array($d) || empty($d['github_token']) || empty($d['github_repo'])) return [];
    return $d;
}

function upd_github($endpoint, $token, $timeout = 30) {
    $ch = curl_init('https://api.github.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'v10-Updates-Client',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github.v3+json',
            'Authorization: token ' . $token,
            'Cache-Control: no-cache',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body !== false ? json_decode($body, true) : null, 'error' => $err];
}

function upd_download_raw($url, $token, $timeout = 120) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'v10-Updates-Client',
        CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3.raw', 'Authorization: token ' . $token],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}

function upd_encode_path($path) {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function upd_replace_file($targetPath, $content) {
    $dir = dirname($targetPath);
    if (!is_dir($dir)) return ['success' => false, 'bytes' => 0, 'error' => 'Target directory does not exist'];
    $expected = strlen($content);
    $existed = file_exists($targetPath);
    if ($existed && !is_writable($targetPath)) @chmod($targetPath, 0644);

    $tmp = $dir . DIRECTORY_SEPARATOR . '.upd_' . md5($targetPath . microtime(true)) . '.tmp';
    $bytes = @file_put_contents($tmp, $content, LOCK_EX);
    if ($bytes === false) {
        $e = error_get_last();
        return ['success' => false, 'bytes' => 0, 'error' => $e['message'] ?? 'Failed writing temporary file'];
    }

    // 1) Preferred: atomic temp -> target rename.
    if (@rename($tmp, $targetPath)) { @chmod($targetPath, 0644); return ['success' => true, 'bytes' => (int)$bytes, 'error' => null]; }

    // Rename is blocked (typical on Windows when the target is open — e.g. this
    // very script updating itself). Everything below keeps a backup so the
    // target can NEVER be lost or left corrupted.
    $bak = null;
    if ($existed && file_exists($targetPath)) {
        $bak = $dir . DIRECTORY_SEPARATOR . '.upd_' . md5($targetPath) . '.bak';
        if (!@copy($targetPath, $bak)) $bak = null;
    }

    // 2) In-place overwrite (no delete involved — works for the running script).
    $direct = @file_put_contents($targetPath, $content);
    if ($direct === $expected) {
        @unlink($tmp);
        if ($bak) @unlink($bak);
        @chmod($targetPath, 0644);
        return ['success' => true, 'bytes' => (int)$direct, 'error' => null];
    }
    if ($direct !== false && $bak) @copy($bak, $targetPath); // partial write — roll back

    // 3) Last resort: unlink + rename, restoring the backup on any failure.
    if (file_exists($targetPath)) @unlink($targetPath);
    if (@rename($tmp, $targetPath)) {
        if ($bak) @unlink($bak);
        @chmod($targetPath, 0644);
        return ['success' => true, 'bytes' => (int)$bytes, 'error' => null];
    }
    if (@copy($tmp, $targetPath)) {
        @unlink($tmp);
        if ($bak) @unlink($bak);
        @chmod($targetPath, 0644);
        return ['success' => true, 'bytes' => (int)$bytes, 'error' => null];
    }

    // Total failure — put the original back so the site keeps working.
    if ($bak && file_exists($bak)) { @copy($bak, $targetPath); @unlink($bak); }
    $e = error_get_last();
    @unlink($tmp);
    return ['success' => false, 'bytes' => 0, 'error' => ($e['message'] ?? 'Failed replacing target file') . ' — original file restored'];
}

function upd_history_load() {
    $data = ['meta' => ['version' => '', 'last_check' => date('Y-m-d H:i:s'), 'total_updates' => 0], 'updates' => []];
    if (file_exists(UPD_HISTORY)) {
        $decoded = json_decode((string) file_get_contents(UPD_HISTORY), true);
        if (is_array($decoded)) $data = $decoded;
    }
    return $data;
}

function upd_history_save($data) {
    $data['meta']['last_check'] = date('Y-m-d H:i:s');
    $data['meta']['total_updates'] = count($data['updates']);
    @file_put_contents(UPD_HISTORY, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function upd_installed_shas($data) {
    $out = [];
    foreach ($data['updates'] as $u) {
        if (in_array($u['status'] ?? '', ['installed', 'partial_failed'], true)) $out[] = $u['uid'];
    }
    return $out;
}

// Read updates_start_date from updates-config.php as TEXT (never executed —
// that file needs the app's $db and would fatal here).
function upd_start_date() {
    $file = UPD_ROOT . '/app/views/admin/updates-config.php';
    if (file_exists($file)) {
        $src = (string) file_get_contents($file);
        if (preg_match("/'updates_start_date'\s*=>\s*'([^']+)'/", $src, $m)) return $m[1];
    }
    return '2026-08-15';
}

// Fetch the commit list (OLDEST first, filtered by start date — same as admin).
function upd_commit_list($repo, $token) {
    $result = upd_github('/repos/' . $repo . '/commits?per_page=100&t=' . time(), $token);
    if ($result['code'] !== 200 || !is_array($result['body'])) return null;
    $start = strtotime(upd_start_date());
    $commits = array_values(array_filter($result['body'], function ($c) use ($start) {
        return strtotime($c['commit']['author']['date'] ?? '') >= $start;
    }));
    usort($commits, function ($a, $b) {
        return strtotime($a['commit']['author']['date']) - strtotime($b['commit']['author']['date']);
    });
    return $commits;
}

/** Fetch the changed-file names of one commit (null on failure). */
function upd_commit_filenames($repo, $token, $sha) {
    $result = upd_github('/repos/' . $repo . '/commits/' . urlencode($sha), $token, 30);
    if ($result['code'] !== 200 || empty($result['body']['files']) || !is_array($result['body']['files'])) return null;
    return array_map(function ($f) { return $f['filename'] ?? ''; }, $result['body']['files']);
}

/** True when every file in the list is internal repo housekeeping. */
function upd_files_are_internal(array $files) {
    global $UPD_INTERNAL_ONLY;
    if (empty($files)) return false;
    foreach ($files as $file) {
        $matched = false;
        foreach ($UPD_INTERNAL_ONLY as $pattern) {
            if (strpos($file, rtrim($pattern, '/')) === 0) { $matched = true; break; }
        }
        if (!$matched) return false;
    }
    return true;
}

/**
 * Walk the pending queue oldest-first and auto-mark internal-only commits
 * (those touching only files in $UPD_INTERNAL_ONLY — currently none) as
 * skipped, so they never appear in the list and never block the sequential
 * install order. Stops at the first commit that ships real files. Returns the
 * refreshed installed-sha list.
 */
function upd_autoskip_internal($repo, $token, array $commits, array &$history) {
    $installed = upd_installed_shas($history);
    $changed   = false;
    $lookups   = 0;

    foreach ($commits as $commit) {
        $sha = $commit['sha'];
        if (in_array($sha, $installed, true)) continue;

        if ($lookups >= 5) break; // keep page loads fast; the rest resolves next visit
        $lookups++;

        $files = upd_commit_filenames($repo, $token, $sha);
        if ($files === null || !upd_files_are_internal($files)) break;

        $history['updates'][] = [
            'uid'           => $sha,
            'status'        => 'installed',
            'internal'      => true,
            'installed_at'  => date('Y-m-d H:i:s'),
            'files_updated' => 0,
            'files_failed'  => 0,
            'files_skipped' => count($files),
            'db_executed'   => false,
            'db_skipped'    => true,
            'db_queries'    => 0,
            'db_errors'     => 0,
            'message'       => $commit['commit']['message'] ?? '',
            'author'        => $commit['commit']['author']['name'] ?? '',
            'date'          => $commit['commit']['author']['date'] ?? '',
            'note'          => 'Auto-skipped — internal repo files only (not shipped to installations)',
        ];
        $installed[] = $sha;
        $changed = true;
    }

    if ($changed) upd_history_save($history);
    return $installed;
}

/** Sha list of history entries flagged internal (for hiding them in the UI). */
function upd_internal_shas(array $history) {
    $out = [];
    foreach ($history['updates'] as $u) {
        if (!empty($u['internal'])) $out[] = $u['uid'];
    }
    return $out;
}

// Optional DB connection straight from .env — used only to run db.sql
// migrations shipped inside an update. Failure is non-fatal by design.
function upd_db() {
    try {
        $envFile = UPD_ROOT . '/.env';
        if (!file_exists($envFile)) return null;
        $env = @parse_ini_file($envFile);
        if (!is_array($env) || empty($env['DB_DATABASE'])) return null;
        return new PDO(
            'mysql:host=' . ($env['DB_HOST'] ?? 'localhost') . ';dbname=' . $env['DB_DATABASE'] . ';charset=utf8mb4',
            $env['DB_USERNAME'] ?? '',
            $env['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
    } catch (Throwable $e) {
        return null;
    }
}

function upd_parse_sql($sql) {
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/#.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

function upd_json($payload) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($payload);
    exit;
}

//==============================================================
// FILES ENDPOINT (GET ?action=files&sha=) — changed files of one commit,
// shown in the "Update Details" modal before installing.
//==============================================================

if (($_GET['action'] ?? '') === 'files') {
    try {
        $sha = strtolower(trim($_GET['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{7,40}$/', $sha)) upd_json(['success' => false, 'error' => 'Commit SHA is required']);

        $creds = upd_credentials();
        if (empty($creds)) upd_json(['success' => false, 'error' => 'Update service unavailable']);

        $result = upd_github('/repos/' . $creds['github_repo'] . '/commits/' . urlencode($sha), $creds['github_token']);
        if ($result['code'] !== 200 || !isset($result['body']['files'])) {
            upd_json(['success' => false, 'error' => 'Could not load commit files (HTTP ' . $result['code'] . ')']);
        }

        $files = array_map(function ($file) {
            return [
                'filename'  => $file['filename'],
                'status'    => $file['status'],
                'additions' => $file['additions'] ?? 0,
                'deletions' => $file['deletions'] ?? 0,
                'changes'   => $file['changes'] ?? 0,
                'patch'     => isset($file['patch']) ? substr($file['patch'], 0, 200) : null,
            ];
        }, $result['body']['files']);

        upd_json(['success' => true, 'files' => $files, 'total_files' => count($files)]);
    } catch (Throwable $e) {
        error_log('UPDATES FILES ERROR: ' . $e->getMessage());
        upd_json(['success' => false, 'error' => 'Failed to load file information']);
    }
}

//==============================================================
// INSTALL (POST action=install) — public, but strictly limited to
// installing the OLDEST pending official commit, nothing else.
//==============================================================

if (($_POST['action'] ?? '') === 'install') {
    try {
        $sha = strtolower(trim($_POST['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$/', $sha)) upd_json(['success' => false, 'message' => 'Invalid update id']);

        $creds = upd_credentials();
        if (empty($creds)) upd_json(['success' => false, 'message' => 'Update service unavailable. Try again shortly.']);
        $repo  = $creds['github_repo'];
        $token = $creds['github_token'];

        $history   = upd_history_load();
        $installed = upd_installed_shas($history);
        if (in_array($sha, $installed, true)) upd_json(['success' => true, 'message' => 'Already installed', 'data' => ['files_updated' => 0, 'files_failed' => 0, 'files_skipped' => 0]]);

        // Server-side sequential enforcement: only the oldest pending commit
        // from the official repo may be installed.
        $commits = upd_commit_list($repo, $token);
        if ($commits === null) upd_json(['success' => false, 'message' => 'Could not reach the update server']);

        // Internal-only commits (repo housekeeping) never block the queue
        $installed = upd_autoskip_internal($repo, $token, $commits, $history);
        if (in_array($sha, $installed, true)) upd_json(['success' => true, 'message' => 'Already installed', 'data' => ['files_updated' => 0, 'files_failed' => 0, 'files_skipped' => 0]]);

        $pendingOldestFirst = [];
        foreach ($commits as $c) {
            if (!in_array($c['sha'], $installed, true)) $pendingOldestFirst[] = $c['sha'];
        }
        if (empty($pendingOldestFirst)) upd_json(['success' => true, 'message' => 'Everything is up to date', 'data' => ['files_updated' => 0, 'files_failed' => 0, 'files_skipped' => 0]]);
        if ($sha !== $pendingOldestFirst[0]) upd_json(['success' => false, 'message' => 'Updates must be installed in order — install the oldest pending update first']);

        $result = upd_github('/repos/' . $repo . '/commits/' . $sha, $token);
        if ($result['code'] !== 200 || empty($result['body']['files'])) {
            upd_json(['success' => false, 'message' => 'Could not load update details (HTTP ' . $result['code'] . ')']);
        }
        $commitData = $result['body'];

        global $UPD_EXCLUDED;
        $updated = $failed = $skipped = [];
        $debugInfo = [
            'root_path' => UPD_ROOT,
            'temp_dir' => 'in-place (atomic temp+rename per file)',
            'total_files_in_commit' => count($commitData['files']),
            'php_file_location' => __FILE__,
            'details' => [],
        ];

        foreach ($commitData['files'] as $file) {
            $filename = $file['filename'];
            $status   = $file['status'];
            $fileDebug = ['filename' => $filename, 'status' => $status];

            $isExcluded = false;
            foreach ($UPD_EXCLUDED as $pattern) {
                if (strpos($filename, rtrim($pattern, '/')) === 0) {
                    $isExcluded = true;
                    $fileDebug['result'] = 'skipped (excluded by pattern: ' . $pattern . ')';
                    break;
                }
            }
            if ($isExcluded) { $skipped[] = $filename; $debugInfo['details'][] = $fileDebug; continue; }

            $normalized = str_replace('/', DIRECTORY_SEPARATOR, $filename);
            $target = UPD_ROOT . DIRECTORY_SEPARATOR . $normalized;
            $fileDebug['normalized_filename'] = $normalized;
            $fileDebug['target_path'] = $target;
            $fileDebug['target_dir'] = dirname($target);
            $fileDebug['file_exists_before'] = file_exists($target);

            if ($status === 'removed') {
                if (!file_exists($target)) {
                    $skipped[] = $filename;
                    $fileDebug['result'] = 'skipped (file not found)';
                } elseif (@unlink($target)) {
                    $updated[] = $filename . ' (deleted)';
                    $fileDebug['result'] = 'deleted successfully';
                } else {
                    $failed[] = $filename;
                    $fileDebug['result'] = 'failed to delete';
                }
                $debugInfo['details'][] = $fileDebug;
                continue;
            }

            // Download full file content at this commit (retry once; large files
            // fall back to the raw download_url).
            $content = null;
            $downloadError = null;
            $downloadCode = 0;
            for ($attempt = 1; $attempt <= 2 && $content === null; $attempt++) {
                $r = upd_github('/repos/' . $repo . '/contents/' . upd_encode_path($filename) . '?ref=' . $sha, $token, 60);
                $downloadCode = $r['code'];
                if ($r['code'] === 200 && !empty($r['body']['content'])) {
                    $content = base64_decode(str_replace("\n", '', $r['body']['content']));
                    break;
                }
                if ($r['code'] === 200 && !empty($r['body']['download_url'])) {
                    $raw = upd_download_raw($r['body']['download_url'], $token);
                    if ($raw['code'] === 200 && $raw['body'] !== false && $raw['body'] !== '') { $content = $raw['body']; break; }
                    $downloadError = 'raw download HTTP ' . $raw['code'] . ($raw['error'] ? ' (' . $raw['error'] . ')' : '');
                    continue;
                }
                $downloadError = 'HTTP ' . $r['code'];
                if (isset($r['body']['message'])) $downloadError .= ': ' . $r['body']['message'];
            }
            $fileDebug['download_http_code'] = $downloadCode;

            if ($content === null) {
                $skipped[] = $filename;
                $fileDebug['result'] = 'skipped (download unavailable: ' . $downloadError . ')';
                $fileDebug['error'] = $downloadError;
                $debugInfo['details'][] = $fileDebug;
                continue;
            }
            $fileDebug['download_size'] = strlen($content);
            $fileDebug['content_size'] = strlen($content);

            $dir = dirname($target);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    $e = error_get_last();
                    $failed[] = $filename;
                    $fileDebug['result'] = 'failed mkdir';
                    $fileDebug['error'] = $e['message'] ?? 'mkdir failed';
                    $debugInfo['details'][] = $fileDebug;
                    continue;
                }
                $fileDebug['dir_created'] = true;
            } else {
                $fileDebug['dir_exists'] = true;
            }
            $fileDebug['dir_writable'] = is_writable($dir);
            if (!$fileDebug['dir_writable']) {
                $failed[] = $filename;
                $fileDebug['result'] = 'failed (directory not writable)';
                $debugInfo['details'][] = $fileDebug;
                continue;
            }

            $write = upd_replace_file($target, $content);
            $fileDebug['bytes_written'] = $write['bytes'];
            $fileDebug['file_exists_after'] = file_exists($target);
            if ($write['success']) {
                $updated[] = $filename;
                $fileDebug['result'] = 'success';
            } else {
                $failed[] = $filename;
                $fileDebug['result'] = 'failed write';
                $fileDebug['error'] = $write['error'];
            }
            $debugInfo['details'][] = $fileDebug;
        }

        // Run db.sql migrations when shipped (best-effort; DB may be down).
        $dbExecuted = false;
        $dbErrors = 0;
        if (in_array('app/database/db.sql', $updated, true)) {
            $pdo = upd_db();
            $sqlFile = UPD_ROOT . '/app/database/db.sql';
            if ($pdo && file_exists($sqlFile)) {
                foreach (upd_parse_sql((string) file_get_contents($sqlFile)) as $query) {
                    try { $pdo->exec($query); $dbExecuted = true; } catch (Throwable $e) { $dbErrors++; error_log('UPDATES DB: ' . $e->getMessage()); }
                }
            }
        }

        $entry = [
            'uid' => $sha,
            'status' => count($failed) ? 'partial_failed' : 'installed',
            'installed_at' => date('Y-m-d H:i:s'),
            'files_updated' => count($updated),
            'files_failed' => count($failed),
            'files_skipped' => count($skipped),
            'db_executed' => $dbExecuted,
            'db_skipped' => !$dbExecuted,
            'db_queries' => 0,
            'db_errors' => $dbErrors,
            'message' => $commitData['commit']['message'] ?? '',
            'author' => $commitData['commit']['author']['name'] ?? '',
            'date' => $commitData['commit']['author']['date'] ?? '',
        ];
        $replaced = false;
        foreach ($history['updates'] as $i => $u) {
            if (($u['uid'] ?? '') === $sha) { $history['updates'][$i] = $entry; $replaced = true; break; }
        }
        if (!$replaced) $history['updates'][] = $entry;
        upd_history_save($history);

        upd_json([
            'success' => count($failed) === 0,
            'message' => count($failed) === 0
                ? 'Update installed (' . count($updated) . ' files)'
                : count($failed) . ' file(s) could not be written',
            'data' => [
                'files_updated' => count($updated),
                'files_failed' => count($failed),
                'files_skipped' => count($skipped),
                'db_executed' => $dbExecuted,
                'debug' => $debugInfo,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('UPDATES INSTALL ERROR: ' . $e->getMessage());
        upd_json(['success' => false, 'message' => 'Installation failed. Check server logs.']);
    }
}

//==============================================================
// .HTACCESS SELF-HEAL
// Older updaters excluded .htaccess from updates, so installations are
// stranded on stale rewrite rules (e.g. /updates 404s and only
// /updates.php works). Whenever this page loads and the local .htaccess
// lacks the /updates rewrite, pull the current repo copy and replace it.
// Best-effort and silent: any failure leaves the existing file untouched.
//==============================================================

function upd_selfheal_htaccess($creds) {
    if (empty($creds['github_repo']) || empty($creds['github_token'])) return;
    $path = UPD_ROOT . DIRECTORY_SEPARATOR . '.htaccess';
    $current = @file_get_contents($path);
    if ($current !== false && strpos($current, 'RewriteRule ^updates/?$ updates.php') !== false) return;

    $r = upd_github('/repos/' . $creds['github_repo'] . '/contents/.htaccess', $creds['github_token'], 30);
    $content = null;
    if ($r['code'] === 200 && !empty($r['body']['content'])) {
        $content = base64_decode(str_replace("\n", '', $r['body']['content']));
    } elseif ($r['code'] === 200 && !empty($r['body']['download_url'])) {
        $raw = upd_download_raw($r['body']['download_url'], $creds['github_token']);
        if ($raw['code'] === 200 && $raw['body'] !== false && $raw['body'] !== '') $content = $raw['body'];
    }
    // Sanity: only ever write something that actually looks like our .htaccess
    // and carries the /updates rule this heal exists to restore.
    if (!$content || strpos($content, 'RewriteRule ^updates/?$ updates.php') === false) return;
    if ($current !== false && trim($content) === trim($current)) return;
    upd_replace_file($path, $content);
}

//==============================================================
// PAGE (GET)
//==============================================================

$creds     = upd_credentials();
if (!empty($creds)) { try { upd_selfheal_htaccess($creds); } catch (Throwable $e) {} }
$history   = upd_history_load();
$installed = upd_installed_shas($history);
$commits   = [];
$loadError = null;

if (empty($creds)) {
    $loadError = 'Update service is unreachable right now. Please try again in a few minutes.';
} else {
    $commits = upd_commit_list($creds['github_repo'], $creds['github_token']);
    if ($commits === null) { $commits = []; $loadError = 'Failed to fetch updates from GitHub.'; }
    else {
        // Auto-skip internal-only commits (files in $UPD_INTERNAL_ONLY, currently
        // none) so they never show up or block the queue, then hide them entirely.
        $installed = upd_autoskip_internal($creds['github_repo'], $creds['github_token'], $commits, $history);
        $internalShas = upd_internal_shas($history);
        $commits = array_values(array_filter($commits, function ($c) use ($internalShas) {
            return !in_array($c['sha'], $internalShas, true);
        }));
        upd_history_save($history); // refresh last_check
    }
}

// $commits is oldest-first. Sequential: first pending = oldest uninstalled.
$firstPendingSha = null;
foreach ($commits as $c) {
    if (!in_array($c['sha'], $installed, true)) { $firstPendingSha = $c['sha']; break; }
}
$pendingShas = array_values(array_filter(
    array_map(function ($c) { return $c['sha']; }, $commits),
    function ($sha) use ($installed) { return !in_array($sha, $installed, true); }
));
$pendingCount = count($pendingShas);

// Installed count shown in the UI excludes hidden internal entries
$internalShas = $internalShas ?? [];
$installedVisibleCount = count(array_diff($installed, $internalShas));

// Best-effort extras for the stat cards (never fatal).
$currentVersion = '—';
try {
    $pdo = upd_db();
    if ($pdo) {
        $v = $pdo->query('SELECT version FROM settings LIMIT 1')->fetchColumn();
        if ($v) $currentVersion = $v;
    }
} catch (Throwable $e) {}
$lastCheck    = $history['meta']['last_check'] ?? null;
$serverOnline = !empty($creds);
$startDate    = upd_start_date();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Updates</title>
    <link rel="shortcut icon" href="uploads/global/favicon.png">
    <script defer src="assets/js/tailwind.js"></script>
    <script src="https://cdn.tailwindcss.com" data-cfasync="false"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <style>
        .material-symbols-outlined { font-family: 'Material Symbols Outlined'; font-weight: normal; font-style: normal; display: inline-block; line-height: 1; letter-spacing: normal; text-transform: none; white-space: nowrap; word-wrap: normal; direction: ltr; }
        body { font-family: 'Outfit', system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 0.5rem; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.375rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; background: #2563eb; color: #fff; border: 1px solid #2563eb; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .btn:hover { background: #1d4ed8; }
        .btn.secondary { background: #fff; color: #334155; border-color: #cbd5e0; }
        .btn.secondary:hover { background: #f8fafc; }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="max-w-6xl mx-auto px-4 my-4 py-4">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">System Updates</h1>
            <p class="text-sm text-slate-600 mt-1">Standalone installer — works even if the main application is down.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="updates.php" class="btn secondary">
                <span class="material-symbols-outlined text-lg">refresh</span>
                Refresh
            </a>
            <a href="updates.php?check_updates=1" class="btn">
                <span class="material-symbols-outlined text-lg">cloud_download</span>
                Check For Updates
            </a>
            <a href="admin/updates/database" class="btn" style="background:#16a34a;border-color:#16a34a;">
                <span class="material-symbols-outlined text-lg">database</span>
                Database Update
            </a>
            <?php if ($pendingCount > 2): ?>
            <button type="button" onclick="startInstallAll(<?= $pendingCount ?>)"
                class="btn" style="background:#ea580c;border-color:#ea580c;">
                <span class="material-symbols-outlined text-lg">bolt</span>
                Install All Updates (<?= $pendingCount ?>)
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Install-all progress banner (auto-driven across page reloads) -->
    <div id="installAllBanner" class="hidden mb-6 bg-orange-50 border border-orange-200 rounded-lg p-4">
        <div class="flex items-center gap-3">
            <svg class="animate-spin h-5 w-5 text-orange-600 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <div class="flex-1 min-w-0">
                <div id="installAllText" class="text-sm font-medium text-orange-800">Installing updates…</div>
                <div class="w-full bg-orange-100 rounded-full h-2 mt-2 overflow-hidden">
                    <div id="installAllBar" class="h-2 bg-orange-500 rounded-full transition-all duration-300" style="width:0%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="card p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-blue-600 text-2xl">deployed_code</span>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium">Current Version</div>
                    <div class="text-lg font-bold text-slate-800"><?= htmlspecialchars($currentVersion) ?></div>
                </div>
            </div>
        </div>
        <div class="card p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-green-600 text-2xl">check_circle</span>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium">Installed Updates</div>
                    <div class="text-lg font-bold text-slate-800"><?= $installedVisibleCount ?></div>
                </div>
            </div>
        </div>
        <div class="card p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-purple-600 text-2xl">schedule</span>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium">Last Checked</div>
                    <div class="text-sm font-medium text-slate-800"><?= $lastCheck ? date('M d, Y H:i', strtotime($lastCheck)) : 'Never' ?></div>
                </div>
            </div>
        </div>
        <div class="card p-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 <?= $serverOnline ? 'bg-blue-600' : 'bg-red-500' ?> rounded-lg flex items-center justify-center">
                    <span class="material-symbols-outlined text-white text-2xl">cloud_sync</span>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium">Updates Server</div>
                    <div class="text-sm font-medium text-slate-800 flex items-center gap-1.5">
                        <?= $serverOnline ? 'Connected' : 'Unreachable' ?>
                        <span class="px-1.5 py-0.5 <?= $serverOnline ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?> rounded text-xs inline-flex items-center gap-0.5">
                            <span class="material-symbols-outlined text-xs"><?= $serverOnline ? 'check_circle' : 'error' ?></span><?= $serverOnline ? 'Online' : 'Offline' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($loadError): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-6 text-sm flex items-center gap-2">
        <span class="material-symbols-outlined">error</span>
        <span><?= htmlspecialchars($loadError) ?></span>
    </div>
    <?php endif; ?>

    <!-- Sequential Mode Info -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
            <div>
                <h3 class="font-semibold text-blue-900 mb-1">Sequential Installation Mode Active</h3>
                <p class="text-sm text-blue-700">Updates must be installed in order, from oldest to newest.</p>
                <p class="text-xs text-blue-600 mt-2">
                    <span class="material-symbols-outlined text-sm align-middle">calendar_today</span>
                    Showing updates from: <strong><?= date('M d, Y', strtotime($startDate)) ?></strong> onwards
                </p>
            </div>
        </div>
    </div>

    <!-- Updates List -->
    <div class="card p-4">
        <!-- Tabs -->
        <div class="flex items-center justify-between border-b border-slate-200 mb-4">
            <nav class="flex -mb-px" aria-label="Tabs">
                <button type="button" onclick="switchUpdatesTab('new')" id="tabBtnNew"
                        class="whitespace-nowrap py-3 px-5 border-b-2 font-medium text-sm transition-colors border-blue-500 text-blue-600">
                    New Updates<?= $pendingCount > 0 ? ' (' . $pendingCount . ')' : '' ?>
                </button>
                <button type="button" onclick="switchUpdatesTab('installed')" id="tabBtnInstalled"
                        class="whitespace-nowrap py-3 px-5 border-b-2 font-medium text-sm transition-colors border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Installed<?= $installedVisibleCount > 0 ? ' (' . $installedVisibleCount . ')' : '' ?>
                </button>
            </nav>
            <?php if (!empty($commits)): ?>
            <span class="text-sm text-slate-500 pb-2"><?= count($commits) ?> updates found</span>
            <?php endif; ?>
        </div>

        <?php if (empty($commits)): ?>
        <!-- Empty State -->
        <div class="text-center py-12">
            <span class="material-symbols-outlined text-slate-300 mb-3" style="font-size:60px;">cloud_off</span>
            <p class="text-slate-500 text-sm">No updates loaded yet.</p>
            <a href="updates.php?check_updates=1" class="btn mt-4">
                <span class="material-symbols-outlined text-lg">cloud_download</span>
                Check For Updates Now
            </a>
        </div>
        <?php else: ?>
        <!-- Commits Timeline (rows buffered per tab) -->
        <?php
            $newRows = [];
            $installedRows = [];
            foreach ($commits as $index => $commit):
                $sha = $commit['sha'];
                $shortSha = substr($sha, 0, 7);
                $message = explode("\n", trim($commit['commit']['message'] ?? 'No message'))[0];
                $author = $commit['commit']['author']['name'] ?? 'Unknown';
                $date = $commit['commit']['author']['date'] ?? '';
                $isInstalled = in_array($sha, $installed, true);
                $canInstall = !$isInstalled && $sha === $firstPendingSha;
                ob_start();
            ?>
            <div class="flex gap-4 p-4 border border-slate-200 rounded-lg hover:border-blue-300 hover:bg-blue-50/50 transition-all <?= $isInstalled ? 'bg-green-50 border-green-200' : '' ?> <?= !$canInstall && !$isInstalled ? 'opacity-60' : '' ?>">
                <!-- Icon -->
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= $isInstalled ? 'bg-green-500' : ($canInstall ? 'bg-blue-500' : 'bg-gray-400') ?>">
                        <span class="material-symbols-outlined text-white text-xl">
                            <?= $isInstalled ? 'check_circle' : ($canInstall ? 'commit' : 'lock') ?>
                        </span>
                    </div>
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <h3 class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($message) ?></h3>
                                <?php if ($canInstall): ?>
                                <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-semibold rounded-full">NEXT</span>
                                <?php endif; ?>
                                <?php if (!$canInstall && !$isInstalled): ?>
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs font-semibold rounded-full">LOCKED</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">person</span>
                                    <?= htmlspecialchars($author) ?>
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">schedule</span>
                                    <?= $date ? date('M d, Y H:i', strtotime($date)) : '' ?>
                                </span>
                                <span class="flex items-center gap-1 font-mono bg-slate-100 px-2 py-0.5 rounded">
                                    <span class="material-symbols-outlined text-sm">tag</span>
                                    <?= $shortSha ?>
                                </span>
                                <?php if ($index === 0): ?>
                                <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-xs font-semibold rounded">OLDEST</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                            <!-- Update Details Button -->
                            <button onclick="showUpdateDetails(this)"
                                    data-sha="<?= htmlspecialchars($sha) ?>"
                                    data-message="<?= htmlspecialchars($message) ?>"
                                    data-author="<?= htmlspecialchars($author) ?>"
                                    data-date="<?= htmlspecialchars($date) ?>"
                                    class="btn secondary text-xs inline-flex items-center justify-center w-full sm:w-36">
                                <span class="material-symbols-outlined text-sm">info</span>
                                Update Details
                            </button>

                            <!-- Install Button -->
                            <?php if ($isInstalled): ?>
                            <button disabled class="btn text-xs cursor-default inline-flex items-center justify-center w-full sm:w-32" style="background:#16a34a;border-color:#16a34a;">
                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                Installed
                            </button>
                            <?php elseif ($canInstall): ?>
                            <button onclick="confirmInstallUpdate(this)"
                                    data-sha="<?= htmlspecialchars($sha) ?>"
                                    data-message="<?= htmlspecialchars($message) ?>"
                                    class="btn text-xs inline-flex items-center justify-center w-full sm:w-32"
                                    id="install-btn-<?= $sha ?>">
                                <span class="material-symbols-outlined text-sm">download</span>
                                Install Update
                            </button>
                            <?php else: ?>
                            <button disabled
                                    class="btn text-xs cursor-not-allowed inline-flex items-center justify-center w-full sm:w-32"
                                    style="background:#d1d5db;border-color:#d1d5db;color:#4b5563;"
                                    title="Install previous updates first">
                                <span class="material-symbols-outlined text-sm">lock</span>
                                Locked
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
                $rowHtml = ob_get_clean();
                if ($isInstalled) {
                    $installedRows[] = $rowHtml;
                } else {
                    $newRows[] = $rowHtml;
                }
            endforeach;
            ?>

        <!-- New Updates Tab -->
        <div id="tabPanelNew" class="space-y-3">
            <?php if (empty($newRows)): ?>
            <div class="text-center py-16">
                <span class="material-symbols-outlined text-green-500 mb-3" style="font-size:60px;">check_circle</span>
                <p class="text-slate-700 text-base font-semibold">No new updates to install</p>
                <p class="text-slate-500 text-sm mt-1">You are running the latest version.</p>
            </div>
            <?php else: ?>
                <?= implode("\n", $newRows) ?>
            <?php endif; ?>
        </div>

        <!-- Installed Tab -->
        <div id="tabPanelInstalled" class="space-y-3 hidden">
            <?php if (empty($installedRows)): ?>
            <div class="text-center py-16">
                <span class="material-symbols-outlined text-slate-300 mb-3" style="font-size:60px;">history</span>
                <p class="text-slate-500 text-sm">No updates installed yet.</p>
            </div>
            <?php else: ?>
                <?= implode("\n", $installedRows) ?>
            <?php endif; ?>
        </div>

        <!-- Summary -->
        <div class="mt-6 pt-4 border-t border-slate-200">
            <div class="flex items-center justify-between text-sm text-slate-600">
                <div>
                    Showing <span class="font-semibold text-slate-800"><?= count($commits) ?></span> commits
                    from <span class="font-semibold text-slate-800"><?= date('M d, Y', strtotime($startDate)) ?></span>
                </div>
                <div>
                    <span class="font-semibold text-green-600"><?= $installedVisibleCount ?></span> installed,
                    <span class="font-semibold text-blue-600"><?= $pendingCount ?></span> pending
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Update Details Modal -->
<div id="detailsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full my-8">
        <!-- Modal Header -->
        <div class="border-b border-slate-200 p-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-slate-800">Update Details</h2>
                        <p class="text-sm text-slate-600" id="detailsCommitMessage"></p>
                    </div>
                </div>
                <button onclick="closeDetailsModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <span class="material-symbols-outlined text-2xl">close</span>
                </button>
            </div>
        </div>

        <!-- Modal Body -->
        <div class="p-6 max-h-[70vh] overflow-y-auto">
            <!-- Update Info -->
            <div class="grid grid-cols-2 gap-4 mb-6 p-4 bg-slate-50 rounded-lg">
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium mb-1">Author</div>
                    <div class="text-sm text-slate-800" id="detailsAuthor"></div>
                </div>
                <div>
                    <div class="text-xs text-slate-500 uppercase font-medium mb-1">Date</div>
                    <div class="text-sm text-slate-800" id="detailsDate"></div>
                </div>
            </div>

            <!-- Files Section -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-blue-600 text-xl">description</span>
                        <h3 class="font-semibold text-blue-800">Files Changed</h3>
                    </div>
                    <span class="text-xs px-2 py-1 bg-blue-100 text-blue-700 rounded font-medium" id="detailsFilesCount">Loading...</span>
                </div>
                <div id="detailsFilesList" class="space-y-2 max-h-96 overflow-y-auto">
                    <div class="text-sm text-blue-600 flex items-center gap-2">
                        <span class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                        Loading file information...
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="border-t border-slate-200 p-6 flex items-center justify-end gap-3">
            <button onclick="closeDetailsModal()" class="btn secondary">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Installation Modal -->
<div id="installModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full my-8">
        <!-- Modal Header -->
        <div class="border-b border-slate-200 p-6">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined text-yellow-600 text-2xl">warning</span>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-slate-800">Install Update</h2>
                    <p class="text-sm text-slate-600" id="updateCommitMessage"></p>
                </div>
            </div>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <!-- Warning Section -->
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <div class="flex gap-3">
                    <span class="material-symbols-outlined text-red-600 text-xl flex-shrink-0">error</span>
                    <div>
                        <h3 class="font-semibold text-red-800 mb-2">⚠️ Important: Backup Your System</h3>
                        <ul class="text-sm text-red-700 space-y-1">
                            <li>• Make sure to backup all your files before installing updates</li>
                            <li>• Database backup is highly recommended</li>
                            <li>• This action will replace existing files</li>
                            <li>• Installation cannot be undone automatically</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Confirmation Checkbox -->
            <div class="mb-6" id="backupConfirmWrap">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" id="backupConfirm" class="mt-0.5 w-4 h-4 rounded border-slate-300 text-blue-600">
                    <span class="text-sm text-slate-700">
                        I confirm that I have created a backup of my files and database, and I understand that this update will modify system files.
                    </span>
                </label>
            </div>

            <!-- Progress Section (Hidden Initially) -->
            <div id="progressSection" class="hidden">
                <div class="mb-4">
                    <div class="flex items-center justify-between text-sm mb-2">
                        <span class="font-medium text-slate-700" id="progressText">Preparing...</span>
                        <span class="font-semibold text-blue-600" id="progressPercent">0%</span>
                    </div>
                    <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                        <div id="progressBar" class="bg-gradient-to-r from-blue-500 to-blue-600 h-full transition-all duration-300 rounded-full" style="width: 0%"></div>
                    </div>
                </div>
            </div>

            <!-- Terminal Section - ALWAYS VISIBLE ONCE STARTED -->
            <div id="terminalSection" class="hidden mb-4">
                <div class="bg-slate-800 rounded-t-lg p-3 flex items-center justify-between border-2 border-slate-700 border-b-0">
                    <div class="flex items-center gap-3">
                        <div class="flex gap-1.5">
                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        </div>
                        <h4 class="text-sm font-semibold text-white">Installation Log Terminal</h4>
                        <span class="text-xs text-slate-400" id="logStats">0 log entries</span>
                    </div>
                </div>
                <div class="bg-slate-900 rounded-b-lg p-4 font-mono text-xs text-green-400 overflow-y-auto border-2 border-slate-700 border-t-0" style="max-height: 500px; min-height: 300px;" id="logOutput">
                    <div class="text-slate-500">Waiting for process to start...</div>
                </div>
            </div>

            <!-- Result Section - SHOWS BELOW TERMINAL -->
            <div id="resultSection" class="hidden">
                <div id="successResult" class="hidden bg-green-50 border-2 border-green-300 rounded-lg p-4 shadow-lg">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
                        <div class="flex-1">
                            <h3 class="font-semibold text-green-800 text-lg">✓ Update Installed Successfully!</h3>
                            <p class="text-sm text-green-700 mt-1">All files have been updated. Review the terminal log above for details.</p>
                        </div>
                    </div>
                </div>
                <div id="errorResult" class="hidden bg-red-50 border-2 border-red-300 rounded-lg p-4 shadow-lg">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-red-600 text-3xl">error</span>
                        <div class="flex-1">
                            <h3 class="font-semibold text-red-800 text-lg">✗ Installation Failed</h3>
                            <p class="text-sm text-red-700 mt-1" id="errorMessage"></p>
                            <p class="text-xs text-red-600 mt-2 font-semibold">↑ Check the terminal log above for detailed error information</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="border-t border-slate-200 p-6 flex items-center justify-end gap-3">
            <button onclick="closeInstallModal()" id="cancelBtn" class="btn secondary">
                Cancel
            </button>
            <button onclick="startInstallation()" id="confirmBtn" class="btn" disabled>
                <span class="material-symbols-outlined text-lg">download</span>
                Proceed with Installation
            </button>
        </div>
    </div>
</div>

<script>
let currentCommitSha = '';
let logEntryCount = 0;

// Tabs: New Updates | Installed
function switchUpdatesTab(tab) {
    const isNew = tab === 'new';
    const panelNew = document.getElementById('tabPanelNew');
    const panelInstalled = document.getElementById('tabPanelInstalled');
    const btnNew = document.getElementById('tabBtnNew');
    const btnInstalled = document.getElementById('tabBtnInstalled');
    if (!panelNew || !panelInstalled) return;

    panelNew.classList.toggle('hidden', !isNew);
    panelInstalled.classList.toggle('hidden', isNew);

    const active   = ['border-blue-500', 'text-blue-600'];
    const inactive = ['border-transparent', 'text-gray-500', 'hover:text-gray-700', 'hover:border-gray-300'];
    [[btnNew, isNew], [btnInstalled, !isNew]].forEach(([btn, on]) => {
        if (!btn) return;
        active.forEach(c => btn.classList.toggle(c, on));
        inactive.forEach(c => btn.classList.toggle(c, !on));
    });
}

// Confirm install update
function confirmInstallUpdate(btn) {
    const sha = btn.dataset.sha;
    const message = btn.dataset.message;
    currentCommitSha = sha;
    document.getElementById('updateCommitMessage').textContent = message;
    document.getElementById('installModal').classList.remove('hidden');
    document.getElementById('backupConfirm').checked = false;
    document.getElementById('confirmBtn').disabled = true;
    logEntryCount = 0;

    // Reset sections
    document.getElementById('backupConfirmWrap').classList.remove('hidden');
    document.getElementById('progressSection').classList.add('hidden');
    document.getElementById('terminalSection').classList.add('hidden');
    document.getElementById('resultSection').classList.add('hidden');
    document.getElementById('successResult').classList.add('hidden');
    document.getElementById('errorResult').classList.add('hidden');
    document.getElementById('cancelBtn').disabled = false;

    // Clear terminal
    document.getElementById('logOutput').innerHTML = '<div class="text-slate-500">Waiting for process to start...</div>';
    document.getElementById('logStats').textContent = '0 log entries';
}

// Show update details modal
function showUpdateDetails(btn) {
    const sha = btn.dataset.sha;
    const message = btn.dataset.message;
    const author = btn.dataset.author;
    const date = btn.dataset.date;
    document.getElementById('detailsCommitMessage').textContent = message;
    document.getElementById('detailsAuthor').textContent = author;
    document.getElementById('detailsDate').textContent = date;

    document.getElementById('detailsModal').classList.remove('hidden');

    // Fetch and display files for this commit
    fetchCommitFiles(sha);
}

// Fetch commit files
async function fetchCommitFiles(sha) {
    const filesList = document.getElementById('detailsFilesList');
    const filesCount = document.getElementById('detailsFilesCount');

    try {
        filesList.innerHTML = `
            <div class="text-sm text-blue-600 flex items-center gap-2">
                <span class="material-symbols-outlined text-base animate-spin">progress_activity</span>
                Loading file information...
            </div>
        `;
        filesCount.textContent = 'Loading...';

        const response = await fetch(`updates.php?action=files&sha=${sha}`);
        const data = await response.json();

        if (data.success && data.files && data.files.length > 0) {
            filesCount.textContent = `${data.files.length} file(s)`;

            const statusIcons = {
                'added': { icon: 'add_circle', color: 'text-green-600', bg: 'bg-green-50', label: 'Added' },
                'modified': { icon: 'edit', color: 'text-blue-600', bg: 'bg-blue-50', label: 'Modified' },
                'removed': { icon: 'delete', color: 'text-red-600', bg: 'bg-red-50', label: 'Removed' },
                'renamed': { icon: 'drive_file_move', color: 'text-purple-600', bg: 'bg-purple-50', label: 'Renamed' }
            };

            let html = '<div class="space-y-2">';
            data.files.forEach(file => {
                const status = statusIcons[file.status] || statusIcons['modified'];
                html += `
                    <div class="flex items-center justify-between p-2 ${status.bg} rounded text-xs border border-slate-200">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                            <span class="material-symbols-outlined text-sm ${status.color} flex-shrink-0">${status.icon}</span>
                            <span class="font-mono text-slate-700 truncate" title="${file.filename}">${file.filename}</span>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="px-2 py-0.5 ${status.bg} ${status.color} rounded font-medium">
                                ${status.label}
                            </span>
                            ${file.changes ? `<span class="text-slate-500">±${file.changes}</span>` : ''}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            filesList.innerHTML = html;
        } else {
            filesCount.textContent = 'No files';
            filesList.innerHTML = `
                <div class="text-sm text-slate-500 text-center py-2">
                    <span class="material-symbols-outlined text-2xl">info</span>
                    <p>No file information available</p>
                </div>
            `;
        }
    } catch (error) {
        filesCount.textContent = 'Error';
        filesList.innerHTML = `
            <div class="text-sm text-red-600 flex items-center gap-2">
                <span class="material-symbols-outlined text-base">error</span>
                Failed to load file information
            </div>
        `;
    }
}

// Close details modal
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.add('hidden');
}

// Close install modal
function closeInstallModal() {
    document.getElementById('installModal').classList.add('hidden');
    currentCommitSha = '';
}

// Enable/disable confirm button based on checkbox
document.getElementById('backupConfirm').addEventListener('change', function() {
    document.getElementById('confirmBtn').disabled = !this.checked;
});

// Start installation
async function startInstallation() {
    // Hide confirmation section, show progress AND terminal
    document.getElementById('backupConfirmWrap').classList.add('hidden');
    document.getElementById('progressSection').classList.remove('hidden');
    document.getElementById('terminalSection').classList.remove('hidden');
    document.getElementById('confirmBtn').disabled = true;
    document.getElementById('cancelBtn').disabled = true;

    const logOutput = document.getElementById('logOutput');
    const progressBar = document.getElementById('progressBar');
    const progressPercent = document.getElementById('progressPercent');
    const progressText = document.getElementById('progressText');

    // Clear terminal at start
    logOutput.innerHTML = '';

    function addLog(message, type = 'info') {
        const colors = {
            info: 'text-green-400',
            warning: 'text-yellow-400',
            error: 'text-red-400',
            success: 'text-blue-400'
        };
        const div = document.createElement('div');
        div.className = colors[type] || colors.info;
        div.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
        logOutput.appendChild(div);
        logOutput.scrollTop = logOutput.scrollHeight;

        logEntryCount++;
        const logStats = document.getElementById('logStats');
        if (logStats) {
            logStats.textContent = `${logEntryCount} log ${logEntryCount === 1 ? 'entry' : 'entries'}`;
        }
    }

    function updateProgress(percent, text) {
        progressBar.style.width = percent + '%';
        progressPercent.textContent = percent + '%';
        progressText.textContent = text;
    }

    try {
        addLog('═══════════════════════════════════════════', 'info');
        addLog('Starting update installation...', 'info');
        addLog('═══════════════════════════════════════════', 'info');
        updateProgress(5, 'Initializing...');

        await new Promise(resolve => setTimeout(resolve, 500));
        updateProgress(10, 'Fetching commit data from GitHub...');
        addLog('→ Connecting to Updates Server...', 'info');

        // Make request to install endpoint
        const response = await fetch('updates.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'install', sha: currentCommitSha })
        });

        addLog('✓ Connected to Updates Server', 'success');
        updateProgress(20, 'Parsing response...');

        const result = await response.json();

        addLog('✓ Commit data received', 'success');
        updateProgress(30, 'Analyzing changes...');

        if (result.success) {
            const data = result.data || result;
            const filesUpdated = data.files_updated || 0;
            const filesFailed = data.files_failed || 0;
            const filesSkipped = data.files_skipped || 0;
            const totalFiles = filesUpdated + filesFailed + filesSkipped;

            addLog(`═══════════════════════════════════════════`, 'info');
            addLog(`Total Files: ${totalFiles} | Updated: ${filesUpdated} | Failed: ${filesFailed} | Skipped: ${filesSkipped}`, 'info');
            addLog(`═══════════════════════════════════════════`, 'info');

            updateProgress(40, 'Processing files...');
            await new Promise(resolve => setTimeout(resolve, 300));

            if (data.debug) {
                addLog('─────────────────────────────────────────', 'warning');
                addLog('INSTALLATION LOG:', 'warning');
                addLog('─────────────────────────────────────────', 'warning');
                addLog(`Root Path: ${data.debug.root_path}`, 'info');
                addLog(`Total Files in Commit: ${data.debug.total_files_in_commit || 0}`, 'info');
                addLog('', 'info');

                if (data.debug.details && data.debug.details.length > 0) {
                    let processedCount = 0;

                    for (let detail of data.debug.details) {
                        processedCount++;
                        const progress = 40 + (processedCount / data.debug.details.length) * 50;
                        updateProgress(Math.round(progress), `Processing ${processedCount}/${data.debug.details.length}...`);

                        const isSuccess = detail.result === 'success' || detail.result === 'deleted successfully';
                        const isSkipped = (detail.result || '').indexOf('skipped') === 0;

                        addLog(`[${processedCount}/${data.debug.details.length}] ${detail.filename}`, 'info');
                        addLog(`  Status: ${detail.status || 'unknown'}`, 'info');

                        if (detail.download_http_code) {
                            const httpColor = detail.download_http_code === 200 ? 'success' : 'error';
                            addLog(`  Download HTTP: ${detail.download_http_code}`, httpColor);
                        }
                        if (detail.download_size !== undefined) {
                            addLog(`  Download Size: ${detail.download_size} bytes`, 'info');
                        }
                        if (detail.target_path) {
                            addLog(`  Target: ${detail.target_path}`, 'info');
                        }
                        if (detail.file_exists_before !== undefined) {
                            addLog(`  File existed before: ${detail.file_exists_before ? 'Yes' : 'No'}`, 'info');
                        }
                        if (detail.dir_writable !== undefined) {
                            addLog(`  Directory writable: ${detail.dir_writable ? 'Yes' : 'No'}`, detail.dir_writable ? 'success' : 'error');
                        }
                        if (detail.bytes_written !== undefined) {
                            addLog(`  Bytes Written: ${detail.bytes_written}`, detail.bytes_written > 0 ? 'success' : 'error');
                        }
                        if (detail.file_exists_after !== undefined) {
                            addLog(`  File exists after: ${detail.file_exists_after ? 'Yes' : 'No'}`, detail.file_exists_after ? 'success' : 'error');
                        }

                        if (detail.result) {
                            if (isSuccess) {
                                addLog(`  ✓ RESULT: ${detail.result.toUpperCase()}`, 'success');
                            } else if (isSkipped) {
                                addLog(`  ⊘ RESULT: ${detail.result.toUpperCase()}`, 'warning');
                            } else {
                                addLog(`  ✗ RESULT: ${detail.result.toUpperCase()}`, 'error');
                            }
                        }
                        if (detail.error) {
                            addLog(`  ERROR: ${detail.error}`, 'error');
                        }

                        addLog('', 'info');
                        await new Promise(resolve => setTimeout(resolve, 100));
                    }
                }
            }

            if (data.db_executed) {
                addLog('✓ Database migrations executed (app/database/db.sql)', 'success');
            }

            updateProgress(95, 'Finalizing...');
            addLog('─────────────────────────────────────────', 'info');
            addLog('Saving update information...', 'info');

            await new Promise(resolve => setTimeout(resolve, 500));

            updateProgress(100, 'Complete!');
            addLog('═══════════════════════════════════════════', 'info');

            if (filesFailed > 0) {
                addLog(`⚠ WARNING: ${filesFailed} file(s) failed to update!`, 'error');
                addLog('Check the detailed log above for specific errors.', 'warning');
            } else if (filesUpdated > 0) {
                addLog(`✓ SUCCESS: All ${filesUpdated} file(s) updated successfully!`, 'success');
            } else {
                addLog(`⊘ No files were updated (${filesSkipped} skipped)`, 'warning');
            }

            addLog('═══════════════════════════════════════════', 'info');
            addLog('', 'info');
            addLog('Installation process completed. Reloading…', 'info');

            document.getElementById('resultSection').classList.remove('hidden');
            document.getElementById('successResult').classList.remove('hidden');

            setTimeout(() => window.location.reload(), 1200);

        } else {
            addLog('═══════════════════════════════════════════', 'error');
            addLog('⚠ INSTALLATION FAILED', 'error');
            addLog('═══════════════════════════════════════════', 'error');
            if (result.message) {
                addLog(`Error Message: ${result.message}`, 'error');
            }
            throw new Error(result.message || 'Installation failed');
        }

    } catch (error) {
        addLog('═══════════════════════════════════════════', 'error');
        addLog('⚠ CRITICAL ERROR', 'error');
        addLog('═══════════════════════════════════════════', 'error');
        addLog(`Error: ${error.message}`, 'error');
        addLog('', 'error');
        addLog('Installation process failed. Check error details below.', 'error');

        updateProgress(0, 'Failed');

        document.getElementById('resultSection').classList.remove('hidden');
        document.getElementById('errorResult').classList.remove('hidden');
        document.getElementById('errorMessage').textContent = error.message;
        document.getElementById('cancelBtn').disabled = false;
    }
}

// Close modals on outside click
document.getElementById('detailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeDetailsModal();
});
document.getElementById('installModal').addEventListener('click', function(e) {
    if (e.target === this) closeInstallModal();
});

// ============================================================================
// INSTALL ALL — installs every pending update one-by-one, reloading between each
// until nothing is left. Driven by the ?install_all=1&iat=<total> query params so
// it survives the page refresh that follows each successful install.
// ============================================================================
const INSTALL_ALL_NEXT_SHA = <?= json_encode($firstPendingSha) ?>;
const INSTALL_ALL_REMAINING = <?= (int) $pendingCount ?>;

function startInstallAll(total) {
    if (!confirm('Install all ' + total + ' pending updates?\n\nThey will install one at a time and the page will keep refreshing automatically until every update is done.')) return;
    const u = new URL(window.location.href);
    u.searchParams.set('install_all', '1');
    u.searchParams.set('iat', total);
    window.location.href = u.toString();
}

function setInstallAllStatus(text) {
    const el = document.getElementById('installAllText');
    if (el) el.textContent = text;
}
function setInstallAllProgress(done, total) {
    const bar = document.getElementById('installAllBar');
    if (bar && total > 0) bar.style.width = Math.round((done / total) * 100) + '%';
}

document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    if (params.get('install_all') !== '1') return;
    autoInstallNext(parseInt(params.get('iat') || INSTALL_ALL_REMAINING, 10));
});

async function autoInstallNext(total) {
    const banner = document.getElementById('installAllBanner');
    if (banner) banner.classList.remove('hidden');

    const remaining = INSTALL_ALL_REMAINING;
    const done = Math.max(0, total - remaining);
    setInstallAllProgress(done, total);

    if (!INSTALL_ALL_NEXT_SHA || remaining === 0) {
        setInstallAllStatus('✅ All updates installed (' + total + ' total).');
        setInstallAllProgress(total, total);
        const u = new URL(window.location.href);
        u.searchParams.delete('install_all');
        u.searchParams.delete('iat');
        window.history.replaceState({}, '', u.toString());
        return;
    }

    const shortSha = INSTALL_ALL_NEXT_SHA.substring(0, 7);
    setInstallAllStatus('Installing update ' + (done + 1) + ' of ' + total + ' (' + shortSha + ')…');

    try {
        const res = await fetch('updates.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'install', sha: INSTALL_ALL_NEXT_SHA })
        });
        const result = await res.json();

        if (result.success) {
            setInstallAllStatus('Installed ' + shortSha + '. Loading next…');
            setInstallAllProgress(done + 1, total);
            const u = new URL(window.location.href);
            u.searchParams.set('install_all', '1');
            u.searchParams.set('iat', total);
            window.location.href = u.toString();
        } else {
            setInstallAllStatus('❌ Failed on ' + shortSha + ': ' + (result.message || 'error') + '. Stopped.');
            const u = new URL(window.location.href);
            u.searchParams.delete('install_all');
            u.searchParams.delete('iat');
            window.history.replaceState({}, '', u.toString());
        }
    } catch (e) {
        setInstallAllStatus('❌ Error installing ' + shortSha + ': ' + e.message + '. Stopped.');
        const u = new URL(window.location.href);
        u.searchParams.delete('install_all');
        u.searchParams.delete('iat');
        window.history.replaceState({}, '', u.toString());
    }
}
</script>
</body>
</html>
