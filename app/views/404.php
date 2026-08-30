<section class="min-h-screen flex flex-col items-center justify-center px-4 py-8">
    <?php if (isset($error_details) && !empty($error_details)): ?>
        <!-- Error Details -->
        <div class="max-w-5xl w-full">
            <!-- Error Header Card -->
            <div class="bg-gradient-to-r from-red-50 to-orange-50 border-l-4 border-red-500 rounded-lg shadow-md mb-4 overflow-hidden">
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                                <span class="material-symbols-outlined text-red-600 text-2xl">error</span>
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h1 class="text-2xl font-bold text-red-900 mb-2 flex items-center gap-2">
                                An Error Occurred
                                <span class="text-xs font-normal text-red-600 bg-red-100 px-2 py-1 rounded"><?= htmlspecialchars($error_details['type']) ?></span>
                            </h1>
                            <p class="text-red-800 text-lg leading-relaxed"><?= htmlspecialchars($error_details['message']) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error Details Card -->
            <div class="bg-white border border-gray-200 rounded-lg shadow-md mb-4">
                <div class="bg-gray-50 px-6 py-3 border-b border-gray-200">
                    <h2 class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">info</span>
                        Error Details
                    </h2>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="text-xs font-semibold text-gray-500 uppercase mb-1">File Location</div>
                            <div class="text-sm text-gray-900 font-mono break-all"><?= htmlspecialchars($error_details['file']) ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <div class="text-xs font-semibold text-gray-500 uppercase mb-1">Line Number</div>
                            <div class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($error_details['line']) ?></div>
                        </div>
                    </div>
                    
                    <?php if (isset($error_details['trace'])): ?>
                        <div class="border-t border-gray-200 pt-4">
                            <details class="group">
                                <summary class="cursor-pointer text-sm font-semibold text-gray-700 flex items-center justify-between hover:text-gray-900 select-none">
                                    <span class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-base">bug_report</span>
                                        Stack Trace
                                    </span>
                                    <span class="material-symbols-outlined text-base transition-transform group-open:rotate-180">expand_more</span>
                                </summary>
                                <div class="mt-3">
                                    <pre class="text-xs bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto leading-relaxed shadow-inner"><?= htmlspecialchars($error_details['trace']) ?></pre>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Help Card -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-blue-600 text-xl flex-shrink-0">lightbulb</span>
                    <div class="text-sm text-blue-900">
                        <p class="font-semibold mb-1">How to fix this error:</p>
                        <ul class="list-disc list-inside space-y-1 text-blue-800">
                            <li>Check if the database table exists</li>
                            <li>Verify table names in your code match the database</li>
                            <li>Review the stack trace for the exact location of the error</li>
                            <li>Check your configuration files for any typos</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap gap-3 justify-center">
                <a href="<?= root ?>" class="btn inline-flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">home</span>
                    Go to Homepage
                </a>
                <button onclick="history.back()" class="btn light inline-flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">arrow_back</span>
                    Go Back
                </button>
                <button onclick="location.reload()" class="btn light inline-flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">refresh</span>
                    Reload Page
                </button>
            </div>
        </div>
    <?php else: ?>
        <!-- Standard 404 Error -->
        <div class="text-center max-w-2xl">
            <div class="mb-8">
                <div class="w-32 h-32 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-6">
                    <span class="material-symbols-outlined text-gray-400" style="font-size: 64px;">search_off</span>
                </div>
                <h1 class="text-4xl font-bold text-gray-900 mb-3">404 - Page Not Found</h1>
                <p class="text-lg text-gray-600 mb-8">Sorry, the page you are looking for does not exist or has been moved.</p>
            </div>
            <div class="flex flex-wrap gap-3 justify-center">
                <a href="<?= root ?>" class="btn inline-flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">home</span>
                    Go to Homepage
                </a>
                <button onclick="history.back()" class="btn light inline-flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">arrow_back</span>
                    Go Back
                </button>
            </div>
        </div>
    <?php endif; ?>
</section>