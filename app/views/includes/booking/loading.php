<!-- ============================================================================ -->
<!-- REUSABLE BOOKING LOADING OVERLAY COMPONENT                                -->
<!-- ============================================================================ -->
<!-- PURPOSE: Full-page loading animation shown during booking submission      -->
<!-- USAGE: Include this file in booking pages (stays, flights, tours)         -->
<!-- REQUIREMENT: Page must have x-data with 'showBookingLoader' property      -->
<!-- ============================================================================ -->

<!-- Full Page Loading Overlay -->
<div x-show="showBookingLoader"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     class="fixed inset-0 z-[9999] bg-white bg-opacity-95 backdrop-blur-sm flex items-center justify-center"
     style="display: none;">
    <div class="text-center">
        <div class="relative inline-flex items-center justify-center">
            <!-- Outer rotating ring -->
            <div class="w-24 h-24 border-8 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
            <!-- Inner pulsing circle -->
            <div class="absolute w-16 h-16 bg-blue-100 rounded-full animate-pulse"></div>
            <!-- Center icon -->
            <span class="absolute material-symbols-outlined text-blue-600 text-3xl">airplane_ticket</span>
        </div>
        <div class="mt-6 space-y-2">
            <h3 class="text-xl font-bold text-gray-800"><?= T::processing ?></h3>
            <p class="text-sm text-gray-600"><?= T::please ?> <?= T::wait ?>, <?= T::do_not ?> <?= T::refresh ?> <?= T::or ?> <?= T::close ?> <?= T::this ?> <?= T::page ?></p>
            <div class="flex items-center justify-center gap-2 mt-4">
                <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce" style="animation-delay: 0ms;"></div>
                <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce" style="animation-delay: 150ms;"></div>
                <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce" style="animation-delay: 300ms;"></div>
            </div>
        </div>
    </div>
</div>