<?php @$SECURE or die('Access Denied!'); ?>
<?php
/**
 * WHITE-LABEL BRANDED STAY DETAIL / ROOM-SELECTION VIEW
 *
 * This is a bespoke, fully re-authored branded alternative to
 * app/views/modules/stays/details/stay.php. It is rendered by
 * app/routes/stays/detailRoutes.php ONLY when the booking page is reached on a
 * tenant host for that tenant's own approved property ($__wlChrome true), inside
 * the hotel's booking chrome (_bookingchrome_head/foot).
 *
 * DESIGN: standalone 5-star hotel look driven by the --wl-* design tokens the
 * chrome injects (primary / accent / ink / paper / display / body). Elegant room
 * cards with a clear "Book this room" action instead of the platform's utilitarian
 * rate table, mobile-first.
 *
 * ENGINE SAFETY: the Alpine components (hotelDetails / updateSearchParams /
 * hotelRooms) and the FSLightbox loaders are COPIED VERBATIM from the platform
 * views (stay.php + rooms.php) at the bottom of this file. Only the presentational
 * markup differs. Every @click / x-model / x-text binding here matches those
 * components exactly — no booking/pricing/hold/supplier-crediting logic is
 * re-implemented, so there is no money-path divergence.
 *
 * Keep the <script> blocks below in sync if the platform components change
 * (they are a deliberate fork — see docs/supplier/WHITE-LABEL-*).
 */

// --- Session stay context (verbatim from stay.php:28-79) -------------------
$stayDetail = $_SESSION['stay_detail'] ?? null;
if (!$stayDetail) {
    header('Location: ' . root . 'stays');
    exit;
}
$hotelName = $stayDetail['hotel_name'];
$hotelId = $stayDetail['hotel_id'];
$supplier = $stayDetail['supplier'];
$hotelChain = $stayDetail['hotel_chain'] ?? '';
$checkin = $stayDetail['checkin'];
$checkout = $stayDetail['checkout'];
$nationality = $stayDetail['nationality'];
$totalRooms = $stayDetail['total_rooms'];
$totalAdults = $stayDetail['total_adults'];
$totalChildren = $stayDetail['total_children'];
$roomsData = $stayDetail['rooms_data'];
$invalidNationality = $stayDetail['invalid_nationality'] ?? false;
$urlParts = $stayDetail['url_parts'] ?? [];
$countries = $stayDetail['countries'] ?? [];

$checkinDate = DateTime::createFromFormat('d-m-Y', $checkin) ?: new DateTime($checkin);
$checkoutDate = DateTime::createFromFormat('d-m-Y', $checkout) ?: new DateTime($checkout);
$nights = max(0, (int) $checkinDate->diff($checkoutDate)->days);

$stayAmenityCurrency = strtoupper(trim((string) ($_SESSION['app_currency'] ?? '')));
$stayAmenityRates = [];
$currencySource = !empty($GLOBALS['currencies']) && is_array($GLOBALS['currencies'])
    ? $GLOBALS['currencies']
    : ((isset($db) && is_object($db)) ? ($db->select('currencies', ['name', 'rate', 'default']) ?: []) : []);
foreach ($currencySource as $currRow) {
    $code = strtoupper(trim((string) ($currRow['name'] ?? '')));
    if ($code === '') {
        continue;
    }
    $stayAmenityRates[$code] = (float) ($currRow['rate'] ?? 0);
    if ($stayAmenityCurrency === '' && (string) ($currRow['default'] ?? '0') === '1') {
        $stayAmenityCurrency = $code;
    }
}
if ($stayAmenityCurrency === '') {
    $stayAmenityCurrency = 'USD';
}

// Tenant display name (spaces, not the raw spaceless hotel name) for headings.
$wlTenantName = (string) (function_exists('wl_tenant') ? (wl_tenant('name') ?: $hotelName) : $hotelName);
?>

<!-- noUiSlider (price filter) + FSLightbox used by the hotelRooms component -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@latest/ol.css">
<script src="https://cdn.jsdelivr.net/npm/ol@latest/dist/ol.js"></script>

