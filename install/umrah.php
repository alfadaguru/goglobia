<?php
/**
 * ============================================================================
 *  Dynamic Umrah Package Generator (v10/umrah.php)
 * ============================================================================
 *
 *  Open: http://localhost/v10/umrah.php
 *
 *  - Each visit inserts ONE Umrah package (Madinah only, for now).
 *  - OpenAI generates the content (name, description, itinerary, prices...).
 *  - Magnific Stock API supplies real, low-resolution photos relevant to
 *    Madinah / Masjid an-Nabawi / ziyarat sites.
 *  - Images are downloaded and saved under uploads/umrah/{gallery,itinerary}.
 *  - Row is inserted into the `umrah` table using the same JSON shape the
 *    admin form uses.
 * ============================================================================
 */

// ============== CONFIG ======================================================
const OPENAI_API_KEY     = '';
const OPENAI_MODEL       = 'gpt-4o-mini';
const MAGNIFIC_API_KEY   = 'FPSXbb4cf7b706ba40f1ad312eda62123840';
const PACKAGES_TO_GENERATE = 1;         // one per visit
const PACKAGE_LOCATION     = 'Jeddah'; // fixed destination for now
// ============================================================================

@set_time_limit(300);
@ini_set('memory_limit', '512M');
header('Content-Type: text/html; charset=utf-8');

chdir(__DIR__);
require_once __DIR__ . '/config.php';
/** @var \Medoo\Medoo $db */

// ----------------------------------------------------------------------------
//  Magnific Stock API
// ----------------------------------------------------------------------------

/** Search Magnific stock photos. Returns array of preview URLs. */
function magnific_search(string $term, int $limit = 10): array
{
    global $log;
    // Build URL manually so brackets are not percent-encoded
    $url = 'https://api.magnific.com/v1/resources?'
         . 'term=' . urlencode($term)
         . '&order=relevance'
         . '&limit=' . (int)$limit;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => [
            'x-magnific-api-key: ' . MAGNIFIC_API_KEY,
            'Accept: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        $msg = "[magnific] HTTP $code for '$term'" . ($err ? " curl_err=$err" : '') . " body=" . substr((string)$resp, 0, 200);
        error_log($msg);
        if (is_array($log)) $log[] = $msg;
        return [];
    }

    $json  = json_decode($resp, true);
    $items = $json['data'] ?? [];
    $urls  = [];
    foreach ($items as $item) {
        // Only keep photos (skip vectors/illustrations)
        if (($item['image']['type'] ?? '') !== 'photo') continue;
        $src = $item['image']['source']['url'] ?? null;
        if ($src && preg_match('~^https?://~', $src)) {
            // Prefer https
            $urls[] = preg_replace('~^http://~', 'https://', $src);
        }
    }
    if (is_array($log)) $log[] = "[magnific] '$term' → " . count($urls) . ' photo url(s) (of ' . count($items) . ' results)';
    return $urls;
}

/** Download a URL to uploads/umrah/{subdir}, return public /uploads/... path or null. */
function umrah_download_image(string $url, string $subdir, string $prefix): ?string
{
    global $log;
    $uploadDir = __DIR__ . '/uploads/umrah/' . trim($subdir, '/') . '/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        $msg = "[dl] upload dir not writable: $uploadDir";
        error_log($msg);
        if (is_array($log)) $log[] = $msg;
        return null;
    }

    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';

    $filename = $prefix . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $fullPath = $uploadDir . $filename;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER     => ['Referer: https://www.freepik.com/'],
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if (!$data || $code !== 200 || strlen($data) < 1024) {
        $msg = "[dl] HTTP $code (" . strlen((string)$data) . " bytes" . ($err ? ", err=$err" : '') . ") for $url";
        error_log($msg);
        if (is_array($log)) $log[] = $msg;
        return null;
    }
    if (file_put_contents($fullPath, $data) === false) return null;

    return '/uploads/umrah/' . trim($subdir, '/') . '/' . $filename;
}

/** Find & download N relevant images. Returns [['url' => ..., 'default' => bool], ...]. */
function umrah_fetch_images(string $topic, int $count, string $subdir, string $prefix): array
{
    $candidates = magnific_search($topic, max(10, $count * 3));
    if (empty($candidates)) {
        // fallback terms
        foreach (['madinah saudi arabia', 'masjid mosque saudi arabia', 'islamic architecture'] as $alt) {
            $candidates = magnific_search($alt, max(10, $count * 3));
            if (!empty($candidates)) break;
        }
    }
    if (empty($candidates)) return [];

    shuffle($candidates);
    $picked = array_slice($candidates, 0, $count);

    $out = [];
    foreach ($picked as $i => $src) {
        $saved = umrah_download_image($src, $subdir, $prefix);
        if ($saved) {
            $out[] = ['url' => $saved, 'default' => $i === 0];
        }
    }
    return $out;
}

