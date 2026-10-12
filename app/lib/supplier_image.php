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
