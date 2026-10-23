<?php
// ============================================================================
// FILE: app/lib/ai/gia_trace.php
// One consistent step-tracer for GIA. Every tool/engine step calls gia_trace()
// so a single test chat leaves a complete, readable breadcrumb trail in the
// SYSTEM ERROR LOG — each line names the FILE it ran in, the STEP, and a short
// detail. This is for diagnosis/verification: the owner tests, reads the log,
// and sees the exact execution path (and where it stopped) — no guessing.
//
// Format (grep for 'GIA-TRACE'):
//   [GIA-TRACE] <step> | file=<relative/path.php> | <detail>
//
// Global namespace so every GIA file (namespaced or not) can call \gia_trace().
// Best-effort + never throws. Toggle-able via settings.gia_trace_enabled (on by
// default here so the current debugging round has data; flip off later).
// ============================================================================

if (!function_exists('gia_trace')) {
    /**
     * @param string $step   short step id, e.g. 'umrah_search' / 'flights_book'
     * @param string $file   the file this ran in (pass __FILE__)
     * @param string $detail free text — inputs, ids, counts, outcome
     */
    function gia_trace(string $step, string $file = '', string $detail = ''): void
    {
        // Make the path relative to the app root so it's short + clickable.
        $rel = $file;
        if ($file !== '') {
            $root = defined('root') ? '' : '';
            $pos = strpos($file, '/app/');
            if ($pos !== false) { $rel = ltrim(substr($file, $pos), '/'); }
            else { $pos2 = strpos($file, '/modules/'); if ($pos2 !== false) { $rel = ltrim(substr($file, $pos2), '/'); } }
        }
        $line = '[GIA-TRACE] ' . $step;
        if ($rel !== '')    { $line .= ' | file=' . $rel; }
        if ($detail !== '') { $line .= ' | ' . $detail; }
        error_log($line);
    }
}