// ----------------------------------------------------------------------------
//  OpenAI content
// ----------------------------------------------------------------------------

function umrah_openai_json(string $systemPrompt, string $userPrompt): ?array
{
    if (OPENAI_API_KEY === '') return null;
    $body = [
        'model'           => OPENAI_MODEL,
        'temperature'     => 0.9,
        'response_format' => ['type' => 'json_object'],
        'messages'        => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $code !== 200) {
        error_log("[openai] HTTP $code: " . substr((string)$resp, 0, 500));
        return null;
    }
    $decoded = json_decode($resp, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!$content) return null;
    $payload = json_decode($content, true);
    return is_array($payload) ? $payload : null;
}

function umrah_template_package(string $location): array
{
    $types = ['Economy', 'Standard', 'Premium', 'Deluxe'];
    $type  = $types[array_rand($types)];
    $days  = [7, 10, 14][array_rand([0, 1, 2])];
    $stars = rand(3, 5);
    $price = match ($stars) { 5 => rand(2200, 4000), 4 => rand(1400, 2200), default => rand(800, 1400) };

    $itinerary = [];
    for ($d = 1; $d <= min($days, 5); $d++) {
        $itinerary[] = [
            'day'         => $d,
            'title'       => "Day $d — $location",
            'description' => $d === 1
                ? "Arrival at Prince Mohammad Bin Abdulaziz Airport, transfer to hotel near Masjid an-Nabawi."
                : "Guided ziyarat to historical Islamic landmarks around Madinah.",
            'activities'  => [
                [
                    'title'       => $d === 1 ? 'Visit Masjid an-Nabawi' : 'Ziyarat tour',
                    'description' => $d === 1
                        ? "Pray at the Prophet's Mosque and visit the Rawdah."
                        : "Visit Quba Mosque, Masjid al-Qiblatayn, and Mount Uhud with an English-speaking guide.",
                ],
            ],
        ];
    }

    return [
        'name'         => "$stars-Star $type Umrah Package in $location",
        'description'  => "<p>A $days-day $type Umrah package in $location with a $stars-star hotel near the Haram, daily breakfast, airport transfers and guided ziyarat visits.</p>",
        'days'         => $days,
        'nights'       => $days,
        'location'     => $location,
        'stars'        => $stars,
        'adult_price'  => $price,
        'child_price'  => round($price * 0.6),
        'infant_price' => round($price * 0.15),
        'tags'         => ['Visa Included', 'Hotel Near Haram', 'Ziyarat Tour'],
        'inclusions'   => ['Return flights', 'Visa processing', "$stars-star hotel", 'Daily breakfast', 'Airport & Haram transfers', 'Guided ziyarat tour'],
        'exclusions'   => ['Personal expenses', 'Lunch & dinner', 'Travel insurance'],
        'itinerary'    => $itinerary,
    ];
}

function umrah_generate_package(string $location): array
{
    $system = "You are a travel content writer creating authentic Umrah packages for a Saudi travel agency. Respond ONLY with valid JSON.";
    $user = <<<TXT
Generate ONE unique, realistic Umrah package located in {$location}, Saudi Arabia. Return strict JSON:
{
  "package": {
    "name": "5-9 words",
    "description": "60-120 words. HTML allowed: <p>, <ul>, <li>, <strong>",
    "days": 7|10|14|21,
    "nights": same as days,
    "location": "{$location}",
    "stars": 3|4|5,
    "adult_price": USD number 700-4500,
    "child_price": ~60% of adult,
    "infant_price": ~15% of adult,
    "tags": ["3-6 short tags"],
    "inclusions": ["6-10 things included"],
    "exclusions": ["3-5 things not included"],
    "itinerary": [
      {
        "day": 1,
        "title": "string",
        "description": "1-2 sentences",
        "activities": [ { "title": "string", "description": "1-2 sentences" } ]
      }
      // one entry per day up to min(days, 5); each day 1-2 activities
    ]
  }
}
Use authentic terminology (Masjid an-Nabawi, Rawdah, Quba Mosque, Masjid al-Qiblatayn, Mount Uhud, Jannat al-Baqi, Ziyarat).
TXT;

    $payload = umrah_openai_json($system, $user);
    if (!empty($payload['package']) && is_array($payload['package'])) {
        $pkg = $payload['package'];
        $pkg['location'] = $location; // enforce
        return $pkg;
    }
    return umrah_template_package($location);
}

function umrah_slug(string $s): string
{
    $s = strtolower(preg_replace('/[^a-z0-9\s-]/i', '', $s));
    $s = trim(preg_replace('/[\s-]+/', '-', $s), '-');
    return $s ?: 'umrah-package';
}

