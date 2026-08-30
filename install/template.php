<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHPTRAVELS Installation</title>
    <link rel="icon" type="image/png" sizes="192x192" href="https://phptravels.com/assets/img/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>if (typeof jQuery === 'undefined') { document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"><\/script>'); }</script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        .step-item { opacity: 0.4; transition: all 0.3s; }
        .step-item.active { opacity: 1; transform: scale(1.05); }
        .step-item.completed { opacity: 1; }
        .fade-in { animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .spinner { border: 2px solid #f3f3f3; border-top: 2px solid #3b82f6; border-radius: 50%; width: 16px; height: 16px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        #cdn-error { display: none; max-width: 520px; margin: 40px auto; padding: 20px; border: 1px solid #fecaca; border-radius: 10px; background: #fff1f2; text-align: center; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">

    <!-- Fallback if jQuery CDN fails -->
    <noscript>
        <div style="max-width:520px;margin:40px auto;padding:20px;border:1px solid #fecaca;border-radius:10px;background:#fff1f2;text-align:center;">
            <h3 style="color:#b91c1c;">JavaScript Required</h3>
            <p>The installer requires JavaScript to be enabled in your browser.</p>
        </div>
    </noscript>
    <div id="cdn-error">
        <h3 style="color:#b91c1c;margin-top:0;">CDN Loading Failed</h3>
        <p style="color:#7f1d1d;">jQuery could not be loaded from CDN. Please check your server's internet connectivity and firewall settings.</p>
        <p style="font-size:13px;color:#7f1d1d;">Try refreshing the page or check if your server can reach <code>code.jquery.com</code></p>
    </div>

    <div class="w-full max-w-2xl" id="installer-main">

        <!-- Header -->
        <div class="text-center mb-0 mt-0">
            <div class="inline-flex items-center gap-1 mb-3">
                <svg class="w-10 h-10 text-blue-600" viewBox="0 0 700 700" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                    <g transform="translate(0,700) scale(0.1,-0.1)">
                        <path d="M4435 5753c-307-19-533-75-848-209l-156-66-78 45c-146 86-349 156-563 193-115 20-360 23-445 5-141-29-285-91-330-141-42-46-7-157 90-288l44-60 68 34c112 55 204 74 367 74 87 0 164-6 202-15 74-17 174-54 174-64 0-8-171-70-275-100-320-91-556-73-658 50-54 67-97 165-97 222 0 60-12 54-40-21-28-73-28-158-2-278 43-196 136-378 224-443 94-69 244-98 431-81 317 28 432 73 1177 461 489 255 824 365 1145 376 157 6 237-7 327-53 72-36 153-116 185-183 25-49 28-67 27-146 0-105-22-172-89-275-65-101-174-196-415-361-188-129-664-429-682-429-4 0-8 19-8 43 0 107-57 347-126 536-30 82-175 390-202 429-6 10-59-13-218-94-115-59-210-108-212-110-2-1 14-33 35-71 134-235 217-551 248-944l6-76-138-78c-245-139-511-317-803-538-172-131-243-191-368-312-275-265-473-617-489-865-4-75-2-94 22-160 66-182 187-375 300-478 54-49 138-103 182-116 20-7 20-3-13 61-123 241-21 584 288 969 189 236 545 536 869 732 789 479 1159 721 1514 987 267 200 412 385 494 630 107 320 19 641-248 905-148 146-286 223-484 270-75 18-310 43-361 38-12-1-43-3-71-5z"/>
                        <path d="M3955 2994c-154-91-285-171-291-177-7-7-24-50-39-97-85-260-216-498-365-662-116-128-345-305-490-378-30-15-91-40-135-55-44-16-85-32-91-37-19-14-36-88-35-153 1-114 63-232 148-282 37-21 56-26 109-24 121 3 264 51 412 138 294 172 642 576 832 968 100 206 159 392 200 633 19 114 37 292 29 292-2-1-130-75-284-166z"/>
                    </g>
                </svg>
                <div class="text-left">
                    <div class="text-2xl font-bold text-gray-800">PHPTRAVELS</div>
                    <div class="text-xs text-gray-500 -mt-1">Travel Booking Platform</div>
                </div>
            </div>
            <!-- <p class="text-sm font-medium text-gray-600">Installation Wizard</p> -->
        </div>

        <!-- Main Card -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">

            <!-- Progress Steps -->
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="step-item active flex items-center gap-2 text-white" data-step="1">
                        <div class="w-6 h-6 rounded-full bg-white/20 flex items-center justify-center text-xs font-semibold">1</div>
                        <span class="text-sm font-medium hidden sm:inline">Requirements</span>
                    </div>
                    <div class="flex-1 h-0.5 bg-white/20 mx-2"></div>
                    <div class="step-item flex items-center gap-2 text-white" data-step="2">
                        <div class="w-6 h-6 rounded-full bg-white/20 flex items-center justify-center text-xs font-semibold">2</div>
                        <span class="text-sm font-medium hidden sm:inline">Database</span>
                    </div>
                    <div class="flex-1 h-0.5 bg-white/20 mx-2"></div>
                    <div class="step-item flex items-center gap-2 text-white" data-step="3">
                        <div class="w-6 h-6 rounded-full bg-white/20 flex items-center justify-center text-xs font-semibold">3</div>
                        <span class="text-sm font-medium hidden sm:inline">Setup</span>
                    </div>
                    <div class="flex-1 h-0.5 bg-white/20 mx-2"></div>
                    <div class="step-item flex items-center gap-2 text-white" data-step="4">
                        <div class="w-6 h-6 rounded-full bg-white/20 flex items-center justify-center text-xs font-semibold">4</div>
                        <span class="text-sm font-medium hidden sm:inline">Installing</span>
                    </div>
                    <div class="flex-1 h-0.5 bg-white/20 mx-2"></div>
                    <div class="step-item flex items-center gap-2 text-white" data-step="5">
                        <div class="w-6 h-6 rounded-full bg-white/20 flex items-center justify-center text-xs font-semibold">5</div>
                        <span class="text-sm font-medium hidden sm:inline">Complete</span>
                    </div>
                </div>
            </div>

            <!-- Content Area -->
            <div class="p-6">

                <!-- Alert Container -->
                <div id="alert-container"></div>

                <!-- Step 1: Requirements -->
                <div id="step-1" class="step-content">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">System Requirements</h2>
                    <div id="requirements-list" class="space-y-2 mb-6">
                        <div class="flex items-center justify-center py-8">
                            <div class="spinner"></div>
                            <span class="ml-2 text-sm text-gray-500">Checking requirements...</span>
                        </div>
                    </div>
                    <button id="btn-next-1" class="w-full bg-blue-600 text-white py-2.5 rounded-lg font-medium hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                        Continue
                    </button>
                </div>

                <!-- Step 2: Database -->
                <div id="step-2" class="step-content hidden">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Database Configuration</h2>
                    <form id="db-form" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Hostname</label>
                                <input type="text" name="hostname" value="localhost" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Database Name</label>
                                <input type="text" name="database" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Username</label>
                                <input type="text" name="username" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Password</label>
                                <input type="password" name="password" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <button type="button" onclick="goToStep(1)" class="px-6 bg-gray-200 text-gray-700 py-2.5 rounded-lg font-medium hover:bg-gray-300 transition-colors">
                                Back
                            </button>
                            <button type="submit" id="test-connection-btn" class="flex-1 bg-blue-600 text-white py-2.5 rounded-lg font-medium hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
                                <span>Test Connection</span>
                            </button>
                            <button type="button" id="continue-setup-btn" onclick="goToStep(3)" class="hidden flex-1 bg-green-600 text-white py-2.5 rounded-lg font-medium hover:bg-green-700 transition-colors flex items-center justify-center gap-2">
                                <span>Continue</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Step 3: Setup -->
                <div id="step-3" class="step-content hidden">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Application Setup</h2>
                    <form id="install-form" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Business Name</label>
                                <input type="text" name="business_name" value="PHPTRAVELS" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">License Key</label>
                                <input type="text" name="license" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Base URL</label>
                            <?php
                                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                                $host = $_SERVER['HTTP_HOST'];
                                // Get current URI and remove /install from the end
                                $currentPath = $_SERVER['REQUEST_URI'];
                                $path = str_replace('\\', '/', dirname($currentPath));
                                // Remove /install from path if it exists
                                $path = preg_replace('/\/install\/?$/', '', $path);
                                $baseUrl = $protocol . '://' . $host . rtrim($path, '/') . '/';
                            ?>
                            <input type="url" name="base_url" value="<?= $baseUrl ?>" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div class="border-t border-gray-200 pt-4 mt-4">
                            <h3 class="text-sm font-semibold text-gray-700 mb-3">Admin Account</h3>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">First Name</label>
                                    <input type="text" name="firstname" value="Super" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Last Name</label>
                                    <input type="text" name="lastname" value="Admin" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Email</label>
                                    <input type="email" name="admin_email" value="admin@phptravels.com" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Password</label>
                                    <input type="password" name="admin_password" value="demoadmin" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <button type="button" onclick="goToStep(2)" class="px-6 bg-gray-200 text-gray-700 py-2.5 rounded-lg font-medium hover:bg-gray-300 transition-colors">
                                Back
                            </button>
                            <button type="submit" class="flex-1 bg-green-600 text-white py-2.5 rounded-lg font-medium hover:bg-green-700 transition-colors flex items-center justify-center gap-2">
                                <span>Install PHPTRAVELS</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Step 4: Installing (Terminal) -->
                <div id="step-4" class="step-content hidden">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Installing PHPTRAVELS</h2>

                    <!-- Terminal Display -->
                    <div class="bg-gray-900 rounded-lg p-4 mb-4 font-mono text-xs text-green-400 h-96 overflow-y-auto" id="terminal">
                        <div class="mb-2">PHPTRAVELS Installation Terminal v10.0</div>
                        <div class="mb-2">═══════════════════════════════════════</div>
                        <div id="terminal-content"></div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="mb-4">
                        <div class="flex justify-between text-xs text-gray-600 mb-1">
                            <span>Progress</span>
                            <span id="progress-text">0%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div id="progress-bar" class="bg-green-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <!-- Step 5: Complete -->
                <div id="step-5" class="step-content hidden">
                    <!-- Success Header -->
                    <div class="text-center mb-8">
                        <div class="w-16 h-16 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">Installation Complete</h2>
                        <p class="text-sm text-gray-600">PHPTRAVELS v10 has been successfully installed and configured</p>
                    </div>

                    <!-- Credentials Box -->
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 mb-6">
                        <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
                            <h3 class="text-base font-semibold text-gray-800">Administrator Credentials</h3>
                            <button id="copy-credentials-btn" class="flex items-center gap-2 px-3 py-1.5 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                </svg>
                                <span id="copy-btn-text">Copy</span>
                            </button>
                        </div>
                        <div id="credentials-box" class="space-y-2.5 text-sm"></div>
                    </div>

                    <!-- Quick Access Links -->
                    <div class="grid grid-cols-3 gap-3 mb-6">
                        <a href="../login" target="_blank" class="flex flex-col items-center gap-2 p-4 bg-white border border-gray-200 rounded-lg hover:border-blue-500 hover:shadow-md transition-all text-center">
                            <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            <span class="text-xs font-medium text-gray-800">Admin Login</span>
                        </a>
                        <a href="../" target="_blank" class="flex flex-col items-center gap-2 p-4 bg-white border border-gray-200 rounded-lg hover:border-blue-500 hover:shadow-md transition-all text-center">
                            <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="text-xs font-medium text-gray-800">Website</span>
                        </a>
                        <a href="../sitemap.xml" target="_blank" class="flex flex-col items-center gap-2 p-4 bg-white border border-gray-200 rounded-lg hover:border-blue-500 hover:shadow-md transition-all text-center">
                            <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span class="text-xs font-medium text-gray-800">Sitemap</span>
                        </a>
                    </div>

                    <!-- Documentation Section -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                                <span class="text-sm text-gray-700">Need help getting started?</span>
                            </div>
                            <a href="https://docs.phptravels.com" target="_blank" class="px-4 py-2 bg-blue-600 text-white rounded text-xs font-medium hover:bg-blue-700 transition-colors">
                                Documentation
                            </a>
                        </div>
                    </div>

                    <!-- Important Notice -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                        <h4 class="text-xs font-semibold text-gray-800 mb-2">Security Recommendations</h4>
                        <ul class="text-xs text-gray-600 space-y-1">
                            <li>• Delete the /install directory for security</li>
                            <li>• Change the default admin password</li>
                            <li>• Enable SSL/HTTPS for production use</li>
                            <li>• Keep your system updated regularly</li>
                        </ul>
                    </div>

                    <!-- Footer Note -->
                    <div class="text-center text-xs text-gray-500">
                        <p>Installation completed successfully • Thank you for choosing PHPTRAVELS</p>
                    </div>
                </div>

            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-4 text-xs text-gray-500">
            © <?= date('Y') ?> PHPTRAVELS - All rights reserved
        </div>
    </div>

    <script>
    // Check if jQuery loaded - show error if not
    if (typeof jQuery === 'undefined') {
        document.getElementById('cdn-error').style.display = 'block';
        var main = document.getElementById('installer-main');
        if (main) main.style.display = 'none';
    }
    </script>
    <script>
    if (typeof jQuery === 'undefined') { throw new Error('jQuery not loaded'); }

    // Resolve the correct AJAX URL (handles missing trailing slash)
    var INSTALL_URL = (function() {
        var path = window.location.pathname;
        // Ensure we POST to the install directory's index.php
        if (path.indexOf('/install') !== -1) {
            return path.substring(0, path.indexOf('/install')) + '/install/index.php';
        }
        return 'index.php';
    })();

    // Set global AJAX defaults
    $.ajaxSetup({ timeout: 30000 });

    let currentStep = 1;

    function showAlert(message, type = 'error') {
        const icon = type === 'error'
            ? '<svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/></svg>'
            : '<svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>';

        const bgColor = type === 'error' ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200';
        const textColor = type === 'error' ? 'text-red-700' : 'text-green-700';

        $('#alert-container').html(`
            <div class="fade-in ${bgColor} border rounded-lg p-3 mb-4 flex items-start gap-2">
                ${icon}
                <p class="text-sm ${textColor} flex-1">${message}</p>
            </div>
        `).show();

        setTimeout(() => $('#alert-container').fadeOut(() => $('#alert-container').html('')), 5000);
    }

    function goToStep(step) {
        $('.step-content').addClass('hidden');
        $(`#step-${step}`).removeClass('hidden').addClass('fade-in');

        $('.step-item').removeClass('active completed');
        for(let i = 1; i < step; i++) {
            $(`.step-item[data-step="${i}"]`).addClass('completed');
        }
        $(`.step-item[data-step="${step}"]`).addClass('active');

        currentStep = step;
    }

    function setButtonLoading(btn, loading, originalText) {
        if (loading) {
            btn.data('original-text', btn.html());
            btn.prop('disabled', true).html('<div class="spinner inline-block mr-2"></div>Processing...');
        } else {
            const original = btn.data('original-text') || originalText;
            btn.prop('disabled', false).html(original);
        }
    }

    // Check requirements on load
    $(document).ready(function() {
        $.post(INSTALL_URL, { action: 'check_requirements' }, function(response) {
            if (response.success) {
                let html = '';
                const requirements = [
                    { key: 'php_version', label: 'PHP Version (>= 8.2)', value: response.php_version },
                    { key: 'mysqli', label: 'MySQLi Extension' },
                    { key: 'pdo', label: 'PDO Extension' },
                    { key: 'curl', label: 'cURL Extension' },
                    { key: 'openssl', label: 'OpenSSL Extension' },
                    { key: 'mbstring', label: 'Mbstring Extension' },
                    { key: 'fileinfo', label: 'Fileinfo Extension' },
                    { key: 'gd', label: 'GD Extension' },
                    { key: 'zip', label: 'ZIP Extension' },
                    { key: 'max_input_vars', label: 'Max Form Fields (required >= 1000)', value: response.max_input_vars_value, current: true },
                    { key: 'uploads_writable', label: 'Uploads Directory', path: response.uploads_path, log: response.uploads_log },
                    { key: 'cache_writable', label: 'Cache Directory', path: response.cache_path, log: response.cache_log }
                ];

                requirements.forEach(req => {
                    const passed = response.checks[req.key];
                    const displayValue = req.current
                        ? `Current: ${req.value}`
                        : (req.value || (passed ? 'OK' : 'Failed'));
                    const badge = passed
                        ? `<span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-700">${displayValue}</span>`
                        : `<span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-700">${displayValue}</span>`;

                    html += `<div class="p-2 bg-gray-50 rounded border ${passed ? 'border-gray-200' : 'border-red-200'}">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-gray-700">${req.label}</span>${badge}
                        </div>`;
                    
                    // Show path for directory checks (always)
                    if (req.path) {
                        html += `<div class="mt-1 text-xs text-gray-600 font-mono bg-gray-100 px-2 py-1 rounded">
                            📁 ${req.path}
                        </div>`;
                    }
                    
                    // Add debug logs ONLY when check fails
                    if (!passed && req.log && req.log.length > 0) {
                        html += `<details class="mt-2">
                            <summary class="text-xs text-red-600 cursor-pointer hover:text-red-700 font-medium">🔍 Show Debug Logs (Why it Failed)</summary>
                            <div class="mt-2 p-2 bg-gray-800 text-gray-100 rounded text-xs font-mono overflow-x-auto">`;
                        req.log.forEach(logLine => {
                            const color = logLine.includes('✓') ? 'text-green-400' : 
                                         logLine.includes('✗') ? 'text-red-400' : 
                                         logLine.includes('⚠') ? 'text-yellow-400' : 
                                         logLine.includes('===') ? 'text-blue-400 font-bold' : 'text-gray-300';
                            html += `<div class="${color}">${logLine}</div>`;
                        });
                        html += `</div></details>`;
                    }
                    
                    html += `</div>`;
                });

                $('#requirements-list').html(html);
                $('#btn-next-1').prop('disabled', !response.passed).text(response.passed ? 'Continue' : 'Requirements Not Met');
            } else {
                $('#requirements-list').html('<div class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">Failed to check requirements: ' + (response.message || 'Unknown error') + '</div>');
            }
        }, 'json').fail(function(xhr, status, error) {
            var errorMsg = 'Could not connect to the installer backend.';
            if (status === 'timeout') {
                errorMsg = 'Request timed out. The server may be slow or unresponsive.';
            } else if (xhr.status === 404) {
                errorMsg = 'Installer endpoint not found (404). Check that install/index.php exists on the server.';
            } else if (xhr.status === 500) {
                errorMsg = 'Server error (500). Check PHP error logs for details.';
            } else if (xhr.responseText) {
                // Might be a PHP error displayed as HTML
                errorMsg += '<br><br><details><summary class="cursor-pointer text-blue-600">Show server response</summary><pre class="mt-2 p-2 bg-gray-100 rounded text-xs overflow-auto max-h-40">' + $('<div>').text(xhr.responseText.substring(0, 1000)).html() + '</pre></details>';
            }
            $('#requirements-list').html('<div class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">' + errorMsg + '</div>');
        });

        $('#btn-next-1').click(function() {
            goToStep(2);
        });

        // Database form
        $('#db-form').submit(function(e) {
            e.preventDefault();
            const btn = $('#test-connection-btn');
            setButtonLoading(btn, true);

            $.post(INSTALL_URL, $(this).serialize() + '&action=test_database', function(response) {
                if (response.success) {
                    showAlert(response.message, 'success');
                    setButtonLoading(btn, false);

                    // Hide test button, show continue button
                    $('#test-connection-btn').addClass('hidden');
                    $('#continue-setup-btn').removeClass('hidden');

                    // Disable form inputs
                    $('#db-form input').prop('readonly', true).addClass('bg-gray-100');
                } else {
                    setButtonLoading(btn, false);
                    showAlert(response.message, 'error');
                }
            }, 'json').fail(() => {
                setButtonLoading(btn, false);
                showAlert('Connection failed. Please check your database credentials.', 'error');
            });
        });

        // Install form
        let installData = {};
        $('#install-form').submit(function(e) {
            e.preventDefault();

            // Validate form data
            const requiredFields = ['business_name', 'license', 'base_url', 'firstname', 'lastname', 'admin_email', 'admin_password'];
            const formData = $(this).serializeArray();
            const missingFields = requiredFields.filter(field => {
                const value = formData.find(item => item.name === field)?.value;
                return !value || value.trim() === '';
            });

            if (missingFields.length > 0) {
                showAlert('Please fill in all required fields: ' + missingFields.join(', '), 'error');
                return;
            }

            // Validate email format
            const email = formData.find(item => item.name === 'admin_email')?.value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showAlert('Please enter a valid email address', 'error');
                return;
            }

            installData = formData.reduce((obj, item) => {
                obj[item.name] = item.value;
                return obj;
            }, {});

            goToStep(4);
            startDatabaseImport();
        });

        function addTerminalLine(text, type = 'info') {
            const colors = {
                'info': 'text-green-400',
                'success': 'text-cyan-400',
                'error': 'text-red-400',
                'warning': 'text-yellow-400'
            };
            const color = colors[type] || colors['info'];
            const time = new Date().toLocaleTimeString();
            $('#terminal-content').append(`<div class="${color}">[${time}] ${text}</div>`);
            $('#terminal').scrollTop($('#terminal')[0].scrollHeight);
        }

        function startDatabaseImport() {
            addTerminalLine('Starting database import...', 'info');
            addTerminalLine('Connecting to database...', 'info');
            importNextQuery(0, 0);
        }

        function importNextQuery(offset, retryCount) {
            const maxRetries = 3;
            
            $.ajax({
                url: INSTALL_URL,
                type: 'POST',
                data: { action: 'import_database', offset: offset },
                dataType: 'json',
                timeout: 60000,
                success: function(response) {
                if (response && response.success) {
                    if (response.completed) {
                        addTerminalLine('═══════════════════════════════════════', 'success');
                        addTerminalLine('✓ Database import completed successfully!', 'success');
                        addTerminalLine('Creating admin user...', 'info');
                        finishInstallation();
                    } else {
                        addTerminalLine(`[${response.current}/${response.total}] ${response.query_type} → ${response.table}`, 'info');
                        $('#progress-bar').css('width', response.progress + '%');
                        $('#progress-text').text(response.progress + '%');
                        importNextQuery(offset + 1, 0);
                    }
                } else {
                    const errorMsg = response && response.message ? response.message : 'Unknown error occurred';
                    addTerminalLine('✗ ERROR: ' + errorMsg, 'error');
                    showAlert('Database import failed: ' + errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                if (retryCount < maxRetries) {
                    addTerminalLine(`⟳ Retrying query ${offset + 1} (attempt ${retryCount + 2}/${maxRetries + 1})...`, 'info');
                    setTimeout(() => importNextQuery(offset, retryCount + 1), 1000);
                } else {
                    // Try to get more error details
                    let errorDetails = error;
                    try {
                        if (xhr.responseText) {
                            errorDetails += ' - Response: ' + xhr.responseText.substring(0, 200);
                        }
                    } catch(e) {}
                    addTerminalLine('✗ Connection failed after ' + (maxRetries + 1) + ' attempts: ' + errorDetails, 'error');
                    showAlert('Installation failed. Please check your server error logs and try again.', 'error');
                }
            }
            });
        }

        function finishInstallation() {
            $.post(INSTALL_URL, $.param(installData) + '&action=install', function(response) {
                if (response && response.success) {
                    addTerminalLine('✓ Admin user created', 'success');
                    addTerminalLine('✓ Configuration file created', 'success');
                    addTerminalLine('✓ Installation completed!', 'success');

                    const creds = response.credentials;

                    if (!creds || !creds.base_url || !creds.email || !creds.password) {
                        addTerminalLine('✗ Invalid response data', 'error');
                        showAlert('Installation completed but credentials are missing', 'error');
                        return;
                    }

                    // Fix escaped slashes in URL
                    const baseUrl = creds.base_url.replace(/\\\//g, '/');

                    // Store credentials globally for copy function
                    window.installCredentials = {
                        email: creds.email,
                        password: creds.password,
                        adminUrl: baseUrl + 'login',
                        websiteUrl: baseUrl,
                        sitemapUrl: baseUrl + 'sitemap.xml',
                        docsUrl: 'https://docs.phptravels.com'
                    };

                    $('#credentials-box').html(`
                        <div class="flex items-center justify-between py-2">
                            <span class="text-gray-600 font-medium">Email</span>
                            <span class="font-mono text-sm text-gray-800">${creds.email}</span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-gray-200">
                            <span class="text-gray-600 font-medium">Password</span>
                            <span class="font-mono text-sm text-gray-800">${creds.password}</span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-gray-200">
                            <span class="text-gray-600 font-medium">Admin URL</span>
                            <a href="${baseUrl}login" target="_blank" class="font-mono text-sm text-blue-600 hover:underline">${baseUrl}login</a>
                        </div>
                        <div class="flex items-center justify-between py-2 border-t border-gray-200">
                            <span class="text-gray-600 font-medium">Website URL</span>
                            <a href="${baseUrl}" target="_blank" class="font-mono text-sm text-blue-600 hover:underline">${baseUrl}</a>
                        </div>
                    `);

                    setTimeout(() => {
                        goToStep(5);
                        launchConfetti();
                    }, 2000);
                } else {
                    const errorMsg = response && response.message ? response.message : 'Installation failed';
                    addTerminalLine('✗ ' + errorMsg, 'error');
                    showAlert(errorMsg, 'error');
                }
            }, 'json').fail(function(xhr, status, error) {
                addTerminalLine('✗ Failed to complete installation: ' + error, 'error');
                showAlert('Installation failed. Please check logs and try again.', 'error');
            });
        }

        function launchConfetti() {
            // Check if confetti is available
            if (typeof confetti !== 'function') {
                console.log('Confetti library not loaded');
                return;
            }

            try {
                // Launch confetti from multiple angles
                const duration = 3000;
                const animationEnd = Date.now() + duration;
                const defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 9999 };

                function randomInRange(min, max) {
                    return Math.random() * (max - min) + min;
                }

                const interval = setInterval(function() {
                    const timeLeft = animationEnd - Date.now();

                    if (timeLeft <= 0) {
                        return clearInterval(interval);
                    }

                    const particleCount = 50 * (timeLeft / duration);

                    // Left side
                    confetti(Object.assign({}, defaults, {
                        particleCount,
                        origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 }
                    }));

                    // Right side
                    confetti(Object.assign({}, defaults, {
                        particleCount,
                        origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 }
                    }));
                }, 250);

                // Also fire immediate burst
                confetti({
                    particleCount: 100,
                    spread: 70,
                    origin: { y: 0.6 },
                    zIndex: 9999
                });
            } catch (err) {
                console.error('Confetti error:', err);
            }
        }

        // Copy credentials function
        $(document).on('click', '#copy-credentials-btn', function() {
            const creds = window.installCredentials;
            if (!creds) return;

            const text = `
PHPTRAVELS v10 - Installation Complete

ADMIN CREDENTIALS
Email: ${creds.email}
Password: ${creds.password}

ACCESS URLS
Admin: ${creds.adminUrl}
Website: ${creds.websiteUrl}
Sitemap: ${creds.sitemapUrl}

DOCUMENTATION
Docs: ${creds.docsUrl}

SECURITY NOTES
• Change admin password
• Delete /install directory
• Enable SSL/HTTPS
• Keep system updated

Installed: ${new Date().toLocaleString()}

Thank you for choosing PHPTRAVELS!
Support: ${creds.docsUrl}
            `.trim();

            const tempTextArea = document.createElement('textarea');
            tempTextArea.value = text;
            tempTextArea.style.position = 'fixed';
            tempTextArea.style.opacity = '0';
            document.body.appendChild(tempTextArea);
            tempTextArea.select();

            try {
                document.execCommand('copy');
                $('#copy-btn-text').text('Copied!');
                setTimeout(() => $('#copy-btn-text').text('Copy'), 2000);

                showAlert('Installation details copied to clipboard!', 'success');
            } catch (err) {
                showAlert('Failed to copy. Please select and copy manually.', 'error');
            }

            document.body.removeChild(tempTextArea);
        });
    });
    </script></body>
</html>
