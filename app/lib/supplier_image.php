<?php
// FILE: app/lib/supplier_image.php
// Server-side image processing for supplier/hotel uploads: downscale oversized
// photos and generate a thumbnail, using PHP GD. Pairs with secureUploadCheck()
// (which validates type/size/content) — this is the OPTIMISE step that runs AFTER
// a file has been validated and moved into place.
//
// Design goals:
//   - Never fatal. If GD is missing, the source is unreadable, or anything throws,
//     the original file is left untouched and we report that — the caller keeps the
//     original URL. A hotel's photo must never be lost to a resize failure.
//   - Deterministic outputs: a resized main (long edge <= MAX_EDGE) re-encoded in
//     place, plus a separate thumbnail written next to it as <name>_thumb.<ext>.
//   - Format-preserving: jpg/png/webp stay in their format; gif is left as-is
//     (animation-safe — we don't flatten animated gifs).

if (!defined('SUPPLIER_IMG_MAX_EDGE'))  { define('SUPPLIER_IMG_MAX_EDGE', 1600); }  // long edge of the stored main image
if (!defined('SUPPLIER_IMG_THUMB_EDGE')) { define('SUPPLIER_IMG_THUMB_EDGE', 400); } // long edge of the thumbnail
if (!defined('SUPPLIER_IMG_QUALITY'))   { define('SUPPLIER_IMG_QUALITY', 82); }      // jpeg/webp quality

// ---- Application upload limits (single source of truth for the UI + the handlers) --
// These are the DESIRED app caps. The EFFECTIVE cap the UI shows and the handlers
// enforce is min(app cap, PHP upload_max_filesize) — see supplier_effective_*() below.
// That way the limit shown to a hotel is always one PHP can actually honour: if PHP
// allows less than the app cap, we advertise + enforce the lower number instead of
// promising a size the server would silently drop.
if (!defined('SUPPLIER_IMG_MAX_BYTES'))   { define('SUPPLIER_IMG_MAX_BYTES', 5 * 1024 * 1024); } // 5 MB per photo (desired)
if (!defined('SUPPLIER_LOGO_MAX_BYTES'))  { define('SUPPLIER_LOGO_MAX_BYTES', 2 * 1024 * 1024); } // 2 MB per logo (desired)
if (!defined('SUPPLIER_IMG_MAX_COUNT'))   { define('SUPPLIER_IMG_MAX_COUNT', 10); }                // photos per upload batch
if (!defined('SUPPLIER_IMG_EXTS'))        { define('SUPPLIER_IMG_EXTS', 'jpg,jpeg,png,webp,gif'); } // accepted photo types
if (!defined('SUPPLIER_LOGO_EXTS'))       { define('SUPPLIER_LOGO_EXTS', 'jpg,jpeg,png,webp'); }    // accepted logo types

if (!function_exists('supplier_ini_bytes')) {
    /** Parse a php.ini shorthand size ("2M", "512K", "1G", "8388608") to bytes. 0/'' => 0. */
    function supplier_ini_bytes($val): int
    {
        $val = trim((string) $val);
        if ($val === '') { return 0; }
        $unit = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($unit) {
            case 'g': $num *= 1024; // fall through
            case 'm': $num *= 1024; // fall through
            case 'k': $num *= 1024;
        }
        return max(0, $num);
    }
}

if (!function_exists('supplier_php_upload_cap')) {
    /** The hard per-file ceiling PHP enforces = min(upload_max_filesize, post_max_size).
     *  A 0 (unlimited) value is ignored. Returns 0 only if both are unset/unlimited. */
    function supplier_php_upload_cap(): int
    {
        $u = supplier_ini_bytes(ini_get('upload_max_filesize'));
        $p = supplier_ini_bytes(ini_get('post_max_size'));
        $caps = array_filter([$u, $p], function ($b) { return $b > 0; });
        return empty($caps) ? 0 : (int) min($caps);
    }
}

if (!function_exists('supplier_effective_img_max')) {
    /** Effective per-photo cap: the app cap, clamped down to what PHP can accept. */
    function supplier_effective_img_max(): int
    {
        $app = (int) (defined('SUPPLIER_IMG_MAX_BYTES') ? SUPPLIER_IMG_MAX_BYTES : 5 * 1024 * 1024);
        $php = supplier_php_upload_cap();
        return $php > 0 ? min($app, $php) : $app;
    }
}

if (!function_exists('supplier_effective_logo_max')) {
    /** Effective per-logo cap: the app cap, clamped down to what PHP can accept. */
    function supplier_effective_logo_max(): int
    {
        $app = (int) (defined('SUPPLIER_LOGO_MAX_BYTES') ? SUPPLIER_LOGO_MAX_BYTES : 2 * 1024 * 1024);
        $php = supplier_php_upload_cap();
        return $php > 0 ? min($app, $php) : $app;
    }
}

if (!function_exists('supplier_human_bytes')) {
    /** Format a byte count as a short human string, e.g. 2097152 -> "2 MB", 1572864 -> "1.5 MB". */
    function supplier_human_bytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            $mb = $bytes / (1024 * 1024);
            return rtrim(rtrim(number_format($mb, 1), '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KB';
        }
        return $bytes . ' B';
    }
}

