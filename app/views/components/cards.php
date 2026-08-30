<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Card Components</h1>
                    <p class="text-gray-600 mb-6">Comprehensive card system with headers, footers, colors, and interactive styles</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Basic Card with Icon Header -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="card p-0 mb-4">
                                <div class="card-header">
                                    <div>
                                        <span class="card-header-icon">key</span>
                                        <h3>API Credentials</h3>
                                    </div>
                                    <div>
                                        Integration Settings
                                    </div>
                                </div>
                                <div class="card-body">
                                    <p class="text-sm text-gray-600">This is a basic card with an icon header and badge.</p>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card">
    <div class="card-header">
        <div>
            <span class="card-header-icon">key</span>
            <h3>API Credentials</h3>
        </div>
        <div>
            Integration Settings
        </div>
    </div>
    <div class="card-body">
        <p class="text-sm text-gray-600">Card content goes here.</p>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Card with Simple Header -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="card p-0 mb-4">
                                <div class="card-header">
                                    <div>
                                        <span class="card-header-icon">info</span>
                                        <h3>Module Information</h3>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="card-info-list">
                                        <div class="card-info-item">
                                            <span class="card-info-label">Module ID:</span>
                                            <span class="card-info-value">12345</span>
                                        </div>
                                        <div class="card-info-item">
                                            <span class="card-info-label">Status:</span>
                                            <span class="card-info-value text-green-600">Enabled</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card">
    <div class="card-header">
        <div>
            <span class="card-header-icon">info</span>
            <h3>Module Information</h3>
        </div>
    </div>
    <div class="card-body">
        <div class="card-info-list">
            <div class="card-info-item">
                <span class="card-info-label">Module ID:</span>
                <span class="card-info-value">12345</span>
            </div>
            <div class="card-info-item">
                <span class="card-info-label">Status:</span>
                <span class="card-info-value text-green-600">Enabled</span>
            </div>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Card with Footer -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="card p-0 mb-4">
                                <div class="card-header">
                                    <div>
                                        <span class="card-header-icon">science</span>
                                        <h3>API Testing</h3>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <p class="text-sm text-gray-600">Test your API credentials and connectivity with a single click.</p>
                                </div>
                                <div class="card-footer">
                                    <div class="flex items-center justify-end gap-2">
                                        <button class="btn light">Cancel</button>
                                        <button class="btn">Test Connection</button>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card">
    <div class="card-header">
        <div>
            <span class="card-header-icon">science</span>
            <h3>API Testing</h3>
        </div>
    </div>
    <div class="card-body">
        <p class="text-sm text-gray-600">Test your API credentials.</p>
    </div>
    <div class="card-footer">
        <div class="flex items-center justify-end gap-2">
            <button class="px-4 py-2 text-sm text-gray-600">Cancel</button>
            <button class="btn">Test Connection</button>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Colorful Gradient Cards (Blue) -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="card p-0 card-blue mb-4">
                                <div class="flex items-start gap-2 p-4">
                                    <span class="material-symbols-outlined card-icon">error</span>
                                    <div>
                                        <h3 class="card-title">Alerts</h3>
                                        <p class="card-text">Error, success, warning & info alerts</p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card p-0 card-blue">
    <div class="flex items-start gap-2 p-4">
        <span class="material-symbols-outlined card-icon">error</span>
        <div>
            <h3 class="card-title">Alerts</h3>
            <p class="card-text">Error, success, warning & info alerts</p>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Colorful Gradient Cards (Green) -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="card p-0 card-green mb-4">
                                <div class="flex items-start gap-2 p-4">
                                    <span class="material-symbols-outlined card-icon">input</span>
                                    <div>
                                        <h3 class="card-title">Forms</h3>
                                        <p class="card-text">Inputs, buttons & form elements</p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card p-0 card-green">
    <div class="flex items-start gap-2 p-4">
        <span class="material-symbols-outlined card-icon">input</span>
        <div>
            <h3 class="card-title">Forms</h3>
            <p class="card-text">Inputs, buttons & form elements</p>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Colorful Gradient Cards (Purple) -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="card p-0 card-purple mb-4">
                                <div class="flex items-start gap-2 p-4">
                                    <span class="material-symbols-outlined card-icon">view_agenda</span>
                                    <div>
                                        <h3 class="card-title">Layout</h3>
                                        <p class="card-text">Cards, modals & layout components</p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card p-0 card-purple">
    <div class="flex items-start gap-2 p-4">
        <span class="material-symbols-outlined card-icon">view_agenda</span>
        <div>
            <h3 class="card-title">Layout</h3>
            <p class="card-text">Cards, modals & layout components</p>
        </div>
    </div>
