<?php
// ============================================
// TOUR DETAILS PAGE - MAIN PHP SECTION
// This section handles session data and prepares variables for the tour page.
// ============================================

$tourDetail = $_SESSION['tour_detail'] ?? null;


// Redirect to tours page if no tour detail is found in session
if (!$tourDetail) {
    header('Location: ' . root . 'tours');
    exit;
}

// Extract tour details from session
$tourId = $tourDetail['tour_id'] ?? null;
$tourName = $tourDetail['tour_name'] ?? '';
$supplier = $tourDetail['supplier'] ?? 'direct';
$departureDate = $tourDetail['departure_date'] ?? '';
$duration = $tourDetail['duration'] ?? '';
$totalAdults = $tourDetail['total_adults'] ?? 1;
$totalChildren = $tourDetail['total_children'] ?? 0;
$urlParts = $tourDetail['url_parts'] ?? [];

// Format departure date for display
if ($departureDate && $departureDate !== 'any') {
    $departureDateObj = DateTime::createFromFormat('d-m-Y', $departureDate);
    $departureFormatted = $departureDateObj ? $departureDateObj->format('d M Y') : $departureDate;
} else {
    $departureFormatted = 'Flexible';
}

// Format duration for display
if ($duration && $duration !== 'any') {
    $durationText = $duration . ' ' . ($duration == '1' ? T::day : T::days);
} else {
    $durationText = 'Flexible';
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@latest/ol.css">
<script src="https://cdn.jsdelivr.net/npm/ol@latest/dist/ol.js"></script>

<div class="container mx-auto px-3 sm:px-4 py-4 sm:py-6" x-data="tourDetails()">
    <!-- Breadcrumb Navigation - Responsive -->
    <div class="flex flex-wrap gap-1 sm:gap-2 items-center text-xs sm:text-sm text-gray-500 mb-4 sm:mb-5">
        <a href="<?= root ?>tours" class="text-purple-600 hover:text-purple-700 text-[12px] sm:text-[13px] font-medium"><?= T::tours ?></a>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4" viewBox="0 0 24 24">
            <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
        </svg>
        <span class="text-purple-600 text-[12px] sm:text-[13px] font-medium"><?= T::details ?></span>
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4" viewBox="0 0 24 24">
            <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
        </svg>
        <span class="text-gray-700 text-[12px] sm:text-[13px] font-medium truncate" x-text="tourData?.tour_name || '<?= htmlspecialchars($tourName) ?>'"></span>
    </div>

    <!-- Loading Skeleton - Responsive -->
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

        <!-- Main Tour Content (Visible after loading) -->
        <div x-show="!loading && tourData" x-cloak>
            <div class="cutom-card">
                <div class="my-2">
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="px-2 sm:px-3 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded-full">
                            <span x-text="tourData?.tour_type"></span>
                        </span>
                        <div x-show="tourData?.stars" class="flex items-center gap-0">
                            <template x-for="i in 5" :key="i">
                                <svg class="w-3 h-3 sm:w-4 sm:h-4 -ml-1" :class="i <= tourData?.stars ? 'text-orange-500' : 'text-gray-300'" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            </template>
                        </div>
                    </div>
                    <h1 class="text-lg sm:text-xl md:text-2xl lg:text-3xl font-semibold mb-1.5" x-text="tourData?.name"></h1>

                    <!-- Tour Info Icons - Responsive Grid -->
                    <div class="grid grid-cols-1 xs:grid-cols-2 md:flex md:flex-wrap items-center gap-2 sm:gap-3 md:gap-4 text-xs sm:text-sm text-gray-600 mb-3">
                        <!-- Location -->
                        <div x-show="tourData?.location && tourData?.location !== 'Unknown' && tourData?.location.trim() !== ''" class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-gray-500" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7zm0 9.5c-1.4 0-2.5-1.1-2.5-2.5s1.1-2.5 2.5-2.5s2.5 1.1 2.5 2.5s-1.1 2.5-2.5 2.5z"/>
                            </svg>
                            <span class="truncate" x-text="tourData?.location"></span>
                        </div>

                        <!-- Duration -->
                        <div class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-gray-500" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zM5 6v2h14V6H5zm2 4h10v2H7zm0 4h7v2H7z"/>
                            </svg>
                            <span x-text="(tourData?.nights ?? 0) + ' ' + ((tourData?.nights ?? 0) == 1 ? '<?= T::night ?>' : '<?= T::nights ?>') + ' - ' + (tourData?.days ?? 0) + ' ' + ((tourData?.days ?? 0) == 1 ? '<?= T::day ?>' : '<?= T::days ?>')"></span>
                        </div>

                        <!-- Departure Date -->
                        <div class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-gray-500" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm2-7h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/>
                            </svg>
                            <span class="truncate">Departure: <?= htmlspecialchars($departureFormatted) ?></span>
                        </div>

                        <!-- Rating -->
                        <div x-show="tourData?.rating_average > 0" class="flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-yellow-500" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2L9.19 8.63L2 9.24l5.46 4.73L5.82 21z"/>
                            </svg>
                            <span class="font-medium" x-text="tourData?.rating_average?.toFixed(1)"></span>
                            <span class="text-gray-500" x-text="`(${tourData?.rating_count} <?= T::reviews ?>)`"></span>
                        </div>
                    </div>

                    <!-- Travelers Count -->
                    <div class="flex items-center gap-2 text-xs sm:text-sm text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" class="sm:w-4 sm:h-4 text-gray-500" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5S5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05c1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                        </svg>
                        <span>Travelers: <?= $totalAdults ?> <?= T::adults ?><?= $totalChildren > 0 ? ', ' . $totalChildren . ' ' . T::children : '' ?></span>
                    </div>
                </div>

                <!-- Main Content with Sidebar Layout -->
                <div class="flex flex-col lg:flex-row gap-4 sm:gap-6">
                    <!-- Left Content (Main Content) -->
                    <div class="w-full lg:w-2/3 order-2 lg:order-1">
                        <!-- Tour Main Image with Lightbox and Navigation -->
                        <div class="relative w-full h-64 sm:h-80 md:h-96 rounded-lg overflow-hidden">
                            <img
                                :src="currentImageUrl || tourData?.images?.[0]?.url || tourData?.images?.[0] || '<?= root ?>uploads/no_img.jpg'"
                                class="w-full h-full object-cover cursor-pointer"
                                onerror="this.src='<?= root ?>uploads/no_img.jpg'"
                                @click="openFSLightbox(currentImageIndex)"
                                x-ref="mainImage"
                            >
                            <!-- Previous Image Button -->
                            <button
                                x-show="(tourData?.images?.length || 0) > 1"
                                @click="prevImage()"
                                class="absolute left-2 sm:left-4 top-1/2 transform -translate-y-1/2 w-8 h-8 sm:w-10 sm:h-10 bg-white/80 hover:bg-white rounded-full flex items-center justify-center shadow-lg transition-all hover:scale-105"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="sm:w-6 sm:h-6 text-gray-700" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6l6 6l1.41-1.41z"/>
                                </svg>
                            </button>
                            <!-- Next Image Button -->
                            <button
                                x-show="(tourData?.images?.length || 0) > 1"
                                @click="nextImage()"
                                class="absolute right-2 sm:right-4 top-1/2 transform -translate-y-1/2 w-8 h-8 sm:w-10 sm:h-10 bg-white/80 hover:bg-white rounded-full flex items-center justify-center shadow-lg transition-all hover:scale-105"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="sm:w-6 sm:h-6 text-gray-700" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M8.59 16.59L13.17 12L8.59 7.41L10 6l6 6l-6 6l-1.41-1.41z"/>
                                </svg>
                            </button>
                        </div>
                        <div class="md:pr-6 lg:pr-14 my-2 sm:my-4">
                            <h3 class="text-base sm:text-lg md:text-[18px] font-semibold text-black/90 mb-3 sm:mb-4"><?= T::about ?> <?= T::this_tour ?></h3>
                            <div class="text-sm sm:text-[14.5px] text-gray-600 space-y-2" x-html="tourData?.description || '<?= T::loading_description ?>'"></div>
                        </div>

                        <!-- Location Map Section - Shows when coordinates available -->
                        <div x-show="tourData?.latitude && tourData?.longitude && tourData?.supplier !== 'tours'" class="card mx-auto mb-4 sm:mb-6">
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-3 sm:mb-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="inline-block w-5 h-5 sm:w-6 sm:h-6 mr-2 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7zm0 9.5c-1.4 0-2.5-1.1-2.5-2.5s1.1-2.5 2.5-2.5s2.5 1.1 2.5 2.5s-1.1 2.5-2.5 2.5z"/>
                                </svg>
                                <?= T::location ?>
                            </h3>
                            <div id="map" class="w-full h-64 sm:h-80 rounded-lg border border-gray-300"></div>
                            <div x-show="tourData?.address" class="mt-3 flex items-start gap-2 text-sm text-gray-600">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-500 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7zm0 9.5c-1.4 0-2.5-1.1-2.5-2.5s1.1-2.5 2.5-2.5s2.5 1.1 2.5 2.5s-1.1 2.5-2.5 2.5z"/>
                                </svg>
                                <span x-text="tourData?.address"></span>
                            </div>
                        </div>

                        <!-- Tour Itinerary Section with Map - Responsive -->
                        <div x-show="tourData?.itinerary?.length > 1" class="card mx-auto mb-4 sm:mb-6">
                            <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-3 sm:mb-4"><?= T::tour_itinerary ?></h3>

                            <div class="flex flex-col lg:flex-row gap-4 sm:gap-6">
                                <!-- Left: Itinerary Content (Accordion) -->
                                <div class="w-full">
                                    <!-- Map Container - Only show if coordinates exist -->
                                    <div x-show="tourData?.latitude && tourData?.longitude" id="itinerary-map" class="w-full h-48 sm:h-64 rounded-lg border border-gray-300 mb-4"></div>

                                    <!-- Button Map ke neeche -->
                                    <div class="flex justify-end mb-4">
                                        <button
                                            @click="toggleAllAccordions()"
                                            type="button"
                                            class="inline-flex items-center gap-2 px-3 sm:px-4 py-1.5 sm:py-2 bg-blue-50 hover:bg-blue-100 text-blue-600 hover:text-blue-700 rounded-lg text-xs sm:text-sm font-medium transition-colors"
                                            :class="{ 'bg-blue-100 text-blue-700': allAccordionsOpen }"
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                width="14"
                                                height="14"
                                                class="sm:w-4 sm:h-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                            >
                                                <path x-show="!allAccordionsOpen" d="m6 9 6 6 6-6"/>
                                                <path x-show="allAccordionsOpen" d="m18 15-6-6-6 6"/>
                                            </svg>
                                            <span x-text="allAccordionsOpen ? '<?= T::close_all ?>' : '<?= T::open_all ?>'"></span>
                                        </button>
                                    </div>

                                    <!-- Accordion Content -->
                                    <div class="space-y-2 sm:space-y-3">
                                        <template x-for="(day, index) in tourData?.itinerary" :key="index">
                                            <!-- Accordion Item -->
                                            <div class="bg-white border border-gray-200 rounded-lg">
                                                <!-- Accordion Header -->
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
                                                            <div x-show="day.location && day.location.trim() !== ''" class="flex items-center gap-2 text-xs sm:text-sm text-gray-500 mt-1">
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

                                                <!-- Accordion Content -->
                                                <div
                                                    x-show="isAccordionOpen(index)"
                                                    x-collapse
                                                    class="px-4 sm:px-6 pb-4 sm:pb-6 border-t border-gray-200"
                                                >
                                                    <div class="mt-3 sm:mt-4 ml-0 sm:ml-10 md:ml-14">
                                                        <p class="text-gray-600 text-sm sm:text-base mb-3 sm:mb-4" x-text="day.description"></p>

                                                        <!-- Activities -->
                                                        <template x-if="day.activities && day.activities.length > 0">
                                                            <div class="mt-4 sm:mt-6">
                                                                <h5 class="font-medium text-gray-700 text-sm sm:text-base mb-2 sm:mb-3">Activities:</h5>
                                                                <div class="space-y-2 sm:space-y-3">
                                                                    <template x-for="activity in day.activities" :key="activity.title">
                                                                        <div class="flex gap-2 sm:gap-3 p-2 sm:p-3 bg-gray-50 rounded-lg">
                                                                            <img :src="activity.image || '<?= root ?>uploads/no_img.jpg'"
                                                                                class="w-12 h-12 sm:w-16 sm:h-16 object-cover rounded"
                                                                                onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                                                                            <div class="flex-1">
                                                                                <h6 class="font-medium text-gray-800 text-sm sm:text-base" x-text="activity.title"></h6>
                                                                                <p class="text-xs sm:text-sm text-gray-600 mt-1" x-text="activity.description"></p>
                                                                            </div>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inclusions & Exclusions Section - Responsive -->
                        <div x-show="tourData?.inclusions?.length > 0 || tourData?.exclusions?.length > 0" class="card mx-auto mb-4 sm:mb-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                                <!-- What's Included -->
                                <div>
                                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-3 sm:mb-4 flex items-center gap-2">
                                        <?= T::whats_included ?>
                                    </h3>
                                    <div class="space-y-2 sm:space-y-3">
                                        <template x-if="!tourData?.inclusions?.length">
                                            <div class="text-gray-500 italic text-sm sm:text-base">No inclusions specified</div>
                                        </template>
                                        <template x-for="(inclusion, index) in (tourData?.inclusions || [])" :key="inclusion.id || ('inc-' + index)">
                                            <div class="flex items-start gap-2 sm:gap-3">
                                                <div class="flex-shrink-0 mt-0.5">
                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                        width="18"
                                                        height="18"
                                                        class="sm:w-5 sm:h-5 text-green-600"
                                                        viewBox="0 0 24 24"
                                                        fill="currentColor"
                                                        aria-hidden="true">
                                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                                    </svg>
                                                </div>
                                                <span class="text-gray-700 text-sm sm:text-base leading-relaxed flex-1" x-text="inclusion.name"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <!-- What's Not Included -->
                                <div>
                                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-3 sm:mb-4 flex items-center gap-2">
                                        <?= T::whats_excluded ?>
                                    </h3>
                                    <div class="space-y-2 sm:space-y-3">
                                        <template x-if="!tourData?.exclusions?.length">
                                            <div class="text-gray-500 italic text-sm sm:text-base">No exclusions specified</div>
                                        </template>
                                        <template x-for="(exclusion, index) in (tourData?.exclusions || [])" :key="exclusion.id || ('exc-' + index)">
                                            <div class="flex items-start gap-2 sm:gap-3">
                                                <div class="flex-shrink-0 mt-0.5">
                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                        width="18"
                                                        height="18"
                                                        class="sm:w-5 sm:h-5 text-red-600"
                                                        viewBox="0 0 24 24"
                                                        fill="currentColor"
                                                        aria-hidden="true">
                                                        <path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/>
                                                    </svg>
                                                </div>
                                                <span class="text-gray-700 text-sm sm:text-base leading-relaxed flex-1" x-text="exclusion.name"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cancellation Policy - Responsive -->
                        <div x-show="tourData?.cancellation_policy" class="card mx-auto mb-4 sm:mb-6">
                            <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-2 sm:mb-3"><?= T::cancellation_policy ?></h3>
                            <div class="text-gray-600 text-xs sm:text-sm md:text-[14.5px] leading-relaxed space-y-2 [&_p]:mb-2 [&_p:last-child]:mb-0 [&_a]:text-blue-600 [&_a]:underline"
                                 x-html="tourData?.cancellation_policy"></div>
                        </div>

                        <!-- Terms & Conditions - Responsive -->
                        <div x-show="tourData?.terms_conditions" class="card mx-auto mb-4 sm:mb-6">
                            <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-2 sm:mb-3"><?= T::terms_conditions ?></h3>
                            <div class="text-gray-600 text-xs sm:text-sm md:text-[14.5px] leading-relaxed space-y-2 [&_p]:mb-2 [&_p:last-child]:mb-0 [&_a]:text-blue-600 [&_a]:underline"
                                 x-html="tourData?.terms_conditions"></div>
                        </div>
                    </div>

                    <!-- Right Sidebar (Sticky Booking Form) -->
                    <div class="w-full lg:w-1/3 order-1 lg:order-2">
                        <div class="sticky bg-white text-gray-800 p-4 sm:p-6 rounded-xl shadow-[0_4px_6px_5px_#0000001a]"
                            style="max-height: calc(100vh - 100px); overflow-y: auto; top: 125px;">

                            <h3 x-show="tourData?.display_price > 0" class="text-lg sm:text-xl font-semibold mb-3 sm:mb-4 relative">
                                <span x-show="!loading && tourData">
                                    From
                                    <span x-text="tourData?.currency ?? 'USD'"></span>
                                    <span x-text="Number(tourData?.display_price || 0).toFixed(2)"></span>
                                </span>
                                <span x-show="loading" class="text-gray-300">Loading price...</span>
                            </h3>

                            <!-- Date Picker -->
                            <div class="mb-3 sm:mb-4">
                                <label class="block text-sm font-medium mb-1"><?= T::start_date ?></label>
                                <input type="text"
                                    name="start_date"
                                    :disabled="updatingTravelers || loading"
                                    placeholder="<?= T::start_date ?>"
                                    class="dp input w-full bg-white text-gray-700"
                                    value="<?= htmlspecialchars($departureFormatted === 'Flexible' ? '' : $departureDate) ?>"
                                    readonly />
                            </div>

                            <!-- Adults Selection -->
                            <div class="flex justify-between items-center mb-3 sm:mb-4">
                                <div class="flex-1">
                                    <div class="font-medium text-sm sm:text-base"><?= T::adults ?></div>
                                    <div class="text-xs text-gray-400"><?= T::age ?> 18+</div>
                                </div>
                                <div x-show="tourData?.display_price_per_adult > 0" class="text-right mx-2 sm:mx-4">
                                    <div class="text-xs sm:text-sm"><?= T::price ?></div>
                                    <div class="text-xs sm:text-sm"
                                        x-html="
                                            loading
                                                ? 'Loading...'
                                                : `${tourData?.currency ?? 'USD'} ${Number(tourData?.display_price_per_adult || 0).toFixed(2)}`
                                        ">
                                    </div>
                                </div>
                                <div class="form-control w-20 relative">
                                    <select x-model.number="selectedAdults"
                                            @change="updateURL()"
                                            x-ref="adultSelect"
                                            :disabled="updatingTravelers || loading"
                                            class="select select-bordered text-gray-700 bg-white text-sm sm:text-base w-full">
                                        <template x-if="tourData && !loading">
                                            <template x-for="i in createNumberArray(tourData?.max_adults || 6)" :key="i">
                                                <option :value="i" x-text="i"></option>
                                            </template>
                                        </template>
                                        <template x-if="!tourData">
                                            <?php for($i = 1; $i <= 6; $i++): ?>
                                            <option value="<?= $i ?>" <?= $i == $totalAdults ? 'selected' : '' ?>>
                                                <?= $i ?>
                                            </option>
                                            <?php endfor; ?>
                                        </template>
                                    </select>
                                </div>
                            </div>

                            <!-- Children Selection -->
                            <div class="flex justify-between items-center mb-4 sm:mb-6">
                                <div class="flex-1">
                                    <div class="font-medium text-sm sm:text-base"><?= T::children ?></div>
                                    <div class="text-xs text-gray-400"><?= T::age ?> 2-17</div>
                                </div>
                                <div x-show="tourData?.display_price_per_child > 0" class="text-right mx-2 sm:mx-4">
                                    <div class="text-xs sm:text-sm"><?= T::price ?></div>
                                    <div class="text-xs sm:text-sm"
                                        x-html="
                                            loading
                                                ? 'Loading...'
                                                : `${tourData?.currency ?? 'USD'} ${Number(tourData?.display_price_per_child || 0).toFixed(2)}`
                                        ">
                                    </div>
                                </div>
                                <div class="form-control w-20 relative">
                                    <select x-model.number="selectedChildren"
                                            @change="updateURL()"
                                            x-ref="childSelect"
                                            :disabled="updatingTravelers || loading"
                                            class="select select-bordered text-gray-700 bg-white text-sm sm:text-base w-full">
                                        <option value="0">0</option>
                                        <template x-if="tourData && (tourData?.max_children || 0) > 0 && !loading">
                                            <template x-for="i in createNumberArray(tourData.max_children)" :key="i">
                                                <option :value="i" x-text="i"></option>
                                            </template>
                                        </template>
                                        <template x-if="!tourData">
                                            <?php for($i = 1; $i <= 3; $i++): ?>
                                            <option value="<?= $i ?>" <?= $i == $totalChildren ? 'selected' : '' ?>>
                                                <?= $i ?>
                                            </option>
                                            <?php endfor; ?>
                                        </template>
                                    </select>
                                </div>
                            </div>

                            <!-- Book Now Button -->
                            <a @click="bookNow()"
                            :class="loading
                                    ? 'bg-gray-600 cursor-not-allowed'
                                    : 'bg-blue-600 hover:bg-blue-700'"
                            class="block w-full text-white font-medium py-2 sm:py-3 rounded-lg transition text-center text-sm sm:text-base relative cursor-pointer">

                            <span x-show="!loading" class="flex items-center justify-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 sm:w-5 sm:h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                <span><?= T::book ?> <?= T::now ?></span>
                            </span>
                            <span x-show="loading" class="flex items-center justify-center gap-2">
                                <div class="inline-block animate-spin rounded-full h-4 w-4 border-b-2 border-white"></div>
                                Processing...
                            </span>
                            </a>

                            <!-- Reserve Now, Pay Later Information -->
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
                                <div class="flex items-center gap-2 text-xs sm:text-sm text-gray-400 mt-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                    </svg>
                                    <span>Free cancellation available</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- Error State - Responsive -->
    <div x-show="error" x-cloak class="card mx-auto text-center py-8 sm:py-12">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" class="sm:w-16 sm:h-16 mx-auto mb-3 sm:mb-4 text-red-500" viewBox="0 0 24 24">
            <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
        <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-1.5 sm:mb-2"><?= T::error_loading_tour ?></h3>
        <p class="text-gray-600 text-sm sm:text-base mb-3 sm:mb-4" x-text="errorMessage"></p>
        <a href="<?= root ?>tours" class="inline-flex items-center gap-1 sm:gap-2 px-4 sm:px-6 py-1.5 sm:py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm sm:text-base">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" class="sm:w-5 sm:h-5" viewBox="0 0 24 24">
                <path fill="currentColor" d="M20 11H7.83l5.59-5.59L12 4l-8 8l8 8l1.41-1.41L7.83 13H20v-2z"/>
            </svg>
            <?= T::back_to_search ?>
        </a>
    </div>
</div>

<!-- ============================================
    MAIN JAVASCRIPT SECTION - TOUR DETAILS LOGIC
============================================ -->
<script>
function tourDetails() {
    return {
        // Component state variables
        loading: true,
        updatingTravelers: false,
        selectedAdults: <?= (int)$totalAdults ?>,
        selectedChildren: <?= (int)$totalChildren ?>,
        error: false,
        errorMessage: '',
        tourData: null,
        currentImageIndex: 0,
        currentImageUrl: '',
        map: null,
        itineraryMap: null,

        openAccordion: 0,
        openAccordions: new Set(),
        allAccordionsOpen: false,

        // Initialize component when page loads
        init() {
            this.fetchTourDetails();
            this.initDatePicker();
        },

        createNumberArray(max) {
            return Array.from({length: max}, (_, i) => i + 1);
        },

        updateURL() {
            if (this.updatingTravelers || this.loading) return;

            this.updatingTravelers = true;

            const adultSelect = this.$refs.adultSelect;
            const childSelect = this.$refs.childSelect;
            if (adultSelect) adultSelect.disabled = true;
            if (childSelect) childSelect.disabled = true;

            try {
                const parts = window.location.pathname.split('/').filter(p => p);
                const tourIndex = parts.indexOf('tour');

                if (tourIndex === -1 || parts.length < tourIndex + 6) {
                    this.updatingTravelers = false;
                    if (adultSelect) adultSelect.disabled = false;
                    if (childSelect) childSelect.disabled = false;
                    return;
                }

                const slug = parts[tourIndex + 1];
                const tourId = parts[tourIndex + 2];
                const supplier = parts[tourIndex + 3];
                const date = parts[tourIndex + 4];
                const duration = parts[tourIndex + 5];

                const newTravelersStr = this.selectedChildren > 0
                    ? `${this.selectedAdults}-${this.selectedChildren}`
                    : `${this.selectedAdults}`;

                window.location.href = `<?= root ?>tour/${slug}/${tourId}/${supplier}/${date}/${duration}/${newTravelersStr}`;
            } catch (error) {
                console.error('Error updating URL:', error);
                this.updatingTravelers = false;
                if (adultSelect) adultSelect.disabled = false;
                if (childSelect) childSelect.disabled = false;
            }
        },

        initDatePicker() {
            this.$nextTick(() => {
                const dateInput = document.querySelector('input[name="start_date"]');
                if (dateInput) {
                    // Listen for datepicker's date selection event
                    $(dateInput).on('changeDate', (e) => {
                        if (this.loading) return;
                        
                        const newDate = e.date; // Get Date object from datepicker event
                        if (!newDate) return;

                        this.loading = true;

                        try {
                            const parts = window.location.pathname.split('/').filter(p => p);
                            const tourIndex = parts.indexOf('tour');

                            if (tourIndex === -1 || parts.length < tourIndex + 6) {
                                this.loading = false;
                                return;
                            }

                            const slug = parts[tourIndex + 1];
                            const tourId = parts[tourIndex + 2];
                            const supplier = parts[tourIndex + 3];
                            const duration = parts[tourIndex + 5];
                            const travelers = parts[tourIndex + 6];

                            // Format date as DD-MM-YYYY
                            const day = String(newDate.getDate()).padStart(2, '0');
                            const month = String(newDate.getMonth() + 1).padStart(2, '0');
                            const year = newDate.getFullYear();
                            const formattedDate = `${day}-${month}-${year}`;

                            const newUrl = `<?= root ?>tour/${slug}/${tourId}/${supplier}/${formattedDate}/${duration}/${travelers}`;

                            // Redirect to update the page with new date
                            window.location.href = newUrl;

                        } catch (error) {
                            console.error('Error updating date:', error);
                            this.loading = false;
                        }
                    });
                }
            });
        },

        // Fetch tour details from server
        async fetchTourDetails() {
            try {
                const supplier = '<?= $supplier ?>';
                const endpoint = '<?= root ?>modules/tours/' + supplier + '/details';

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        tour_id: '<?= $tourId ?>',
                        supplier: supplier,
                        departure_date: '<?= $departureDate ?>',
                        duration: '<?= $duration ?>',
                        total_adults: <?= $totalAdults ?>,
                        total_children: <?= $totalChildren ?>
                    })
                });

                const data = await response.json();

                if (data.success) {
                    this.tourData = data.data;
                    
                    // Set initial image for gallery (Prioritize default image)
                    if (this.tourData?.image && this.tourData?.images && this.tourData.images.length > 0) {
                        const defaultImg = this.tourData.image;
                        const defaultIdx = this.tourData.images.findIndex(img => (img.url || img) === defaultImg);
                        
                        if (defaultIdx > 0) {
                            const images = [...this.tourData.images];
                            const [item] = images.splice(defaultIdx, 1);
                            images.unshift(item);
                            this.tourData.images = images;
                        }
                    }

                    // Set initial image for gallery
                    if (this.tourData?.images && this.tourData.images.length > 0) {
                        const img = this.tourData.images[0];
                        this.currentImageUrl = img.url || img;
                        this.currentImageIndex = 0;
                    }

                    // Initialize maps after data is loaded and DOM is ready
                    this.$nextTick(() => {
                        if (this.tourData?.latitude && this.tourData?.longitude) {
                            this.initMap(); // Initialize main location map
                        }
                        // Initialize itinerary map with all points
                        if (this.hasItineraryCoordinates()) {
                            this.initItineraryMap();
                        }
                    });
                } else {
                    this.error = true;
                    this.errorMessage = data.message || '<?= T::failed_to_load_tour_details ?>';
                }
            } catch (err) {
                console.error('Error fetching tour details:', err);
                this.error = true;
                this.errorMessage = '<?= T::network_error_please_try_again ?>';
            } finally {
                this.loading = false;
            }
        },

        async bookNow() {
            if (this.loading) return;

            this.loading = true;

            try {
                // Prepare booking data
                const bookingData = {
                    tour_id: '<?= $tourId ?>',
                    tour_name: this.tourData?.tour_name || '<?= htmlspecialchars($tourName) ?>',
                    supplier: '<?= $supplier ?>',
                    start_date: '<?= $departureDate ?>',
                    duration: this.tourData?.nights + ' ' + (this.tourData?.nights == 1 ? '<?= T::night ?>' : '<?= T::nights ?>') + ' - ' + this.tourData?.days + ' ' + (this.tourData?.days == 1 ? '<?= T::day ?>' : '<?= T::days ?>'),
                    total_adults: parseInt(this.selectedAdults || <?= $totalAdults ?>),
                    total_children: parseInt(this.selectedChildren || <?= $totalChildren ?>),
                    currency: this.tourData?.original_currency || 'USD',
                    tour_image: this.tourData?.images?.[0]?.url || this.tourData?.images?.[0] || '',
                    tour_images: this.tourData?.images || [],
                    tour_location: this.tourData?.location || '',

                    // ACTUAL PRICES (BASE CURRENCY)
                    actual_price_per_person: this.tourData?.price_breakdown?.adults?.base_price_per_person || 0,
                    actual_total_price_persons: this.tourData?.price_breakdown?.adults?.subtotal_base || 0,

                    actual_price_per_child: this.tourData?.price_breakdown?.children?.base_price_per_person || 0,
                    actual_total_price_childrens: this.tourData?.price_breakdown?.children?.subtotal_base || 0,

                    actual_total_tour_price: this.tourData?.price_breakdown?.summary?.total_base_price
                        || this.tourData?.actual_total_tour_price || 0,

                    // MARKUP PRICES (BASE CURRENCY — system default)
                    markup_price_per_person: this.tourData?.price_breakdown?.adults?.marked_up_price_per_person_base
                        || this.tourData?.price_breakdown?.adults?.marked_up_price_per_person || 0,
                    markup_total_price_persons: this.tourData?.price_breakdown?.adults?.subtotal_marked_up_base
                        || this.tourData?.markup_total_price_persons
                        || this.tourData?.price_breakdown?.adults?.subtotal_marked_up || 0,

                    markup_price_per_child: this.tourData?.price_breakdown?.children?.marked_up_price_per_person_base
                        || this.tourData?.price_breakdown?.children?.marked_up_price_per_person || 0,
                    markup_total_price_childrens: this.tourData?.price_breakdown?.children?.subtotal_marked_up_base
                        || this.tourData?.markup_total_price_childrens
                        || this.tourData?.price_breakdown?.children?.subtotal_marked_up || 0,

                    markup_total_tour_price: this.tourData?.price_breakdown?.summary?.total_marked_up_price_base
                        || this.tourData?.markup_total_tour_price
                        || this.tourData?.price_breakdown?.summary?.total_marked_up_price
                        || this.calculateTotalAmount() || 0,

                };

                // Save booking draft to database
                const response = await fetch('<?= root ?>api/tour/booking/save-draft', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(bookingData)
                });

                const data = await response.json();

                if (data.success && data.hash) {
                    // Check if tour has external redirect URL (e.g., Viator)
                    if (this.tourData?.redirect_url && this.tourData.redirect_url.trim() !== '') {
                        // Open external booking link in new window
                        window.open(this.tourData.redirect_url, '_blank');
                        // Redirect main page to tours listing
                        window.location.href = '<?= root ?>tours';
                    } else {
                        // Standard internal booking flow
                        window.location.href = `<?= root ?>tours/booking/${data.hash}`;
                    }
                } else {
                    throw new Error(data.message || 'Failed to save booking');
                }
            } catch (err) {
                console.error('Booking error:', err);
                alert('Failed to proceed with booking. Please try again.');
                this.loading = false;
            }
        },

        // Calculate total amount
        calculateTotalAmount() {
            const adults = parseInt(this.selectedAdults || <?= $totalAdults ?>);
            const children = parseInt(this.selectedChildren || <?= $totalChildren ?>);
            const adultPrice = this.tourData?.display_price_per_adult || 0;
            const childPrice = this.tourData?.display_price_per_child || 0;

            return (adults * adultPrice) + (children * childPrice);
        },

        // Check if any itinerary day has valid coordinates
        hasItineraryCoordinates() {
            if (!this.tourData?.itinerary) return false;
            return this.tourData.itinerary.some(day =>
                day.latitude && day.longitude &&
                day.latitude !== '' && day.longitude !== ''
            );
        },

        // Navigate to next image in gallery
        nextImage() {
            if (!this.tourData?.images || this.tourData.images.length === 0) return;

            this.currentImageIndex = (this.currentImageIndex + 1) % this.tourData.images.length;
            const img = this.tourData.images[this.currentImageIndex];
            this.currentImageUrl = img.url || img;
        },

        // Navigate to previous image in gallery
        prevImage() {
            if (!this.tourData?.images || this.tourData.images.length === 0) return;

            this.currentImageIndex = this.currentImageIndex === 0 ?
                this.tourData.images.length - 1 :
                this.currentImageIndex - 1;
            const img = this.tourData.images[this.currentImageIndex];
            this.currentImageUrl = img.url || img;
        },

        // ============================================
        // ACCORDION FUNCTIONS
        // ============================================

        // Check if accordion is open
        isAccordionOpen(index) {
            return this.openAccordions.has(index);
        },

        // Toggle single accordion
        toggleSingleAccordion(index) {
            if (this.isAccordionOpen(index)) {
                this.openAccordions.delete(index);
            } else {
                this.openAccordions.add(index);
            }

            // Update allAccordionsOpen state
            this.updateAllAccordionsState();
        },

        // Toggle all accordions
        toggleAllAccordions() {
            if (!this.tourData?.itinerary) return;

            if (this.allAccordionsOpen) {
                // Close all
                this.openAccordions.clear();
                this.allAccordionsOpen = false;
            } else {
                // Open all
                const totalDays = this.tourData.itinerary.length;
                for (let i = 0; i < totalDays; i++) {
                    this.openAccordions.add(i);
                }
                this.allAccordionsOpen = true;
            }
        },

        // Update allAccordionsOpen state
        updateAllAccordionsState() {
            if (!this.tourData?.itinerary) return;

            const totalDays = this.tourData.itinerary.length;
            this.allAccordionsOpen = this.openAccordions.size === totalDays;
        },

        // ============================================
        // OPENLAYERS MAP FUNCTIONS
        // ============================================

        // Initialize main tour location map
        initMap() {
            const mapEl = document.getElementById('map');

            if (!this.tourData || !this.tourData.latitude || !this.tourData.longitude) {
                if (mapEl) {
                    mapEl.innerHTML = '<div class="flex items-center justify-center h-full text-gray-500 text-sm"><?= T::location_not_available ?></div>';
                }
                return;
            }

            const lat = parseFloat(this.tourData.latitude);
            const lng = parseFloat(this.tourData.longitude);
            const centerCoordinates = ol.proj.fromLonLat([lng, lat]);

            this.map = new ol.Map({
                target: 'map',
                layers: [
                    new ol.layer.Tile({
                        source: new ol.source.OSM()
                    })
                ],
                view: new ol.View({
                    center: centerCoordinates,
                    zoom: 14
                })
            });

            const marker = new ol.Feature({
                geometry: new ol.geom.Point(centerCoordinates)
            });

            marker.setStyle(new ol.style.Style({
                image: new ol.style.Icon({
                    anchor: [0.5, 1],
                    src: "https://cdn-icons-png.flaticon.com/512/684/684908.png",
                    scale: 0.08
                })
            }));

            const vectorLayer = new ol.layer.Vector({
                source: new ol.source.Vector({
                    features: [marker]
                })
            });

            this.map.addLayer(vectorLayer);

            const popupElement = document.createElement('div');
            popupElement.className = 'ol-popup';
            popupElement.innerHTML = `
                <div class="p-2 sm:p-3 bg-white rounded-lg shadow-lg border border-gray-200">
                    <h4 class="font-bold text-gray-900 text-sm sm:text-base mb-1">${this.tourData.name || this.tourData.tour_name || 'Tour Location'}</h4>
                    <p class="text-xs sm:text-sm text-gray-600">${this.tourData.address || this.tourData.location || 'No address available'}</p>
                </div>
            `;

            const popupOverlay = new ol.Overlay({
                element: popupElement,
                positioning: 'bottom-center',
                stopEvent: false,
                offset: [0, -10]
            });

            this.map.addOverlay(popupOverlay);

            this.map.on('click', (event) => {
                const feature = this.map.forEachFeatureAtPixel(event.pixel, (feature) => feature);
                if (feature) {
                    const coordinates = feature.getGeometry().getCoordinates();
                    popupOverlay.setPosition(coordinates);
                    popupElement.style.display = 'block';
                } else {
                    popupElement.style.display = 'none';
                }
            });
        },

        // Initialize itinerary map with multiple locations
        initItineraryMap() {
            const mapEl = document.getElementById('itinerary-map');

            if (!mapEl || !this.tourData?.itinerary) return;

            const validDays = this.tourData.itinerary.filter((day, index) => {
                if (day.latitude && day.longitude) {
                    const lat = parseFloat(day.latitude);
                    const lng = parseFloat(day.longitude);
                    return !isNaN(lat) && !isNaN(lng);
                }
                return false;
            }).map((day, index) => ({
                ...day,
                dayNumber: day.day || index + 1,
                lat: parseFloat(day.latitude),
                lng: parseFloat(day.longitude)
            }));

            if (validDays.length === 0) {
                mapEl.innerHTML = '<div class="flex items-center justify-center h-full text-gray-500 text-sm">No itinerary coordinates available</div>';
                return;
            }

            const itineraryCoordinates = validDays.map(day => ol.proj.fromLonLat([day.lng, day.lat]));

            this.itineraryMap = new ol.Map({
                target: 'itinerary-map',
                layers: [
                    new ol.layer.Tile({
                        source: new ol.source.OSM()
                    })
                ],
                view: new ol.View({
                    center: itineraryCoordinates[0],
                    zoom: 10
                })
            });

            const routeLine = new ol.Feature({
                geometry: new ol.geom.LineString(itineraryCoordinates)
            });

            routeLine.setStyle(new ol.style.Style({
                stroke: new ol.style.Stroke({
                    color: 'blue',
                    width: 3
                })
            }));

            const dayMarkers = validDays.map((day, index) => {
                const marker = new ol.Feature({
                    geometry: new ol.geom.Point(itineraryCoordinates[index])
                });

                marker.setStyle(new ol.style.Style({
                    image: new ol.style.Circle({
                        radius: 10,
                        fill: new ol.style.Fill({ color: 'white' }),
                        stroke: new ol.style.Stroke({
                            color: 'blue',
                            width: 2
                        })
                    }),
                    text: new ol.style.Text({
                        text: day.dayNumber.toString(),
                        fill: new ol.style.Fill({ color: 'blue' }),
                        font: 'bold 10px Arial',
                        offsetY: 1
                    })
                }));

                return marker;
            });

            const routeLayer = new ol.layer.Vector({
                source: new ol.source.Vector({
                    features: [routeLine, ...dayMarkers]
                })
            });

            this.itineraryMap.addLayer(routeLayer);

            if (validDays.length > 1) {
                const extent = routeLine.getGeometry().getExtent();
                this.itineraryMap.getView().fit(extent, {
                    padding: [30, 30, 30, 30],
                    maxZoom: 12
                });
            }
        },

        // Open lightbox for image gallery
        openFSLightbox(index) {
            if (!this.tourData?.images || this.tourData.images.length === 0) return;

            const tryOpenLightbox = () => {
                if (typeof FsLightbox === 'undefined') {
                    console.error('FSLightbox is not loaded, trying fallback...');
                    const validImages = this.tourData.images.filter(img => {
                        const imgUrl = img.url || img;
                        return !imgUrl.includes('no_img.jpg');
                    });
                    if (validImages[index]) {
                        window.open(validImages[index].url || validImages[index], '_blank');
                    }
                    return;
                }

                const validImages = this.tourData.images.filter(img => {
                    const imgUrl = img.url || img;
                    return !imgUrl.includes('no_img.jpg');
                }).map(img => img.url || img);

                if (validImages.length === 0) return;

                if (!window.fsLightboxInstances) {
                    window.fsLightboxInstances = {};
                }

                if (!window.fsLightboxInstances.tourGallery) {
                    window.fsLightboxInstances.tourGallery = new FsLightbox();
                }

                const lightbox = window.fsLightboxInstances.tourGallery;
                lightbox.props.sources = validImages;
                lightbox.props.slide = Math.min(index, validImages.length - 1) + 1;
                lightbox.open();
            };

            if (typeof FsLightbox === 'undefined') {
                setTimeout(tryOpenLightbox, 100);
            } else {
                tryOpenLightbox();
            }
        }
    }
}
</script>

<!-- ============================================
    FSLIGHTBOX SCRIPT LOADER
============================================ -->
<script>
(function() {
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
        script.onload = function() {
            console.log('FSLightbox loaded successfully from:', cdnUrls[currentIndex]);
        };
        script.onerror = function() {
            console.warn('Failed to load FSLightbox from:', cdnUrls[currentIndex]);
            currentIndex++;
            loadScript();
        };
        document.head.appendChild(script);
    }

    loadScript();
})();
</script>