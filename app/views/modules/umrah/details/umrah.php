<?php
// ============================================
// UMRAH DETAILS PAGE - MAIN PHP SECTION
// ============================================

$umrahDetail = $_SESSION['umrah_detail'] ?? null;

// Redirect to umrah page if no session data
if (!$umrahDetail) {
    header('Location: ' . root . 'umrah');
    exit;
}

// Extract umrah details from session
$umrahId        = $umrahDetail['umrah_id'] ?? null;
$slug           = $umrahDetail['slug'] ?? '';
$supplier       = $umrahDetail['supplier'] ?? 'umrah';
$departureDate  = $umrahDetail['departure_date'] ?? '';
$duration       = $umrahDetail['duration'] ?? 'any';
$totalAdults    = $umrahDetail['total_adults'] ?? 1;
$totalChildren  = $umrahDetail['total_children'] ?? 0;

// Format departure date for display
if ($departureDate && $departureDate !== 'any') {
    $departureDateObj  = DateTime::createFromFormat('d-m-Y', $departureDate);
    $departureFormatted = $departureDateObj ? $departureDateObj->format('d M Y') : $departureDate;
} else {
    $departureFormatted = '<?= T::flexible ?>';
}

// Format duration for display
if ($duration && $duration !== 'any') {
    $durationParts = explode('-', $duration);
    $days = $durationParts[0] ?? $duration;
    $durationText = $days . ' ' . ($days == '1' ? T::day : T::days);
} else {
    $durationText = '<?= T::flexible ?>';
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@latest/ol.css">
<script src="https://cdn.jsdelivr.net/npm/ol@latest/dist/ol.js"></script>

<div class="container mx-auto px-3 sm:px-4 py-4 sm:py-6" x-data="umrahDetails()">
    <!-- Breadcrumb Navigation -->
    <div class="flex flex-wrap gap-1 sm:gap-2 items-center text-xs sm:text-sm text-gray-500 mb-4 sm:mb-5">
        <a href="<?= root ?>umrah" class="text-purple-600 hover:text-purple-700 text-[12px] sm:text-[13px] font-medium"><?= T::umrah ?></a>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4" viewBox="0 0 24 24">
            <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
        </svg>
        <span class="text-purple-600 text-[12px] sm:text-[13px] font-medium"><?= T::details ?></span>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4" viewBox="0 0 24 24">
            <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
        </svg>
        <span class="text-gray-700 text-[12px] sm:text-[13px] font-medium truncate" x-text="(umrahData?.location ? umrahData?.location + ' - ' : '') + (umrahData?.name || '')"></span>
    </div>

    <!-- Loading Skeleton -->
    <div x-show="loading" class="card mx-auto">
        <div class="animate-pulse">
            <div class="h-5 sm:h-6 bg-gray-200 rounded w-24 sm:w-32 mb-3"></div>
            <div class="h-6 sm:h-8 bg-gray-200 rounded w-3/4 sm:w-2/3 mb-2"></div>
            <div class="h-3 sm:h-4 bg-gray-200 rounded w-1/2 mb-6"></div>
            <div class="grid grid-cols-1 gap-2 mb-6">
                <div class="bg-gray-200 rounded-lg h-48 sm:h-64 md:h-96 relative"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="col-span-3 space-y-3">
                    <div class="h-5 sm:h-6 bg-gray-200 rounded w-36 sm:w-48"></div>
                    <div class="h-3 sm:h-4 bg-gray-200 rounded w-full"></div>
                    <div class="h-3 sm:h-4 bg-gray-200 rounded w-full"></div>
                    <div class="h-3 sm:h-4 bg-gray-200 rounded w-3/4"></div>
                </div>
                <div class="col-span-1 space-y-3">
                    <div class="h-5 sm:h-6 bg-gray-200 rounded w-24 sm:w-32"></div>
                    <div class="h-3 sm:h-4 bg-gray-200 rounded w-full"></div>
                    <div class="h-3 sm:h-4 bg-gray-200 rounded w-full"></div>
                    <div class="h-3 sm:h-4 bg-gray-200 rounded w-3/4"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         FIX: Use x-if instead of x-show so Alpine NEVER evaluates
         any child expression while umrahData is still null.
         x-show hides visually but still evaluates all bindings;
         x-if skips DOM creation entirely until the condition is true.
    ============================================================ -->
    <template x-if="!loading && umrahData">
        <div>
            <div class="cutom-card">
                <div class="my-2">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span x-show="umrahData?.umrah_type" class="px-2 sm:px-3 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded-full">
                            <span x-text="umrahData?.umrah_type"></span>
                        </span>
                        <div x-show="umrahData?.stars" class="flex items-center gap-0">
                            <template x-for="i in 5" :key="i">
                                <svg class="w-3 h-3 sm:w-4 sm:h-4 -ml-1" :class="i <= umrahData?.stars ? 'text-orange-500' : 'text-gray-300'" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            </template>
                        </div>
                    </div>
                    <h1 class="text-lg sm:text-xl md:text-2xl lg:text-3xl font-semibold mb-1.5" x-text="umrahData?.name"></h1>

                    <!-- Info Icons -->
                    <div class="grid grid-cols-1 xs:grid-cols-2 md:flex md:flex-wrap items-center gap-2 sm:gap-3 md:gap-4 text-xs sm:text-sm text-gray-600 mb-3">

                        <!-- Duration -->
                        <div class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-blue-600" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zM5 6v2h14V6H5zm2 4h10v2H7zm0 4h7v2H7z"/>
                            </svg>
                            <span x-text="umrahData?.nights + ' ' + (umrahData?.nights == 1 ? '<?= T::night ?>' : '<?= T::nights ?>') + ' - ' + umrahData?.days + ' ' + (umrahData?.days == 1 ? '<?= T::day ?>' : '<?= T::days ?>')"></span>
                        </div>

                        <!-- Departure Date -->
                        <div class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-blue-600" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/>
                            </svg>
                            <span class="truncate"><?= T::departure ?>: <?= htmlspecialchars($departureFormatted) ?></span>
                        </div>

                        <!-- Rating -->
                        <div x-show="umrahData?.rating_average > 0" class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-yellow-500" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2L9.19 8.63L2 9.24l5.46 4.73L5.82 21z"/>
                            </svg>
                            <span class="font-medium" x-text="umrahData?.rating_average?.toFixed(1)"></span>
                            <span class="text-gray-500" x-text="`(${umrahData?.rating_count} <?= T::reviews ?>)`"></span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 text-xs sm:text-sm text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-blue-600" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5S5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05c1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                        </svg>
                        <span><?= T::travelers ?>: <?= $totalAdults ?> <?= T::adults ?><?= $totalChildren > 0 ? ', ' . $totalChildren . ' ' . T::children : '' ?></span>
                    </div>
                </div>

                <!-- Main Content with Sidebar Layout -->
                <div class="flex flex-col lg:flex-row gap-4 sm:gap-6">
                    <!-- Left Content -->
                    <div class="w-full lg:w-2/3 order-2 lg:order-1">
                        <!-- Image Slider -->
                        <div class="relative w-full h-64 sm:h-80 md:h-96 rounded-lg overflow-hidden">
                            <img
                                :src="currentImageUrl || umrahData?.images?.[0] || '<?= root ?>uploads/no_img.jpg'"
                                class="w-full h-full object-cover cursor-pointer"
                                onerror="this.src='<?= root ?>uploads/no_img.jpg'"
                                @click="openLightbox(currentImageIndex)"
                                x-ref="mainImage"
                            >
                            <!-- Prev -->
                            <button
                                x-show="(umrahData?.images?.length || 0) > 1"
                                @click="prevImage()"
                                class="absolute left-2 sm:left-4 top-1/2 transform -translate-y-1/2 w-8 h-8 sm:w-10 sm:h-10 bg-white/80 hover:bg-white rounded-full flex items-center justify-center shadow-lg transition-all hover:scale-105"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="sm:w-6 sm:h-6 text-gray-700" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6l6 6l1.41-1.41z"/>
                                </svg>
                            </button>
                            <!-- Next -->
                            <button
                                x-show="(umrahData?.images?.length || 0) > 1"
                                @click="nextImage()"
                                class="absolute right-2 sm:right-4 top-1/2 transform -translate-y-1/2 w-8 h-8 sm:w-10 sm:h-10 bg-white/80 hover:bg-white rounded-full flex items-center justify-center shadow-lg transition-all hover:scale-105"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="sm:w-6 sm:h-6 text-gray-700" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M8.59 16.59L13.17 12L8.59 7.41L10 6l6 6l-6 6l-1.41-1.41z"/>
                                </svg>
                            </button>
                        </div>

                        <!-- Description -->
                        <div class="md:pr-6 lg:pr-14 my-2 sm:my-4">
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-3 sm:mb-4"><?= T::about ?> <?= T::umrah ?></h3>
                            <div class="text-sm sm:text-[14.5px] text-gray-600 space-y-2 leading-relaxed" x-html="umrahData?.description || 'Loading...'"></div>
                        </div>

                        <!-- Flight Details Header -->
                        <div x-show="hasFlights()" class="flex items-center gap-2 mb-3 sm:mb-4 mt-6 sm:mt-8 border-b pb-2">
                            <span class="material-symbols-outlined text-blue-600" style="font-size:22px">flight</span>
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900"><?= T::flight_details ?></h3>
                        </div>

                        <div x-show="hasFlights()" class="space-y-4 mb-4 sm:mb-6">
                            <template x-for="(flight, fIdx) in (umrahData?.flights || [])" :key="fIdx">
                                <div x-data="{
                                    expanded: false,
                                    expandedReturn: false,
                                    activeTab: 'details',
                                    activeReturnTab: 'details',
                                    get firstSeg()      { return flight.segments?.length ? flight.segments[0] : (flight || {}) },
                                    get lastSeg()       { return flight.segments?.length ? flight.segments[flight.segments.length - 1] : (flight || {}) },
                                    get firstRetSeg()   { return flight.returnSegments?.length ? flight.returnSegments[0] : null },
                                    get lastRetSeg()    { return flight.returnSegments?.length ? flight.returnSegments[flight.returnSegments.length - 1] : null },
                                    get outboundStops() { return flight.segments?.length > 1 ? flight.segments.length - 1 : 0 },
                                    get returnStops()   { return flight.returnSegments?.length > 1 ? flight.returnSegments.length - 1 : 0 },
                                    stopLabel(n)        { return n === 0 ? '<?= T::direct ?>' : n + ' <?= T::stop ?>' + (n > 1 ? 's' : '') }
                                }" class="space-y-3">

                                    <!-- ═══════════════════════════════════════════════
                                        OUTBOUND CARD
                                    ═══════════════════════════════════════════════ -->
                                    <div class="bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow-md transition-all">

                                        <div class="p-3 sm:p-4">

                                            <!-- Header -->
                                            <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-100">
                                                <span class="material-symbols-outlined text-blue-600" style="font-size:18px">flight_takeoff</span>
                                                <span class="text-sm font-bold text-gray-700"><?= T::outbound_flight ?></span>
                                                <span x-show="firstRetSeg" class="ml-auto inline-block px-1.5 py-0.5 bg-blue-100 text-blue-700 text-xs rounded"><?= T::round_trip ?></span>
                                            </div>

                                            <!-- MOBILE -->
                                            <div class="block lg:hidden">
                                                <div class="flex items-center gap-2 mb-3">
                                                    <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center border border-gray-200 p-1">
                                                        <img :src="`https://pics.avs.io/200/200/${getAirlineCode(firstSeg)}@2x.png`"
                                                            :alt="firstSeg.airline || 'Airline'"
                                                            class="w-full h-full object-contain"
                                                            @error="$event.target.src='<?= root ?>uploads/no_img.jpg'" />
                                                    </div>
                                                    <div>
                                                        <p class="font-semibold text-xs text-gray-900" x-text="firstSeg.airline || 'Airline'"></p>
                                                        <p class="text-xs text-gray-500" x-text="firstSeg.flight_no || '---'"></p>
                                                    </div>
                                                </div>
                                                <div class="flex items-center justify-between gap-2 mb-3">
                                                    <div class="text-center flex-1">
                                                        <p class="text-xs font-bold text-gray-700" x-text="formatTime12h(firstSeg.departure_time)"></p>
                                                        <p class="text-xs font-semibold text-gray-700 mt-1" x-text="getAirportCode(firstSeg.departure_airport)"></p>
                                                        <p class="text-xs text-gray-500" x-text="firstSeg.departure_date || '---'"></p>
                                                    </div>
                                                    <div class="flex flex-col items-center flex-1">
                                                        <p class="text-xs text-gray-500 mb-1" x-text="firstSeg.duration || ''"></p>
                                                        <div class="w-full relative flex items-center">
                                                            <span class="material-symbols-outlined text-gray-400" style="font-size:14px">flight_takeoff</span>
                                                            <template x-if="outboundStops === 0">
                                                                <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                            </template>
                                                            <template x-if="outboundStops > 0">
                                                                <div class="flex-1 flex items-center relative">
                                                                    <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                    <div class="absolute left-1/2 -translate-x-1/2 w-4 h-4 bg-blue-500 rounded-full flex items-center justify-center">
                                                                        <span class="material-symbols-outlined text-white" style="font-size:10px">flight</span>
                                                                    </div>
                                                                    <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                </div>
                                                            </template>
                                                            <span class="material-symbols-outlined text-gray-400" style="font-size:14px">flight_land</span>
                                                        </div>
                                                        <p class="text-xs font-medium mt-1"
                                                        :class="outboundStops === 0 ? 'text-green-600' : 'text-orange-600'"
                                                        x-text="stopLabel(outboundStops)"></p>
                                                    </div>
                                                    <div class="text-center flex-1">
                                                        <p class="text-xs font-bold text-gray-700" x-text="formatTime12h(lastSeg.arrival_time)"></p>
                                                        <p class="text-xs font-semibold text-gray-700 mt-1" x-text="getAirportCode(lastSeg.arrival_airport)"></p>
                                                        <p class="text-xs text-gray-500" x-text="lastSeg.arrival_date || '---'"></p>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- DESKTOP -->
                                            <div class="hidden lg:block">
                                                <div class="flex items-center justify-between gap-3">
                                                    <div class="flex flex-col items-start gap-1 flex-shrink-0 min-w-[100px]">
                                                        <div class="w-12 h-12 bg-white rounded-lg flex items-center justify-center border border-gray-200 p-1.5 mb-1">
                                                            <img :src="`https://pics.avs.io/200/200/${getAirlineCode(firstSeg)}@2x.png`"
                                                                :alt="firstSeg.airline || 'Airline'"
                                                                class="w-full h-full object-contain"
                                                                @error="$event.target.src='<?= root ?>uploads/no_img.jpg'" />
                                                        </div>
                                                        <p class="font-semibold text-xs text-gray-900 leading-tight" x-text="firstSeg.airline || 'Airline'"></p>
                                                        <p class="text-xs text-gray-500" x-text="firstSeg.flight_no || '---'"></p>
                                                    </div>
                                                    <div class="flex-1 flex items-center justify-center gap-4 min-w-0">
                                                        <div class="text-center w-24">
                                                            <p class="text-xs font-bold text-gray-700" x-text="formatTime12h(firstSeg.departure_time)"></p>
                                                            <p class="text-sm font-semibold text-gray-700" x-text="getAirportCode(firstSeg.departure_airport)"></p>
                                                            <p class="text-xs text-gray-500" x-text="firstSeg.departure_date || '---'"></p>
                                                        </div>
                                                        <div class="flex-1 flex flex-col items-center max-w-[200px]">
                                                            <p class="text-xs text-gray-500 mb-1" x-text="firstSeg.duration || ''"></p>
                                                            <div class="w-full relative flex items-center">
                                                                <span class="material-symbols-outlined text-gray-400 flex-shrink-0" style="font-size:16px">flight_takeoff</span>
                                                                <template x-if="outboundStops === 0">
                                                                    <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                </template>
                                                                <template x-if="outboundStops > 0">
                                                                    <div class="flex-1 flex items-center relative">
                                                                        <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                        <div class="absolute left-1/2 -translate-x-1/2 w-5 h-5 bg-blue-500 rounded-full flex items-center justify-center">
                                                                            <span class="material-symbols-outlined text-white" style="font-size:12px">flight</span>
                                                                        </div>
                                                                        <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                    </div>
                                                                </template>
                                                                <span class="material-symbols-outlined text-gray-400 flex-shrink-0" style="font-size:16px">flight_land</span>
                                                            </div>
                                                            <p class="text-xs font-medium mt-1 flex items-center gap-1"
                                                            :class="outboundStops === 0 ? 'text-green-600' : 'text-orange-600'">
                                                                <template x-if="outboundStops > 0">
                                                                    <span class="material-symbols-outlined" style="font-size:12px">schedule</span>
                                                                </template>
                                                                <span x-text="stopLabel(outboundStops)"></span>
                                                            </p>
                                                        </div>
                                                        <div class="text-center w-24">
                                                            <p class="text-xs font-bold text-gray-700" x-text="formatTime12h(lastSeg.arrival_time)"></p>
                                                            <p class="text-sm font-semibold text-gray-700" x-text="getAirportCode(lastSeg.arrival_airport)"></p>
                                                            <p class="text-xs text-gray-500" x-text="lastSeg.arrival_date || '---'"></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Footer: baggage summary + outbound expand toggle -->
                                            <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-200 text-xs text-gray-600">
                                                <div class="flex items-center gap-3 flex-wrap">
                                                    <template x-if="firstSeg.cabin_baggage">
                                                        <div class="flex items-center gap-1">
                                                            <span class="material-symbols-outlined" style="font-size:15px">work</span>
                                                            <span x-text="firstSeg.cabin_baggage + ' kg'"></span>
                                                        </div>
                                                    </template>
                                                    <template x-if="firstSeg.baggage">
                                                        <div class="flex items-center gap-1">
                                                            <span class="material-symbols-outlined" style="font-size:15px">luggage</span>
                                                            <span x-text="firstSeg.baggage"></span>
                                                        </div>
                                                    </template>
                                                    <template x-if="firstSeg.class">
                                                        <span class="px-2 py-0.5 bg-blue-50 text-blue-700 rounded text-xs font-medium capitalize"
                                                            x-text="firstSeg.class"></span>
                                                    </template>
                                                </div>
                                                <button @click="expanded = !expanded"
                                                        class="flex items-center gap-1 text-blue-600 hover:text-blue-700 text-sm">
                                                    <span x-text="expanded ? '<?= T::hide ?>' : '<?= T::details ?>'"></span>
                                                    <span class="material-symbols-outlined transition-transform"
                                                        :class="expanded && 'rotate-180'" style="font-size:18px">expand_more</span>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Outbound expandable panel -->
                                        <div x-show="expanded"
                                            x-transition:enter="transition ease-out duration-300"
                                            x-transition:enter-start="opacity-0 scale-95"
                                            x-transition:enter-end="opacity-100 scale-100"
                                            x-transition:leave="transition ease-in duration-200"
                                            x-transition:leave-start="opacity-100 scale-100"
                                            x-transition:leave-end="opacity-0 scale-95"
                                            class="border-t border-gray-200 bg-gray-50 p-4">

                                            <div class="flex gap-2 mb-4 border-b border-gray-200">
                                                <button @click="activeTab = 'details'"
                                                        :class="activeTab === 'details' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'"
                                                        class="px-4 py-2 border-b-2 font-semibold text-sm">
                                                    <?= T::flight_details ?>
                                                </button>
                                                <button @click="activeTab = 'baggage'"
                                                        :class="activeTab === 'baggage' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500'"
                                                        class="px-4 py-2 border-b-2 font-semibold text-sm">
                                                    <?= T::baggage ?>
                                                </button>
                                            </div>

                                            <div class="bg-white rounded-lg p-4">

                                                <!-- Outbound details tab -->
                                                <div x-show="activeTab === 'details'" class="space-y-6">

                                                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-100 flex items-center justify-between gap-3">
                                                        <div class="flex items-center gap-3">
                                                            <span class="material-symbols-outlined text-blue-600" style="font-size:28px">flight_takeoff</span>
                                                            <div>
                                                                <p class="text-sm font-semibold text-gray-900">
                                                                    <?= T::outbound ?>:
                                                                    <span x-text="getAirportCode(firstSeg.departure_airport) + ' → ' + getAirportCode(lastSeg.arrival_airport)"></span>
                                                                </p>
                                                                <p class="text-xs text-gray-500"
                                                                x-text="outboundStops === 0 ? '<?= T::direct_flight ?>' : outboundStops + ' <?= T::stop ?>'"></p>
                                                            </div>
                                                        </div>
                                                        <div class="text-center">
                                                            <p class="text-xs text-gray-500"><?= T::duration ?></p>
                                                            <p class="font-bold text-gray-900 text-sm" x-text="firstSeg.duration || '---'"></p>
                                                        </div>
                                                    </div>

                                                    <template x-for="(seg, sIdx) in (flight.segments || [])" :key="'out-' + sIdx">
                                                        <div class="relative">
                                                            <div class="flex items-center gap-2 mb-3">
                                                                <span class="px-3 py-1 bg-blue-600 text-white text-xs font-bold rounded-full"
                                                                    x-text="'<?= T::outbound ?> <?= T::flight ?> ' + (sIdx + 1)"></span>
                                                                <div class="flex-1 h-px bg-gray-200"></div>
                                                            </div>
                                                            <div class="bg-gray-50 rounded-lg border border-gray-200 p-4">
                                                                <div class="flex gap-4">
                                                                    <div class="flex flex-col items-center pt-1">
                                                                        <div class="w-4 h-4 bg-blue-600 rounded-full border-2 border-white shadow"></div>
                                                                        <div class="flex-1 w-0.5 bg-gradient-to-b from-blue-600 to-blue-400 my-2 min-h-[120px]"></div>
                                                                        <div class="w-4 h-4 bg-blue-400 rounded-full border-2 border-white shadow"></div>
                                                                    </div>
                                                                    <div class="flex-1 space-y-4">
                                                                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                                            <div class="flex items-start justify-between gap-3 flex-wrap">
                                                                                <div>
                                                                                    <div class="flex items-center gap-2 mb-1">
                                                                                        <span class="material-symbols-outlined text-blue-600" style="font-size:18px">flight_takeoff</span>
                                                                                        <span class="text-xs font-semibold text-gray-500 uppercase"><?= T::departure ?></span>
                                                                                    </div>
                                                                                    <p class="text-2xl font-bold text-gray-900 mb-1" x-text="formatTime12h(seg.departure_time)"></p>
                                                                                    <p class="text-sm font-semibold text-gray-700" x-text="seg.departure_airport || '---'"></p>
                                                                                    <p class="text-xs text-gray-500">
                                                                                        <span x-text="getAirportCode(seg.departure_airport)"></span>
                                                                                        • <span x-text="seg.departure_date || '---'"></span>
                                                                                    </p>
                                                                                </div>
                                                                                <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs font-medium">
                                                                                    <span class="material-symbols-outlined" style="font-size:14px">event</span>
                                                                                    <span x-text="(seg.departure_date || '---').split(',')[0]"></span>
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                        <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-lg p-3 border border-dashed border-gray-300">
                                                                            <div class="flex items-center gap-3 mb-3">
                                                                                <img :src="`https://pics.avs.io/200/200/${getAirlineCode(seg)}@2x.png`"
                                                                                    class="w-8 h-8 rounded-lg border border-gray-200 bg-white p-1"
                                                                                    @error="$event.target.style.display='none'" />
                                                                                <div>
                                                                                    <p class="text-sm font-bold text-gray-900" x-text="seg.airline || '---'"></p>
                                                                                    <p class="text-xs text-gray-500"><?= T::flight ?> <span x-text="seg.flight_no || '---'"></span></p>
                                                                                </div>
                                                                            </div>
                                                                            <div class="grid grid-cols-2 gap-3">
                                                                                <div class="flex items-center gap-2">
                                                                                    <span class="material-symbols-outlined text-gray-500" style="font-size:16px">schedule</span>
                                                                                    <div>
                                                                                        <p class="text-xs text-gray-500"><?= T::duration ?></p>
                                                                                        <p class="text-sm font-semibold text-gray-900" x-text="seg.duration || '---'"></p>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="flex items-center gap-2">
                                                                                    <span class="material-symbols-outlined text-gray-500" style="font-size:16px">airline_seat_recline_normal</span>
                                                                                    <div>
                                                                                        <p class="text-xs text-gray-500"><?= T::classes ?></p>
                                                                                        <p class="text-sm font-semibold text-gray-900 capitalize" x-text="seg.class || '---'"></p>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="flex items-center gap-2">
                                                                                    <span class="material-symbols-outlined text-gray-500" style="font-size:16px">work</span>
                                                                                    <div>
                                                                                        <p class="text-xs text-gray-500"><?= T::cabin_baggage ?></p>
                                                                                        <p class="text-sm font-semibold text-gray-900" x-text="(seg.cabin_baggage || '---') + ' kg'"></p>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="flex items-center gap-2">
                                                                                    <span class="material-symbols-outlined text-gray-500" style="font-size:16px">luggage</span>
                                                                                    <div>
                                                                                        <p class="text-xs text-gray-500"><?= T::checked_baggage ?></p>
                                                                                        <p class="text-sm font-semibold text-gray-900" x-text="seg.baggage || '---'"></p>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                                            <div class="flex items-start justify-between gap-3 flex-wrap">
                                                                                <div>
                                                                                    <div class="flex items-center gap-2 mb-1">
                                                                                        <span class="material-symbols-outlined text-blue-400" style="font-size:18px">flight_land</span>
                                                                                        <span class="text-xs font-semibold text-gray-500 uppercase"><?= T::arrival ?></span>
                                                                                    </div>
                                                                                    <p class="text-2xl font-bold text-gray-900 mb-1" x-text="formatTime12h(seg.arrival_time)"></p>
                                                                                    <p class="text-sm font-semibold text-gray-700" x-text="seg.arrival_airport || '---'"></p>
                                                                                    <p class="text-xs text-gray-500">
                                                                                        <span x-text="getAirportCode(seg.arrival_airport)"></span>
                                                                                        • <span x-text="seg.arrival_date || '---'"></span>
                                                                                    </p>
                                                                                </div>
                                                                                <span class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs font-medium">
                                                                                    <span class="material-symbols-outlined" style="font-size:14px">event</span>
                                                                                    <span x-text="(seg.arrival_date || '---').split(',')[0]"></span>
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>

                                                <!-- Outbound baggage tab -->
                                                <div x-show="activeTab === 'baggage'" class="space-y-4">
                                                    <div class="flex items-start gap-3">
                                                        <span class="material-symbols-outlined text-blue-600" style="font-size:24px">luggage</span>
                                                        <div>
                                                            <h5 class="font-semibold text-gray-900"><?= T::checked_baggage ?></h5>
                                                            <p class="text-sm text-gray-600" x-text="firstSeg.baggage || '---'"></p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-start gap-3">
                                                        <span class="material-symbols-outlined text-green-600" style="font-size:24px">work</span>
                                                        <div>
                                                            <h5 class="font-semibold text-gray-900"><?= T::cabin_baggage ?></h5>
                                                            <p class="text-sm text-gray-600" x-text="(firstSeg.cabin_baggage || '---') + ' kg'"></p>
                                                        </div>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                        <!-- END outbound expandable -->

                                    </div>
                                    <!-- END outbound card -->


                                    <!-- ═══════════════════════════════════════════════
                                        RETURN CARD (only if round-trip)
                                    ═══════════════════════════════════════════════ -->
                                    <template x-if="firstRetSeg">
                                        <div class="bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow-md transition-all">

                                            <div class="p-3 sm:p-4">

                                                <!-- Header -->
                                                <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-100">
                                                    <span class="material-symbols-outlined text-green-600" style="font-size:18px">flight_land</span>
                                                    <span class="text-sm font-bold text-gray-700"><?= T::return_flight ?></span>
                                                </div>

                                                <!-- MOBILE -->
                                                <div class="block lg:hidden">
                                                    <div class="flex items-center gap-2 mb-3">
                                                        <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center border border-gray-200 p-1">
                                                            <img :src="`https://pics.avs.io/200/200/${getAirlineCode(firstRetSeg)}@2x.png`"
                                                                :alt="firstRetSeg.airline || 'Airline'"
                                                                class="w-full h-full object-contain"
                                                                @error="$event.target.src='<?= root ?>uploads/no_img.jpg'" />
                                                        </div>
                                                        <div>
                                                            <p class="font-semibold text-xs text-gray-900" x-text="firstRetSeg.airline || 'Airline'"></p>
                                                            <p class="text-xs text-gray-500" x-text="firstRetSeg.flight_no || '---'"></p>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center justify-between gap-2 mb-3">
                                                        <div class="text-center flex-1">
                                                            <p class="text-xs font-bold text-gray-700" x-text="formatTime12h(firstRetSeg.departure_time)"></p>
                                                            <p class="text-xs font-semibold text-gray-700 mt-1" x-text="getAirportCode(firstRetSeg.departure_airport)"></p>
                                                            <p class="text-xs text-gray-500" x-text="firstRetSeg.departure_date || '---'"></p>
                                                        </div>
                                                        <div class="flex flex-col items-center flex-1">
                                                            <p class="text-xs text-gray-500 mb-1" x-text="firstRetSeg.duration || ''"></p>
                                                            <div class="w-full relative flex items-center">
                                                                <span class="material-symbols-outlined text-gray-400" style="font-size:14px">flight_takeoff</span>
                                                                <template x-if="returnStops === 0">
                                                                    <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                </template>
                                                                <template x-if="returnStops > 0">
                                                                    <div class="flex-1 flex items-center relative">
                                                                        <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                        <div class="absolute left-1/2 -translate-x-1/2 w-4 h-4 bg-green-500 rounded-full flex items-center justify-center">
                                                                            <span class="material-symbols-outlined text-white" style="font-size:10px">flight</span>
                                                                        </div>
                                                                        <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                    </div>
                                                                </template>
                                                                <span class="material-symbols-outlined text-gray-400" style="font-size:14px">flight_land</span>
                                                            </div>
                                                            <p class="text-xs font-medium mt-1"
                                                            :class="returnStops === 0 ? 'text-green-600' : 'text-orange-600'"
                                                            x-text="stopLabel(returnStops)"></p>
                                                        </div>
                                                        <div class="text-center flex-1">
                                                            <p class="text-xs font-bold text-gray-700" x-text="formatTime12h(lastRetSeg.arrival_time)"></p>
                                                            <p class="text-xs font-semibold text-gray-700 mt-1" x-text="getAirportCode(lastRetSeg.arrival_airport)"></p>
                                                            <p class="text-xs text-gray-500" x-text="lastRetSeg.arrival_date || '---'"></p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- DESKTOP -->
                                                <div class="hidden lg:block">
                                                    <div class="flex items-center justify-between gap-3">
                                                        <div class="flex flex-col items-start gap-1 flex-shrink-0 min-w-[100px]">
                                                            <div class="w-12 h-12 bg-white rounded-lg flex items-center justify-center border border-gray-200 p-1.5 mb-1">
                                                                <img :src="`https://pics.avs.io/200/200/${getAirlineCode(firstRetSeg)}@2x.png`"
                                                                    :alt="firstRetSeg.airline || 'Airline'"
                                                                    class="w-full h-full object-contain"
                                                                    @error="$event.target.src='<?= root ?>uploads/no_img.jpg'" />
                                                            </div>
                                                            <p class="font-semibold text-xs text-gray-900 leading-tight" x-text="firstRetSeg.airline || 'Airline'"></p>
                                                            <p class="text-xs text-gray-500" x-text="firstRetSeg.flight_no || '---'"></p>
                                                        </div>
                                                        <div class="flex-1 flex items-center justify-center gap-4 min-w-0">
                                                            <div class="text-center w-24">
                                                                <p class="text-xs font-bold text-gray-700" x-text="formatTime12h(firstRetSeg.departure_time)"></p>
                                                                <p class="text-sm font-semibold text-gray-700" x-text="getAirportCode(firstRetSeg.departure_airport)"></p>
                                                                <p class="text-xs text-gray-500" x-text="firstRetSeg.departure_date || '---'"></p>
                                                            </div>
                                                            <div class="flex-1 flex flex-col items-center max-w-[200px]">
                                                                <p class="text-xs text-gray-500 mb-1" x-text="firstRetSeg.duration || ''"></p>
                                                                <div class="w-full relative flex items-center">
                                                                    <span class="material-symbols-outlined text-gray-400 flex-shrink-0" style="font-size:16px">flight_takeoff</span>
                                                                    <template x-if="returnStops === 0">
                                                                        <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                    </template>
                                                                    <template x-if="returnStops > 0">
                                                                        <div class="flex-1 flex items-center relative">
                                                                            <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                            <div class="absolute left-1/2 -translate-x-1/2 w-5 h-5 bg-green-500 rounded-full flex items-center justify-center">
                                                                                <span class="material-symbols-outlined text-white" style="font-size:12px">flight</span>
                                                                            </div>
                                                                            <div class="flex-1 h-[1px] bg-gray-300 mx-1"></div>
                                                                        </div>
                                                                    </template>
                                                                    <span class="material-symbols-outlined text-gray-400 flex-shrink-0" style="font-size:16px">flight_land</span>
                                                                </div>
                                                                <p class="text-xs font-medium mt-1 flex items-center gap-1"
                                                                :class="returnStops === 0 ? 'text-green-600' : 'text-orange-600'">
                                                                    <template x-if="returnStops > 0">
                                                                        <span class="material-symbols-outlined" style="font-size:12px">schedule</span>
                                                                    </template>
                                                                    <span x-text="stopLabel(returnStops)"></span>
                                                                </p>
                                                            </div>
                                                            <div class="text-center w-24">
                                                                <p class="text-xs font-bold text-gray-700" x-text="formatTime12h(lastRetSeg.arrival_time)"></p>
                                                                <p class="text-sm font-semibold text-gray-700" x-text="getAirportCode(lastRetSeg.arrival_airport)"></p>
                                                                <p class="text-xs text-gray-500" x-text="lastRetSeg.arrival_date || '---'"></p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Footer: baggage summary + return expand toggle -->
                                                <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-200 text-xs text-gray-600">
                                                    <div class="flex items-center gap-3 flex-wrap">
                                                        <template x-if="firstRetSeg.cabin_baggage">
                                                            <div class="flex items-center gap-1">
                                                                <span class="material-symbols-outlined" style="font-size:15px">work</span>
                                                                <span x-text="firstRetSeg.cabin_baggage + ' kg'"></span>
                                                            </div>
                                                        </template>
                                                        <template x-if="firstRetSeg.baggage">
                                                            <div class="flex items-center gap-1">
                                                                <span class="material-symbols-outlined" style="font-size:15px">luggage</span>
                                                                <span x-text="firstRetSeg.baggage"></span>
                                                            </div>
                                                        </template>
                                                        <template x-if="firstRetSeg.class">
                                                            <span class="px-2 py-0.5 bg-green-50 text-green-700 rounded text-xs font-medium capitalize"
                                                                x-text="firstRetSeg.class"></span>
                                                        </template>
                                                    </div>
                                                    <button @click="expandedReturn = !expandedReturn"
                                                            class="flex items-center gap-1 text-green-600 hover:text-green-700 text-sm">
                                                        <span x-text="expandedReturn ? '<?= T::hide ?>' : '<?= T::details ?>'"></span>
                                                        <span class="material-symbols-outlined transition-transform"
                                                            :class="expandedReturn && 'rotate-180'" style="font-size:18px">expand_more</span>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Return expandable panel -->
                                            <div x-show="expandedReturn"
                                                x-transition:enter="transition ease-out duration-300"
                                                x-transition:enter-start="opacity-0 scale-95"
                                                x-transition:enter-end="opacity-100 scale-100"
                                                x-transition:leave="transition ease-in duration-200"
                                                x-transition:leave-start="opacity-100 scale-100"
                                                x-transition:leave-end="opacity-0 scale-95"
                                                class="border-t border-gray-200 bg-gray-50 p-4">

                                                <div class="flex gap-2 mb-4 border-b border-gray-200">
                                                    <button @click="activeReturnTab = 'details'"
                                                            :class="activeReturnTab === 'details' ? 'border-green-600 text-green-600' : 'border-transparent text-gray-500'"
                                                            class="px-4 py-2 border-b-2 font-semibold text-sm">
                                                        <?= T::flight_details ?>
                                                    </button>
                                                    <button @click="activeReturnTab = 'baggage'"
                                                            :class="activeReturnTab === 'baggage' ? 'border-green-600 text-green-600' : 'border-transparent text-gray-500'"
                                                            class="px-4 py-2 border-b-2 font-semibold text-sm">
                                                        <?= T::baggage ?>
                                                    </button>
                                                </div>

                                                <div class="bg-white rounded-lg p-4">

                                                    <!-- Return details tab -->
                                                    <div x-show="activeReturnTab === 'details'" class="space-y-6">

                                                        <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg p-4 border border-green-100 flex items-center justify-between gap-3">
                                                            <div class="flex items-center gap-3">
                                                                <span class="material-symbols-outlined text-green-600" style="font-size:28px">flight_land</span>
                                                                <div>
                                                                    <p class="text-sm font-semibold text-gray-900">
                                                                        <?= T::return ?>:
                                                                        <span x-text="getAirportCode(firstRetSeg.departure_airport) + ' → ' + getAirportCode(lastRetSeg.arrival_airport)"></span>
                                                                    </p>
                                                                    <p class="text-xs text-gray-500"
                                                                    x-text="returnStops === 0 ? '<?= T::direct_flight ?>' : returnStops + ' <?= T::stop ?>'"></p>
                                                                </div>
                                                            </div>
                                                            <div class="text-center">
                                                                <p class="text-xs text-gray-500"><?= T::duration ?></p>
                                                                <p class="font-bold text-gray-900 text-sm" x-text="firstRetSeg.duration || '---'"></p>
                                                            </div>
                                                        </div>

                                                        <template x-for="(seg, sIdx) in (flight.returnSegments || [])" :key="'ret-' + sIdx">
                                                            <div class="relative">
                                                                <div class="flex items-center gap-2 mb-3">
                                                                    <span class="px-3 py-1 bg-green-600 text-white text-xs font-bold rounded-full"
                                                                        x-text="'<?= T::return ?> <?= T::flight ?> ' + (sIdx + 1)"></span>
                                                                    <div class="flex-1 h-px bg-gray-200"></div>
                                                                </div>
                                                                <div class="bg-gray-50 rounded-lg border border-gray-200 p-4">
                                                                    <div class="flex gap-4">
                                                                        <div class="flex flex-col items-center pt-1">
                                                                            <div class="w-4 h-4 bg-green-600 rounded-full border-2 border-white shadow"></div>
                                                                            <div class="flex-1 w-0.5 bg-gradient-to-b from-green-600 to-green-400 my-2 min-h-[120px]"></div>
                                                                            <div class="w-4 h-4 bg-green-400 rounded-full border-2 border-white shadow"></div>
                                                                        </div>
                                                                        <div class="flex-1 space-y-4">
                                                                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                                                <div class="flex items-start justify-between gap-3 flex-wrap">
                                                                                    <div>
                                                                                        <div class="flex items-center gap-2 mb-1">
                                                                                            <span class="material-symbols-outlined text-green-600" style="font-size:18px">flight_takeoff</span>
                                                                                            <span class="text-xs font-semibold text-gray-500 uppercase"><?= T::departure ?></span>
                                                                                        </div>
                                                                                        <p class="text-2xl font-bold text-gray-900 mb-1" x-text="formatTime12h(seg.departure_time)"></p>
                                                                                        <p class="text-sm font-semibold text-gray-700" x-text="seg.departure_airport || '---'"></p>
                                                                                        <p class="text-xs text-gray-500">
                                                                                            <span x-text="getAirportCode(seg.departure_airport)"></span>
                                                                                            • <span x-text="seg.departure_date || '---'"></span>
                                                                                        </p>
                                                                                    </div>
                                                                                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-50 text-green-700 rounded text-xs font-medium">
                                                                                        <span class="material-symbols-outlined" style="font-size:14px">event</span>
                                                                                        <span x-text="(seg.departure_date || '---').split(',')[0]"></span>
                                                                                    </span>
                                                                                </div>
                                                                            </div>
                                                                            <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-lg p-3 border border-dashed border-gray-300">
                                                                                <div class="flex items-center gap-3 mb-3">
                                                                                    <img :src="`https://pics.avs.io/200/200/${getAirlineCode(seg)}@2x.png`"
                                                                                        class="w-8 h-8 rounded-lg border border-gray-200 bg-white p-1"
                                                                                        @error="$event.target.style.display='none'" />
                                                                                    <div>
                                                                                        <p class="text-sm font-bold text-gray-900" x-text="seg.airline || '---'"></p>
                                                                                        <p class="text-xs text-gray-500"><?= T::flight ?> <span x-text="seg.flight_no || '---'"></span></p>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="grid grid-cols-2 gap-3">
                                                                                    <div class="flex items-center gap-2">
                                                                                        <span class="material-symbols-outlined text-gray-500" style="font-size:16px">schedule</span>
                                                                                        <div>
                                                                                            <p class="text-xs text-gray-500"><?= T::duration ?></p>
                                                                                            <p class="text-sm font-semibold text-gray-900" x-text="seg.duration || '---'"></p>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="flex items-center gap-2">
                                                                                        <span class="material-symbols-outlined text-gray-500" style="font-size:16px">airline_seat_recline_normal</span>
                                                                                        <div>
                                                                                            <p class="text-xs text-gray-500"><?= T::classes ?></p>
                                                                                            <p class="text-sm font-semibold text-gray-900 capitalize" x-text="seg.class || '---'"></p>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="flex items-center gap-2">
                                                                                        <span class="material-symbols-outlined text-gray-500" style="font-size:16px">work</span>
                                                                                        <div>
                                                                                            <p class="text-xs text-gray-500"><?= T::cabin_baggage ?></p>
                                                                                            <p class="text-sm font-semibold text-gray-900" x-text="(seg.cabin_baggage || '---') + ' kg'"></p>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="flex items-center gap-2">
                                                                                        <span class="material-symbols-outlined text-gray-500" style="font-size:16px">luggage</span>
                                                                                        <div>
                                                                                            <p class="text-xs text-gray-500"><?= T::checked_baggage ?></p>
                                                                                            <p class="text-sm font-semibold text-gray-900" x-text="seg.baggage || '---'"></p>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                                                                <div class="flex items-start justify-between gap-3 flex-wrap">
                                                                                    <div>
                                                                                        <div class="flex items-center gap-2 mb-1">
                                                                                            <span class="material-symbols-outlined text-green-400" style="font-size:18px">flight_land</span>
                                                                                            <span class="text-xs font-semibold text-gray-500 uppercase"><?= T::arrival ?></span>
                                                                                        </div>
                                                                                        <p class="text-2xl font-bold text-gray-900 mb-1" x-text="formatTime12h(seg.arrival_time)"></p>
                                                                                        <p class="text-sm font-semibold text-gray-700" x-text="seg.arrival_airport || '---'"></p>
                                                                                        <p class="text-xs text-gray-500">
                                                                                            <span x-text="getAirportCode(seg.arrival_airport)"></span>
                                                                                            • <span x-text="seg.arrival_date || '---'"></span>
                                                                                        </p>
                                                                                    </div>
                                                                                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-50 text-green-700 rounded text-xs font-medium">
                                                                                        <span class="material-symbols-outlined" style="font-size:14px">event</span>
                                                                                        <span x-text="(seg.arrival_date || '---').split(',')[0]"></span>
                                                                                    </span>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </template>
                                                    </div>

                                                    <!-- Return baggage tab -->
                                                    <div x-show="activeReturnTab === 'baggage'" class="space-y-4">
                                                        <div class="flex items-start gap-3">
                                                            <span class="material-symbols-outlined text-blue-600" style="font-size:24px">luggage</span>
                                                            <div>
                                                                <h5 class="font-semibold text-gray-900"><?= T::checked_baggage ?></h5>
                                                                <p class="text-sm text-gray-600" x-text="firstRetSeg.baggage || '---'"></p>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-start gap-3">
                                                            <span class="material-symbols-outlined text-green-600" style="font-size:24px">work</span>
                                                            <div>
                                                                <h5 class="font-semibold text-gray-900"><?= T::cabin_baggage ?></h5>
                                                                <p class="text-sm text-gray-600" x-text="(firstRetSeg.cabin_baggage || '---') + ' kg'"></p>
                                                            </div>
                                                        </div>
                                                    </div>

                                                </div>
                                            </div>
                                            <!-- END return expandable -->

                                        </div>
                                        <!-- END return card -->
                                    </template>

                                </div>
                                <!-- END per-flight wrapper -->
                            </template>
                        </div>

                        <!-- Stays Details Header -->
                        <div x-show="hasStays()" class="flex items-center gap-2 mb-3 sm:mb-4 mt-6 sm:mt-8 border-b pb-2">
                            <span class="material-symbols-outlined text-blue-600" style="font-size:22px">hotel</span>
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900"><?= T::stays_information ?></h3>
                        </div>

                        <!-- Stays Section -->
                        <!-- FIX: use (umrahData?.stays || []) -->
                            <div x-show="hasStays()" class="space-y-4 mb-4">
                                <template x-for="(stay, sIdx) in (umrahData?.stays || [])" :key="sIdx">
                                    <div x-data="{
                                        currentImg: 0,
                                        getStayImages() {
                                            let imgs = [];
                                            if (stay.images && stay.images.length > 0) {
                                                imgs.push(...stay.images.map(img => img.url));
                                            }
                                            if (stay.rooms && stay.rooms.length > 0) {
                                                stay.rooms.forEach(room => {
                                                    if (room.images && room.images.length > 0) {
                                                        imgs.push(...room.images.map(img => img.url));
                                                    }
                                                });
                                            }
                                            if (imgs.length === 0) {
                                                if (umrahData.images && umrahData.images.length > 0) {
                                                    imgs = [...umrahData.images];
                                                } else {
                                                    imgs = ['<?= root ?>uploads/no_img.jpg'];
                                                }
                                            }
                                            return imgs;
                                        },
                                        get stayImages() { return this.getStayImages(); },
                                        nextImg() { this.currentImg = (this.currentImg + 1) % this.stayImages.length; },
                                        prevImg() { this.currentImg = this.currentImg === 0 ? this.stayImages.length - 1 : this.currentImg - 1; }
                                    }"
                                    class="card overflow-hidden mb-3 p-0">

                                        <div class="flex flex-col md:flex-row">

                                            <!-- IMAGE CAROUSEL SECTION -->
                                            <div class="md:w-1/3 relative group">

                                                <!-- Images -->
                                                <template x-for="(img, iIdx) in stayImages" :key="iIdx">
                                                    <img x-show="currentImg === iIdx"
                                                        :src="img"
                                                        alt="Stay Image"
                                                        class="w-full h-48 md:h-64 object-cover"
                                                        x-transition:enter="transition ease-out duration-200"
                                                        x-transition:enter-start="opacity-0"
                                                        x-transition:enter-end="opacity-100"
                                                        onerror="this.onerror=null;this.src='<?= root ?>uploads/no_img.jpg'" />
                                                </template>

                                                <!-- Prev Button -->
                                                <button x-show="stayImages.length > 1"
                                                        @click="prevImg()"
                                                        type="button"
                                                        style="transform: translateY(-50%);"
                                                        class="absolute left-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                                                    <span class="material-symbols-outlined" style="font-size: 20px; font-variation-settings: 'wght' 300;">chevron_left</span>
                                                </button>

                                                <!-- Next Button -->
                                                <button x-show="stayImages.length > 1"
                                                        @click="nextImg()"
                                                        type="button"
                                                        style="transform: translateY(-50%);"
                                                        class="absolute right-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                                                    <span class="material-symbols-outlined" style="font-size: 20px; font-variation-settings: 'wght' 300;">chevron_right</span>
                                                </button>

                                                <!-- Image Counter -->
                                                <div x-show="stayImages.length > 1"
                                                    class="absolute bottom-2 right-2 bg-black/70 text-white px-2 py-0.5 rounded-full text-xs font-semibold">
                                                    <span x-text="currentImg + 1"></span>/<span x-text="stayImages.length"></span>
                                                </div>

                                                <!-- Star Badge -->
                                                <div x-show="stay.stars"
                                                    class="absolute top-2 left-2 bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5 text-orange-500" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                                    </svg>
                                                    <span x-text="parseFloat(stay.stars || 5).toFixed(1)"></span>
                                                </div>

                                                <!-- Location Badge (top-right, mirrors supplier badge) -->
                                                <div x-show="stay.location"
                                                    class="absolute top-2 right-2 bg-blue-600 text-white px-2 py-0.5 rounded text-xs font-bold uppercase truncate max-w-[120px]"
                                                    x-text="stay.location">
                                                </div>
                                            </div>

                                            <!-- HOTEL INFORMATION SECTION -->
                                            <div class="md:w-2/3 p-4 flex flex-col">

                                                <div class="flex-1">
                                                    <!-- Name & Location -->
                                                    <h4 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1"
                                                        x-text="stay.hotel_name || 'Hotel Name'"></h4>

                                                    <div class="flex items-start gap-1.5 mb-2">
                                                        <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size: 16px;">location_on</span>
                                                        <span class="text-xs text-gray-600 dark:text-gray-400 line-clamp-1"
                                                            x-text="stay.location || 'Location not specified'"></span>
                                                    </div>

                                                    <!-- Star Row -->
                                                    <div x-show="stay.stars" class="flex items-center gap-1 mb-2">
                                                        <template x-for="i in 5" :key="i">
                                                            <svg class="w-4 h-4 -ml-1"
                                                                :class="i <= parseInt(stay.stars || 0) ? 'text-orange-500' : 'text-gray-300'"
                                                                fill="currentColor" viewBox="0 0 20 20">
                                                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                                            </svg>
                                                        </template>
                                                        <span class="text-xs text-gray-500 dark:text-gray-400"
                                                            x-text="'(' + parseFloat(stay.stars || 0).toFixed(1) + ')'"></span>
                                                    </div>

                                                    <!-- Room Type Badges -->
                                                    <div x-show="stay.rooms && stay.rooms.length > 0" class="flex flex-wrap gap-1 mt-2">
                                                        <template x-for="(room, rIdx) in stay.rooms" :key="rIdx">
                                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                                                <span x-text="room.type || room.name || room.room_type || 'Room'"></span>
                                                                <span x-show="room.occupancy || room.room_occupancy"
                                                                    class="ml-1 opacity-70"
                                                                    x-text="'(' + (room.occupancy || room.room_occupancy) + ')'"></span>
                                                            </span>
                                                        </template>
                                                    </div>

                                                    <!-- Notes -->
                                                    <div x-show="stay.notes && stay.notes.trim() !== ''"
                                                        class="mt-2 flex flex-wrap gap-1">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 italic">
                                                            <span class="material-symbols-outlined" style="font-size: 13px;">info</span>
                                                            <span x-text="stay.notes"></span>
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- CHECK-IN / CHECK-OUT FOOTER -->
                                                <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-end gap-3">
                                                    <div>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?= T::check_in ?></p>
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="material-symbols-outlined text-blue-600" style="font-size: 16px;">calendar_today</span>
                                                            <span class="text-sm font-bold text-gray-900 dark:text-gray-100"
                                                                x-text="stay.check_in || '---'"></span>
                                                        </div>
                                                    </div>

                                                    <span class="material-symbols-outlined text-gray-300" style="font-size: 20px;">arrow_forward</span>

                                                    <div class="text-right">
                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?= T::check_out ?></p>
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="material-symbols-outlined text-blue-600" style="font-size: 16px;">calendar_today</span>
                                                            <span class="text-sm font-bold text-gray-900 dark:text-gray-100"
                                                                x-text="stay.check_out || '---'"></span>
                                                        </div>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                        <!-- Transfer Details Header -->
                        <div x-show="hasTransfers()" class="flex items-center gap-2 mb-3 sm:mb-4 mt-6 sm:mt-8 border-b pb-2">
                            <span class="material-symbols-outlined text-blue-600" style="font-size:22px">transfer_within_a_station</span>
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900"><?= T::transfer_details ?? 'Transfer Details' ?></h3>
                        </div>

                        <!-- Transfer Section -->
                            <div x-show="hasTransfers()" class="space-y-4 mb-4">
                                <template x-for="(travel, tIdx) in (umrahData?.transfers || [])" :key="tIdx">
                                    <div class="card overflow-hidden mb-3 p-0">
                                        <div class="flex flex-col md:flex-row">

                                        <!-- Image Section -->
                                        <div class="md:w-1/3 relative group" x-data="{
                                            tImg: 0,
                                            tImages: (travel.images && travel.images.length > 0) ? travel.images.map(i => i.url) : (umrahData.images && umrahData.images.length > 0 ? [umrahData.images[0]] : ['<?= root ?>uploads/no_img.jpg'])
                                        }">
                                                <template x-for="(img, iIdx) in tImages" :key="iIdx">
                                                    <img x-show="tImg === iIdx"
                                                         :src="img"
                                                         alt="Vehicle Image"
                                                         class="w-full h-48 md:h-64 object-cover"
                                                         x-transition:enter="transition ease-out duration-200"
                                                         x-transition:enter-start="opacity-0"
                                                         x-transition:enter-end="opacity-100"
                                                         onerror="this.onerror=null;this.src='<?= root ?>uploads/no_img.jpg'" />
                                                </template>

                                                <!-- Prev Button -->
                                                <button x-show="tImages.length > 1"
                                                        @click="tImg = (tImg === 0 ? tImages.length - 1 : tImg - 1)"
                                                        type="button"
                                                        style="transform: translateY(-50%);"
                                                        class="absolute left-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                                                    <span class="material-symbols-outlined" style="font-size: 20px; font-variation-settings: 'wght' 300;">chevron_left</span>
                                                </button>

                                                <!-- Next Button -->
                                                <button x-show="tImages.length > 1"
                                                        @click="tImg = (tImg + 1) % tImages.length"
                                                        type="button"
                                                        style="transform: translateY(-50%);"
                                                        class="absolute right-2 top-1/2 bg-black/50 hover:bg-black/90 text-white rounded-full w-8 h-8 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10">
                                                    <span class="material-symbols-outlined" style="font-size: 20px; font-variation-settings: 'wght' 300;">chevron_right</span>
                                                </button>

                                                <!-- Image Counter -->
                                                <div x-show="tImages.length > 1"
                                                    class="absolute bottom-2 right-2 bg-black/70 text-white px-2 py-0.5 rounded-full text-xs font-semibold">
                                                    <span x-text="tImg + 1"></span>/<span x-text="tImages.length"></span>
                                                </div>

                                                <!-- Type Badge -->
                                                <div x-show="!String(travel.type || '').toLowerCase().includes('vip')"
                                                    class="absolute top-2 left-2 bg-black/80 backdrop-blur-sm text-white px-2 py-0.5 rounded text-xs font-bold uppercase"
                                                    x-text="travel.type || 'Standard'"></div>
                                        </div>

                                        <!-- Content Section -->
                                        <div class="md:w-2/3 p-4 flex flex-col">
                                            <div class="flex-1">
                                                <h4 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1" x-text="travel.type || '<?= T::transfer ?>'"></h4>
                                                <div class="flex items-start gap-1.5 mb-2">
                                                    <span class="material-symbols-outlined text-gray-500 dark:text-gray-400" style="font-size: 16px;">local_taxi</span>
                                                    <span class="text-xs text-gray-600 dark:text-gray-400"><?= T::vehicle_service ?></span>
                                                </div>

                                                <!-- Pickup / Dropoff -->
                                                <div class="flex flex-wrap gap-1 mt-2">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                                        <span class="material-symbols-outlined" style="font-size: 13px;">location_on</span>
                                                        <span class="ml-0.5" x-text="travel.from || travel.pickup || '---'"></span>
                                                    </span>
                                                    <span class="inline-flex items-center px-1 py-0.5 text-xs text-gray-400">→</span>
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                                                        <span class="material-symbols-outlined" style="font-size: 13px;">near_me</span>
                                                        <span class="ml-0.5" x-text="travel.to || travel.dropoff || '---'"></span>
                                                    </span>
                                                </div>

                                                <!-- Notes -->
                                                <div x-show="travel.notes && travel.notes.trim() !== ''"
                                                    class="mt-2 flex flex-wrap gap-1">
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 italic">
                                                        <span class="material-symbols-outlined" style="font-size: 13px;">info</span>
                                                        <span x-text="travel.notes"></span>
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Schedule Footer -->
                                            <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-end gap-3">
                                                <div>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?= T::date ?? 'Date' ?></p>
                                                    <div class="flex items-center gap-1.5">
                                                        <span class="material-symbols-outlined text-blue-600" style="font-size: 16px;">calendar_today</span>
                                                        <span class="text-sm font-bold text-gray-900 dark:text-gray-100"
                                                            x-text="travel.date ? travel.date.split('T')[0] : '---'"></span>
                                                    </div>
                                                </div>

                                                <div class="text-right">
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-0.5"><?= T::time ?? 'Time' ?></p>
                                                    <div class="flex items-center gap-1.5">
                                                        <span class="material-symbols-outlined text-blue-600" style="font-size: 16px;">schedule</span>
                                                        <span class="text-sm font-bold text-gray-900 dark:text-gray-100"
                                                            x-text="travel.date && travel.date.includes('T') ? travel.date.split('T')[1] : '---'"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        </div>
                                    </div>
                                </template>
                            </div>

                        <!-- Services (Inclusions) -->
                        <div x-show="umrahData?.inclusions?.length > 0" class="card mx-auto mb-4 sm:mb-6">
                            <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-3 sm:mb-4 flex items-center gap-2">
                                <?= T::services ?>
                            </h3>
                            <div class="space-y-2 sm:space-y-3">
                                <template x-if="!umrahData?.inclusions?.length">
                                    <div class="text-gray-500 italic text-sm sm:text-base"><?= T::no_services_found ?></div>
                                </template>
                                <template x-for="inclusion in (umrahData?.inclusions || [])" :key="inclusion.name">
                                    <div class="flex items-center gap-2 sm:gap-3">
                                        <div class="flex-shrink-0">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" class="sm:w-5 sm:h-5 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                            </svg>
                                        </div>
                                        <span class="text-gray-700 text-sm sm:text-base" x-text="inclusion.name"></span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Itinerary Accordion -->
                        <div x-show="hasItinerary()" class="card mx-auto mb-4 sm:mb-6">
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-3 sm:mb-4"><?= T::itinerary ?></h3>
                            <div class="space-y-2 sm:space-y-3">
                                <template x-for="(day, index) in (umrahData?.itinerary || [])" :key="index">
                                    <div class="bg-white border border-gray-200 rounded-lg">
                                        <button
                                            @click="toggleSingleAccordion(index)"
                                            class="w-full px-4 sm:px-6 py-3 sm:py-4 flex items-center justify-between hover:bg-gray-50"
                                        >
                                            <div class="flex items-center gap-2 sm:gap-4">
                                                <div class="bg-blue-100 text-blue-700 w-8 h-8 sm:w-10 sm:h-10 rounded-lg flex items-center justify-center font-bold text-sm sm:text-base">
                                                    <span x-text="day.day || index + 1"></span>
                                                </div>
                                                <div class="text-left">
                                                    <h4 class="font-semibold text-gray-900 text-sm sm:text-base" x-text="day.title"></h4>
                                                    <div x-show="day.location && day.location.trim() !== ''" class="flex items-center gap-2 text-xs sm:text-sm text-blue-600 mt-1">
                                                        <svg class="w-3 h-3 sm:w-4 sm:h-4" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                                        </svg>
                                                        <span class="truncate" x-text="day.location"></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-gray-500 transition-transform duration-200"
                                                :class="{ 'rotate-180': isAccordionOpen(index) }"
                                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>
                                        <div
                                            x-show="isAccordionOpen(index)"
                                            x-collapse
                                            class="px-4 sm:px-6 pb-4 sm:pb-6 border-t border-gray-200"
                                        >
                                            <div class="mt-3 sm:mt-4 ml-0 sm:ml-10 md:ml-14">
                                                <p x-show="day.description" class="text-gray-600 text-sm sm:text-base mb-3 sm:mb-4" x-text="day.description"></p>

                                                <template x-if="day.activities && day.activities.length">
                                                    <div class="space-y-4">
                                                        <h5 class="text-sm sm:text-base font-semibold text-gray-900"><?= T::activities ?? 'Activities' ?></h5>
                                                        <template x-for="(act, aIdx) in day.activities" :key="aIdx">
                                                            <div class="border border-gray-200 rounded-lg p-3 sm:p-4 bg-gray-50/40">
                                                                <h6 class="font-semibold text-gray-900 text-sm sm:text-base mb-1" x-text="act.title"></h6>
                                                                <p x-show="act.description" class="text-gray-600 text-xs sm:text-sm mb-3" x-text="act.description"></p>
                                                                <template x-if="act.images && act.images.length">
                                                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                                                                        <template x-for="(img, iIdx) in act.images" :key="iIdx">
                                                                            <button type="button" @click="openActivityLightbox(act.images, iIdx)" class="block aspect-square overflow-hidden rounded-md border border-gray-200 hover:opacity-90 cursor-pointer">
                                                                                <img :src="img.url" :alt="act.title" loading="lazy" class="w-full h-full object-cover">
                                                                            </button>
                                                                        </template>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div x-show="umrahData?.cancellation_policy" class="card mx-auto mb-4 sm:mb-6">
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-2 sm:mb-3">
                                <?= T::cancellation_policy ?>
                            </h3>
                            <div class="text-sm sm:text-[14.5px] text-gray-600 leading-relaxed prose prose-sm max-w-none" x-html="umrahData?.cancellation_policy"></div>
                        </div>

                        <div x-show="umrahData?.terms_conditions" class="card mx-auto mb-4 sm:mb-6">
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-2 sm:mb-3">
                                <?= T::terms_conditions ?? 'Terms and Conditions' ?>
                            </h3>
                            <div class="text-sm sm:text-[14.5px] text-gray-600 leading-relaxed prose prose-sm max-w-none" x-html="umrahData?.terms_conditions"></div>
                        </div>
                    </div>

                    <!-- Right Sticky Sidebar (Booking Form) -->
                    <div class="w-full lg:w-1/3 order-1 lg:order-2">
                        <div class="sticky text-gray-800 bg-white p-4 sm:p-6 rounded-xl shadow-[0_4px_6px_5px_#0000001a]"
                            style="max-height: calc(100vh - 100px); overflow-y: auto; top: 125px;"
                            x-data="{
                                selectedAdults: <?= (int)$totalAdults ?>,
                                selectedChildren: <?= (int)$totalChildren ?>,
                                loading: false,

                                createNumberArray(max) {
                                    return Array.from({length: max}, (_, i) => i + 1);
                                },

                                async updateURL() {
                                    if (this.loading) return;
                                    this.loading = true;

                                    const adultSelect = this.$refs.adultSelect;
                                    const childSelect = this.$refs.childSelect;
                                    if (adultSelect) adultSelect.disabled = true;
                                    if (childSelect) childSelect.disabled = true;

                                    try {
                                        const parts = window.location.pathname.split('/').filter(p => p);
                                        const detailIndex = parts.indexOf('detail');

                                        if (detailIndex === -1 || parts.length < detailIndex + 6) {
                                            this.loading = false;
                                            return;
                                        }

                                        const slug     = parts[detailIndex + 1];
                                        const umrahId  = parts[detailIndex + 2];
                                        const supplier = parts[detailIndex + 3];
                                        const date     = parts[detailIndex + 4];
                                        const duration = parts[detailIndex + 5];

                                        const newTravelersStr = this.selectedChildren > 0
                                            ? `${this.selectedAdults}-${this.selectedChildren}`
                                            : `${this.selectedAdults}`;

                                        window.location.href = `<?= root ?>umrah/detail/${slug}/${umrahId}/${supplier}/${date}/${duration}/${newTravelersStr}`;
                                    } catch (error) {
                                        console.error('Error updating URL:', error);
                                        this.loading = false;
                                        if (adultSelect) adultSelect.disabled = false;
                                        if (childSelect) childSelect.disabled = false;
                                    }
                                },

                                updateDateInURL(newDate) {
                                    if (this.loading || !newDate) return;
                                    this.loading = true;
                                    try {
                                        const parts = window.location.pathname.split('/').filter(p => p);
                                        const detailIndex = parts.indexOf('detail');
                                        if (detailIndex === -1 || parts.length < detailIndex + 6) { this.loading = false; return; }

                                        const slug     = parts[detailIndex + 1];
                                        const umrahId  = parts[detailIndex + 2];
                                        const supplier = parts[detailIndex + 3];
                                        const duration = parts[detailIndex + 5];
                                        const travelers= parts[detailIndex + 6];

                                        const d   = String(newDate.getDate()).padStart(2, '0');
                                        const m   = String(newDate.getMonth() + 1).padStart(2, '0');
                                        const y   = newDate.getFullYear();
                                        const fmt = `${d}-${m}-${y}`;

                                        window.location.href = `<?= root ?>umrah/detail/${slug}/${umrahId}/${supplier}/${fmt}/${duration}/${travelers}`;
                                    } catch(e) { console.error(e); this.loading = false; }
                                }
                            }">

                            <!-- Price Heading -->
                            <h3 x-show="(umrahData?.display_price || 0) > 0" class="text-lg sm:text-xl font-semibold mb-3 sm:mb-4 relative">
                                <span x-show="!loading">
                                    <?= T::from ?>
                                    <span x-text="umrahData?.currency"></span>
                                    <span x-text="Number(umrahData?.display_price || 0).toFixed(2)"></span>
                                </span>
                                <span x-show="loading" class="text-gray-300"><?= T::loading ?>...</span>
                            </h3>

                            <!-- Date Picker -->
                            <div class="mb-3 sm:mb-4">
                                <label class="block text-sm font-medium mb-1"><?= T::start_date ?></label>
                                <input type="text"
                                    name="start_date"
                                    :disabled="loading"
                                    placeholder="<?= T::start_date ?>"
                                    class="dp input w-full text-gray-700 bg-white"
                                    value="<?= htmlspecialchars($departureFormatted === 'Flexible' ? '' : $departureDate) ?>"
                                    readonly />
                            </div>

                            <!-- Adults -->
                            <div class="flex justify-between items-center mb-3 sm:mb-4">
                                <div class="flex-1">
                                    <div class="font-medium text-sm sm:text-base"><?= T::adults ?></div>
                                    <div class="text-xs text-gray-400"><?= T::age ?> 18+</div>
                                </div>
                                <div x-show="umrahData?.display_price_per_adult > 0" class="text-right mx-2 sm:mx-4">
                                    <div class="text-xs sm:text-sm"><?= T::price ?></div>
                                    <div class="text-xs sm:text-sm"
                                        x-html="
                                            loading
                                                ? 'Loading...'
                                                : `${umrahData.currency ?? 'USD'} ${Number(umrahData.display_price_per_adult).toFixed(2)}`
                                        ">
                                    </div>
                                </div>
                                <div class="form-control w-20 relative">
                                    <select x-model="selectedAdults"
                                            @change="updateURL()"
                                            x-ref="adultSelect"
                                            :disabled="loading"
                                            class="select select-bordered text-gray-700 bg-white text-sm sm:text-base w-full"
                                            x-init="$el.value = '<?= (int)$totalAdults ?>';">
                                        <template x-if="umrahData && !loading">
                                            <template x-for="i in createNumberArray(umrahData.max_adults || 1)" :key="i">
                                                <option :value="i" x-text="i" :selected="i === <?= (int)$totalAdults ?>"></option>
                                            </template>
                                        </template>
                                        <template x-if="!umrahData">
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <option value="<?= $i ?>" <?= $i == $totalAdults ? 'selected' : '' ?>><?= $i ?></option>
                                            <?php endfor; ?>
                                        </template>
                                    </select>
                                </div>
                            </div>

                            <!-- Children -->
                            <div class="flex justify-between items-center mb-4 sm:mb-6">
                                <div class="flex-1">
                                    <div class="font-medium text-sm sm:text-base"><?= T::children ?></div>
                                    <div class="text-xs text-gray-400"><?= T::age ?> 2-17</div>
                                </div>
                                <div x-show="umrahData?.display_price_per_child > 0" class="text-right mx-2 sm:mx-4">
                                    <div class="text-xs sm:text-sm"><?= T::price ?></div>
                                    <div class="text-xs sm:text-sm"
                                        x-html="
                                            loading
                                                ? 'Loading...'
                                                : `${umrahData.currency ?? 'USD'} ${Number(umrahData.display_price_per_child).toFixed(2)}`
                                        ">
                                    </div>
                                </div>
                                <div class="form-control w-20 relative">
                                    <select x-model="selectedChildren"
                                            @change="updateURL()"
                                            x-ref="childSelect"
                                            :disabled="loading"
                                            class="select select-bordered text-gray-700 bg-white text-sm sm:text-base w-full"
                                            x-init="$el.value = '<?= (int)$totalChildren ?>';">
                                        <option value="0">0</option>
                                        <template x-if="umrahData && umrahData.max_children > 0 && !loading">
                                            <template x-for="i in createNumberArray(umrahData.max_children)" :key="i">
                                                <option :value="i" x-text="i" :selected="i === <?= (int)$totalChildren ?>"></option>
                                            </template>
                                        </template>
                                        <template x-if="!umrahData">
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <option value="<?= $i ?>" <?= $i == $totalChildren ? 'selected' : '' ?>><?= $i ?></option>
                                            <?php endfor; ?>
                                        </template>
                                    </select>
                                </div>
                            </div>

                            <!-- Book Now Button -->
                            <a @click="bookNow()"
                            :class="loading ? 'bg-gray-600 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700'"
                            class="block w-full text-white font-medium py-2 sm:py-3 rounded-lg transition text-center text-sm sm:text-base relative cursor-pointer">
                                <span x-show="!loading" class="flex items-center justify-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 sm:w-5 sm:h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    <span><?= T::book_now ?></span>
                                </span>
                                <span x-show="loading" class="flex items-center justify-center gap-2">
                                    <div class="inline-block animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                                    <?= T::processing ?>...
                                </span>
                            </a>

                            <!-- Reserve Now Pay Later -->
                            <div class="mt-4 sm:mt-6 pt-3 sm:pt-4 border-t border-gray-700">
                                <div class="flex items-start gap-2 sm:gap-3 mb-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 sm:w-6 sm:h-6 text-green-400 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M9 11l3 3L22 4"/>
                                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                                    </svg>
                                    <div>
                                        <p class="font-semibold text-sm sm:text-base text-white"><?= T::reserve_now_pay_later ?></p>
                                        <p class="text-xs sm:text-sm text-gray-300 mt-1"><?= T::secure_your_spot_flexible ?></p>
                                    </div>
                                </div>
                                <div x-show="umrahData?.cancellation_policy" class="flex items-center gap-2 text-xs sm:text-sm text-gray-400 mt-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                    </svg>
                                    <span><?= T::free_cancellation ?></span>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
    <!-- END x-if wrapper -->

    <!-- Error State -->
    <div x-show="error" x-cloak class="card mx-auto text-center py-8 sm:py-12">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" class="sm:w-16 sm:h-16 mx-auto mb-3 sm:mb-4 text-red-500" viewBox="0 0 24 24">
            <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-1.5 sm:mb-2"><?= T::error_loading_details ?? 'Error loading Umrah details' ?></h3>
        <p class="text-gray-600 text-sm sm:text-base mb-3 sm:mb-4" x-text="errorMessage"></p>
        <a href="<?= root ?>umrah" class="inline-flex items-center gap-1 sm:gap-2 px-4 sm:px-6 py-1.5 sm:py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm sm:text-base">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" class="sm:w-5 sm:h-5" viewBox="0 0 24 24">
                <path fill="currentColor" d="M20 11H7.83l5.59-5.59L12 4l-8 8l8 8l1.41-1.41L7.83 13H20v-2z"/>
            </svg>
            <?= T::back_to_umrah ?? 'Back to Umrah' ?>
        </a>
    </div>
