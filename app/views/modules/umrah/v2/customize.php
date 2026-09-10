<?php
// UMRAH v2 — Customize / Personalize a trip (quote request). Mobile-first.
// Expects: $template, $cities, $months, $tiers, $selectedDepartureId, $submitted.
@$SECURE or die('Access Denied!');
$brand = $GLOBALS['app']['business_name'] ?? 'GoGlobia';
$wa = preg_replace('/[^0-9]/', '', (string) ($GLOBALS['app']['contact_phone'] ?? ''));
$waLink = $wa ? ('https://wa.me/' . $wa) : '#';
$csrf = $_SESSION['csrf_token'] ?? (class_exists('CSRF') ? CSRF::getToken() : '');
$cities = $cities ?? [];
$months = $months ?? [];
$tiers = $tiers ?? [];
$baseMadinah = (int) ($template['madinah_nights'] ?? 4);
$baseMakkah  = (int) ($template['makkah_nights'] ?? 10);
$ziyarahOptions = ['Makkah Ziyarah','Madinah Ziyarah','Taif','Badr','Jabal al-Nour (Cave of Hira)','Jabal Thawr'];
$addonOptions = ['Extra checked baggage','Private room upgrade','Wheelchair assistance','Airport VIP fast-track','Extra data top-up','Guided historical tour'];
?>
<div class="bg-white">
  <section class="bg-gradient-to-br from-primary-50 to-white">
    <div class="container py-8 md:py-12">
      <a href="<?= root ?>umrah" class="text-sm text-primary inline-flex items-center gap-1 mb-3"><span class="material-symbols-outlined text-[18px]">arrow_back</span> Back to Umrah</a>
      <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900">Personalize your Umrah</h1>
      <p class="mt-3 text-gray-700 md:text-lg max-w-2xl">Tell us how you'd like your journey. Set your nights in Madinah &amp; Makkah,
         extend your stay to 3 or 4 weeks, add extra Ziyarah, choose your comfort tier — and our team will send you a tailored quote.</p>
    </div>
  </section>

  <section class="container py-8">
    <?php if (!empty($submitted) && !empty($submitted['ok'])): ?>
      <div class="card border max-w-2xl mx-auto text-center">
        <div class="mx-auto w-14 h-14 rounded-full bg-green-100 flex items-center justify-center mb-3">
          <span class="material-symbols-outlined text-green-600 text-3xl">check_circle</span>
        </div>
        <h2 class="text-xl font-bold text-gray-900">Request received</h2>
        <p class="text-gray-600 mt-2">Your reference is <span class="font-semibold text-gray-900"><?= htmlspecialchars($submitted['ref']) ?></span>.
           Our team will review your personalized Umrah and send you a tailored quote shortly.</p>
        <div class="mt-5 flex flex-wrap gap-3 justify-center">
          <a href="<?= root ?>umrah" class="btn">Back to departures</a>
          <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener" class="btn outline">Chat on WhatsApp</a>
        </div>
      </div>
    <?php else: ?>
      <?php if (!empty($submitted) && empty($submitted['ok'])): ?>
        <div class="alert-error max-w-3xl mx-auto mb-4"><span class="material-icon material-symbols-outlined">error</span><p><?= htmlspecialchars($submitted['message'] ?? 'Something went wrong.') ?></p></div>
      <?php endif; ?>

      <form method="POST" action="<?= root ?>umrah/customize" class="max-w-3xl mx-auto" x-data="umrahCustomize()">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="template_id" value="<?= (int) ($template['id'] ?? 0) ?>">
        <input type="hidden" name="departure_id" value="<?= (int) ($selectedDepartureId ?? 0) ?>">

        <!-- 1. When & where -->
        <div class="card border">
          <h2 class="font-bold text-gray-900 mb-1">1. When &amp; where</h2>
          <p class="text-sm text-gray-500 mb-4">Pick a city and month, or leave flexible and we'll advise.</p>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Departure city</label>
              <select name="origin_city" class="select w-full">
                <option value="">Flexible</option>
                <?php foreach ($cities as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Preferred month</label>
              <select name="preferred_month" class="select w-full">
                <option value="">Flexible</option>
                <?php foreach ($months as $m): ?><option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Exact date (optional)</label>
              <input type="date" name="preferred_date" class="input w-full">
            </div>
          </div>
        </div>

        <!-- 2. Stay length -->
        <div class="card border mt-4">
          <h2 class="font-bold text-gray-900 mb-1">2. Your stay</h2>
          <p class="text-sm text-gray-500 mb-4">Standard is <?= $baseMadinah ?> nights Madinah + <?= $baseMakkah ?> nights Makkah (2 weeks). Extend to 3 or 4 weeks and split the nights how you like.</p>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Total length</label>
              <select name="total_weeks" class="select w-full" x-model.number="weeks" @change="rebalance()">
                <option value="2">2 weeks (standard)</option>
                <option value="3">3 weeks</option>
                <option value="4">4 weeks</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nights in Madinah</label>
              <input type="number" name="madinah_nights" min="0" max="28" class="input w-full" x-model.number="madinah">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nights in Makkah</label>
              <input type="number" name="makkah_nights" min="0" max="28" class="input w-full" x-model.number="makkah">
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-2">Total nights: <span class="font-semibold" x-text="madinah + makkah"></span> (<span x-text="weeks*7"></span> planned)</p>
        </div>

        <!-- 3. Tier & pax -->
        <div class="card border mt-4">
          <h2 class="font-bold text-gray-900 mb-1">3. Comfort &amp; group</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Comfort tier</label>
              <select name="tier_code" class="select w-full">
                <?php foreach ($tiers as $t): ?>
                  <option value="<?= htmlspecialchars($t['code']) ?>"><?= htmlspecialchars($t['public_label'] ?: $t['name']) ?><?= $t['room_sharing'] ? ' — ' . htmlspecialchars($t['room_sharing']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Number of pilgrims</label>
              <input type="number" name="pax" min="1" max="50" value="1" class="input w-full">
            </div>
          </div>
        </div>

        <!-- 4. Ziyarah -->
        <div class="card border mt-4">
          <h2 class="font-bold text-gray-900 mb-1">4. Ziyarah &amp; extras</h2>
          <p class="text-sm text-gray-500 mb-3">Add the sacred sites and extras you'd like included.</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <?php foreach ($ziyarahOptions as $z): ?>
              <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="ziyarah[]" value="<?= htmlspecialchars($z) ?>" class="rounded border-gray-300"> <?= htmlspecialchars($z) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-4">
            <?php foreach ($addonOptions as $a): ?>
              <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="addons[]" value="<?= htmlspecialchars($a) ?>" class="rounded border-gray-300"> <?= htmlspecialchars($a) ?></label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- 5. Contact -->
        <div class="card border mt-4">
          <h2 class="font-bold text-gray-900 mb-1">5. Your details</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
            <input class="input" name="name" placeholder="Full name" required>
            <input class="input" name="email" type="email" placeholder="Email">
            <input class="input" name="phone" placeholder="WhatsApp / phone">
          </div>
          <textarea name="notes" rows="3" class="input w-full mt-3" placeholder="Anything else we should know? (special requests, dietary needs, family/room preferences…)"></textarea>
          <p class="text-xs text-gray-500 mt-2">Enter your email or phone so we can send your quote.</p>
        </div>

        <div class="mt-5 flex flex-wrap gap-3">
          <button type="submit" class="btn btn-lg">Request my tailored quote</button>
          <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noopener" class="btn btn-lg outline">Prefer to chat? WhatsApp us</a>
        </div>
        <p class="text-xs text-gray-400 mt-3">No payment now. We'll review your request and send a personalized quote — usually within a few hours.</p>
      </form>
    <?php endif; ?>
  </section>
</div>

<script>
function umrahCustomize() {
  return {
    weeks: 2, madinah: <?= $baseMadinah ?>, makkah: <?= $baseMakkah ?>,
    rebalance() {
      // Suggest a night split proportional to the standard 4:10 ratio.
      const totalNights = this.weeks * 7;
      const mad = Math.round(totalNights * (<?= $baseMadinah ?> / <?= max(1, $baseMadinah + $baseMakkah) ?>));
      this.madinah = mad;
      this.makkah = totalNights - mad;
    }
  };
}
</script>