// ----------------------------------------------------------------------------
//  Magnific search terms per topic
// ----------------------------------------------------------------------------
function gallery_terms_for(string $location): array
{
    return match (strtolower($location)) {
        'madinah' => ['masjid nabawi', 'prophet mosque madinah', 'madinah city'],
        'jeddah'  => ['jeddah corniche', 'jeddah saudi arabia', 'floating mosque jeddah'],
        default   => ['kaaba mecca', 'masjid al haram', 'mecca pilgrimage'],
    };
}
function activity_terms_for(string $location): array
{
    return match (strtolower($location)) {
        'madinah' => ['quba mosque', 'mount uhud', 'masjid qiblatayn', 'jannat al baqi', 'islamic landmark saudi arabia'],
        'jeddah'  => ['old jeddah balad', 'red sea jeddah', 'al rahma mosque jeddah'],
        default   => ['kaaba tawaf pilgrims', 'mina tent city', 'jabal al nour mecca', 'hira cave mecca'],
    };
}

// ----------------------------------------------------------------------------
//  Main run
// ----------------------------------------------------------------------------
$start       = microtime(true);
$log         = [];
$createdRows = [];

$maxId = (int)($db->max('umrah', 'id') ?? 0);

for ($n = 0; $n < PACKAGES_TO_GENERATE; $n++) {
    $location = PACKAGE_LOCATION;
    $log[] = "[gen] requesting OpenAI for {$location}…";
    $pkg = umrah_generate_package($location);
    $log[] = '[gen] got: ' . ($pkg['name'] ?? '(unnamed)');

    // ---- Gallery images
    $galleryTermPool = gallery_terms_for($location);
    $galleryTerm = $galleryTermPool[array_rand($galleryTermPool)];
    $log[] = "[mag] gallery search: \"$galleryTerm\"";
    $galleryImgs = umrah_fetch_images($galleryTerm, 3, 'gallery', 'umrah');
    if (empty($galleryImgs)) {
        $log[] = '[skip] no gallery images downloaded — aborting this package';
        continue;
    }
    $log[] = '[mag] gallery downloaded: ' . count($galleryImgs);

    // ---- Itinerary activity images
    $activityTerms = activity_terms_for($location);
    $itinerary = $pkg['itinerary'] ?? [];
    foreach ($itinerary as &$day) {
        $day['day']         = $day['day']         ?? 1;
        $day['title']       = $day['title']       ?? 'Day ' . $day['day'];
        $day['description'] = $day['description'] ?? '';
        $day['latitude']    = $day['latitude']    ?? '';
        $day['longitude']   = $day['longitude']   ?? '';
        $day['activities']  = $day['activities']  ?? [];
        foreach ($day['activities'] as &$act) {
            $act['title']       = $act['title']       ?? 'Visit';
            $act['description'] = $act['description'] ?? '';
            $act['images']      = $act['images']      ?? [];
            $term = $activityTerms[array_rand($activityTerms)];
            $imgs = umrah_fetch_images($term, rand(1, 2), 'itinerary', 'activity');
            if (!empty($imgs)) {
                $act['images'] = array_merge($act['images'], $imgs);
                $log[] = '[mag] act "' . $act['title'] . '" → ' . count($imgs) . ' img (' . $term . ')';
            }
        }
        unset($act);
    }
    unset($day);

    // ---- DB row
    $serviceIds = $db->select('umrah_settings', 'id', [
        'setting_type' => ['service', 'hotel', 'flight', 'car'],
        'status'       => 1,
    ]) ?: [];
    shuffle($serviceIds);
    $serviceIds = array_slice($serviceIds, 0, min(4, count($serviceIds)));

    $umrahTypeId = $db->get('umrah_settings', 'id', [
        'setting_type' => 'umrah_type',
        'status'       => 1,
        'ORDER'        => ['id' => 'DESC'],
    ]) ?: null;

    $userId = $db->get('users', 'user_id', ['role' => 'admin']) ?: null;

    $name        = trim((string)($pkg['name'] ?? 'Umrah Package'));
    $description = (string)($pkg['description'] ?? '');
    $days        = max(1, (int)($pkg['days'] ?? 7));
    $nights      = max(0, (int)($pkg['nights'] ?? $days));
    $stars       = max(1, min(5, (int)($pkg['stars'] ?? 4)));
    $adultPrice  = max(0, (float)($pkg['adult_price'] ?? 0));
    $childPrice  = max(0, (float)($pkg['child_price'] ?? 0));
    $infantPrice = max(0, (float)($pkg['infant_price'] ?? 0));

    $latLng = match ($location) {
        'Madinah' => ['24.4672', '39.6111'],
        'Jeddah'  => ['21.4858', '39.1925'],
        default   => ['21.4225', '39.8262'],
    };

    $maxId++;
    $row = [
        'id'                  => $maxId,
        'user_id'             => $userId,
        'name'                => $name,
        'slug'                => umrah_slug($name) . '-' . $maxId,
        'description'         => $description,
        'currency'            => 'USD',
        'adult_price'         => $adultPrice,
        'child_price'         => $childPrice,
        'infant_price'        => $infantPrice,
        'discount_percentage' => 0,
        'max_adults'          => 4,
        'max_children'        => 2,
        'max_infants'         => 1,
        'days'                => $days,
        'nights'              => $nights,
        'location'            => $location,
        'latitude'            => $latLng[0],
        'longitude'           => $latLng[1],
        'address'             => $location . ', Saudi Arabia',
        'img'                 => json_encode($galleryImgs),
        'umrah_type_id'       => $umrahTypeId,
        'stars'               => $stars,
        'tags'                => json_encode($pkg['tags'] ?? []),
        'inclusions'          => json_encode($pkg['inclusions'] ?? []),
        'exclusions'          => json_encode($pkg['exclusions'] ?? []),
        'services'            => json_encode($serviceIds),
        'flights_data'        => '[]',
        'stays_data'          => '[]',
        'travelings_data'     => '[]',
        'itinerary'           => json_encode($itinerary),
        'amenities'           => json_encode([]),
        'refundable'          => 1,
        'status'              => 1,
        'featured'            => 1,
        'created_at'          => date('Y-m-d H:i:s'),
        'meta_title'          => $name,
        'meta_description'    => mb_substr(strip_tags($description), 0, 160),
        'meta_keywords'       => implode(', ', $pkg['tags'] ?? []),
    ];

    try {
        $db->insert('umrah', $row);
        $createdRows[] = $row;
        $log[] = "[ok] inserted #{$row['id']} — {$row['name']} ({$row['location']}, {$row['stars']}★, \${$adultPrice})";
    } catch (Throwable $e) {
        $log[] = "[error] insert failed: " . $e->getMessage();
    }
}

