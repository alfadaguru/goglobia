<?php
// PARTIAL: HEADER CURRENCY LIST
// FETCHED ON DEMAND (GET partials/currencies) WHEN THE HEADER DROPDOWN OPENS,
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

// CURRENT CURRENCY FIRST, THEN THE REST
$currentCurrency = strtoupper((string)($_SESSION['app_currency'] ?? 'USD'));
$selectedCurr = null;
$otherCurrs = [];
foreach ($GLOBALS['currencies'] ?? [] as $curr) {
  if (strtoupper((string)$curr['name']) === $currentCurrency) {
    $selectedCurr = $curr;
  } else {
    $otherCurrs[] = $curr;
  }
}
$sortedCurrs = $selectedCurr ? array_merge([$selectedCurr], $otherCurrs) : $otherCurrs;
foreach ($sortedCurrs as $curr):
  $active = strtoupper((string)$curr['name']) === $currentCurrency;
  $flagCode = !empty($curr['country']) ? strtolower($curr['country']) : 'xx';
  $flagPath = flagAssetPath($flagCode);
?>
<a href="<?=root?>currency?currency=<?= $curr['name'] ?>"
   class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition-all duration-150 <?= $active ? 'bg-primary/5 text-primary' : 'text-gray-700 hover:bg-gray-50' ?>">
  <img src="<?= $flagPath ?>" alt="<?= htmlspecialchars($curr['country_name'] ?? $curr['name']) ?> flag" class="w-6 h-6 object-cover rounded-full border border-gray-200 flex-shrink-0" style="background: #f3f4f6;" loading="lazy">
  <div class="flex-1 min-w-0">
    <div class="text-sm font-semibold leading-tight"><?= htmlspecialchars($curr['name']) ?></div>
    <div class="text-xs text-gray-500 truncate leading-tight mt-0.5"><?= htmlspecialchars($curr['country_name'] ?? 'Global') ?></div>
  </div>
  <?php if ($active): ?>
  <span class="material-symbols-outlined !text-[18px] text-primary flex-shrink-0">check_circle</span>
  <?php endif; ?>
</a>
<?php endforeach; ?>
