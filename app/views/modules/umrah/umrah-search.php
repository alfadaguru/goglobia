<?php
// UMRAH homepage search widget (flight-style). Served at /partials/search/umrah.
// City + Month + Pilgrims -> submits to the dedicated results route /umrah/search.
// Mirrors the flights-search .field-box styling. $db is in scope (partial route).
@$SECURE or die('Access Denied!');

// Facets from PUBLISHED departures only (what a visitor can actually book).
$uCities = [];
$uMonths = [];
try {
    $rows = $db->select('umrah_departures', ['origin_city', 'month_bucket', 'departure_date'], [
        'status' => 'published', 'ORDER' => ['departure_date' => 'ASC'],
    ]) ?: [];
    foreach ($rows as $r) {
        $c = trim((string) ($r['origin_city'] ?? '')) ?: 'Kano';
        $uCities[$c] = true;
        $m = trim((string) ($r['month_bucket'] ?? '')) ?: date('F Y', strtotime($r['departure_date']));
        if (!isset($uMonths[$m])) { $uMonths[$m] = $r['departure_date']; }
    }
} catch (\Throwable $e) { /* facets optional */ }
$uCities = array_keys($uCities);
asort($uMonths);
$uMonths = array_keys($uMonths);
?>
<form class="space-y-4" method="GET" action="<?= root ?>umrah/search" x-data="umrahHomeSearch()" @submit.prevent="go()">
  <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-stretch">

    <!-- Departure city -->
    <div class="md:col-span-4 field-box">
      <div class="field-box-segment">
        <span class="field-box-icon material-symbols-outlined" style="left:12px;">flight_takeoff</span>
        <div class="field-box-content">
          <label class="field-box-label">Departure city</label>
          <select class="field-box-input cursor-pointer bg-transparent" x-model="city">
            <option value="">Any city</option>
            <?php foreach ($uCities as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Month -->
    <div class="md:col-span-4 field-box">
      <div class="field-box-segment">
        <span class="field-box-icon material-symbols-outlined" style="left:12px;">calendar_month</span>
        <div class="field-box-content">
          <label class="field-box-label">Travel month</label>
          <select class="field-box-input cursor-pointer bg-transparent" x-model="month">
            <option value="">Any month</option>
            <?php foreach ($uMonths as $m): ?>
              <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Pilgrims -->
    <div class="md:col-span-2 field-box">
      <div class="field-box-segment">
        <span class="field-box-icon material-symbols-outlined" style="left:12px;">groups</span>
        <div class="field-box-content">
          <label class="field-box-label">Pilgrims</label>
          <select class="field-box-input cursor-pointer bg-transparent" x-model.number="pax">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?> pilgrim<?= $i > 1 ? 's' : '' ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Search button -->
    <div class="md:col-span-2 flex">
      <button type="submit" class="btn w-full justify-center h-full min-h-[58px]">
        <span class="material-symbols-outlined">search</span>
        <span class="ml-1">Search</span>
      </button>
    </div>
  </div>
  <p class="text-xs text-gray-500 mt-1">14-day Umrah from Kano — flights, visa, hotels, transport, Ziyarah &amp; support. Choose city &amp; month.</p>
</form>

<script>
function umrahHomeSearch() {
  return {
    city: '', month: '', pax: 1,
    go() {
      // Build a clean flight-style URL: /umrah/search/{city}/{month}/{pax}
      const city = this.city ? encodeURIComponent(this.city) : 'any';
      const month = this.month ? encodeURIComponent(this.month) : 'any';
      const pax = Math.min(5, Math.max(1, parseInt(this.pax || 1, 10)));
      window.location.href = '<?= root ?>umrah/search/' + city + '/' + month + '/' + pax;
    }
  };
}
</script>