if (!function_exists('supplier_image_gd_ready')) {
    /** True when GD + the codecs we rely on are present. */
    function supplier_image_gd_ready(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatetruecolor')
            && function_exists('getimagesize');
    }
}

if (!function_exists('supplier_image_load')) {
    /** Load an image file into a GD resource by its real type (not its extension). */
    function supplier_image_load(string $path, int $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG: return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null;
            case IMAGETYPE_PNG:  return function_exists('imagecreatefrompng')  ? @imagecreatefrompng($path)  : null;
            case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
            case IMAGETYPE_GIF:  return function_exists('imagecreatefromgif')  ? @imagecreatefromgif($path)  : null;
        }
        return null;
    }
}

if (!function_exists('supplier_image_save')) {
    /** Save a GD resource to $path in the format implied by $type. Returns bool. */
    function supplier_image_save($im, string $path, int $type): bool
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return function_exists('imagejpeg') ? @imagejpeg($im, $path, SUPPLIER_IMG_QUALITY) : false;
            case IMAGETYPE_PNG:
                // PNG quality is 0-9 (compression); preserve alpha.
                if (function_exists('imagesavealpha')) { @imagealphablending($im, false); @imagesavealpha($im, true); }
                return function_exists('imagepng') ? @imagepng($im, $path, 6) : false;
            case IMAGETYPE_WEBP:
                return function_exists('imagewebp') ? @imagewebp($im, $path, SUPPLIER_IMG_QUALITY) : false;
            case IMAGETYPE_GIF:
                return function_exists('imagegif') ? @imagegif($im, $path) : false;
        }
        return false;
    }
}

if (!function_exists('supplier_image_scaled')) {
    /**
     * Return a NEW GD resource scaled so its long edge is <= $maxEdge, preserving
     * aspect ratio and transparency. If the source is already small enough, returns
     * a copy at original size. Caller imagedestroy()s the result.
     */
    function supplier_image_scaled($src, int $w, int $h, int $maxEdge)
    {
        $long = max($w, $h);
        $ratio = ($long > $maxEdge && $long > 0) ? ($maxEdge / $long) : 1.0;
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        // Preserve transparency for png/webp/gif.
        @imagealphablending($dst, false);
        @imagesavealpha($dst, true);
        $transparent = @imagecolorallocatealpha($dst, 0, 0, 0, 127);
        @imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }
}

if (!function_exists('supplier_image_process')) {
    /**
     * Downscale $absPath in place (long edge -> SUPPLIER_IMG_MAX_EDGE) and write a
     * thumbnail alongside it (<base>_thumb.<ext>, long edge -> SUPPLIER_IMG_THUMB_EDGE).
     *
     * @param string $absPath  absolute path to the already-validated, already-moved file
     * @param string $publicUrl the public URL of the main file (e.g. /uploads/hotels/gallery/x.jpg)
     * @return array ['url'=>string, 'thumb'=>string|null] — URLs. On any failure the
     *               original URL is returned and 'thumb' falls back to the main URL, so
     *               callers always get a usable pair and the original is never lost.
     */
    function supplier_image_process(string $absPath, string $publicUrl): array
    {
        $fallback = ['url' => $publicUrl, 'thumb' => $publicUrl];
        try {
            if (!supplier_image_gd_ready() || !is_file($absPath)) { return $fallback; }
            $info = @getimagesize($absPath);
            if (!$info || empty($info[0]) || empty($info[1])) { return $fallback; }
            [$w, $h] = $info;
            $type = (int) ($info[2] ?? 0);
            // Animated GIFs: leave untouched (resampling would flatten the animation).
            if ($type === IMAGETYPE_GIF) { return ['url' => $publicUrl, 'thumb' => $publicUrl]; }

            $src = supplier_image_load($absPath, $type);
            if (!$src) { return $fallback; }

            // 1) Main: downscale in place only if it exceeds the cap (else skip re-encode).
            if (max($w, $h) > SUPPLIER_IMG_MAX_EDGE) {
                $main = supplier_image_scaled($src, $w, $h, SUPPLIER_IMG_MAX_EDGE);
                if ($main) {
                    @supplier_image_save($main, $absPath, $type); // best-effort; original stays if it fails
                    imagedestroy($main);
                }
            }

            // 2) Thumbnail alongside the main file.
            $dot = strrpos($absPath, '.');
            $thumbAbs = $dot !== false ? substr($absPath, 0, $dot) . '_thumb' . substr($absPath, $dot) : $absPath . '_thumb';
            $thumbUrl = null;
            $tdot = strrpos($publicUrl, '.');
            $thumbUrl = $tdot !== false ? substr($publicUrl, 0, $tdot) . '_thumb' . substr($publicUrl, $tdot) : $publicUrl . '_thumb';

            $thumb = supplier_image_scaled($src, $w, $h, SUPPLIER_IMG_THUMB_EDGE);
            $thumbOk = false;
            if ($thumb) {
                $thumbOk = @supplier_image_save($thumb, $thumbAbs, $type);
                if ($thumbOk) { @chmod($thumbAbs, 0644); }
                imagedestroy($thumb);
            }

            imagedestroy($src);
            return ['url' => $publicUrl, 'thumb' => $thumbOk ? $thumbUrl : $publicUrl];
        } catch (\Throwable $e) {
            error_log('supplier_image_process: ' . $e->getMessage());
            return $fallback;
        }
    }
}
