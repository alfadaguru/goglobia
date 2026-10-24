<?php
// SEO LANDING — Visa country (/visa/{country}). SSR evergreen "{country} visa from
// Nigeria" guide + a live link into the real visa application flow. Content is
// deliberately GENERAL — visa rules, fees and eligibility are set by each
// government and change often — so we never state fixed fees, guaranteed outcomes
// or precise processing times as fact.
@$SECURE or die('Access Denied!');

$v = $GLOBALS['seo_visa'] ?? null;
if (!$v) { echo '<div class="container py-10">Visa destination not found.</div>'; return; }

$name    = htmlspecialchars($v['name']);
$slug    = htmlspecialchars($v['slug']);
$iso     = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($v['iso'] ?? '')));
$intro   = htmlspecialchars((string) ($v['intro'] ?? ''));
$tip     = htmlspecialchars((string) ($v['tip'] ?? ''));
$processing = htmlspecialchars((string) ($v['processing'] ?? ''));
$entry      = htmlspecialchars((string) ($v['entry'] ?? ''));
$visaTypes    = is_array($v['visa_types'] ?? null) ? $v['visa_types'] : [];
$requirements = is_array($v['requirements'] ?? null) ? $v['requirements'] : [];

// Live visa application deep-link: /visa/{FROM}/{TO}/{dd-mm-yyyy}/{type}/{speed}/{n}
// From Nigeria (NG) -> destination ISO; sensible defaults (tourist/standard/1).
$applyDate = date('d-m-Y', strtotime('+30 days'));
$applyUrl  = ($iso !== '')
    ? rtrim((string) root, '/') . '/visa/NG/' . $iso . '/' . $applyDate . '/tourist/standard/1'
    : rtrim((string) root, '/') . '/visa';

// FAQ (rendered + FAQPage JSON-LD). Honest, with the "rules change" disclaimer.
$destName = (string) $v['name'];
$faqs = [
    ['q' => 'Do Nigerian citizens need a visa for ' . $destName . '?', 'a' => 'In most cases yes — ' . $destName . ' requires a visa for Nigerian passport holders, obtained before travel. Requirements are set by the destination government and can change, so always confirm the current rules when you apply.'],
    ['q' => 'What documents do I need for a ' . $destName . ' visa?', 'a' => ($requirements ? 'Typically: ' . implode('; ', array_map('strval', $requirements)) . '. Exact requirements depend on your visa type and can change.' : 'Requirements depend on your visa type and are set by the destination government; we guide you through the current checklist when you apply.')],
    ['q' => 'How long does a ' . $destName . ' visa take to process?', 'a' => ($v['processing'] ?? 'Processing times vary by visa type, season and the destination’s own procedures. Apply well ahead of your travel date.')],
    ['q' => 'How do I apply for a ' . $destName . ' visa with GoGlobia?', 'a' => 'Start your application from this page, tell us your travel details, and we guide you through the documents and submission — with support throughout. Government fees and decisions are set by the destination authority.'],
];
?>

<div class="w-full bg-gradient-to-b from-indigo-50 to-white border-b border-gray-100">
  <div class="container mx-auto px-4 py-10 sm:py-12">
    <nav aria-label="Breadcrumb" class="text-sm text-gray-500 mb-3">
      <a href="<?= root ?>" class="hover:text-indigo-700">Home</a>
      <span class="mx-1.5">/</span>
      <a href="<?= root ?>visa" class="hover:text-indigo-700">Visa</a>
      <span class="mx-1.5">/</span>
      <span class="text-gray-700 font-medium"><?= $name ?></span>
    </nav>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2"><?= $name ?> Visa from Nigeria</h1>
    <p class="text-gray-600 max-w-2xl"><?= $intro ?></p>
    <div class="mt-5">
      <a href="<?= htmlspecialchars($applyUrl) ?>"
         class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2.5 rounded-lg">
        <span class="material-symbols-outlined" aria-hidden="true">assignment</span>
        Apply for a <?= $name ?> visa
      </a>
    </div>
  </div>
</div>

