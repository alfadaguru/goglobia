<?php
@$SECURE or die('Access Denied!');

$searchParams = $_SESSION['rail_search'] ?? [];
$from = htmlspecialchars($searchParams['from'] ?? '');
$to = htmlspecialchars($searchParams['to'] ?? '');
$dateRaw = $searchParams['date'] ?? date('d-m-Y', strtotime('+7 days'));
$railDateObj = DateTime::createFromFormat('d-m-Y', (string)$dateRaw);
if (!$railDateObj instanceof DateTime) {
    $railDateObj = DateTime::createFromFormat('Y-m-d', (string)$dateRaw);
}
$date = htmlspecialchars($dateRaw);
$dateDisplay = $railDateObj instanceof DateTime ? $railDateObj->format('d M Y') : date('d M Y', strtotime($dateRaw));
$journeyType = (int)($searchParams['journey_type'] ?? 3);
$dateUnix = _train_inquiry_timestamp_from_date($dateRaw, $journeyType);
$adults = (int)($searchParams['adults'] ?? 1);
$children = (int)($searchParams['children'] ?? 0);
$infants = (int)($searchParams['infants'] ?? 0);
$childAges = _train_parse_category_metrics($searchParams['child_ages'] ?? [], $children, $journeyType, 'child');
$infantAges = _train_parse_category_metrics($searchParams['infant_ages'] ?? [], $infants, $journeyType, 'infant');

if (!function_exists('_train_station_label')) {
    require_once dirname(__DIR__, 5) . '/modules/rail/train/stations.php';
}

// Resolve full station names from rail_stations (not raw codes like PIJ / POJ).
$fromName = _train_station_label($db, (string)($searchParams['from'] ?? ''));
$toName = _train_station_label($db, (string)($searchParams['to'] ?? ''));
$stationEnglishMap = [
    strtoupper((string)($searchParams['from'] ?? '')) => _train_station_english_only($fromName),
    strtoupper((string)($searchParams['to'] ?? '')) => _train_station_english_only($toName),
];

if (!function_exists('_train_seat_classes')) {
    require_once dirname(__DIR__, 5) . '/modules/rail/train/search.php';
}
$trainSeatClassMap = _train_seat_classes();
_train_ensure_seat_classes_table($db);
$seatClassCatalog = _train_seat_class_catalog($db);
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css">
<script src="<?= root ?>assets/js/noui.js"></script>

<style>
  .noUi-connect {
    background: #1570ef;
  }
  .noUi-horizontal {
    height: 6px;
  }
  .noUi-target {
    background: #e5e7eb;
    border-radius: 4px;
    border: none;
    box-shadow: none;
  }
  .noUi-handle {
    border: 3px solid #1570ef;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 2px 4px rgba(0, 0, 0, .2);
    cursor: pointer;
  }
  .noUi-handle:before,
  .noUi-handle:after {
    display: none;
  }
  .noUi-horizontal .noUi-handle {
    width: 18px;
    height: 18px;
    right: -9px;
    top: -7px;
  }
</style>

<div class="w-full h-full bg-gray-100 dark:bg-gray-900">
<!-- SEARCH WIDGET (RE-FILLS FROM SESSION) -->
<div class="container py-5 pb-2">
  <div class="card overflow-visible bg-white border border-gray-200 rounded-2xl p-5 relative">
    <?php include views . "modules/rail/rail-search.php"; ?>
  </div>
</div>

<section class="container mx-auto px-3 py-6">
  <div x-data="railListing()" x-init="load()">

    <div class="grid grid-cols-12 gap-4 md:gap-6">
      <?php include views . 'modules/rail/listing/rail-filters.php'; ?>
      <?php include views . 'modules/rail/listing/rail-results.php'; ?>
    </div>

    <!-- MOBILE FILTER BUTTON -->
    <button @click="showMobileFilters=true" x-show="!showMobileFilters"
      class="md:hidden fixed bottom-6 right-6 w-12 h-12 z-30 bg-[#1570ef] text-white rounded-full shadow-2xl flex items-center justify-center"
      aria-label="Filters">
      <span class="material-symbols-outlined text-xl">tune</span>
    </button>

  </div>
</section>
</div>

<?php include views . 'modules/rail/listing/rail-script.php'; ?>
