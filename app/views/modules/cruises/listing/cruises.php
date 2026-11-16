<?php @$SECURE or die('Access Denied!'); ?>
<?php
// CRUISES SEARCH RESULTS (own-inventory — Phase 1). Server-rendered list of active
// cruises matching the search ($cruises, set by /cruises). Honest empty state when
// nothing matches. Booking is a later phase: the detail CTA is a "Book" link to the
// detail page (which itself marks booking as coming soon).
$cruises = isset($cruises) && is_array($cruises) ? $cruises : [];
$count   = count($cruises);
$fmt = function ($amt, $ccy) {
    return htmlspecialchars((string) $ccy) . ' ' . number_format((float) $amt, 2);
};
$dest   = htmlspecialchars((string) ($_SESSION['cruise_destination'] ?? ''));
$guests = (int) ($_SESSION['cruise_guests'] ?? 1);
?>
<section class="max-w-6xl mx-auto px-4 py-6">

  <?php if (!empty($_SESSION['message'])): $__m = $_SESSION['message']; unset($_SESSION['message']); ?>
    <div class="mb-4 rounded-lg px-4 py-3 text-sm <?= ($__m['type'] ?? '') === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700' ?>">
      <?= htmlspecialchars((string) ($__m['text'] ?? '')) ?>
    </div>
  <?php endif; ?>

  <!-- SEARCH SUMMARY -->
  <div class="rounded-2xl bg-[#101828] text-white px-4 py-4 flex items-center justify-between gap-3 mb-5 flex-wrap">
    <div class="flex items-center gap-3 min-w-0">
      <span class="material-symbols-outlined text-2xl shrink-0">directions_boat</span>
      <div class="min-w-0">
        <div class="font-bold text-base truncate"><?= $dest !== '' ? $dest : 'All cruises' ?></div>
        <div class="text-white/70 text-xs truncate">
          <?= $guests ?> guest<?= $guests === 1 ? '' : 's' ?>
          <?php if (!empty($_SESSION['cruise_length']) && strtolower((string) $_SESSION['cruise_length']) !== 'any'): ?>
            &middot; <?= htmlspecialchars((string) $_SESSION['cruise_length']) ?> nights
          <?php endif; ?>
        </div>
      </div>
    </div>
    <a href="<?= root ?>#cruises" class="bg-white text-[#101828] rounded-lg px-4 py-2 text-sm font-semibold inline-flex items-center gap-1.5 shrink-0">
      <span class="material-symbols-outlined text-[18px]">tune</span> Modify
    </a>
  </div>

  <h1 class="text-lg font-bold text-gray-900 mb-1"><?= $count ?> cruise<?= $count === 1 ? '' : 's' ?> found</h1>
  <p class="text-sm text-gray-500 mb-5">Hand-picked sailings at great prices.</p>

  <?php if ($count === 0): ?>
    <!-- EMPTY STATE (honest) -->
    <div class="text-center py-16 rounded-2xl border border-dashed border-gray-200 bg-gray-50">
      <span class="material-symbols-outlined text-5xl text-gray-300">directions_boat</span>
      <h3 class="text-lg font-semibold text-gray-800 mt-3">No cruises found</h3>
      <p class="text-sm text-gray-500 mt-1">Try a different destination, length, or fewer guests.</p>
      <a href="<?= root ?>#cruises" class="inline-block mt-4 btn text-sm">Adjust your search</a>
    </div>
  <?php else: ?>
    <!-- CRUISE CARDS -->
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($cruises as $c):
        $img  = trim((string) ($c['img'] ?? ''));
        $imgUrl = $img !== '' ? (preg_match('#^https?://#i', $img) ? $img : rtrim((string) root, '/') . '/' . ltrim($img, '/')) : '';
        $slug = (string) ($c['slug'] ?? '');
        $href = root . 'cruise/' . rawurlencode($slug);
        $stars = max(0, min(5, (int) ($c['stars'] ?? 0)));
      ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"
           class="group bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col hover:shadow-md transition">
          <div class="relative h-40 bg-blue-50">
            <?php if ($imgUrl !== ''): ?>
              <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars((string) $c['name']) ?>" loading="lazy" decoding="async"
                   class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                   onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center bg-blue-50\'><span class=\'material-symbols-outlined text-blue-300\' style=\'font-size:40px\'>directions_boat</span></div>'">
            <?php else: ?>
              <div class="w-full h-full flex items-center justify-center">
                <span class="material-symbols-outlined text-blue-300" style="font-size:40px">directions_boat</span>
              </div>
            <?php endif; ?>
            <?php if (!empty($c['featured'])): ?>
              <span class="absolute top-2 left-2 text-[11px] font-semibold text-white bg-amber-500 rounded-full px-2 py-0.5">Featured</span>
            <?php endif; ?>
          </div>
          <div class="p-4 flex flex-col flex-1">
            <?php if (!empty($c['cruise_line'])): ?>
              <div class="text-[11px] font-medium text-blue-600 uppercase tracking-wide truncate"><?= htmlspecialchars((string) $c['cruise_line']) ?></div>
            <?php endif; ?>
            <h3 class="font-semibold text-gray-900 leading-tight line-clamp-2"><?= htmlspecialchars((string) $c['name']) ?></h3>
            <?php if ($stars > 0): ?>
              <div class="text-amber-500 text-sm mt-0.5"><?= str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) ?></div>
            <?php endif; ?>
            <div class="text-xs text-gray-500 mt-1 flex items-center gap-1 truncate">
              <span class="material-symbols-outlined text-[14px]">location_on</span>
              <?= htmlspecialchars((string) ($c['destination'] ?? '')) ?>
              <?php if (!empty($c['departure_port'])): ?>· from <?= htmlspecialchars((string) $c['departure_port']) ?><?php endif; ?>
            </div>
            <div class="text-xs text-gray-500 mt-1 flex items-center gap-3">
              <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">dark_mode</span><?= (int) ($c['nights'] ?? 0) ?> nights</span>
              <?php if (!empty($c['departure_date'])): ?>
                <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">event</span><?= htmlspecialchars(date('d M Y', strtotime((string) $c['departure_date']))) ?></span>
              <?php endif; ?>
            </div>
            <div class="mt-auto pt-3 flex items-end justify-between">
              <div>
                <div class="text-[11px] text-gray-400">from</div>
                <div class="text-lg font-bold text-gray-900"><?= $fmt($c['display_price'] ?? $c['price'] ?? 0, $c['display_currency'] ?? $c['currency'] ?? 'NGN') ?></div>
                <div class="text-[11px] text-gray-400">per guest</div>
              </div>
              <span class="btn text-sm whitespace-nowrap">View cruise</span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</section>
