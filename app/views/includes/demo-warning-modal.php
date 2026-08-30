<?php
// app/views/includes/demo-warning-modal.php
// Demo Warning Modal for phptravels.net and localhost
@$SECURE or die('Access Denied!');

// Check if helper function exists
if (!function_exists('shouldShowDemoWarning')) {
    error_log("ERROR: shouldShowDemoWarning function not found!");
    echo "<!-- ERROR: Demo warning helper function not loaded -->";
    return;
}

// Use helper function to check if demo warning should be shown
try {
    $shouldShowDemoModal = shouldShowDemoWarning();
} catch (\Exception $e) {
    error_log("ERROR in shouldShowDemoWarning: " . $e->getMessage());
    echo "<!-- ERROR: " . htmlspecialchars($e->getMessage()) . " -->";
    return;
}
?>

<?php if ($shouldShowDemoModal): ?>
    <!-- CSS for Modal Animations -->
    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }

            to {
                opacity: 0;
            }
        }

        #demoWarningModal {
            animation: fadeIn 0.4s ease-in;
        }

        #demoWarningModal.fade-out {
            animation: fadeOut 0.4s ease-out;
        }
    </style>
    <!-- DEMO SYSTEM WARNING MODAL -->
    <div class="modal-overlay flex" id="demoWarningModal"
        style="z-index: 9999; background: rgba(15, 23, 42, 0.6); pointer-events: auto;">
        <div class="modal-content w-full max-w-5xl bg-white rounded-lg border border-gray-200 shadow-xl flex flex-col max-h-[90vh]"
            style="pointer-events: auto;">

            <!-- Modal Header -->
            <div class="px-8 py-3 bg-slate-800 rounded-t-lg flex-shrink-0">
                <div class="flex gap-2 items-start">
                    <span class="text-xl">⚠️</span>
                    <div>
                        <h2 class="text-lg font-bold text-white">Important Notice: Demo Environment</h2>
                        <p class="text-sm text-gray-300 mt-1">Please read this information carefully before using the system
                        </p>
                    </div>
                </div>
            </div>

            <!-- Modal Body - Scrollable -->
            <div class="px-8 py-5 space-y-5 flex-1 overflow-y-auto">

                <!-- Info Item 1 -->
                <div class="flex gap-5 pb-5 border-b border-gray-200">
                    <span
                        class="material-symbols-outlined text-2xl text-slate-700 flex-shrink-0 mt-1">currency_exchange</span>
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-slate-900 mb-1">Pricing May Not Reflect Real Rates</h3>
                        <p class="text-sm text-slate-700 leading-relaxed">Prices displayed in this demo environment are
                            simulated and may differ significantly from real-world rates. Live rates require valid supplier
                            API credentials to be configured in your account settings.</p>
                    </div>
                </div>

                <!-- Info Item 2 -->
                <div class="flex gap-5 pb-5 border-b border-gray-200">
                    <span class="material-symbols-outlined text-2xl text-slate-700 flex-shrink-0 mt-1">vpn_key</span>
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-slate-900 mb-1">Configure Your Own API Credentials</h3>
                        <p class="text-sm text-slate-700 leading-relaxed">To access real supplier data and accurate pricing,
                            you must add your own API keys through <strong class="text-slate-900">Admin Settings →
                                Modules</strong>. This will enable live data integration and replace all demo content with
                            real-time information.</p>
                    </div>
                </div>

                <!-- Info Item 3 -->
                <div class="flex gap-5 pb-5 border-b border-gray-200">
                    <span class="material-symbols-outlined text-2xl text-slate-700 flex-shrink-0 mt-1">credit_card</span>
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-slate-900 mb-1">Testing Environment Only - NO Real Payments
                        </h3>
                        <p class="text-sm text-slate-700 leading-relaxed">This is a testing platform for development
                            purposes. Do not use real payment methods or credit card information. All payment gateway
                            integrations are configured in sandbox/testing mode exclusively.</p>
                    </div>
                </div>

                <!-- Info Item 4 -->
                <div class="flex gap-5 pb-5 border-b border-gray-200">
                    <span class="material-symbols-outlined text-2xl text-slate-700 flex-shrink-0 mt-1">storage</span>
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-slate-900 mb-1">Demo Data May Be Reset Periodically</h3>
                        <p class="text-sm text-slate-700 leading-relaxed">Demo environment data, including bookings,
                            reservations, and settings, may be reset periodically for maintenance. Do not rely on this
                            system for storing critical business information or live customer data.</p>
                    </div>
                </div>

                <!-- Info Item 5 -->
                <div class="flex gap-5">
                    <span class="material-symbols-outlined text-2xl text-slate-700 flex-shrink-0 mt-1">help</span>
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-slate-900 mb-1">Need Help or Ready for Production?</h3>
                        <p class="text-sm text-slate-700 leading-relaxed">For comprehensive documentation, technical
                            support, API integration guides, and assistance with moving to production, visit <a
                                href="https://phptravels.com" target="_blank"
                                class="text-blue-600 font-semibold hover:text-blue-700 underline">phptravels.com</a></p>
                    </div>
                </div>

            </div>

            <!-- Modal Footer - Sticky -->
            <div class="sticky bottom-0 px-4 py-4 border-t border-gray-200 bg-slate-50 rounded-b-lg flex flex-wrap gap-3 justify-end flex-shrink-0"
                style="pointer-events: auto;">
                <a href="https://phptravels.com/pricing" target="_blank" class="btn light w-full sm:w-fit">Learn More</a>
                <button id="acknowledgeDemoWarning" class="btn w-full sm:w-fit">I Understand & Continue</button>
            </div>

        </div>
    </div>

    <!-- Demo Warning Modal Script -->
    <script>
        (function () {
            'use strict';
            const demoModal = document.getElementById('demoWarningModal');
            const acknowledgeBtn = document.getElementById('acknowledgeDemoWarning');

            // Only allow closing via "I Understand & Continue" button
            if (acknowledgeBtn) {
                acknowledgeBtn.addEventListener('click', function () {
                    fetch('<?= root ?>api/save-demo-acknowledgement', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({ acknowledged: true, timestamp: new Date().toISOString() })
                    }).then(() => closeDemoModal()).catch(() => closeDemoModal());
                });
            }

            function closeDemoModal() {
                if (demoModal) {
                    demoModal.classList.add('fade-out');
                    setTimeout(() => {
                        demoModal.style.display = 'none';
                        demoModal.classList.remove('flex');
                    }, 400);
                }
            }

            // Prevent closing by clicking overlay
            if (demoModal) {
                demoModal.addEventListener('click', function (event) {
                    // Only close if clicking the acknowledge button, not the overlay
                    if (event.target !== this) {
                        event.stopPropagation();
                    }
                });
            }

            // Disable Escape key to close modal
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && demoModal?.style.display !== 'none') {
                    event.preventDefault();
                    // Modal stays open - do nothing
                }
            });
        })();
    </script>

<?php endif; ?>