<div class="container mx-auto px-4 py-8 sm:py-10">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <article class="lg:col-span-2 prose max-w-none
        [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-gray-900 [&_h2]:mt-7 [&_h2]:mb-2
        [&_p]:text-gray-700 [&_p]:leading-relaxed [&_ul]:text-gray-700 [&_ul]:list-disc [&_ul]:ml-6 [&_ul]:space-y-1">

      <?php if (!empty($visaTypes)): ?>
      <h2>Types of <?= $name ?> visa</h2>
      <p>Depending on your trip, the main visa types include:</p>
      <ul>
        <?php foreach ($visaTypes as $vt): ?>
        <li><?= htmlspecialchars($vt) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <?php if (!empty($requirements)): ?>
      <h2>Typical requirements</h2>
      <p>Most <?= $name ?> visa applications from Nigeria ask for documents along these lines:</p>
      <ul>
        <?php foreach ($requirements as $req): ?>
        <li><?= htmlspecialchars($req) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="!text-sm !text-gray-500">This is a general guide. The exact checklist depends on your visa type and can change — we confirm the current requirements with you when you apply.</p>
      <?php endif; ?>

      <?php if ($processing !== ''): ?>
      <h2>Processing time</h2>
      <p><?= $processing ?></p>
      <?php endif; ?>

      <?php if ($entry !== ''): ?>
      <h2>Validity &amp; entry</h2>
      <p><?= $entry ?></p>
      <?php endif; ?>

      <h2>How to apply for your <?= $name ?> visa</h2>
      <ul>
        <li>Start your application from this page and tell us your travel details.</li>
        <li>We guide you through the documents and help you prepare a complete submission.</li>
        <li>You pay the service securely by card, bank transfer or your GoGlobia wallet; government visa fees and the decision are set by the destination authority.</li>
        <li>We keep you updated through the process, with support if you need help.</li>
      </ul>

      <?php if ($tip !== ''): ?>
      <h2>Good to know</h2>
      <p><?= $tip ?></p>
      <?php endif; ?>

      <div class="not-prose mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        <strong>Please note:</strong> visa requirements, fees and eligibility are decided by the destination government and can change at any time. The information here is a general guide, not legal or immigration advice — always confirm the current requirements for your situation when you apply.
      </div>

      <?php if (!empty($faqs)): ?>
      <h2><?= $name ?> visa — frequently asked questions</h2>
      <?php foreach ($faqs as $f): ?>
      <p><strong><?= htmlspecialchars($f['q']) ?></strong><br><?= htmlspecialchars($f['a']) ?></p>
      <?php endforeach; ?>
      <?php endif; ?>
    </article>

    <aside class="lg:col-span-1">
      <div class="bg-white border border-gray-200 rounded-xl p-5 sticky top-24">
        <h3 class="font-bold text-gray-900 mb-3">Start your <?= $name ?> visa</h3>
        <ul class="space-y-2 text-sm">
          <li><a href="<?= htmlspecialchars($applyUrl) ?>" class="text-indigo-700 hover:underline font-medium">Apply for a <?= $name ?> visa</a></li>
          <li><a href="<?= root ?>visa" class="text-indigo-700 hover:underline">All visa services</a></li>
          <li><a href="<?= root ?>flights" class="text-indigo-700 hover:underline">Flights to <?= $name ?></a></li>
          <li><a href="<?= root ?>tours/<?= $slug ?>" class="text-indigo-700 hover:underline">Tours in <?= $name ?></a></li>
        </ul>
        <div class="mt-4 pt-4 border-t border-gray-100">
          <a href="<?= htmlspecialchars($applyUrl) ?>" class="block text-center bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-lg">Apply now</a>
        </div>
      </div>
    </aside>
  </div>
</div>

<?php
// FAQPage structured data (honest — mirrors the on-page FAQ exactly).
if (!empty($faqs)) {
    $faqLd = [
        '@context' => 'https://schema.org',
        '@type'    => 'FAQPage',
        'mainEntity' => array_map(fn($f) => [
            '@type' => 'Question',
            'name'  => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $faqs),
    ];
    echo '<script type="application/ld+json">' . json_encode($faqLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}
?>
