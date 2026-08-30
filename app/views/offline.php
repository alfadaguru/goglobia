<style>
    header, footer { display: none }
    @keyframes spin-gear {
        from { transform: rotate(0deg); }
        to   { transform: rotate(360deg); }
    }
    .spin-gear { animation: spin-gear 10s linear infinite; }
</style>

<section class="min-h-screen flex flex-col items-center justify-center bg-gray-50 px-4 py-12">
    <div class="max-w-xl w-full text-center">

        <!-- Icon -->
        <div class="w-24 h-24 mx-auto bg-primary-50 rounded-full flex items-center justify-center mb-6">
            <span class="material-symbols-outlined text-primary-500 spin-gear" style="font-size:2.75rem;">settings</span>
        </div>

        <!-- Status badge -->
        <div class="inline-flex items-center gap-2 bg-amber-50 border border-amber-200 text-amber-700 text-xs font-semibold uppercase tracking-widest px-4 py-1.5 rounded-full mb-6">
            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse inline-block"></span>
            Under Maintenance
        </div>

        <!-- Heading -->
        <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-4 leading-tight">
            We'll be back soon
        </h1>

        <!-- Dynamic message -->
        <?php if (!empty($GLOBALS['app']['offline_message'])): ?>
        <p class="text-gray-600 text-base leading-relaxed mb-8">
            <?= htmlspecialchars($GLOBALS['app']['offline_message']) ?>
        </p>
        <?php else: ?>
        <p class="text-gray-600 text-base leading-relaxed mb-8">
            Our website is currently undergoing scheduled maintenance.<br>
            We apologize for the inconvenience and appreciate your patience.
        </p>
        <?php endif; ?>

        <!-- Divider -->
        <div class="border-t border-gray-200 mb-8"></div>

        <!-- Info cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8 text-left">
            <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                <div class="w-9 h-9 bg-blue-50 rounded-lg flex items-center justify-center mb-3">
                    <span class="material-symbols-outlined text-primary-500 text-lg">schedule</span>
                </div>
                <p class="text-sm font-semibold text-gray-800 mb-1">Back Shortly</p>
                <p class="text-xs text-gray-500">We're working hard to restore service as quickly as possible.</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                <div class="w-9 h-9 bg-green-50 rounded-lg flex items-center justify-center mb-3">
                    <span class="material-symbols-outlined text-green-600 text-lg">verified_user</span>
                </div>
                <p class="text-sm font-semibold text-gray-800 mb-1">Data is Safe</p>
                <p class="text-xs text-gray-500">All your information is secure and will remain intact.</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                <div class="w-9 h-9 bg-purple-50 rounded-lg flex items-center justify-center mb-3">
                    <span class="material-symbols-outlined text-purple-600 text-lg">rocket_launch</span>
                </div>
                <p class="text-sm font-semibold text-gray-800 mb-1">Coming Improved</p>
                <p class="text-xs text-gray-500">New improvements and features are on their way.</p>
            </div>
        </div>

        <!-- Action button -->
        <button onclick="window.location.reload()" class="btn inline-flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">refresh</span>
            Try Again
        </button>

        <p class="text-xs text-gray-400 mt-6">
            If you have an urgent query, please contact us directly.
        </p>

    </div>
</section>