$elapsed = round(microtime(true) - $start, 2);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Umrah Package Generator</title>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background:#0f172a; color:#e2e8f0; padding:24px; }
        h1 { color:#fff; margin:0 0 8px; }
        .meta { color:#94a3b8; margin-bottom:24px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px,1fr)); gap:16px; }
        .card { background:#1e293b; border-radius:12px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,.2); }
        .card img { width:100%; height:160px; object-fit:cover; display:block; }
        .card .b { padding:14px; }
        .card h3 { margin:0 0 6px; color:#fff; font-size:15px; }
        .card p { margin:0; color:#cbd5e1; font-size:12px; line-height:1.5; }
        .price { display:inline-block; background:#0ea5e9; color:#fff; font-weight:600; padding:2px 8px; border-radius:6px; font-size:12px; margin-top:8px; }
        pre { background:#020617; color:#94a3b8; padding:14px; border-radius:8px; max-height:380px; overflow:auto; font-size:12px; }
        a { color:#38bdf8; }
    </style>
</head>
<body>
    <h1>Umrah Package Generator — <?= htmlspecialchars(PACKAGE_LOCATION) ?></h1>
    <p class="meta">
        Inserted <strong><?= count($createdRows) ?></strong> package(s) in <?= $elapsed ?>s.
        Content: OpenAI <?= OPENAI_MODEL ?> · Images: Magnific Stock.
        &nbsp; <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">Run again</a>
        &nbsp; <a href="<?= rtrim(str_replace('modules/', '', root), '/') ?>/umrah">View umrah search →</a>
    </p>

    <?php if (!empty($createdRows)): ?>
    <div class="grid">
        <?php foreach ($createdRows as $r):
            $imgs = json_decode($r['img'], true) ?: [];
            $first = $imgs[0]['url'] ?? '';
            $publicUrl = $first ? (rtrim(str_replace('modules/', '', root), '/') . $first) : '';
        ?>
            <div class="card">
                <?php if ($publicUrl): ?><img src="<?= htmlspecialchars($publicUrl) ?>" alt=""><?php endif; ?>
                <div class="b">
                    <h3><?= htmlspecialchars($r['name']) ?></h3>
                    <p>
                        <?= htmlspecialchars($r['location']) ?> · <?= (int)$r['days'] ?> days ·
                        <?= str_repeat('★', (int)$r['stars']) ?>
                    </p>
                    <div class="price">$<?= number_format((float)$r['adult_price'], 0) ?> / adult</div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h3 style="margin-top:32px;color:#fff;">Log</h3>
    <pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
</body>
</html>
