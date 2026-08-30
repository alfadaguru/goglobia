<div class="container mx-auto">
<div class="flex min-h-screen">
    <?php require_once "app/views/components/sidebar.php"; ?>

    <!-- Minimal CSS for container layout -->
    <style>
    /* Ensure content stays within container */
    .main-content {
        flex: 1;
        min-width: 0; /* Allow flex item to shrink */
        overflow-x: hidden; /* Prevent horizontal overflow */
    }

    /* Content area padding and width control */
    .content-wrapper {
        max-width: 100%;
        overflow-x: hidden;
    }
    </style>

<!-- Dashboard Component -->
<div class="main-content bg-gray-50">
    <div class="content-wrapper p-6">
        <!-- Dashboard Content -->
        <div class="bg-white rounded-lg p-6 shadow-sm">
            <h1 class="text-2xl font-bold text-gray-900 mb-4">Component Library</h1>
            <p class="text-gray-600 mb-6">Welcome to the PHPTRAVELS component library. Select a component from the sidebar to view examples and implementation details.</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="<?= root ?>components/alerts" class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg hover:from-blue-100 hover:to-blue-200 transition-colors">
                    <div class="flex items-center mb-2">
                        <span class="material-symbols-outlined text-blue-600 mr-2">error</span>
                        <h3 class="font-semibold text-blue-900">Alerts</h3>
                    </div>
                    <p class="text-sm text-blue-700">Error, success, warning & info</p>
                </a>

                <a href="<?= root ?>components/input" class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg hover:from-green-100 hover:to-green-200 transition-colors">
                    <div class="flex items-center mb-2">
                        <span class="material-symbols-outlined text-green-600 mr-2">input</span>
                        <h3 class="font-semibold text-green-900">Input Fields</h3>
                    </div>
                    <p class="text-sm text-green-700">Text inputs with icons & validation</p>
                </a>

                <a href="<?= root ?>components/select" class="bg-gradient-to-br from-purple-50 to-purple-100 p-4 rounded-lg hover:from-purple-100 hover:to-purple-200 transition-colors">
                    <div class="flex items-center mb-2">
                        <span class="material-symbols-outlined text-purple-600 mr-2">arrow_drop_down_circle</span>
                        <h3 class="font-semibold text-purple-900">Select Fields</h3>
                    </div>
                    <p class="text-sm text-purple-700">Dropdowns, multi-select & search</p>
                </a>
            </div>

            <!-- Second Row -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <a href="<?= root ?>components/textarea" class="bg-gradient-to-br from-indigo-50 to-indigo-100 p-4 rounded-lg hover:from-indigo-100 hover:to-indigo-200 transition-colors">
                    <div class="flex items-center mb-2">
                        <span class="material-symbols-outlined text-indigo-600 mr-2">text_fields</span>
                        <h3 class="font-semibold text-indigo-900">Textarea Fields</h3>
                    </div>
                    <p class="text-sm text-indigo-700">Multi-line text with features</p>
                </a>

                <a href="<?= root ?>components/buttons" class="bg-gradient-to-br from-cyan-50 to-cyan-100 p-4 rounded-lg hover:from-cyan-100 hover:to-cyan-200 transition-colors">
                    <div class="flex items-center mb-2">
                        <span class="material-symbols-outlined text-cyan-600 mr-2">smart_button</span>
                        <h3 class="font-semibold text-cyan-900">Buttons</h3>
                    </div>
                    <p class="text-sm text-cyan-700">Button styles & variations</p>
                </a>

                <a href="<?= root ?>components/datepicker" class="bg-gradient-to-br from-orange-50 to-orange-100 p-4 rounded-lg hover:from-orange-100 hover:to-orange-200 transition-colors">
                    <div class="flex items-center mb-2">
                        <span class="material-symbols-outlined text-orange-600 mr-2">calendar_today</span>
                        <h3 class="font-semibold text-orange-900">Datepicker</h3>
                    </div>
                    <p class="text-sm text-orange-700">Date selection & range pickers</p>
                </a>
            </div>

            <!-- Third Row -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <a href="<?= root ?>components/notifications" class="bg-gradient-to-br from-amber-50 to-amber-100 p-4 rounded-lg hover:from-amber-100 hover:to-amber-200 transition-colors">
                    <div class="flex items-center mb-2">
                        <span class="material-symbols-outlined text-amber-600 mr-2">notifications</span>
                        <h3 class="font-semibold text-amber-900">Notifications</h3>
                    </div>
                    <p class="text-sm text-amber-700">Toast notifications & alerts</p>
                </a>
            </div>

            <!-- Quick Stats -->
            <div class="mt-8 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-gray-900">15</div>
                    <div class="text-sm text-gray-600">Components</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-gray-900">4</div>
                    <div class="text-sm text-gray-600">Categories</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-gray-900">100%</div>
                    <div class="text-sm text-gray-600">Responsive</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="text-2xl font-bold text-gray-900">Pure</div>
                    <div class="text-sm text-gray-600">Tailwind</div>
                </div>
            </div>

            <!-- Recent Updates -->
            <div class="mt-8">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Recent Updates</h2>
                <div class="space-y-3">
                    <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                        <span class="material-symbols-outlined text-orange-600 mr-3">calendar_today</span>
                        <div>
                            <div class="font-medium text-gray-900">Datepicker Components</div>
                            <div class="text-sm text-gray-600">Universal Alpine.js datepicker with range selection</div>
                        </div>
                    </div>
                    <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                        <span class="material-symbols-outlined text-green-600 mr-3">check_circle</span>
                        <div>
                            <div class="font-medium text-gray-900">Alert Components</div>
                            <div class="text-sm text-gray-600">Updated with Material Icons and custom classes</div>
                        </div>
                    </div>
                    <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                        <span class="material-symbols-outlined text-blue-600 mr-3">palette</span>
                        <div>
                            <div class="font-medium text-gray-900">Design System</div>
                            <div class="text-sm text-gray-600">Consistent color scheme and typography</div>
                        </div>
                    </div>
                    <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                        <span class="material-symbols-outlined text-purple-600 mr-3">code</span>
                        <div>
                            <div class="font-medium text-gray-900">Code Examples</div>
                            <div class="text-sm text-gray-600">Copy-ready HTML snippets with syntax highlighting</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</div>
</div>