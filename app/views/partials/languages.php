<?php
// PARTIAL: HEADER LANGUAGE LIST
// FETCHED ON DEMAND (GET partials/languages) WHEN THE HEADER DROPDOWN OPENS,
// SO THE LIST AND ITS FLAG IMAGES ARE NOT PART OF THE FIRST-PAINT DOM.
@$SECURE or die('Access Denied!');

if (!function_exists('flagAssetPath')) {
  function flagAssetPath($flagCode) {
    static $availableFlags = null;

    if ($availableFlags === null) {
      $availableFlags = [];
      $flagsDir = __DIR__ . '/../../../assets/img/flags';
      foreach (glob($flagsDir . '/*.svg') as $flagFile) {
        $availableFlags[basename($flagFile, '.svg')] = true;
      }
    }

    $code = strtolower(trim((string)$flagCode));
    if ($code !== '' && isset($availableFlags[$code])) {
      return root . "assets/img/flags/{$code}.svg";
    }

    return root . 'assets/img/flags/xx.svg';
  }
}

// CURRENT LANGUAGE FIRST, THEN THE REST
$currentLangCode = $_SESSION['app_language'] ?? 'en';
$selectedLang = null;
$otherLangs = [];
foreach ($GLOBALS['languages'] ?? [] as $lang) {
  if ($lang['lang_code'] === $currentLangCode) {
    $selectedLang = $lang;
  } else {
    $otherLangs[] = $lang;
  }
}
$sortedLangs = $selectedLang ? array_merge([$selectedLang], $otherLangs) : $otherLangs;
foreach ($sortedLangs as $lang):
  $active = $lang['lang_code'] === $currentLangCode;
  $flagCode = !empty($lang['country']) ? strtolower($lang['country']) : 'xx';
  $flagPath = flagAssetPath($flagCode);
?>
<a href="<?=root?>lang?lang=<?= $lang['lang_code'] ?>"
   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-150 <?= $active ? 'bg-primary/5 text-primary' : 'text-gray-700 hover:bg-gray-50' ?>">
  <img src="<?= $flagPath ?>" alt="<?= htmlspecialchars($lang['name']) ?> flag" class="w-6 h-6 object-cover rounded-full border border-gray-200 flex-shrink-0" style="background: #f3f4f6;" loading="lazy">
  <div class="flex-1 min-w-0">
    <div class="text-sm font-semibold leading-tight"><?= htmlspecialchars($lang['name']) ?></div>
    <div class="text-xs text-gray-500 leading-tight mt-0.5"><?= htmlspecialchars($lang['country_name'] ?? ($lang['country'] ?? '')) ?><?php if (!empty($lang['country_name']) || !empty($lang['country'])): ?> &middot; <?php endif; ?><span class="uppercase tracking-wide"><?= htmlspecialchars($lang['lang_code']) ?></span></div>
  </div>
  <?php if ($active): ?>
  <span class="material-symbols-outlined !text-[18px] text-primary flex-shrink-0">check_circle</span>
  <?php endif; ?>
</a>
<?php endforeach; ?>