<style>
  /* ---- Branded stay-detail look. All colour/typography from chrome --wl-* vars ---- */
  .wl-bk{--r:18px;font-family:var(--wl-body);color:var(--wl-ink);max-width:1180px;margin:0 auto;padding:clamp(20px,4vw,40px) clamp(16px,4vw,28px) 120px}
  .wl-bk a{color:inherit}
  .wl-bk .crumbs{display:flex;gap:8px;align-items:center;font-size:.76rem;letter-spacing:.04em;color:var(--wl-ink-soft);text-transform:uppercase;margin-bottom:22px}
  .wl-bk .crumbs span.sep{opacity:.5}
  .wl-bk h1,.wl-bk h2,.wl-bk h3{font-family:var(--wl-display);font-weight:500}

  /* Modify-search panel */
  .wl-modify{background:#fff;border:1px solid var(--wl-line,rgba(0,0,0,.08));border-radius:var(--r);padding:clamp(16px,3vw,24px);margin-bottom:28px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
  .wl-modify .ttl{display:flex;align-items:center;gap:10px;font-family:var(--wl-display);font-size:1.05rem;margin-bottom:16px}
  .wl-modify .grid{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end}
  .wl-modify .fld{flex:1;min-width:150px}
  .wl-modify label{display:block;font-size:.62rem;letter-spacing:.16em;text-transform:uppercase;color:var(--wl-accent);margin-bottom:6px}
  .wl-modify .inp,.wl-modify select{width:100%;height:46px;border:1px solid var(--wl-line,rgba(0,0,0,.14));border-radius:12px;padding:0 14px;background:var(--wl-paper);font-family:var(--wl-body);font-size:.92rem;color:var(--wl-ink);outline:none}
  .wl-modify .inp:focus,.wl-modify select:focus{border-color:var(--wl-accent)}
  .wl-modify .upd{height:46px;padding:0 24px;border:0;border-radius:12px;background:var(--wl-primary);color:#fff;letter-spacing:.1em;text-transform:uppercase;font-size:.74rem;cursor:pointer;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
  .wl-gd{position:relative}
  .wl-gd-menu{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:40;background:#fff;border:1px solid var(--wl-line,rgba(0,0,0,.12));border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.14);max-height:360px;overflow:auto;padding:4px}
  .wl-step{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;border:1px solid var(--wl-line,rgba(0,0,0,.2));background:#fff;cursor:pointer}
  .wl-step[disabled]{opacity:.35;cursor:not-allowed}

  /* Hotel header / gallery */
  .wl-hhead{margin-bottom:10px}
  .wl-hhead .stars{color:var(--wl-accent);letter-spacing:2px;font-size:.9rem}
  .wl-hhead h1{font-size:clamp(1.8rem,3.4vw,2.6rem);line-height:1.1;margin:6px 0 4px;overflow-wrap:break-word;word-break:break-word}
  .wl-hhead .addr{color:var(--wl-ink-soft);font-size:.9rem}
  .wl-gallery{display:grid;grid-template-columns:2fr 1fr;gap:10px;margin:18px 0 26px;border-radius:var(--r);overflow:hidden}
  .wl-gallery .main{height:400px}
  .wl-gallery .side{display:grid;grid-template-rows:1fr 1fr;gap:10px}
  .wl-gallery img{width:100%;height:100%;object-fit:cover;cursor:pointer;display:block}
  .wl-gallery .side{height:400px}
  @media(max-width:760px){.wl-gallery{grid-template-columns:1fr}.wl-gallery .main,.wl-gallery .side{height:220px}}

  .wl-about{display:grid;grid-template-columns:2.2fr 1fr;gap:34px;margin-bottom:34px}
  .wl-about h3{font-size:1.2rem;margin-bottom:12px}
  .wl-about .desc{color:var(--wl-ink-soft);font-size:.96rem;line-height:1.65}
  .wl-about .amen{display:flex;flex-direction:column;gap:9px}
  .wl-about .amen .it{display:flex;gap:8px;align-items:center;font-size:.9rem;color:var(--wl-ink-soft)}
  .wl-about .amen .it .material-symbols-outlined{font-size:18px;color:var(--wl-accent)}
  @media(max-width:760px){.wl-about{grid-template-columns:1fr;gap:20px}}

  /* Room filter bar */
  .wl-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:center;background:#fff;border:1px solid var(--wl-line,rgba(0,0,0,.08));border-radius:14px;padding:12px 16px;margin-bottom:22px}
  .wl-filters .lbl{font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;color:var(--wl-ink-soft)}
  .wl-filters select{height:40px;border:1px solid var(--wl-line,rgba(0,0,0,.14));border-radius:999px;padding:0 14px;background:var(--wl-paper);font-family:var(--wl-body);font-size:.86rem;color:var(--wl-ink);outline:none}
  .wl-filters .slide{display:flex;align-items:center;gap:10px;background:var(--wl-paper);border:1px solid var(--wl-line,rgba(0,0,0,.12));border-radius:999px;padding:6px 16px;min-width:230px}
  .wl-filters .slide #roomPriceSlider{flex:1}
  .wl-filters .reset{height:40px;padding:0 16px;border-radius:999px;border:1px solid var(--wl-line,rgba(0,0,0,.16));background:#fff;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:.8rem}
  #roomPriceSlider .noUi-connect{background:var(--wl-accent)}
  #roomPriceSlider .noUi-handle{border:2px solid var(--wl-accent);border-radius:50%;background:#fff;box-shadow:0 2px 4px rgba(0,0,0,.2);width:15px;height:15px}
  #roomPriceSlider .noUi-handle:before,#roomPriceSlider .noUi-handle:after{display:none}
  #roomPriceSlider.noUi-horizontal{height:4px}
  #roomPriceSlider.noUi-horizontal .noUi-handle{width:15px;height:15px;right:-7px;top:-6px}
  #roomPriceSlider.noUi-target{background:rgba(0,0,0,.12);border-radius:4px;border:none;box-shadow:none;padding:0 8px}

  /* Branded room cards */
  .wl-room{background:#fff;border:1px solid var(--wl-line,rgba(0,0,0,.08));border-radius:var(--r);overflow:hidden;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
  .wl-room .top{display:grid;grid-template-columns:200px 1fr;gap:0}
  .wl-room .photo{position:relative;min-height:200px;background:var(--wl-paper) center/cover no-repeat;cursor:pointer}
  .wl-room .photo .empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:color-mix(in srgb,var(--wl-primary) 28%,#fff)}
  .wl-room .photo .count{position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.6);color:#fff;font-size:.68rem;padding:3px 8px;border-radius:999px}
  .wl-room .info{padding:22px 24px}
  .wl-room .info h3{font-size:1.4rem;margin-bottom:8px}
  .wl-room .cap{display:flex;gap:16px;color:var(--wl-ink-soft);font-size:.82rem;margin-bottom:12px}
  .wl-room .cap span{display:inline-flex;align-items:center;gap:5px}
  .wl-room .cap .material-symbols-outlined{font-size:17px}
  .wl-room .ramen{display:flex;flex-wrap:wrap;gap:6px}
  .wl-room .ramen span{font-size:.72rem;padding:3px 10px;border:1px solid var(--wl-line,rgba(0,0,0,.12));border-radius:999px;color:var(--wl-ink-soft)}
  @media(max-width:620px){.wl-room .top{grid-template-columns:1fr}.wl-room .photo{height:200px}}

  /* Rate rows (branded, card-ish, no spreadsheet) */
  .wl-rates{border-top:1px solid var(--wl-line,rgba(0,0,0,.08))}
  .wl-rate{display:grid;grid-template-columns:1fr auto auto;gap:18px;align-items:center;padding:16px 24px;border-bottom:1px solid var(--wl-line,rgba(0,0,0,.06))}
  .wl-rate:last-child{border-bottom:0}
  .wl-rate.sel{background:color-mix(in srgb,var(--wl-accent) 8%,#fff)}
  .wl-rate .meta .type{font-weight:600;font-size:.95rem;margin-bottom:3px}
  .wl-rate .meta .sub{font-size:.78rem;color:var(--wl-ink-soft);display:flex;flex-wrap:wrap;gap:4px 12px}
  .wl-rate .meta .sub .ok{color:#2e7d55}
  .wl-rate .meta .disc{display:inline-block;margin-top:5px;font-size:.72rem;color:#b3261e;background:#fdeceb;border-radius:999px;padding:2px 9px}
  .wl-rate .price{text-align:right;white-space:nowrap}
  .wl-rate .price .pn{font-family:var(--wl-display);font-size:1.3rem;font-weight:600}
  .wl-rate .price .per,.wl-rate .price .tot{font-size:.72rem;color:var(--wl-ink-soft)}
  .wl-rate .act{display:flex;align-items:center;gap:10px}
  .wl-rate .qty{height:40px;border:1px solid var(--wl-line,rgba(0,0,0,.16));border-radius:999px;padding:0 10px;background:#fff;font-size:.85rem;outline:none}
  .wl-book-btn{height:44px;min-width:132px;padding:0 20px;border:0;border-radius:999px;cursor:pointer;font-family:var(--wl-body);font-weight:600;font-size:.86rem;letter-spacing:.02em;transition:all .15s;display:inline-flex;align-items:center;justify-content:center;gap:6px}
  .wl-book-btn.pick{background:var(--wl-accent);color:#fff}
  .wl-book-btn.pick:hover{filter:brightness(.94)}
  .wl-book-btn.on{background:var(--wl-primary);color:#fff}
  @media(max-width:620px){
    .wl-rate{grid-template-columns:1fr;gap:10px;align-items:stretch}
    .wl-rate .price{text-align:left}
    .wl-rate .act{justify-content:space-between}
    .wl-book-btn{flex:1}
  }

  /* Sticky booking bar */
  .wl-bar{position:fixed;left:0;right:0;bottom:0;z-index:60;background:var(--wl-primary);color:#fff;box-shadow:0 -6px 24px rgba(0,0,0,.2)}
  .wl-bar .in{max-width:1180px;margin:0 auto;padding:14px clamp(16px,4vw,28px);display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .wl-bar .sum .k{font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;opacity:.75}
  .wl-bar .sum .v{font-family:var(--wl-display);font-size:1rem}
  .wl-bar .sum .list{font-size:.76rem;opacity:.8}
  .wl-bar .tot{text-align:right}
  .wl-bar .tot .amt{font-family:var(--wl-display);font-size:1.6rem;font-weight:600}
  .wl-bar .go{height:50px;padding:0 28px;border:0;border-radius:999px;background:var(--wl-accent);color:#fff;font-weight:700;font-size:1rem;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
  .wl-bar .go:disabled{opacity:.7}
  @media(max-width:620px){.wl-bar .in{gap:10px}.wl-bar .go{width:100%}}

  .wl-empty{background:#fff;border:1px solid var(--wl-line,rgba(0,0,0,.08));border-radius:var(--r);padding:40px;text-align:center;color:var(--wl-ink-soft)}
  [x-cloak]{display:none!important}
</style>

<!-- ================= HOTEL DETAIL + ROOM SELECTION (branded) ================= -->
<div class="wl-bk" x-data="hotelDetails()">

  <div class="crumbs">
    <a href="<?= htmlspecialchars(function_exists('wl_home_url') ? wl_home_url((string) (function_exists('wl_tenant') ? wl_tenant('slug') : '')) : root) ?>"><?= htmlspecialchars($wlTenantName) ?></a>
    <span class="sep">/</span>
    <span>Rooms &amp; rates</span>
  </div>

  <!-- Modify search -->
  <div class="wl-modify" x-data="updateSearchParams()">
    <div class="ttl"><span class="material-symbols-outlined" style="color:var(--wl-accent)">edit_calendar</span> Modify your stay</div>
    <form @submit.prevent="updateSearch()">
      <div class="grid">
        <div class="fld">
          <label>Check-in</label>
          <input type="text" value="<?= htmlspecialchars($checkin) ?>" class="inp HotelCheckin" readonly placeholder="Select date">
        </div>
        <div class="fld">
          <label>Check-out</label>
          <input type="text" value="<?= htmlspecialchars($checkout) ?>" class="inp HotelCheckout" readonly placeholder="Select date">
        </div>
        <div class="fld wl-gd" @click.away="guestsOpen = false">
          <label>Guests &amp; rooms</label>
          <div class="inp" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer" @click="guestsOpen = !guestsOpen">
            <span x-text="getGuestText()"></span>
            <span class="material-symbols-outlined" :class="guestsOpen ? 'rotate-180' : ''" style="font-size:18px">expand_more</span>
          </div>
          <div class="wl-gd-menu" x-show="guestsOpen" x-cloak>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--wl-line,rgba(0,0,0,.08))">
              <div style="font-size:.82rem;font-weight:600">Rooms</div>
              <div style="display:flex;align-items:center;gap:6px">
                <button type="button" class="wl-step" @click="removeRoom()" :disabled="roomsData.length <= 1"><span class="material-symbols-outlined" style="font-size:15px">remove</span></button>
                <span x-text="roomsData.length" style="width:26px;text-align:center;font-weight:600"></span>
                <button type="button" class="wl-step" @click="addRoom()" :disabled="roomsData.length >= 5"><span class="material-symbols-outlined" style="font-size:15px">add</span></button>
              </div>
            </div>
            <template x-for="(room, index) in roomsData" :key="index">
              <div style="padding:10px 12px;border-bottom:1px solid var(--wl-line,rgba(0,0,0,.06))">
                <div style="font-size:.78rem;font-weight:600;margin-bottom:8px" x-text="`Room ${index + 1}`"></div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                  <span style="font-size:.84rem">Adults</span>
                  <div style="display:flex;align-items:center;gap:8px">
                    <button type="button" class="wl-step" @click="decrementGuest(index, 'adults')">-</button>
                    <span style="width:26px;text-align:center" x-text="room.adults"></span>
                    <button type="button" class="wl-step" @click="incrementGuest(index, 'adults')">+</button>
                  </div>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between">
                  <span style="font-size:.84rem">Children</span>
                  <div style="display:flex;align-items:center;gap:8px">
                    <button type="button" class="wl-step" @click="decrementGuest(index, 'children')">-</button>
                    <span style="width:26px;text-align:center" x-text="room.children"></span>
                    <button type="button" class="wl-step" @click="incrementGuest(index, 'children')">+</button>
                  </div>
                </div>
                <div x-show="room.children > 0" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px">
                  <template x-for="(age, ageIndex) in room.childAges" :key="ageIndex">
                    <select x-model.number="room.childAges[ageIndex]" style="height:36px;border:1px solid var(--wl-line,rgba(0,0,0,.14));border-radius:8px;padding:0 8px;font-size:.8rem">
                      <option value="" disabled>Age</option>
                      <template x-for="a in Array.from({length: 18}, (_, i) => i)" :key="a">
                        <option :value="a" x-text="a"></option>
                      </template>
                    </select>
                  </template>
                </div>
              </div>
            </template>
          </div>
        </div>
        <div class="fld">
          <label>Nationality</label>
          <select x-model="nationality">
            <option value="NULL">Select nationality</option>
            <?php foreach ($countries as $country): ?>
              <option value="<?= htmlspecialchars($country['iso']) ?>"><?= htmlspecialchars($country['nicename']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld" style="flex:0 0 auto">
          <button type="submit" class="upd" :disabled="updating">
            <span class="material-symbols-outlined" style="font-size:18px" x-show="!updating">refresh</span>
            <span x-show="!updating">Update</span>
            <span x-show="updating">Updating…</span>
          </button>
        </div>
      </div>
    </form>
  </div>

  <!-- Loading -->
  <div x-show="loading" class="wl-empty">
    <span class="material-symbols-outlined" style="font-size:40px;color:var(--wl-accent)">hourglass_top</span>
    <p style="margin-top:8px">Loading availability…</p>
  </div>

  <!-- Error -->
  <div x-show="error" x-cloak class="wl-empty">
    <span class="material-symbols-outlined" style="font-size:40px;color:#b3261e">error</span>
    <p style="margin-top:8px" x-text="errorMessage"></p>
    <a href="<?= root ?>stays" style="color:var(--wl-accent);font-weight:600">Back to search</a>
  </div>

  <!-- Hotel detail + rooms -->
  <div x-show="!loading && hotelData" x-cloak>
    <div class="wl-hhead">
      <div class="stars" x-show="hotelData?.stars"><template x-for="i in (Number(hotelData?.stars) || 0)" :key="i"><span>★</span></template></div>
      <h1 x-text="hotelData?.name || '<?= htmlspecialchars($wlTenantName) ?>'"></h1>
      <p class="addr" x-text="hotelData?.full_address || hotelData?.address || hotelData?.location"></p>
    </div>

    <!-- Gallery -->
    <div class="wl-gallery">
      <div class="main"><img :src="hotelData?.images?.[0] || '<?= root ?>uploads/no_img.jpg'" onerror="this.src='<?= root ?>uploads/no_img.jpg'" @click="openFSLightbox(0)" alt=""></div>
      <div class="side">
        <img :src="hotelData?.images?.[1] || '<?= root ?>uploads/no_img.jpg'" onerror="this.src='<?= root ?>uploads/no_img.jpg'" @click="openFSLightbox(1)" alt="">
        <img :src="hotelData?.images?.[2] || '<?= root ?>uploads/no_img.jpg'" onerror="this.src='<?= root ?>uploads/no_img.jpg'" @click="openFSLightbox(2)" alt="">
      </div>
    </div>

    <!-- About + amenities -->
    <div class="wl-about">
      <div>
        <h3>About this stay</h3>
        <div class="desc" :class="descriptionExpanded ? '' : 'line-clamp-6'" x-html="hotelData?.description || ''"></div>
        <button type="button" x-show="descriptionNeedsToggle()" @click="descriptionExpanded = !descriptionExpanded" style="margin-top:8px;color:var(--wl-accent);font-weight:600;font-size:.86rem;background:none;border:0;cursor:pointer" x-text="descriptionExpanded ? 'Read less' : 'Read more'"></button>
      </div>
      <div>
        <h3>Amenities</h3>
        <div class="amen" x-show="keyAmenitiesList().length > 0">
          <template x-for="(amenity, ai) in visibleKeyAmenities()" :key="ai">
            <div class="it"><span class="material-symbols-outlined">check_circle</span><span x-text="amenityLabel(amenity)"></span></div>
          </template>
        </div>
        <button type="button" x-show="amenitiesNeedsToggle()" @click="amenitiesExpanded = !amenitiesExpanded" style="margin-top:8px;color:var(--wl-accent);font-weight:600;font-size:.86rem;background:none;border:0;cursor:pointer" x-text="amenitiesExpanded ? 'Read less' : 'Read more'"></button>
        <p x-show="keyAmenitiesList().length === 0" style="color:var(--wl-ink-soft);font-size:.86rem">Not available</p>
      </div>
    </div>

    <!-- ROOMS (branded) — bound to hotelRooms() component -->
    <div x-data="hotelRooms()">
      <h2 style="font-size:1.5rem;margin-bottom:16px">Choose your room</h2>

      <!-- Filters -->
      <div class="wl-filters" x-show="!loading && !error && rooms.length > 0" x-cloak>
        <span class="lbl"><span x-text="getFilteredCount()"></span> available</span>
        <select x-model="roomFilters.selectedType" @change="applyRoomFilters()">
          <option value="">All room types</option>
          <template x-for="type in availableRoomTypes" :key="type"><option :value="type" x-text="type"></option></template>
        </select>
        <div class="slide">
          <span style="font-size:.74rem;color:var(--wl-ink-soft)" x-text="getCurrencySymbol(currency) + roomFilters.priceRange[0]"></span>
          <div id="roomPriceSlider"></div>
          <span style="font-size:.74rem;color:var(--wl-ink-soft)" x-text="getCurrencySymbol(currency) + roomFilters.priceRange[1]"></span>
        </div>
        <select x-model="roomFilters.sortBy" @change="applyRoomFilters()">
          <option value="price_low">Price: Low to High</option>
          <option value="price_high">Price: High to Low</option>
          <option value="name_asc">Name: A-Z</option>
          <option value="name_desc">Name: Z-A</option>
        </select>
        <button class="reset" @click="resetRoomFilters()"><span class="material-symbols-outlined" style="font-size:16px">restart_alt</span> Reset</button>
      </div>

      <!-- Loading rooms -->
      <div x-show="loading" class="wl-empty"><span class="material-symbols-outlined" style="font-size:36px;color:var(--wl-accent)">hourglass_top</span><p style="margin-top:6px">Finding the best rates…</p></div>

      <!-- Error rooms -->
      <div x-show="error && !loading" class="wl-empty">
        <p x-text="errorMessage" style="color:#b3261e"></p>
        <button @click="fetchRooms()" style="margin-top:8px;color:var(--wl-accent);font-weight:600;background:none;border:0;cursor:pointer">Try again</button>
      </div>

      <!-- Room cards -->
      <div x-show="!loading && !error && rooms.length > 0">
        <template x-for="(roomData, roomIndex) in filteredRooms" :key="roomData.room_id">
          <div class="wl-room" x-show="roomData.visible">
            <div class="top">
              <div class="photo" :style="roomData.room_images && roomData.room_images.length ? `background-image:url('${roomData.room_images[0]}')` : ''" @click="roomData.room_images && roomData.room_images.length && openRoomImageGallery(roomData.room_images, 0)">
                <template x-if="!roomData.room_images || !roomData.room_images.length"><div class="empty"><span class="material-symbols-outlined" style="font-size:40px">bed</span></div></template>
                <div class="count" x-show="roomData.room_images && roomData.room_images.length > 1" x-text="`${roomData.room_images.length} photos`"></div>
              </div>
              <div class="info">
                <h3 x-text="roomData.room_name" style="text-transform:capitalize"></h3>
                <div class="cap">
                  <span><span class="material-symbols-outlined">person</span><span x-text="`${roomData.max_adults} ${roomData.max_adults > 1 ? 'adults' : 'adult'}`"></span></span>
                  <span x-show="roomData.max_children > 0"><span class="material-symbols-outlined">child_care</span><span x-text="`${roomData.max_children} ${roomData.max_children > 1 ? 'children' : 'child'}`"></span></span>
                </div>
                <div class="ramen" x-show="roomData.amenities && roomData.amenities.length > 0">
                  <template x-for="amenity in roomData.amenities.slice(0, 6)" :key="amenity.id"><span x-text="amenity.name"></span></template>
                  <span x-show="roomData.amenities.length > 6" style="border:0" x-text="`+${roomData.amenities.length - 6} more`"></span>
                </div>
              </div>
            </div>

            <!-- No pricing -->
            <div x-show="!roomData.options || roomData.options.length === 0" style="padding:20px 24px;color:var(--wl-ink-soft);font-size:.88rem">
              Contact us for pricing on this room.
            </div>

            <!-- Rates -->
            <div class="wl-rates" x-show="roomData.options && roomData.options.length > 0">
              <template x-for="(option, optIndex) in roomData.options" :key="optIndex">
                <div class="wl-rate" :class="isOptionSelected(roomData.room_id, optIndex) ? 'sel' : ''">
                  <div class="meta">
                    <div class="type" x-text="isOptionRefundable(option) ? 'Refundable' : 'Non-refundable'"></div>
                    <div class="sub">
                      <span x-text="option.board_name ? option.board_name : (option.breakfast_included ? 'Breakfast included' : 'Room only')"></span>
                      <span class="ok" x-show="isOptionCancellationFree(option)">Free cancellation</span>
                    </div>
                    <div class="disc" x-show="option.discount_percentage > 0" x-text="`${option.discount_percentage}% off`"></div>
                  </div>
                  <div class="price">
                    <div class="pn" x-text="`${getCurrencySymbol(currency)}${parseFloat(option.price_per_night || 0).toFixed(2)}`"></div>
                    <div class="per">per night</div>
                    <div class="tot"><span x-text="`${getCurrencySymbol(currency)}${parseFloat(option.total_price || 0).toFixed(2)}`"></span> total</div>
                  </div>
                  <div class="act">
                    <!-- Hotelbeds multi-room: one button per occupancy -->
                    <template x-if="isHotelbedsMultiRoomSearch()">
                      <div style="display:flex;flex-direction:column;gap:6px">
                        <template x-for="occupancyIndex in option.matching_occupancy_indexes" :key="`${roomData.room_id}_${optIndex}_${occupancyIndex}`">
                          <button class="wl-book-btn" :class="isOptionSelectedForOccupancy(option, occupancyIndex) ? 'on' : 'pick'"
                                  @click="selectOccupancyOption(roomData, option, optIndex, occupancyIndex)"
                                  x-text="isOptionSelectedForOccupancy(option, occupancyIndex) ? `Room ${occupancyIndex + 1} selected` : `Select for room ${occupancyIndex + 1}`"></button>
                        </template>
                      </div>
                    </template>
                    <!-- Single-room: qty + book -->
                    <template x-if="!isHotelbedsMultiRoomSearch()">
                      <div style="display:flex;align-items:center;gap:10px">
                        <select class="qty" x-model="optionQuantities[`${roomData.room_id}_${optIndex}`]" @change="updateOptionQuantity(roomData.room_id, optIndex, parseInt($event.target.value))">
                          <template x-for="qty in (option.available_quantity || 1) + 1" :key="qty"><option :value="qty - 1" x-text="qty - 1"></option></template>
                        </select>
                        <button class="wl-book-btn" :class="isOptionSelected(roomData.room_id, optIndex) ? 'on' : 'pick'" @click="selectRoomOption(roomData, option, optIndex)">
                          <span x-show="!isOptionSelected(roomData.room_id, optIndex)">Book this room</span>
                          <span x-show="isOptionSelected(roomData.room_id, optIndex)" style="display:inline-flex;align-items:center;gap:5px"><span class="material-symbols-outlined" style="font-size:17px">check</span> Selected</span>
                        </button>
                      </div>
                    </template>
                  </div>
                </div>
              </template>
            </div>
          </div>
        </template>
      </div>

      <!-- No rooms -->
      <div x-show="!loading && !error && rooms.length === 0" class="wl-empty"><p>No rooms available for these dates.</p></div>

      <!-- Sticky booking bar -->
      <div class="wl-bar" x-show="getTotalSelectedRooms() > 0" x-cloak
           x-transition:enter="transition ease-out duration-300"
           x-transition:enter-start="transform translate-y-full" x-transition:enter-end="transform translate-y-0">
        <div class="in">
          <div class="sum">
            <div class="k">Selected</div>
            <div class="v" x-text="`${getTotalSelectedRooms()} room${getTotalSelectedRooms() > 1 ? 's' : ''}`"></div>
            <div class="list" x-text="getSelectedRoomsSummary()"></div>
          </div>
          <div style="display:flex;align-items:center;gap:20px">
            <div class="tot">
              <div class="k">Total · <?= (int) $nights ?> night<?= $nights > 1 ? 's' : '' ?></div>
              <div class="amt" x-text="`${getCurrencySymbol(currency)}${getTotalPrice().toFixed(2)}`"></div>
            </div>
            <button class="go" @click="bookNow()" :disabled="bookingLoading">
              <span class="material-symbols-outlined" style="font-size:20px" x-show="!bookingLoading">lock</span>
              <span x-show="!bookingLoading">Continue booking</span>
              <span x-show="bookingLoading">Processing…</span>
            </button>
          </div>
        </div>
      </div>
    </div>
    <!-- /hotelRooms -->

    <!-- Map -->
    <div style="background:#fff;border:1px solid var(--wl-line,rgba(0,0,0,.08));border-radius:18px;padding:22px;margin-top:28px" x-show="hasValidCoordinates()">
      <h3 style="font-size:1.1rem;margin-bottom:12px">Location</h3>
      <div id="map" style="width:100%;height:320px;border-radius:12px;overflow:hidden"></div>
    </div>
  </div>

  <!-- Nationality modal -->
  <div x-show="showNationalityModal" x-cloak style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(10,8,6,.7)">
    <div style="background:#fff;border-radius:16px;max-width:440px;width:100%;padding:28px">
      <h3 style="font-family:var(--wl-display);font-size:1.2rem;margin-bottom:8px">Select your nationality</h3>
      <p style="color:var(--wl-ink-soft);font-size:.9rem;margin-bottom:14px">Please select your nationality to continue viewing rates.</p>
      <select x-model="selectedNationality" @change="confirmNationality()" style="width:100%;height:46px;border:1px solid var(--wl-line,rgba(0,0,0,.16));border-radius:12px;padding:0 14px">
        <option value="">Select country</option>
        <?php foreach ($countries as $country): ?>
          <option value="<?= htmlspecialchars($country['iso']) ?>"><?= htmlspecialchars($country['nicename']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>
<!-- ================= /branded markup — VERBATIM COMPONENT SCRIPTS BELOW ================= -->

<!-- updateSearchParams() + hotelDetails() — VERBATIM from app/views/modules/stays/details/stay.php -->
<script>
    /**
     * UPDATE SEARCH PARAMS ALPINE COMPONENT
     *
     * Manages the modify search card functionality
     *
     * STATE:
     * @property {String} checkin - Check-in date (YYYY-MM-DD)
     * @property {String} checkout - Check-out date (YYYY-MM-DD)
     * @property {String} nationality - Selected nationality ISO code
     * @property {Array} roomsData - Room configurations with adults/children/ages
     * @property {Boolean} guestsOpen - Guests dropdown visibility state
     * @property {Boolean} updating - Loading state during search update
     *
     * KEY METHODS:
     * - getGuestText(): Formats guest summary text (e.g., "3 Guests, 2 Rooms")
     * - incrementGuest(): Adds adults/children to a room
     * - decrementGuest(): Removes adults/children from a room
     * - updateSearch(): Rebuilds URL with new parameters and redirects
     *
     * URL FORMAT:
     * /stay/{hotel-name}/{hotel_id}/{supplier}/{checkin}/{checkout}/{nationality}/{rooms}/{room_configs}
     * Room configs: "2-1-5-3/2-0" = Room1(2 adults, 1 child age 5 with 1 infant age 3), Room2(2 adults, 0 children)
     */
    function updateSearchParams() {
        return {
            checkin: '<?= $checkin ?>',
            checkout: '<?= $checkout ?>',
            nationality: '<?= $nationality ?>',
            roomsData: <?= json_encode($roomsData) ?>,
            guestsOpen: false,
            updating: false,

            getGuestText() {
                const totalAdults = this.roomsData.reduce((sum, room) => sum + parseInt(room.adults), 0);
                const totalChildren = this.roomsData.reduce((sum, room) => sum + parseInt(room.children), 0);
                const totalRooms = this.roomsData.length;

                let text = `${totalAdults + totalChildren} Guest${(totalAdults + totalChildren) > 1 ? 's' : ''}`;
                text += `, ${totalRooms} Room${totalRooms > 1 ? 's' : ''}`;
                return text;
            },

            incrementGuest(roomIndex, type) {
                const maxAdults = '<?= $supplier ?>' === 'tbo-holidays' ? 8 : 10;
                const maxChildren = '<?= $supplier ?>' === 'tbo-holidays' ? 4 : 6;
                if (type === 'adults') {
                    if (this.roomsData[roomIndex].adults < maxAdults) {
                        this.roomsData[roomIndex].adults++;
                    }
                } else {
                    if (this.roomsData[roomIndex].children < maxChildren) {
                        this.roomsData[roomIndex].children++;
                        this.roomsData[roomIndex].childAges.push(1);
                    }
                }
            },

            decrementGuest(roomIndex, type) {
                if (type === 'adults') {
                    if (this.roomsData[roomIndex].adults > 1) {
                        this.roomsData[roomIndex].adults--;
                    }
                } else {
                    if (this.roomsData[roomIndex].children > 0) {
                        this.roomsData[roomIndex].children--;
                        this.roomsData[roomIndex].childAges.pop();
                    }
                }
            },

            addRoom() {
                if (this.roomsData.length < 5) {
                    this.roomsData.push({ adults: 2, children: 0, childAges: [] });
                }
            },

            removeRoom() {
                if (this.roomsData.length > 1) {
                    this.roomsData.pop();
                }
            },

            updateSearch() {
                const checkinInput = document.querySelector('.HotelCheckin');
                const checkoutInput = document.querySelector('.HotelCheckout');
                const checkin = window.SearchDate && checkinInput
                    ? SearchDate.getValue(checkinInput)
                    : (checkinInput?.value || this.checkin);
                const checkout = window.SearchDate && checkoutInput
                    ? SearchDate.getValue(checkoutInput)
                    : (checkoutInput?.value || this.checkout);

                if (!checkin || !checkout) return alert('<?= T::please_select_dates ?? "Please select dates" ?>');

                this.updating = true;
                const roomConfigs = this.roomsData.map(r => {
                    let cfg = `${r.adults}-${r.children}`;
                    if (r.children > 0 && r.childAges?.length) {
                        cfg += '-' + r.childAges.map(a => {
                            const n = parseInt(a, 10);
                            return Number.isFinite(n) && n >= 0 ? Math.min(17, n) : 1;
                        }).join('-');
                    }
                    return cfg;
                }).join('/');

                const chain = '<?= $hotelChain ?: "_" ?>';
                const url = `<?= root ?>stay/<?= strtolower(str_replace(' ', '-', preg_replace('/[^a-zA-Z0-9\\s-]/', '', $hotelName))) ?>/<?= $hotelId ?>/<?= $supplier ?>/${chain}/${checkin}/${checkout}/${this.nationality}/${this.roomsData.length}/${roomConfigs}`;
                location.href = url;
            }
        }
    }

    /**
     * HOTEL DETAILS ALPINE COMPONENT
     *
     * Main component managing hotel data fetching and display
     *
     * STATE PROPERTIES:
     * @property {Boolean} loading - Loading state for initial hotel data fetch
     * @property {Boolean} error - Error state for failed requests
     * @property {String} errorMessage - Error message to display
     * @property {Object} hotelData - Complete hotel information from API
     * @property {Boolean} showNationalityModal - Modal visibility state
     * @property {String} selectedNationality - Selected nationality in modal
     *
     * KEY METHODS:
     * - init(): Called on component mount, triggers hotel data fetch
     * - fetchHotelDetails(): Fetches hotel info from API
     * - initMap(): Initializes OpenLayers map with hotel coordinates
     * - openFSLightbox(index): Opens image gallery at specific index
     * - confirmNationality(): Handles nationality selection and page reload
     *
     * API ENDPOINTS:
     * - HotelBeds: /modules/stays/hotelbeds/details
     * - Other suppliers: /modules/stays/hotels/details
     *
     * GLOBAL REFERENCES:
     * - window.hotelDetailsComponent: Reference to this component
     * - window.hotelMapInstance: OpenLayers map instance
     * - window.fsLightboxInstances: FSLightbox instances storage
     */
    function hotelDetails() {
        return {
            loading: true,
            loadingRooms: false,
            error: false,
            errorMessage: '',
            hotelData: null,
            displayCurrency: '<?= addslashes($stayAmenityCurrency) ?>',
            currencyRates: <?= json_encode($stayAmenityRates, JSON_UNESCAPED_UNICODE) ?>,
            showNationalityModal: <?= $invalidNationality ? 'true' : 'false' ?>,
            selectedNationality: '',
            descriptionExpanded: false,
            amenitiesExpanded: false,
            amenitiesPreviewCount: 8,

            // FSLightbox will handle image gallery

            init() {
                <?php if (!$invalidNationality): ?>
                    this.fetchHotelDetails();
                <?php endif; ?>
            },

            /**
             * Flat amenity list for Key amenities.
             * Prefers Content API detailed facilities (with paid/fee flags) when present.
             * Returns objects: { name, paid, fee_amount, fee_currency }
             */
            keyAmenitiesList() {
                const items = [];
                const pushUnique = (entry) => {
                    const name = String(entry?.name || '').trim();
                    if (!name || name === '1' || /^\d+$/.test(name)) return;
                    if (items.some(n => n.name.toLowerCase() === name.toLowerCase())) return;
                    items.push({
                        name,
                        paid: !!entry?.paid,
                        fee_amount: entry?.fee_amount ?? null,
                        fee_currency: entry?.fee_currency || '',
                    });
                };

                const detailed = this.hotelData?.amenities_detailed;
                if (Array.isArray(detailed) && detailed.length) {
                    detailed.forEach(item => pushUnique({
                        name: item?.name || item?.description || item,
                        paid: !!(item?.paid || item?.ind_fee),
                        fee_amount: item?.fee_amount ?? item?.amount ?? null,
                        fee_currency: item?.fee_currency || item?.currency || '',
                    }));
                    return items;
                }

                const grouped = this.hotelData?.amenities_grouped;
                if (Array.isArray(grouped) && grouped.length) {
                    grouped.forEach(group => {
                        (group?.amenities || []).forEach(name => pushUnique({ name, paid: false }));
                    });
                    return items;
                }

                (this.hotelData?.amenities || []).forEach(amenity => {
                    if (typeof amenity === 'object' && amenity !== null) {
                        pushUnique({
                            name: amenity.name || amenity.title || amenity.description,
                            paid: !!(amenity.paid || amenity.ind_fee),
                            fee_amount: amenity.fee_amount ?? amenity.amount ?? null,
                            fee_currency: amenity.fee_currency || amenity.currency || '',
                        });
                    } else {
                        pushUnique({ name: amenity, paid: false });
                    }
                });
                return items;
            },

            amenityLabel(amenity) {
                if (typeof amenity === 'object' && amenity !== null) {
                    return amenity.name || '';
                }
                return String(amenity || '');
            },

            amenityIsPaid(amenity) {
                return !!(typeof amenity === 'object' && amenity && amenity.paid);
            },

            amenityFeeText(amenity) {
                if (!this.amenityIsPaid(amenity)) return '';
                let amount = amenity?.fee_amount;
                let currency = String(amenity?.fee_currency || '').trim().toUpperCase();
                if (amount === null || amount === undefined || amount === '') return '';
                let n = Number(amount);
                if (!Number.isFinite(n) || n <= 0) return '';

                // Client-side fallback: convert supplier fee (often EUR) → guest display currency
                const to = String(this.displayCurrency || '').trim().toUpperCase();
                const rates = this.currencyRates || {};
                if (to && currency && currency !== to) {
                    const fromRate = parseFloat(rates[currency]);
                    const toRate = parseFloat(rates[to]);
                    if (fromRate > 0 && toRate > 0) {
                        n = Math.round(n * (toRate / fromRate) * 100) / 100;
                        currency = to;
                    }
                } else if (to && !currency) {
                    currency = to;
                }

                const formatted = Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '');
                return currency ? `${formatted} ${currency}` : formatted;
            },

            visibleKeyAmenities() {
                const all = this.keyAmenitiesList();
                if (this.amenitiesExpanded) return all;
                return all.slice(0, this.amenitiesPreviewCount);
            },

            amenitiesNeedsToggle() {
                return this.keyAmenitiesList().length > this.amenitiesPreviewCount;
            },

            descriptionNeedsToggle() {
                const html = this.hotelData?.description || '';
                const text = String(html).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                return text.length > 280;
            },

            hotelNoticesList() {
                const issues = this.hotelData?.issues;
                if (!Array.isArray(issues) || !issues.length) return [];
                const notices = [];
                issues.forEach(issue => {
                    const text = typeof issue === 'object' && issue !== null
                        ? String(issue.description || issue.name || '').trim()
                        : String(issue || '').trim();
                    if (text && !notices.includes(text)) notices.push(text);
                });
                return notices;
            },

            confirmNationality() {
                if (!this.selectedNationality) return;

                // Rebuild URL with selected nationality
                const urlParts = <?= json_encode($urlParts) ?>;
                urlParts[6] = this.selectedNationality;
                const newUrl = '<?= root ?>stay/' + urlParts.join('/');

                // Reload page with new URL
                window.location.href = newUrl;
            },

            hasValidCoordinates() {
                if (!this.hotelData) return false;

                const lat = Number(this.hotelData.latitude);
                const lng = Number(this.hotelData.longitude);
                const isZeroCoordinates = Math.abs(lat) < 0.000001 && Math.abs(lng) < 0.000001;

                return Number.isFinite(lat)
                    && Number.isFinite(lng)
                    && lat >= -90
                    && lat <= 90
                    && lng >= -180
                    && lng <= 180
                    && !isZeroCoordinates;
            },

            /**
             * FETCH HOTEL DETAILS FROM API
             *
             * Sends POST request with booking parameters
             * Populates hotelData with response
             * Initializes map after data loaded
             *
             * REQUEST PAYLOAD:
             * - hotel_id: Hotel identifier
             * - supplier: Hotel supplier (hotelbeds, etc.)
             * - checkin/checkout: Stay dates
             * - nationality: Guest nationality
             * - rooms: Array of room configurations
             *
             * RESPONSE:
             * - success: boolean
             * - data: Complete hotel object with images, amenities, description, etc.
             * - message: Error message if failed
             */
            async fetchHotelDetails() {
                try {
                    const supplier = '<?= strtolower($supplier) ?>';
                    const endpoint = '<?= root ?>modules/stays/' + supplier + '/details';

                    // ✅ Get last clicked hotel from localStorage (set by search page)
                    const lastHotel = JSON.parse(localStorage.getItem('last_hotel') || '{}');

                    // ✅ Build request payload with chain
                    const requestPayload = {
                        hotel_id: '<?= $hotelId ?>',
                        supplier: supplier,
                        checkin: '<?= $checkin ?>',
                        checkout: '<?= $checkout ?>',
                        nationality: '<?= $nationality ?>',
                        rooms: <?= json_encode($roomsData) ?>,
                        currency: this.displayCurrency || '<?= addslashes($stayAmenityCurrency) ?>',

                        // Pass address, amenities, city from search results if available
                        address: lastHotel.address || '',
                        city: lastHotel.city || '',
                        country: lastHotel.country || '',
                        amenities: lastHotel.amenities || [],
                        rating: lastHotel.rating || 0,
                        stars: lastHotel.stars || 0
                    };

                    // ✅ Add hotel_chain only if it exists (for Travelport)
                    const hotelChain = '<?= $hotelChain ?>';
                    if (hotelChain && hotelChain !== '_') {
                        requestPayload.hotel_chain = hotelChain;
                    }

                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(requestPayload)
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Reorder images to place default image first
                        if (data.data.image && data.data.images && data.data.images.length > 0) {
                            const defaultImg = data.data.image;
                            const defaultIdx = data.data.images.findIndex(img => (img.url || img) === defaultImg);

                            if (defaultIdx > 0) {
                                const images = [...data.data.images];
                                const [item] = images.splice(defaultIdx, 1);
                                images.unshift(item);
                                data.data.images = images;
                            }
                        }

                        this.hotelData = data.data;

                        window.hotelDetailsComponent = this;

                        this.$nextTick(() => {
                            this.initMap();
                        });
                    } else {
                        this.error = true;
                        this.errorMessage = data.message || '<?= T::failed_to_load_hotel_details ?>';
                    }
                } catch (err) {
                    console.error('Error fetching hotel details:', err);
                    this.error = true;
                    this.errorMessage = '<?= T::network_error_please_try_again ?>';
                } finally {
                    this.loading = false;
                }
            },

            /**
             * INITIALIZE OPENLAYERS MAP
             *
             * Creates interactive map with hotel marker
             *
             * FEATURES:
             * - OpenStreetMap base layer
             * - Custom pin marker at hotel coordinates
             * - Centered on hotel location with zoom level 15
             *
             * COORDINATES:
             * - Uses latitude/longitude from hotelData
             * - Converts to OpenLayers projection (EPSG:3857)
             *
             * MARKER:
             * - Custom pin icon from Flaticon
             * - Anchored at bottom center for accurate positioning
             *
             * GLOBAL REFERENCE:
             * - Stores instance in window.hotelMapInstance
             */
            initMap() {
                const mapEl = document.getElementById('map');

                if (!mapEl) {
                    return;
                }

                if (!this.hasValidCoordinates()) {
                    return;
                }

                const lat = Number(this.hotelData.latitude);
                const lng = Number(this.hotelData.longitude);

                const center = ol.proj.fromLonLat([lng, lat]);

                const map = new ol.Map({
                    target: 'map',
                    layers: [
                        new ol.layer.Tile({
                            source: new ol.source.OSM()
                        })
                    ],
                    view: new ol.View({
                        center: center,
                        zoom: 15
                    })
                });

                const marker = new ol.Feature({
                    geometry: new ol.geom.Point(center)
                });

                const markerStyle = new ol.style.Style({
                    image: new ol.style.Icon({
                        anchor: [0.5, 1],
                        src: "https://cdn-icons-png.flaticon.com/512/684/684908.png",
                        scale: 0.08
                    })
                });

                marker.setStyle(markerStyle);

                const vectorLayer = new ol.layer.Vector({
                    source: new ol.source.Vector({
                        features: [marker]
                    })
                });

                map.addLayer(vectorLayer);

                window.hotelMapInstance = map;
            },

            /**
             * OPEN FSLIGHTBOX IMAGE GALLERY
             *
             * Opens fullscreen image gallery at specified index
             *
             * FEATURES:
             * - Filters out no_img.jpg placeholder images
             * - Trims whitespace from URLs
             * - Creates fresh lightbox instance (prevents conflicts)
             * - Explicitly sets type as 'image' for each source
             * - Fallback: Opens image in new tab if FSLightbox fails
             *
             * BUG FIX:
             * Previous issue with double query parameters in HotelBeds URLs
             * Fixed by destroying old instance and creating fresh one
             *
             * @param {Number} index - Starting image index (0-based)
             */
            openFSLightbox(index) {
                if (!this.hotelData?.images || this.hotelData.images.length === 0) return;

                // Check if FSLightbox is loaded with retry mechanism
                const tryOpenLightbox = () => {
                    if (typeof FsLightbox === 'undefined') {
                        console.error('FSLightbox is not loaded, trying fallback...');
                        // Fallback: open image in new tab
                        const validImages = this.hotelData.images.filter(img => !img.includes('no_img.jpg'));
                        if (validImages[index]) {
                            window.open(validImages[index], '_blank');
                        }
                        return;
                    }

                    // Filter out no_img.jpg images and ensure valid URLs
                    const validImages = this.hotelData.images
                        .filter(img => img && !img.includes('no_img.jpg'))
                        .map(img => img.trim());

                    if (validImages.length === 0) return;

                    // Destroy existing instance to prevent conflicts
                    if (window.fsLightboxInstances?.hotelGallery) {
                        delete window.fsLightboxInstances.hotelGallery;
                    }

                    // Create fresh lightbox instance with sources
                    const lightbox = new FsLightbox();
                    lightbox.props.sources = validImages;
                    lightbox.props.types = validImages.map(() => 'image');
                    lightbox.props.slide = Math.min(index, validImages.length - 1) + 1;

                    // Store instance
                    if (!window.fsLightboxInstances) {
                        window.fsLightboxInstances = {};
                    }
                    window.fsLightboxInstances.hotelGallery = lightbox;

                    // Open lightbox
                    lightbox.open();
                };

                // Try immediately, if fails retry after short delay
                if (typeof FsLightbox === 'undefined') {
                    setTimeout(tryOpenLightbox, 100);
                } else {
                    tryOpenLightbox();
                }
            }
        }
    }
</script>

<!-- hotelRooms() — VERBATIM from app/views/modules/stays/details/rooms.php -->
<script>
/*
 * HOTEL ROOMS ALPINE.JS COMPONENT
 *
 * Manages all room data, filtering, selection, and booking functionality
 *
 * STATE PROPERTIES:
 * - rooms: All available rooms from API
 * - loading: Loading state for API request
 * - error: Error state for failed requests
 * - currency: Currency code (USD, EUR, etc.)
 * - selectedRooms: Selected options with quantities
 * - optionQuantities: Quantity per option
 * - filteredRooms: Rooms after applying filters
 * - roomFilters: Current filter values
 * - availableRoomTypes: Unique room type names
 * - roomPriceRange: Min/max prices
 * - roomPriceSlider: noUiSlider instance
 *
 * KEY METHODS:
 * - fetchRooms(): Load room data from API
 * - extractRoomFilters(): Build filter options from room data
 * - applyRoomFilters(): Filter and sort rooms based on current filters
 * - selectRoomOption(): Toggle selection of a rate option
 * - updateOptionQuantity(): Update quantity for selected option
 * - continueBooking(): Save draft and redirect to booking page
 */
function hotelRooms() {
    return {
        rooms: [],
        loading: true,
        error: false,
        errorMessage: '',
        isPlaceholderPricing: false,
        currency: 'USD',
        supplier: '<?= $supplier ?>',
        searchedRooms: <?= (int) ($totalRooms ?? 1) ?>,
        requestedRooms: <?= json_encode(array_values($roomsData)) ?>,
        selectedRooms: {}, // Multi-room Hotelbeds: { 'occupancy_0': { searched_room_index: 0, ... } }
        optionQuantities: {}, // { 'room_id_optIndex': quantity }
        bookingLoading: false,

        // Room Filters
        filteredRooms: [],
        roomFiltersExpanded: false,
        roomFilters: {
            selectedType: '',
            priceRange: [0, 100000],
            sortBy: 'price_low'
        },
        availableRoomTypes: [],
        roomPriceRange: { min: 0, max: 100000 },
        roomPriceSlider: null,

        getCurrencySymbol(currency) {
            return (currency || 'USD') + ' ';
        },

        // INITIALIZATION - Called automatically by Alpine.js when component mounts
        init() {
            this.fetchRooms();
        },

        /*
         * FETCH AVAILABLE ROOMS
         * Calls API endpoint to get all available rooms and rate options
         *
         * API ENDPOINTS:
         * - HotelBeds supplier: /modules/stays/hotelbeds/rooms
         * - Other suppliers: /modules/stays/hotels/rooms
         *
         * REQUEST PAYLOAD:
         * - hotel_id, supplier, checkin, checkout, nationality, rooms config
         *
         * RESPONSE HANDLING:
         * - Sets rooms array with all room types and options
         * - Initializes option quantities to 0
         * - Extracts filter options and applies default filters
         */
        async fetchRooms() {
            this.loading = true;
            this.error = false;

            try {
                const supplier = '<?= $supplier ?>';
                const endpoint = '<?=root?>modules/stays/'+supplier+'/rooms';

                // Prepare body
                <?php
                $checkinFormatted = date('Y-m-d', strtotime($checkin));
                $checkoutFormatted = date('Y-m-d', strtotime($checkout));

                // Ensure childAges exists for each room
                foreach ($roomsData as &$room) {
                    if (!isset($room['childAges'])) {
                        $room['childAges'] = [];
                    }
                }

                // Get Base Currency
                $baseCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
                $baseCurrencyCode = $baseCurrencyData['name'] ?? 'USD';
                $baseCurrencyRate = $baseCurrencyData['rate'] ?? 1;

                // Get Display Currency (Session)
                $sessionCurrency = $_SESSION['app_currency'] ?? $baseCurrencyCode;
                $displayCurrencyData = $db->get('currencies', ['name', 'rate'], ['name' => $sessionCurrency]);
                $displayCurrencyCode = $displayCurrencyData['name'] ?? $baseCurrencyCode;
                $displayCurrencyRate = $displayCurrencyData['rate'] ?? 1;

                // Calculate Conversion Rate (Base to Display)
                // Rate to convert Display -> Base = 1 / (DisplayRate / BaseRate)
                // Or simply: BaseAmount = DisplayAmount * (BaseRate / DisplayRate)
                $conversionFactor = 1;
                if ($displayCurrencyRate > 0) {
                    $conversionFactor = $baseCurrencyRate / $displayCurrencyRate;
                }
                ?>

                const requestPayload = {
                    hotel_id: '<?= $hotelId ?>',
                    supplier: supplier,
                    checkin: '<?= $checkin ?>',
                    checkout: '<?= $checkout ?>',
                    destination: '<?= addslashes($_SESSION['stay_detail']['destination'] ?? $_SESSION['hotel_destination'] ?? '') ?>',
                    adults: <?= $totalAdults ?>,
                    children: <?= $totalChildren ?>,
                    rooms: <?= $totalRooms ?>,
                    rooms_data: <?= json_encode($roomsData) ?>,
                    nationality: '<?= $nationality ?>',
                    currency: '<?= $_SESSION['app_currency'] ?? 'USD' ?>'
                };

                // ✅ Add hotel_chain only for Travelport
                const hotelChain = '<?= $hotelChain ?>';
                if (hotelChain && hotelChain !== '_') {
                    requestPayload.hotel_chain = hotelChain;
                }

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload)
                });

                // const body = JSON.stringify({
                //     hotel_id: '<?= $hotelId ?>',
                //     supplier: supplier,
                //     checkin: '<?= $checkinFormatted ?>',
                //     checkout: '<?= $checkoutFormatted ?>',
                //     nationality: '<?= $nationality ?>',
                //     rooms: <?= json_encode($roomsData) ?>
                // });

                // const response = await fetch(endpoint, {
                //     method: 'POST',
                //     headers: { 'Content-Type': 'application/json' },
                //     body: body
                // });

                const data = await response.json();

                console.log('🏨 Stays Supplier API Response:', data);

                if (data.success) {
                    this.rooms = data.data.rooms || [];

                    // Log available quantity / allotment for each room option from API
                    this.rooms.forEach(room => {
                        (room.options || []).forEach(opt => {
                            console.log(`[API Room Allotment] ${room.room_name} (${opt.board_name || 'Option'}) -> Total Available API Quantity: ${opt.available_quantity}`);
                        });
                    });

                    this.currency = data.data.currency || 'USD';
                    this.searchedRooms = parseInt(data.data.searched_rooms || <?= (int) ($totalRooms ?? 1) ?>, 10) || 1;
                    
                    // CRITICAL: Store book_hash from API response
                    this.book_hash = data.data.book_hash || '';
                    
                    // Store dev mode info and test credentials if present
                    this.dev_mode_data = {
                        enabled: data.data.dev_mode_enabled || false,
                        test_hotel_id: data.data.actual_hotel_id_used || null,
                        prebook_status: data.data.prebook_status || null
                    };
                    
                    if (this.dev_mode_data.enabled) {
                        console.log('🧪 DEV MODE ACTIVE');
                        console.log('📍 Test Hotel Used:', this.dev_mode_data.test_hotel_id);
                        console.log('✅ Prebook Status:', this.dev_mode_data.prebook_status);
                    }

                    // Inject Currency Info
                    this.baseCurrency = '<?= $baseCurrencyCode ?>';
                    this.conversionFactor = <?= $conversionFactor ?>;

                    this.isPlaceholderPricing = data.data.is_placeholder_pricing || false;

                    // Default order: cheapest rates first within each room, then rooms by min price
                    this.rooms.forEach(room => this.sortRoomOptionsByPrice(room));
                    this.rooms.sort((a, b) => this.roomMinTotalPrice(a) - this.roomMinTotalPrice(b));

                    // Initialize option quantities
                    this.rooms.forEach(room => {
                        if (room.options) {
                            room.options.forEach((opt, idx) => {
                                this.optionQuantities[`${room.room_id}_${idx}`] = 0;
                            });
                        }
                    });

                    // Extract filter data and initialize filters
                    this.extractRoomFilters();
                    this.applyRoomFilters();
                } else {
                    this.error = true;
                    this.errorMessage = data.message || 'Failed to load rooms';
                }
            } catch (err) {
                console.error('Error fetching rooms:', err);
                this.error = true;
                this.errorMessage = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        /*
         * UPDATE OPTION QUANTITY
         * Called when user changes quantity in dropdown
         *
         * LOGIC:
         * - Updates optionQuantities state
         * - If quantity = 0 and option selected: Remove from selectedRooms
         * - If quantity > 0 and option selected: Update quantity in selectedRooms
         */
        updateOptionQuantity(roomId, optionIndex, quantity) {
            const key = `${roomId}_${optionIndex}`;
            this.optionQuantities[key] = quantity;

            // If quantity is 0, remove from selected rooms
            if (quantity === 0 && this.selectedRooms[key]) {
                delete this.selectedRooms[key];
            } else if (quantity > 0 && this.selectedRooms[key]) {
                // Update quantity in selected room
                this.selectedRooms[key].quantity = quantity;
            }
        },

        getOptionQuantity(roomId, optionIndex) {
            const key = `${roomId}_${optionIndex}`;
            return this.optionQuantities[key] || 0;
        },

        isOptionRefundable(option) {
            return Number(option?.refundable) === 1;
        },

        isOptionCancellationFree(option) {
            return Number(option?.cancellation_free) === 1;
        },

        isHotelbedsMultiRoomSearch() {
            return this.supplier === 'hotelbeds' && this.requestedRooms.length > 1;
        },

        formatOccupancy(occupancy) {
            const adults = Number(occupancy?.adults || 0);
            const children = Number(occupancy?.children || 0);
            const childAges = Array.isArray(occupancy?.child_ages) ? occupancy.child_ages : [];
            const labels = [`${adults} ${adults === 1 ? 'adult' : 'adults'}`];

            if (children > 0) {
                const ageText = childAges.length ? ` (age${childAges.length > 1 ? 's' : ''} ${childAges.join(', ')})` : '';
                labels.push(`${children} ${children === 1 ? 'child' : 'children'}${ageText}`);
            }

            return labels.join(', ');
        },

        isOptionSelectedForOccupancy(option, occupancyIndex) {
            const selected = this.selectedRooms[`occupancy_${occupancyIndex}`];
            return selected?.option?.rate_key === option?.rate_key;
        },

        selectOccupancyOption(roomData, option, optionIndex, occupancyIndex) {
            const key = `occupancy_${occupancyIndex}`;

            if (this.isOptionSelectedForOccupancy(option, occupancyIndex)) {
                delete this.selectedRooms[key];
                return;
            }

            if (!(option.matching_occupancy_indexes || []).includes(occupancyIndex)) {
                vt.warn('This rate does not match the selected room occupancy.');
                return;
            }

            // Replacing the choice for a requested room is safe; it cannot
            // create a duplicate occupancy slot.
            this.selectedRooms[key] = {
                quantity: 1,
                option_index: optionIndex,
                option: option,
                room_id: roomData.room_id,
                room_name: roomData.room_name,
                searched_room_index: occupancyIndex,
            };
        },

        /*
         * SELECT/UNSELECT ROOM OPTION
         * Called when user clicks Select/Selected button
         *
         * BEHAVIOR:
         * - If already selected: Unselect and reset quantity to 0
         * - If not selected: Add to selectedRooms with quantity (auto-set to 1 if 0)
         * - Multiple options from same room type are allowed
         *
         * SELECTION KEY FORMAT: 'room_id_optionIndex'
         */
        selectRoomOption(roomData, option, optionIndex) {
            if (this.isHotelbedsMultiRoomSearch()) {
                return;
            }

            const key = `${roomData.room_id}_${optionIndex}`;

            // Check if this option is already selected
            if (this.selectedRooms[key]) {
                // Unselect - remove from selected rooms
                delete this.selectedRooms[key];
                this.optionQuantities[key] = 0;
                console.log('Option unselected:', key);
                return;
            }

            let quantity = this.getOptionQuantity(roomData.room_id, optionIndex);

            // If quantity is 0, auto-set to 1 (or full multi-room package qty when available)
            if (quantity === 0) {
                const packageQty = parseInt(option.available_quantity || 1, 10) || 1;
                const autoQty = (this.searchedRooms > 1 && packageQty > 1)
                    ? Math.min(packageQty, this.searchedRooms)
                    : 1;
                this.optionQuantities[key] = autoQty;
                quantity = autoQty;
            }

            // Store selected option
            this.selectedRooms[key] = {
                quantity: quantity,
                option_index: optionIndex,
                option: option,
                room_id: roomData.room_id,
                room_name: roomData.room_name
            };

            console.log('Option selected:', this.selectedRooms[key]);
        },

        /*
         * OPEN ROOM IMAGE GALLERY
         * Opens FSLightbox gallery to view room images
         *
         * FEATURES:
         * - Filters out no_img.jpg placeholder images
         * - Trims whitespace from image URLs
         * - Creates fresh lightbox instance to prevent conflicts
         * - Explicitly sets type as 'image' for each source
         * - Fallback to new tab if FSLightbox fails to load
         */
        openRoomImageGallery(images, startIndex = 0) {
            if (!images || images.length === 0) return;

            // Filter out no_img.jpg images and ensure valid URLs
            const validImages = images
                .filter(img => img && !img.includes('no_img.jpg'))
                .map(img => img.trim());

            if (validImages.length === 0) return;

            // Ensure startIndex is within bounds
            const slideIndex = Math.max(0, Math.min(startIndex, validImages.length - 1)) + 1;

            // Check if FSLightbox is loaded
            if (typeof FsLightbox === 'undefined') {
                console.error('FSLightbox is not loaded yet');
                // Fallback - open first image in new tab
                if (validImages.length > 0) {
                    window.open(validImages[startIndex] || validImages[0], '_blank');
                }
                return;
            }

            try {
                // Destroy existing instance to prevent conflicts
                if (window.fsLightboxInstances?.roomGallery) {
                    delete window.fsLightboxInstances.roomGallery;
                }

                // Create fresh lightbox instance with sources
                const lightbox = new FsLightbox();
                lightbox.props.sources = validImages;
                lightbox.props.types = validImages.map(() => 'image');
                lightbox.props.slide = slideIndex;

                // Store instance
                if (!window.fsLightboxInstances) {
                    window.fsLightboxInstances = {};
                }
                window.fsLightboxInstances.roomGallery = lightbox;

                // Open lightbox
                lightbox.open();
            } catch (error) {
                console.error('Error opening FSLightbox:', error);
                // Fallback - open image in new tab
                window.open(validImages[startIndex] || validImages[0], '_blank');
            }
        },

        isOptionSelected(roomId, optionIndex) {
            const key = `${roomId}_${optionIndex}`;
            return this.selectedRooms[key] !== undefined;
        },

        /*
         * EXTRACT FILTER OPTIONS FROM ROOM DATA
         * Analyzes fetched rooms to build filter dropdowns and ranges
         *
         * EXTRACTS:
         * - availableRoomTypes: Unique room type names (sorted)
         * - roomPriceRange: Min/max prices from all options
         *
         * TRIGGERS: Initializes noUiSlider for price range
         */
        extractRoomFilters() {
            // Extract unique room types
            this.availableRoomTypes = [...new Set(this.rooms.map(r => r.room_name))].sort();

            // Calculate price range from all room options
            let minPrice = Infinity;
            let maxPrice = 0;

            this.rooms.forEach(room => {
                if (room.options && room.options.length > 0) {
                    room.options.forEach(opt => {
                        const price = parseFloat(opt.total_price);
                        if (price < minPrice) minPrice = price;
                        if (price > maxPrice) maxPrice = price;
                    });
                }
            });

            this.roomPriceRange.min = minPrice === Infinity ? 0 : Math.floor(minPrice);
            this.roomPriceRange.max = maxPrice === 0 ? 100000 : Math.ceil(maxPrice);
            this.roomFilters.priceRange = [this.roomPriceRange.min, this.roomPriceRange.max];

            // Initialize price slider
            this.$nextTick(() => {
                setTimeout(() => this.initRoomPriceSlider(), 300);
            });
        },

        /*
         * INITIALIZE PRICE RANGE SLIDER
         * Creates noUiSlider instance with dynamic min/max from room data
         *
         * CONFIGURATION:
         * - Range: Dynamic based on actual room prices
         * - Step: Calculated as 1% of total range
         * - Connect: True (fills bar between handles)
         * - Events: 'update' for real-time, 'change' for filter trigger
         */
        initRoomPriceSlider() {
            const slider = document.getElementById('roomPriceSlider');
            if (!slider || typeof noUiSlider === 'undefined') return;

            if (this.roomPriceSlider) {
                this.roomPriceSlider.destroy();
            }

            this.roomPriceSlider = noUiSlider.create(slider, {
                start: [this.roomPriceRange.min, this.roomPriceRange.max],
                connect: true,
                range: {
                    'min': this.roomPriceRange.min,
                    'max': this.roomPriceRange.max
                },
                step: Math.ceil((this.roomPriceRange.max - this.roomPriceRange.min) / 100),
                format: {
                    to: (value) => Math.round(value),
                    from: (value) => Number(value)
                }
            });

            this.roomPriceSlider.on('update', (values) => {
                this.roomFilters.priceRange = [parseInt(values[0]), parseInt(values[1])];
            });

            this.roomPriceSlider.on('change', () => {
                this.applyRoomFilters();
            });
        },

        /*
         * APPLY ROOM FILTERS
         * Filters and sorts rooms based on current filter selections
         *
         * FILTER LOGIC:
         * 1. Room Type: Exact match filtering
         * 2. Price Range: Checks if ANY option falls within range
         * 3. Sorting: price_low (default), price_high, name_asc, name_desc, or default
         * 4. Within each room, rate options are always ordered low → high by total_price
         */
        roomMinTotalPrice(room) {
            const prices = (room?.options || [])
                .map(opt => parseFloat(opt.total_price))
                .filter(price => Number.isFinite(price) && price >= 0);
            return prices.length ? Math.min(...prices) : Number.POSITIVE_INFINITY;
        },

        sortRoomOptionsByPrice(room) {
            if (!room || !Array.isArray(room.options) || room.options.length < 2) {
                return room;
            }
            room.options = [...room.options].sort((a, b) => {
                return (parseFloat(a.total_price) || 0) - (parseFloat(b.total_price) || 0);
            });
            return room;
        },

        applyRoomFilters() {
            let filtered = this.rooms.map(room => {
                const cloned = { ...room, visible: true };
                if (Array.isArray(room.options)) {
                    cloned.options = [...room.options];
                }
                return this.sortRoomOptionsByPrice(cloned);
            });

            // Filter by room type
            if (this.roomFilters.selectedType) {
                filtered = filtered.filter(r => r.room_name === this.roomFilters.selectedType);
            }

            // Filter by price range - check if ANY option is within range
            filtered = filtered.filter(room => {
                if (!room.options || room.options.length === 0) return true;
                return room.options.some(opt => {
                    const price = parseFloat(opt.total_price);
                    return price >= this.roomFilters.priceRange[0] && price <= this.roomFilters.priceRange[1];
                });
            });

            // Sort room types by cheapest rate (default: low → high)
            if (this.roomFilters.sortBy === 'price_low' || this.roomFilters.sortBy === 'default') {
                filtered.sort((a, b) => this.roomMinTotalPrice(a) - this.roomMinTotalPrice(b));
            } else if (this.roomFilters.sortBy === 'price_high') {
                filtered.sort((a, b) => this.roomMinTotalPrice(b) - this.roomMinTotalPrice(a));
            } else if (this.roomFilters.sortBy === 'name_asc') {
                filtered.sort((a, b) => String(a.room_name || '').localeCompare(String(b.room_name || '')));
            } else if (this.roomFilters.sortBy === 'name_desc') {
                filtered.sort((a, b) => String(b.room_name || '').localeCompare(String(a.room_name || '')));
            }

            this.filteredRooms = filtered;
        },

        /*
         * RESET ALL FILTERS
         * Clears all filter selections and resets to defaults
         */
        resetRoomFilters() {
            this.roomFilters.selectedType = '';
            this.roomFilters.priceRange = [this.roomPriceRange.min, this.roomPriceRange.max];
            this.roomFilters.sortBy = 'price_low';

            if (this.roomPriceSlider) {
                this.roomPriceSlider.set([this.roomPriceRange.min, this.roomPriceRange.max]);
            }

            this.applyRoomFilters();
        },

        getFilteredCount() {
            return this.filteredRooms.filter(r => r.visible).length;
        },

        // GET TOTAL SELECTED ROOMS - Sum of all quantities across all selections
        getTotalSelectedRooms() {
            return Object.values(this.selectedRooms).reduce((total, room) => total + room.quantity, 0);
        },

        // GET TOTAL BOOKING PRICE - Formula: Sum of (option.total_price * quantity)
        getTotalPrice() {
            return Object.values(this.selectedRooms).reduce((total, room) => {
                return total + (parseFloat(room.option.total_price) * room.quantity);
            }, 0);
        },

        formatCancellationPolicyText(policies, currency) {
            const first = Array.isArray(policies) ? policies[0] : null;
            if (!first) {
                return '';
            }
            const amount = parseFloat(first.amount ?? 0);
            const from = String(first.from || '');
            let dt = from ? new Date(from) : null;
            if (dt && isNaN(dt.getTime())) {
                dt = null;
            }
            if (dt && dt.getTime() <= Date.now()) {
                return 'The free cancellation period for this room has passed. A cancellation fee now applies.';
            }
            const formattedAmount = (isFinite(amount) ? amount : 0).toFixed(2);
            const currencySuffix = currency ? (' ' + currency) : '';
            if (!dt) {
                return `A cancellation fee of ${formattedAmount}${currencySuffix} will apply.`;
            }
            const dateStr = dt.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' });
            const timeStr = dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
            return `Free cancellation until ${dateStr} at ${timeStr}. After that, a cancellation fee of ${formattedAmount}${currencySuffix} will apply.`;
        },

        // Display amounts are supplier amounts run through markup + currency
        // conversion. Recover that factor from the rate already on screen so a
        // refreshed policy row can be shown in the guest's currency.
        hotelbedsSupplierToDisplayFactor(option, oldSupplierPolicies) {
            const displayPolicies = Array.isArray(option.cancellation_policies) ? option.cancellation_policies : [];
            for (let i = 0; i < displayPolicies.length; i++) {
                const supplierAmount = parseFloat(oldSupplierPolicies?.[i]?.amount ?? 0);
                const displayAmount = parseFloat(displayPolicies[i]?.amount ?? 0);
                if (supplierAmount > 0 && displayAmount > 0) {
                    return displayAmount / supplierAmount;
                }
            }
            const supplierNet = parseFloat(option.supplier_net ?? option.availability_net ?? 0);
            const displayTotal = parseFloat(option.total_price ?? 0);
            if (supplierNet > 0 && displayTotal > 0) {
                return displayTotal / supplierNet;
            }
            return 1;
        },

        applyHotelbedsCheckRateToOption(option, refreshed) {
            if (!option || !refreshed?.rate_key) {
                return;
            }

            const oldSupplierPolicies = Array.isArray(option.supplier_cancellation_policies)
                ? option.supplier_cancellation_policies
                : [];

            if (refreshed.price_changed && refreshed.old_net > 0 && refreshed.new_net > 0) {
                const ratio = parseFloat(refreshed.new_net) / parseFloat(refreshed.old_net);
                if (isFinite(ratio) && ratio > 0) {
                    ['price_per_night', 'total_price', 'base_price', 'original_price'].forEach(key => {
                        if (option[key] !== undefined && option[key] !== null) {
                            option[key] = (parseFloat(option[key]) * ratio).toFixed(2);
                        }
                    });
                    if (Array.isArray(option.cancellation_policies)) {
                        option.cancellation_policies = option.cancellation_policies.map((policy, index) => {
                            const oldRaw = parseFloat(oldSupplierPolicies[index]?.amount ?? 0);
                            const newRaw = parseFloat(refreshed.cancellation_policies?.[index]?.amount ?? oldRaw);
                            const policyRatio = oldRaw > 0 ? (newRaw / oldRaw) : ratio;
                            return {
                                from: refreshed.cancellation_policies?.[index]?.from ?? policy.from,
                                amount: (parseFloat(policy.amount) * policyRatio).toFixed(2),
                            };
                        });
                    }
                }
            } else if (refreshed.policy_changed && Array.isArray(refreshed.cancellation_policies)) {
                // Supplier and display currencies differ, so a refreshed policy amount
                // has to be converted before it is shown. CheckRate can also return a
                // different number of policy rows than the rate was selected with, so
                // only reuse an old row when it really lines up.
                const displayFactor = this.hotelbedsSupplierToDisplayFactor(option, oldSupplierPolicies);
                option.cancellation_policies = refreshed.cancellation_policies.map((policy, index) => {
                    const existing = (option.cancellation_policies || [])[index] || {};
                    const oldRaw = parseFloat(oldSupplierPolicies[index]?.amount ?? 0);
                    const newRaw = parseFloat(policy.amount ?? 0);
                    const aligned = oldRaw > 0 && existing.amount !== undefined;
                    return {
                        from: policy.from ?? existing.from ?? '',
                        amount: aligned
                            ? (parseFloat(existing.amount) * (newRaw / oldRaw)).toFixed(2)
                            : (newRaw * displayFactor).toFixed(2),
                    };
                });
            }

            option.rate_key = refreshed.rate_key;
            option.id = refreshed.rate_key;
            option.rate_type = refreshed.rate_type || 'BOOKABLE';
            if (refreshed.rate_class) {
                option.rate_class = refreshed.rate_class;
            }
            if (refreshed.refundable !== undefined && refreshed.refundable !== null) {
                option.refundable = Number(refreshed.refundable) === 1 ? 1 : 0;
            }
            if (refreshed.cancellation_free !== undefined && refreshed.cancellation_free !== null) {
                option.cancellation_free = Number(refreshed.cancellation_free) === 1 ? 1 : 0;
            }
            option.supplier_net = refreshed.new_net ?? option.supplier_net;
            if (refreshed.currency) {
                option.supplier_currency = refreshed.currency;
            }
            if (refreshed.rate_comments) {
                option.rate_comments = refreshed.rate_comments;
            }
            if (Array.isArray(refreshed.cancellation_policies)) {
                option.supplier_cancellation_policies = refreshed.cancellation_policies.map(policy => ({
                    amount: parseFloat(policy.amount ?? 0),
                    from: policy.from ?? '',
                }));
            }
            // Rebuild fee text in the room-page currency (not Hotelbeds supplier currency).
            if (Array.isArray(option.cancellation_policies) && option.cancellation_policies.length) {
                option.cancellation_text = this.formatCancellationPolicyText(
                    option.cancellation_policies,
                    this.currency || 'USD'
                );
            }
        },

        buildSelectedRoomsPayload(dynamicFactor) {
            return Object.values(this.selectedRooms)
                .sort((a, b) => (a.searched_room_index ?? Number.MAX_SAFE_INTEGER) - (b.searched_room_index ?? Number.MAX_SAFE_INTEGER))
                .map(room => {
                const optionBase = JSON.parse(JSON.stringify(room.option));

                // original_price is the net cost the commission is calculated from,
                // so it has to be converted with the rest of the amounts. Leaving it
                // in the guest's display currency stores a base-currency booking with
                // a display-currency cost.
                ['price_per_night', 'total_price', 'price', 'old_price', 'base_price', 'original_price'].forEach(key => {
                    if (optionBase[key] !== undefined && optionBase[key] !== null) {
                        optionBase[key] = (parseFloat(optionBase[key]) * dynamicFactor).toFixed(2);
                    }
                });

                if (Array.isArray(optionBase.cancellation_policies)) {
                    optionBase.cancellation_policies = optionBase.cancellation_policies.map(policy => ({
                        from: policy.from ?? '',
                        amount: (parseFloat(policy.amount ?? 0) * dynamicFactor).toFixed(2),
                    }));
                    optionBase.cancellation_text = this.formatCancellationPolicyText(
                        optionBase.cancellation_policies,
                        this.baseCurrency || 'USD'
                    );
                }

                const fullRoomData = this.rooms.find(r => r.room_id === room.room_id);
                const roomImages = fullRoomData?.room_images || [];
                const roomMainImage = fullRoomData?.room_main_image || roomImages[0] || null;

                return {
                    room_id: room.room_id,
                    room_name: room.room_name,
                    quantity: room.quantity,
                    option_index: room.option_index,
                    searched_room_index: room.searched_room_index ?? null,
                    option: optionBase,
                    room_images: roomImages,
                    room_main_image: roomMainImage
                };
            });
        },

        recalculateBookingTotals(bookingData, dynamicFactor) {
            const totalDisplay = this.getTotalPrice();
            const actualTotalDisplay = Object.values(this.selectedRooms).reduce((total, room) => {
                const basePrice = parseFloat(room.option.original_price || room.option.base_price || 0);
                return total + (basePrice * room.quantity);
            }, 0);

            bookingData.selected_rooms = this.buildSelectedRoomsPayload(dynamicFactor);
            bookingData.total_amount = (totalDisplay * dynamicFactor).toFixed(2);
            bookingData.actual_amount = (actualTotalDisplay * dynamicFactor).toFixed(2);
            bookingData.display_total = totalDisplay;
            bookingData.display_actual_total = actualTotalDisplay;
        },

        // GET SELECTED ROOMS SUMMARY - Creates text like "2x Deluxe Room, 1x Suite"
        getSelectedRoomsSummary() {
            const rooms = Object.values(this.selectedRooms);
            if (rooms.length === 0) return '';

            if (rooms.length === 1) {
                const room = rooms[0];
                return `${room.quantity}x ${room.room_name}`;
            }

            return rooms.map(r => `${r.quantity}x ${r.room_name}`).join(', ');
        },

        /*
         * BOOK NOW
         * Saves booking draft and redirects to booking page
         *
         * VALIDATION: Ensures at least one room option is selected
         *
         * BOOKING DATA:
         * - Hotel info (id, name, supplier)
         * - Stay dates (checkin, checkout, nights)
         * - Guest info (nationality, rooms config, adults, children)
         * - Selected rooms with options and quantities
         * - Total amount and currency
         *
         * API ENDPOINT: /api/stay/booking/save-draft
         * SUCCESS: Redirects to /stays/booking/{hash}
         */
        async bookNow() {
            const requiredRooms = <?= intval($totalRooms) ?>;
            const selectedCount = this.getTotalSelectedRooms();

            if (selectedCount === 0) {
                vt.warn('<?= T::please_select_room ?? "Please select at least one room" ?>');
                return;
            }

            if (selectedCount !== requiredRooms) {
                vt.warn('<?= defined("T::update_search_for_rooms") ? T::update_search_for_rooms : "You have searched for {required} room(s). You must select {required} room(s) or update your search results." ?>'.replace(/\{required\}/g, requiredRooms));
                return;
            }

            if (this.isHotelbedsMultiRoomSearch()) {
                const selectedIndexes = Object.values(this.selectedRooms)
                    .map(room => Number(room.searched_room_index))
                    .sort((a, b) => a - b);
                const expectedIndexes = Array.from({ length: this.requestedRooms.length }, (_, index) => index);

                if (
                    selectedIndexes.length !== expectedIndexes.length
                    || selectedIndexes.some((index, position) => index !== expectedIndexes[position])
                ) {
                    vt.warn('Select one matching rate for each requested room before continuing.');
                    return;
                }
            }

            this.bookingLoading = true;

            try {
                // Determine robust conversion factor based on API currency
                const apiCurrency = this.currency || 'USD';
                const baseCurrency = this.baseCurrency || 'USD';

                let dynamicFactor = 1;
                if (this.currencyRates && this.currencyRates[apiCurrency] && this.currencyRates[baseCurrency]) {
                    const apiRate = parseFloat(this.currencyRates[apiCurrency]);
                    const baseRate = parseFloat(this.currencyRates[baseCurrency]);
                    if (apiRate > 0) {
                        dynamicFactor = baseRate / apiRate;
                    }
                } else {
                    // Fallback to PHP injected factor if rates missing (unlikely)
                    dynamicFactor = this.conversionFactor || 1;
                }

                // Build complete booking data for each selected room and convert prices to Base Currency
                const selectedRoomsData = this.buildSelectedRoomsPayload(dynamicFactor);

                // Get hotel data from parent component (stay.php)
                const hotelData = window.hotelDetailsComponent?.hotelData || {};

                // Prepare booking data
                // Calculate Base Currency Total (using dynamic factor)
                const totalDisplay = this.getTotalPrice();
                const totalBase = totalDisplay * dynamicFactor;

                // Calculate Net Total (Actual Price without markup)
                // RATEHAWK COMMISSION FIX: Use original_price (already includes all nights)
                const actualTotalDisplay = Object.values(this.selectedRooms).reduce((total, room) => {
                    // original_price and base_price already include total for all nights
                    // If not available, fallback to price_per_night (for backward compatibility)
                    const basePrice = parseFloat(room.option.original_price || room.option.base_price || 0);
                    return total + (basePrice * room.quantity);
                }, 0);
                const actualTotalBase = actualTotalDisplay * dynamicFactor;

                // Prepare booking data
                const composeHotelAddress = (hotel) => {
                    if (!hotel) return '';
                    if (hotel.full_address) return String(hotel.full_address).trim();
                    const parts = [
                        hotel.street_address || hotel.address || '',
                        hotel.postal_code || '',
                        hotel.city || '',
                        hotel.country || '',
                    ].map(p => String(p || '').trim()).filter(Boolean);
                    // Avoid duplicating when address already includes city/country
                    const unique = [];
                    parts.forEach(part => {
                        const lower = part.toLowerCase();
                        if (!unique.some(existing => existing.toLowerCase().includes(lower) || lower.includes(existing.toLowerCase()))) {
                            unique.push(part);
                        }
                    });
                    if (unique.length) return unique.join(', ');
                    return String(hotel.location || hotel.address || '').trim();
                };

                const bookingData = {
                    hotel_id: '<?= $hotelId ?>',
                    hotel_name: hotelData.name || '<?= $hotelName ?>',
                    hotel_images: hotelData.images || [],
                    hotel_address: composeHotelAddress(hotelData),
                    hotel_street: hotelData.street_address || '',
                    hotel_postal_code: hotelData.postal_code || '',
                    hotel_city: hotelData.city || '',
                    hotel_country: hotelData.country || '',
                    hotel_country_code: hotelData.country_code || '',
                    hotel_email: hotelData.email || '',
                    hotel_phone_number: hotelData.phone_number || '',
                    hotel_stars: hotelData.stars || 0,
                    accommodation_type: hotelData.accommodation_type,
                    // Hotelbeds Content API extras (ignored by other suppliers; only set when present)
                    hotel_zone_name: hotelData.zone_name || '',
                    hotel_zone_code: hotelData.zone_code || '',
                    hotel_destination_name: hotelData.destination_name || '',
                    hotel_chain_name: hotelData.chain_name || '',
                    hotel_chain_code: hotelData.chain_code || '',
                    hotel_category_name: hotelData.category_name || '',
                    hotel_category_code: hotelData.category_code || '',
                    hotel_segments: hotelData.segments || [],
                    hotel_issues: hotelData.issues || [],
                    hotel_terminals: hotelData.terminals || [],
                    supplier: '<?= $supplier ?>',
                    checkin: '<?= $checkin ?>',
                    checkout: '<?= $checkout ?>',
                    nights: <?= $nights ?>,
                    nationality: '<?= $nationality ?>',
                    rooms_data: <?= json_encode($roomsData) ?>,
                    adults: <?= $totalAdults ?>,
                    children: <?= $totalChildren ?>,
                    selected_rooms: selectedRoomsData,

                    // SEND BASE CURRENCY TOTALS
                    actual_amount: actualTotalBase.toFixed(2), // Net Total
                    total_amount: totalBase.toFixed(2),       // Selling Total
                    currency: baseCurrency,

                    // KEEP DISPLAY DATA FOR REFERENCE (guest session currency, e.g. PKR)
                    display_actual_total: actualTotalDisplay,
                    display_total: totalDisplay,
                    display_currency: '<?= $displayCurrencyCode ?>',

                    rooms_count: this.getTotalSelectedRooms(),
                    created_at: new Date().toISOString(),
                    
                    // CRITICAL: Include book_hash from rooms API response
                    book_hash: this.book_hash || '',
                    
                    // DEV MODE: Auto-include test credentials when dev_mode is enabled
                    ratehawk_dev_mode: this.dev_mode_data.enabled,
                    ratehawk_test_hotel_id: this.dev_mode_data.test_hotel_id,
                    ratehawk_prebook_status: this.dev_mode_data.prebook_status
                };

                // Hotelbeds: CheckRate ALL selected rates before draft save (fail closed).
                // BOOKABLE keys can still drift; refreshing here avoids payment-time PRODUCT_ERROR.
                if (String(bookingData.supplier || '').toLowerCase() === 'hotelbeds') {
                    try {
                        const rateKeys = selectedRoomsData
                            .map(r => String(r?.option?.rate_key || r?.option?.id || r?.rate_key || '').trim())
                            .filter(Boolean);

                        if (rateKeys.length === 0) {
                            vt.error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        const snapshots = {};
                        rateKeys.forEach(key => {
                            const room = selectedRoomsData.find(r =>
                                String(r?.option?.rate_key || r?.option?.id || r?.rate_key || '').trim() === key
                            );
                            if (room?.option) {
                                snapshots[key] = {
                                    net: parseFloat(room.option.supplier_net ?? room.option.availability_net ?? 0),
                                    currency: room.option.supplier_currency || room.option.base_currency || '',
                                    cancellation_policies: room.option.supplier_cancellation_policies || room.option.cancellation_policies || []
                                };
                            }
                        });

                        const checkRatesRes = await fetch('<?= root ?>modules/stays/hotelbeds/checkrates', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                rate_keys: rateKeys,
                                snapshots,
                                currency: '<?= $displayCurrencyCode ?>',
                            }),
                        });

                        let checkRatesJson = null;
                        try {
                            checkRatesJson = await checkRatesRes.json();
                        } catch (parseErr) {
                            vt.error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        const ratesMap = checkRatesJson?.data?.rates || {};
                        const hasChanges = !!checkRatesJson?.data?.has_changes;

                        if (!checkRatesRes.ok || !checkRatesJson?.success) {
                            vt.error(checkRatesJson?.message || '<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        let beyondTolerance = false;
                        rateKeys.forEach(key => {
                            const row = ratesMap[key];
                            if (!row?.price_changed || !(row.old_net > 0) || !(row.new_net > 0)) {
                                return;
                            }
                            const deltaPct = Math.abs(row.new_net - row.old_net) / row.old_net * 100;
                            if (deltaPct > 2) {
                                beyondTolerance = true;
                            }
                        });
                        if (beyondTolerance) {
                            vt.error('<?= T::rate_price_changed_search_again ?? "Rate price changed beyond the allowed 2% tolerance. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        if (hasChanges) {
                            const changedLines = [...new Set(rateKeys
                                .map(key => ({ key, ...(ratesMap[key] || {}) }))
                                .filter(row => row.changed)
                                .map(row => {
                                    const parts = [];
                                    if (row.price_changed_material && row.old_net != null && row.new_net != null) {
                                        parts.push(`Price: ${row.old_net} → ${row.new_net} ${row.currency || ''}`.trim());
                                    }
                                    if (row.policy_changed_material) {
                                        parts.push('<?= T::cancellation_conditions_updated ?>');
                                    }
                                    return parts.join('; ') || '<?= T::rate_conditions_updated ?>';
                                })
                                .filter(Boolean))];

                            const ok = confirm(
                                '<?= T::rate_updated_before_booking ?>\n\n' +
                                changedLines.join('\n') +
                                '\n\n<?= T::continue_with_updated_rate ?>'
                            );

                            if (!ok) {
                                this.bookingLoading = false;
                                return;
                            }
                        }

                        let missingRate = false;
                        Object.values(this.selectedRooms).forEach(sel => {
                            const oldKey = sel.option?.rate_key;
                            const refreshed = oldKey ? ratesMap[oldKey] : null;
                            if (!refreshed?.rate_key) {
                                missingRate = true;
                                return;
                            }
                            this.applyHotelbedsCheckRateToOption(sel.option, refreshed);
                        });

                        if (missingRate) {
                            vt.error('<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }

                        // Stamp every successful revalidation, not only the ones
                        // the guest had to confirm.
                        bookingData.checkrate_accepted_at = new Date().toISOString();
                        this.recalculateBookingTotals(bookingData, dynamicFactor);
                    } catch (checkRatesErr) {
                        console.error('Hotelbeds checkrates failed:', checkRatesErr);
                        vt.error('<?= defined("T::room_not_available") ? T::room_not_available : "Unable to revalidate this rate. Please try again." ?>');
                        this.bookingLoading = false;
                        return;
                    }
                }

                // Hotelston: checkavailability in module before draft save
                // so cancellation policy + booking remarks are ready on booking page.
                if (bookingData.supplier === 'hotelston') {
                    try {
                        const checkAvailabilityRes = await fetch('<?= root ?>modules/stays/hotelston/checkavailability', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                hotel_id: bookingData.hotel_id,
                                checkin: bookingData.checkin,
                                checkout: bookingData.checkout,
                                nationality: bookingData.nationality,
                                rooms_data: bookingData.rooms_data,
                                selected_rooms: bookingData.selected_rooms,
                            }),
                        });
                        const checkAvailabilityJson = await checkAvailabilityRes.json();
                        if (!checkAvailabilityJson?.success) {
                            vt.error(checkAvailabilityJson?.message || '<?= defined("T::room_not_available") ? T::room_not_available : "This room is no longer available. Please search again." ?>');
                            this.bookingLoading = false;
                            return;
                        }
                        if (Array.isArray(checkAvailabilityJson?.data?.selected_rooms)) {
                            bookingData.selected_rooms = checkAvailabilityJson.data.selected_rooms;
                        }
                    } catch (checkAvailabilityErr) {
                        console.warn('Hotelston checkavailability skipped:', checkAvailabilityErr);
                    }
                }

                // Save booking draft
                const response = await fetch('<?= root ?>api/stay/booking/save-draft', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(bookingData)
                });

                const result = await response.json();

                if (result.success && result.hash) {
                    // Redirect to booking page with hash
                    window.location.href = '<?= root ?>stays/booking/' + result.hash;
                } else {
                    vt.error(result.message || '<?= T::booking_failed ?? "Booking failed. Please try again." ?>');
                }
            } catch (error) {
                console.error('Error creating booking:', error);
                vt.error('<?= T::error_creating_booking ?? "Error creating booking. Please try again." ?>');
                this.bookingLoading = false; // Only reset on error
            }
        },
    }
}
</script>

<!-- FSLightbox loader — VERBATIM from stay.php -->
<script>
    /**
     * FSLightbox CDN Fallback Loader
     * Tries multiple CDN sources to ensure library loads
     */
    (function () {
        const cdnUrls = [
            'https://cdn.jsdelivr.net/npm/fslightbox@3.4.1/index.js',
            'https://unpkg.com/fslightbox@3.4.1/index.js',
            'https://fslightbox.com/javascripts/fsLightbox.js'
        ];

        let currentIndex = 0;

        function loadScript() {
            if (currentIndex >= cdnUrls.length) {
                console.error('All FSLightbox CDNs failed to load');
                return;
            }

            const script = document.createElement('script');
            script.src = cdnUrls[currentIndex];
            script.onload = function () {
                console.log('FSLightbox loaded successfully from:', cdnUrls[currentIndex]);
            };
            script.onerror = function () {
                console.warn('Failed to load FSLightbox from:', cdnUrls[currentIndex]);
                currentIndex++;
                loadScript();
            };
            document.head.appendChild(script);
        }

        loadScript();
    })();
</script>