</div>

<!-- JS Logic -->
<script>
function umrahDetails() {
    return {
        loading: true,
        error: false,
        errorMessage: '',
        umrahData: null,
        currentImageIndex: 0,
        currentImageUrl: '',
        map: null,
        openAccordions: new Set(),
        allAccordionsOpen: false,

        init() {
            this.fetchData();
            this.initDatePicker();
        },

        formatTime12h(timeStr) {
            if (!timeStr) return '--:--';
            const [h, m] = timeStr.split(':').map(Number);
            if (isNaN(h) || isNaN(m)) return timeStr;
            const period = h >= 12 ? 'PM' : 'AM';
            const hour   = h % 12 || 12;
            return `${hour}:${String(m).padStart(2, '0')} ${period}`;
        },

        hasItinerary() {
            if (!this.umrahData?.itinerary?.length) return false;
            return this.umrahData.itinerary.some(day =>
                (day.title && String(day.title).trim().length > 0) ||
                (day.description && String(day.description).trim().length > 0)
            );
        },

        hasFlights() {
            if (!this.umrahData?.flights?.length) return false;
            return this.umrahData.flights.some(f =>
                (f.segments || [f]).some(s =>
                    (s.airline && String(s.airline).trim().length > 0) ||
                    (s.flight_no && String(s.flight_no).trim().length > 0) ||
                    (s.departure_airport && String(s.departure_airport).trim().length > 0)
                )
            );
        },

        getAirlineCode(seg) {
            if (!seg) return 'FL';
            if (seg.code && String(seg.code).trim().length > 0) {
                return seg.code.toUpperCase();
            }
            if (seg.airlineSearch) {
                const match = seg.airlineSearch.match(/\((.*?)\)/);
                if (match) return match[1].toUpperCase();
            }
            if (seg.airline && seg.airline.length >= 2 && seg.airline.length <= 3) {
                return seg.airline.toUpperCase();
            }
            if (seg.airline) {
                const match = seg.airline.match(/\((.*?)\)/);
                if (match) return match[1].toUpperCase();
            }
            return 'FL';
        },

        getAirportCode(airportStr) {
            if (!airportStr) return '---';
            airportStr = String(airportStr).trim();
            if (airportStr.includes(' - ')) {
                return airportStr.split(' - ')[0].toUpperCase();
            }
            const match = airportStr.match(/\((.*?)\)/);
            if (match) return match[1].toUpperCase();
            if (airportStr.length === 3) return airportStr.toUpperCase();
            return airportStr.substring(0, 3).toUpperCase();
        },

        hasStays() {
            if (!this.umrahData?.stays?.length) return false;
            return this.umrahData.stays.some(s =>
                (s.hotel_name && String(s.hotel_name).trim().length > 0) ||
                (s.location && String(s.location).trim().length > 0)
            );
        },

        hasTransfers() {
            return !!(this.umrahData?.transfers?.length > 0);
        },

        initDatePicker() {
            this.$nextTick(() => {
                const dateInput = document.querySelector('input[name="start_date"]');
                if (dateInput) {
                    $(dateInput).on('changeDate', (e) => {
                        if (this.loading) return;
                        const newDate = e.date;
                        if (!newDate) return;
                        const sidebar = Alpine.$data(dateInput.closest('[x-data]'));
                        if (sidebar && sidebar.updateDateInURL) {
                            sidebar.updateDateInURL(newDate);
                        }
                    });
                }
            });
        },

        async fetchData() {
            try {
                const supplier  = '<?= $supplier ?>';
                const endpoint  = '<?= root ?>modules/umrah/' + supplier + '/details';

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        umrah_id:      '<?= $umrahId ?>',
                        supplier:      supplier,
                        departure_date:'<?= $departureDate ?>',
                        duration:      '<?= $duration ?>',
                        total_adults:  <?= $totalAdults ?>,
                        total_children:<?= $totalChildren ?>
                    })
                });

                const data = await response.json();

                if (data.success) {
                    this.umrahData = data.data;

                    if (this.umrahData?.images?.length > 0) {
                        this.currentImageUrl = this.umrahData.images[0];
                        this.currentImageIndex = 0;
                    }

                    this.$nextTick(() => {
                        if (this.umrahData?.latitude && this.umrahData?.longitude) {
                            this.initMap();
                        }
                    });
                } else {
                    this.error = true;
                    this.errorMessage = data.message || '<?= T::failed_to_load_details ?? 'Failed to load Umrah details.' ?>';
                }
            } catch (err) {
                console.error('Error fetching Umrah details:', err);
                this.error = true;
                this.errorMessage = '<?= T::network_error ?? 'Network error. Please try again.' ?>';
            } finally {
                this.loading = false;
            }
        },

        prevImage() {
            const imgs = this.umrahData?.images || [];
            if (imgs.length === 0) return;
            this.currentImageIndex = (this.currentImageIndex - 1 + imgs.length) % imgs.length;
            this.currentImageUrl = imgs[this.currentImageIndex];
        },

        nextImage() {
            const imgs = this.umrahData?.images || [];
            if (imgs.length === 0) return;
            this.currentImageIndex = (this.currentImageIndex + 1) % imgs.length;
            this.currentImageUrl = imgs[this.currentImageIndex];
        },

        openLightbox(index) {
            const imgs = (this.umrahData?.images || [])
                .filter(Boolean)
                .map(img => (typeof img === 'string' ? img : (img?.url || '')).trim())
                .filter(u => u && !u.includes('no_img.jpg'));
            this._openFSLightbox(imgs, index, 'umrahGallery');
        },

        openActivityLightbox(images, index) {
            const imgs = (images || [])
                .map(img => (typeof img === 'string' ? img : (img?.url || '')).trim())
                .filter(u => u && !u.includes('no_img.jpg'));
            this._openFSLightbox(imgs, index, 'umrahActivityGallery');
        },

        _openFSLightbox(sources, index, key) {
            if (!sources || sources.length === 0) return;
            const tryOpen = () => {
                if (typeof FsLightbox === 'undefined') {
                    window.open(sources[index] || sources[0], '_blank');
                    return;
                }
                if (!window.fsLightboxInstances) window.fsLightboxInstances = {};
                if (window.fsLightboxInstances[key]) delete window.fsLightboxInstances[key];
                const lightbox = new FsLightbox();
                lightbox.props.sources = sources;
                lightbox.props.types = sources.map(() => 'image');
                lightbox.props.slide = Math.min(Math.max(index, 0), sources.length - 1) + 1;
                window.fsLightboxInstances[key] = lightbox;
                lightbox.open();
            };
            if (typeof FsLightbox === 'undefined') {
                setTimeout(tryOpen, 150);
            } else {
                tryOpen();
            }
        },

        initMap() {
            try {
                const lat = parseFloat(this.umrahData.latitude);
                const lon = parseFloat(this.umrahData.longitude);
                if (isNaN(lat) || isNaN(lon)) return;

                this.map = new ol.Map({
                    target: 'umrah-map',
                    layers: [new ol.layer.Tile({ source: new ol.source.OSM() })],
                    view: new ol.View({
                        center: ol.proj.fromLonLat([lon, lat]),
                        zoom: 13
                    })
                });

                const marker = new ol.Feature({ geometry: new ol.geom.Point(ol.proj.fromLonLat([lon, lat])) });
                marker.setStyle(new ol.style.Style({
                    image: new ol.style.Circle({ radius: 7, fill: new ol.style.Fill({ color: '#3b82f6' }), stroke: new ol.style.Stroke({ color: '#fff', width: 2 }) })
                }));

                const vectorSource = new ol.source.Vector({ features: [marker] });
                const vectorLayer  = new ol.layer.Vector({ source: vectorSource });
                this.map.addLayer(vectorLayer);
            } catch (e) {
                console.warn('Map error:', e);
            }
        },

        toggleAllAccordions() {
            this.allAccordionsOpen = !this.allAccordionsOpen;
            if (this.allAccordionsOpen) {
                this.umrahData?.itinerary?.forEach((_, i) => this.openAccordions.add(i));
            } else {
                this.openAccordions.clear();
            }
        },

        toggleSingleAccordion(index) {
            if (this.openAccordions.has(index)) {
                this.openAccordions.delete(index);
            } else {
                this.openAccordions.add(index);
            }
            this.openAccordions = new Set(this.openAccordions);
        },

        isAccordionOpen(index) {
            return this.openAccordions.has(index);
        },

        async bookNow() {
            if (!this.umrahData || this.loading) return;
            this.loading = true;

            try {
                const response = await fetch('<?= root ?>api/umrah/booking/save-draft', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        umrah_id: '<?= $umrahId ?>',
                        umrah_name: this.umrahData.name,
                        umrah_image: this.umrahData.images?.[0] || '',
                        umrah_location: this.umrahData.location || '',
                        cancellation_policy: this.umrahData.cancellation_policy || '',
                        start_date: '<?= $departureDate ?>',
                        duration: (this.umrahData?.nights || 0) + ' ' + (this.umrahData?.nights == 1 ? '<?= T::night ?>' : '<?= T::nights ?>') + ' - ' + (this.umrahData?.days || 0) + ' ' + (this.umrahData?.days == 1 ? '<?= T::day ?>' : '<?= T::days ?>'),
                        total_adults: <?= $totalAdults ?>,
                        total_children: <?= $totalChildren ?>,
                        total_infants: 0,
                        supplier: '<?= $supplier ?>',
                        currency: this.umrahData.currency || 'USD',
                        adult_price: this.umrahData.display_price_per_adult || this.umrahData.adult_price || 0,
                        child_price: this.umrahData.display_price_per_child || this.umrahData.child_price || 0,
                        infant_price: 0,
                        markup_total_price_persons: (parseFloat(this.umrahData.display_price_per_adult || this.umrahData.adult_price) || 0) * <?= $totalAdults ?>,
                        markup_total_price_childrens: (parseFloat(this.umrahData.display_price_per_child || this.umrahData.child_price) || 0) * <?= $totalChildren ?>,
                        markup_total_price_infants: 0,
                        actual_total_umrah_price: ((parseFloat(this.umrahData.display_price_per_adult || this.umrahData.adult_price) || 0) * <?= $totalAdults ?>) + ((parseFloat(this.umrahData.display_price_per_child || this.umrahData.child_price) || 0) * <?= $totalChildren ?>),
                        markup_total_umrah_price: ((parseFloat(this.umrahData.display_price_per_adult || this.umrahData.adult_price) || 0) * <?= $totalAdults ?>) + ((parseFloat(this.umrahData.display_price_per_child || this.umrahData.child_price) || 0) * <?= $totalChildren ?>),
                        slug: '<?= $slug ?>',
                        flights: this.hasFlights() ? (this.umrahData.flights || []) : [],
                        stays: this.hasStays() ? (this.umrahData.stays || []) : [],
                        transfers: this.hasTransfers() ? (this.umrahData.transfers || []) : []
                    })
                });

                const data = await response.json();
                if (data.success && data.hash) {
                    window.location.href = '<?= root ?>umrah/booking/' + data.hash;
                } else {
                    alert(data.message || 'Failed to initiate booking.');
                    this.loading = false;
                }
            } catch (err) {
                console.error('Booking error:', err);
                alert('Network error. Please try again.');
                this.loading = false;
            }
        }
    };
}
</script>

<!-- FSLightbox CDN loader (same as stays/tours details) -->
<script>
(function() {
    if (typeof FsLightbox !== 'undefined') return;
    const cdnUrls = [
        'https://cdn.jsdelivr.net/npm/fslightbox@3.4.1/index.js',
        'https://unpkg.com/fslightbox@3.4.1/index.js',
        'https://fslightbox.com/javascripts/fsLightbox.js'
    ];
    let i = 0;
    function load() {
        if (i >= cdnUrls.length) { console.error('All FSLightbox CDNs failed'); return; }
        const s = document.createElement('script');
        s.src = cdnUrls[i];
        s.onload = () => console.log('FSLightbox loaded:', cdnUrls[i]);
        s.onerror = () => { i++; load(); };
        document.head.appendChild(s);
    }
    load();
})();
</script>