</div>

<!-- Available colors: -->
<!-- card-blue, card-green, card-purple, card-orange -->
<!-- card-red, card-indigo, card-pink, card-teal --></code></pre>
                            </div>
                        </div>

                        <!-- Card Without Icon -->
                        <div class="border-l-4 border-orange-500 pl-4">
                            <div class="card p-0 card-orange mb-4">
                                <div class="p-4">
                                    <h3 class="card-title mb-2">Dashboard</h3>
                                    <p class="card-text">Analytics & reporting widgets without icon</p>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card p-0 card-orange">
    <div class="p-4">
        <h3 class="card-title mb-2">Dashboard</h3>
        <p class="card-text">Analytics & reporting widgets</p>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Hover Card -->
                        <div class="border-l-4 border-indigo-500 pl-4">
                            <div class="card p-0 card-hover mb-4">
                                <div class="flex items-start gap-2 p-4">
                                    <span class="material-symbols-outlined text-blue-600">touch_app</span>
                                    <div>
                                        <h3 class="font-semibold text-gray-900">Hover Effect</h3>
                                        <p class="text-sm text-gray-600">This card lifts up when you hover over it.</p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card p-0 card-hover">
    <div class="flex items-start gap-2 p-4">
        <span class="material-symbols-outlined text-blue-600">touch_app</span>
        <div>
            <h3 class="font-semibold text-gray-900">Hover Effect</h3>
            <p class="text-sm text-gray-600">This card lifts up when you hover.</p>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Clickable Card -->
                        <div class="border-l-4 border-pink-500 pl-4">
                            <div class="card p-0 card-clickable mb-4">
                                <div class="flex items-start gap-2 p-4">
                                    <span class="material-symbols-outlined text-green-600">ads_click</span>
                                    <div>
                                        <h3 class="font-semibold text-gray-900">Clickable Card</h3>
                                        <p class="text-sm text-gray-600">This card changes border color on hover.</p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="card p-0 card-clickable">
    <div class="flex items-start gap-2 p-4">
        <span class="material-symbols-outlined text-green-600">ads_click</span>
        <div>
            <h3 class="font-semibold text-gray-900">Clickable Card</h3>
            <p class="text-sm text-gray-600">This card changes border on hover.</p>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Stat Cards -->
                        <div class="border-l-4 border-teal-500 pl-4">
                            <div class="cards-grid-4 mb-4">
                                <div class="card">
                                    <div class="card-stat">
                                        <div class="card-stat-value text-blue-600">1,234</div>
                                        <div class="card-stat-label">Total Bookings</div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-stat">
                                        <div class="card-stat-value text-green-600">$45,678</div>
                                        <div class="card-stat-label">Revenue</div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-stat">
                                        <div class="card-stat-value text-orange-600">856</div>
                                        <div class="card-stat-label">Active Users</div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-stat">
                                        <div class="card-stat-value text-purple-600">92%</div>
                                        <div class="card-stat-label">Satisfaction</div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="cards-grid-4">
    <div class="card">
        <div class="card-stat">
            <div class="card-stat-value text-blue-600">1,234</div>
            <div class="card-stat-label">Total Bookings</div>
        </div>
    </div>

    <div class="card">
        <div class="card-stat">
            <div class="card-stat-value text-green-600">$45,678</div>
            <div class="card-stat-label">Revenue</div>
        </div>
    </div>

    <div class="card">
        <div class="card-stat">
            <div class="card-stat-value text-orange-600">856</div>
            <div class="card-stat-label">Active Users</div>
        </div>
    </div>

    <div class="card">
        <div class="card-stat">
            <div class="card-stat-value text-purple-600">92%</div>
            <div class="card-stat-label">Satisfaction</div>
        </div>
    </div>
</div>

<!-- Grid Options: -->
<!-- .cards-grid (3 cols), .cards-grid-2 (2 cols), .cards-grid-4 (4 cols) --></code></pre>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
[x-cloak] { display: none !important; }
</style>
