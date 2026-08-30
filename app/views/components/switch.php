<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Switch Components</h1>
                    <p class="text-gray-600 mb-6">Toggle switch components with various styles, sizes, and interactive states.</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Basic Switch -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Basic Switch</h3>
                            <div class="space-y-4 mb-6">
                                <!-- Enabled Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Enabled Switch</span>
                                    </label>
                                </div>

                                <!-- Disabled Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue">
                                        <input type="checkbox" class="switch-input">
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Unchecked Switch</span>
                                    </label>
                                </div>

                                <!-- Actually Disabled State -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue disabled">
                                        <input type="checkbox" class="switch-input" disabled>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Disabled Switch</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Basic Switch -->
<label class="switch-container switch-md switch-blue">
    <input type="checkbox" class="switch-input" checked>
    <div class="switch-track">
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label">Switch Label</span>
</label></code></pre>
                            </div>
                        </div>

                        <!-- Switch Sizes -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Switch Sizes</h3>
                            <div class="space-y-6 mb-6">
                                <!-- Small Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-sm switch-blue">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label text-xs">Small Switch</span>
                                    </label>
                                </div>

                                <!-- Medium Switch (Default) -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Medium Switch (Default)</span>
                                    </label>
                                </div>

                                <!-- Large Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-lg switch-blue">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label text-base">Large Switch</span>
                                    </label>
                                </div>

                                <!-- Extra Large Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-xl switch-blue">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label text-lg">Extra Large Switch</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Small Switch -->
<label class="switch-container switch-sm switch-blue">
    <input type="checkbox" class="switch-input">
    <div class="switch-track">
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label text-xs">Small Switch</span>
</label>

<!-- Large Switch -->
<label class="switch-container switch-lg switch-blue">
    <input type="checkbox" class="switch-input">
    <div class="switch-track">
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label text-base">Large Switch</span>
</label></code></pre>
                            </div>
                        </div>

                        <!-- Switch Colors -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Switch Colors</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <!-- Blue Switch (Default) -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Blue Switch</span>
                                    </label>
                                </div>

                                <!-- Green Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-green">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Green Switch</span>
                                    </label>
                                </div>

                                <!-- Red Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-red">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Red Switch</span>
                                    </label>
                                </div>

                                <!-- Purple Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-purple">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Purple Switch</span>
                                    </label>
                                </div>

                                <!-- Yellow Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-yellow">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Yellow Switch</span>
                                    </label>
                                </div>

                                <!-- Pink Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-pink">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Pink Switch</span>
                                    </label>
                                </div>

                                <!-- Indigo Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-indigo">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Indigo Switch</span>
                                    </label>
                                </div>

                                <!-- Teal Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-teal">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Teal Switch</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Green Switch -->
<label class="switch-container switch-md switch-green">
    <input type="checkbox" class="switch-input">
    <div class="switch-track">
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label">Green Switch</span>
</label>

<!-- Red Switch -->
<label class="switch-container switch-md switch-red">
    <input type="checkbox" class="switch-input">
    <div class="switch-track">
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label">Red Switch</span>
</label></code></pre>
                            </div>
                        </div>

                        <!-- Switch with Icons -->
                        <div class="border-l-4 border-orange-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Switch with Icons</h3>
                            <div class="space-y-4 mb-6">
                                <!-- Dark Mode Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue switch-with-icons">
                                        <input type="checkbox" class="switch-input">
                                        <div class="switch-track">
                                            <span class="switch-icon-left material-symbols-outlined">light_mode</span>
                                            <span class="switch-icon-right material-symbols-outlined">dark_mode</span>
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Dark Mode</span>
                                    </label>
                                </div>

                                <!-- Notifications Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-green switch-with-icons">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <span class="switch-icon-left material-symbols-outlined">notifications_off</span>
                                            <span class="switch-icon-right material-symbols-outlined">notifications</span>
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Notifications</span>
                                    </label>
                                </div>

                                <!-- WiFi Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue switch-with-icons">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <span class="switch-icon-left material-symbols-outlined">wifi_off</span>
                                            <span class="switch-icon-right material-symbols-outlined">wifi</span>
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">WiFi</span>
                                    </label>
                                </div>

                                <!-- Volume Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-purple switch-with-icons">
                                        <input type="checkbox" class="switch-input">
                                        <div class="switch-track">
                                            <span class="switch-icon-left material-symbols-outlined">volume_off</span>
                                            <span class="switch-icon-right material-symbols-outlined">volume_up</span>
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Volume</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Switch with Icons -->
<label class="switch-container switch-md switch-blue switch-with-icons">
    <input type="checkbox" class="switch-input">
    <div class="switch-track">
        <span class="switch-icon-left material-symbols-outlined">light_mode</span>
        <span class="switch-icon-right material-symbols-outlined">dark_mode</span>
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label">Dark Mode</span>
</label></code></pre>
                            </div>
                        </div>

                        <!-- Switch Variants -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Switch Variants</h3>
                            <div class="space-y-6 mb-6">
                                <!-- Outlined Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue switch-outlined">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Outlined Switch</span>
                                    </label>
                                </div>

                                <!-- Rounded Square Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue switch-rounded-square">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Rounded Square Switch</span>
                                    </label>
                                </div>

                                <!-- Gradient Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-gradient">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Gradient Switch</span>
                                    </label>
                                </div>

                                <!-- Shadow Switch -->
                                <div class="flex items-center">
                                    <label class="switch-container switch-md switch-blue switch-shadow">
                                        <input type="checkbox" class="switch-input" checked>
                                        <div class="switch-track">
                                            <div class="switch-thumb"></div>
                                        </div>
                                        <span class="switch-label">Shadow Switch</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Outlined Switch -->
<label class="switch-container switch-md switch-blue switch-outlined">
    <input type="checkbox" class="switch-input">
    <div class="switch-track">
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label">Outlined Switch</span>
</label>

<!-- Gradient Switch -->
<label class="switch-container switch-md switch-gradient">
    <input type="checkbox" class="switch-input">
    <div class="switch-track">
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label">Gradient Switch</span>
</label></code></pre>
                            </div>
                        </div>

                        <!-- Interactive Switch with AlpineJS -->
                        <div class="border-l-4 border-yellow-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Interactive Switch with AlpineJS</h3>
                            <div class="space-y-6 mb-6" x-data="{
                                isEnabled: false,
                                message: 'Feature is disabled',
                                count: 0,
                                isDarkMode: false
                            }">
                                <!-- Basic Interactive Switch -->
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <div class="flex items-center mb-4">
                                        <label class="switch-container switch-md switch-blue">
                                            <input type="checkbox" class="switch-input" x-model="isEnabled" @change="message = isEnabled ? 'Feature is enabled!' : 'Feature is disabled'">
                                            <div class="switch-track">
                                                <div class="switch-thumb"></div>
                                            </div>
                                            <span class="switch-label">Enable Feature</span>
                                        </label>
                                    </div>

                                    <div class="p-3 rounded-lg transition-colors" :class="isEnabled ? 'bg-green-100 border border-green-300' : 'bg-red-100 border border-red-300'">
                                        <p class="text-sm font-medium transition-colors" :class="isEnabled ? 'text-green-800' : 'text-red-800'" x-text="message"></p>
                                    </div>
                                </div>

                                <!-- Counter Switch -->
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <div class="flex items-center mb-4">
                                        <label class="switch-container switch-md switch-purple">
                                            <input type="checkbox" class="switch-input" @change="count = $event.target.checked ? count + 1 : count">
                                            <div class="switch-track">
                                                <div class="switch-thumb"></div>
                                            </div>
                                            <span class="switch-label">Counter Switch</span>
                                        </label>
                                    </div>

                                    <div class="p-3 bg-purple-100 border border-purple-300 rounded-lg">
                                        <p class="text-sm font-medium text-purple-800">Switch toggled <span x-text="count"></span> times</p>
                                    </div>
                                </div>

                                <!-- Dark Mode Switch -->
                                <div class="p-4 rounded-lg transition-colors" :class="isDarkMode ? 'bg-gray-800' : 'bg-gray-50'">
                                    <div class="flex items-center mb-4">
                                        <label class="switch-container switch-md switch-blue switch-with-icons">
                                            <input type="checkbox" class="switch-input" x-model="isDarkMode">
                                            <div class="switch-track">
                                                <span class="switch-icon-left material-symbols-outlined">light_mode</span>
                                                <span class="switch-icon-right material-symbols-outlined">dark_mode</span>
                                                <div class="switch-thumb"></div>
                                            </div>
                                            <span class="switch-label transition-colors" :class="isDarkMode ? 'text-white' : 'text-gray-700'">Dark Mode</span>
                                        </label>
                                    </div>

                                    <div class="p-3 rounded-lg transition-colors" :class="isDarkMode ? 'bg-gray-700 border border-gray-600' : 'bg-white border border-gray-300'">
                                        <p class="text-sm font-medium transition-colors" :class="isDarkMode ? 'text-gray-200' : 'text-gray-800'" x-text="isDarkMode ? 'Dark mode is active 🌙' : 'Light mode is active ☀️'"></p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Interactive Switch with AlpineJS -->
<div x-data="{
    isEnabled: false,
    message: 'Feature is disabled'
}">
    <label class="switch-container switch-md switch-blue">
        <input type="checkbox" class="switch-input" x-model="isEnabled" @change="message = isEnabled ? 'Feature is enabled!' : 'Feature is disabled'">
        <div class="switch-track">
            <div class="switch-thumb"></div>
        </div>
        <span class="switch-label">Enable Feature</span>
    </label>

    <div class="p-3 rounded-lg transition-colors" :class="isEnabled ? 'bg-green-100 border border-green-300' : 'bg-red-100 border border-red-300'">
        <p class="text-sm font-medium transition-colors" :class="isEnabled ? 'text-green-800' : 'text-red-800'" x-text="message"></p>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Form Integration -->
                        <div class="border-l-4 border-teal-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Form Integration</h3>
                            <div class="mb-6">
                                <form class="space-y-6 p-6 bg-gray-50 rounded-lg" x-data="{
                                    formData: {
                                        notifications: true,
                                        newsletter: false,
                                        marketing: false,
                                        analytics: true
                                    }
                                }">
                                    <h4 class="text-lg font-medium text-gray-900 mb-4">User Preferences</h4>

                                    <div class="space-y-4">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <label class="text-sm font-medium text-gray-700">Email Notifications</label>
                                                <p class="text-xs text-gray-500">Receive important updates via email</p>
                                            </div>
                                            <label class="switch-container switch-md switch-blue">
                                                <input type="checkbox" name="notifications" class="switch-input" x-model="formData.notifications">
                                                <div class="switch-track">
                                                    <div class="switch-thumb"></div>
                                                </div>
                                            </label>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <div>
                                                <label class="text-sm font-medium text-gray-700">Newsletter</label>
                                                <p class="text-xs text-gray-500">Weekly newsletter with updates</p>
                                            </div>
                                            <label class="switch-container switch-md switch-green">
                                                <input type="checkbox" name="newsletter" class="switch-input" x-model="formData.newsletter">
                                                <div class="switch-track">
                                                    <div class="switch-thumb"></div>
                                                </div>
                                            </label>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <div>
                                                <label class="text-sm font-medium text-gray-700">Marketing Communications</label>
                                                <p class="text-xs text-gray-500">Promotional offers and updates</p>
                                            </div>
                                            <label class="switch-container switch-md switch-purple">
                                                <input type="checkbox" name="marketing" class="switch-input" x-model="formData.marketing">
                                                <div class="switch-track">
                                                    <div class="switch-thumb"></div>
                                                </div>
                                            </label>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <div>
                                                <label class="text-sm font-medium text-gray-700">Analytics</label>
                                                <p class="text-xs text-gray-500">Help improve our service</p>
                                            </div>
                                            <label class="switch-container switch-md switch-orange">
                                                <input type="checkbox" name="analytics" class="switch-input" x-model="formData.analytics">
                                                <div class="switch-track">
                                                    <div class="switch-thumb"></div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="pt-4 border-t border-gray-200">
                                        <h5 class="text-sm font-medium text-gray-700 mb-2">Current Settings:</h5>
                                        <div class="text-xs text-gray-600">
                                            <pre x-text="JSON.stringify(formData, null, 2)"></pre>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Form Integration -->
<form x-data="{ formData: { notifications: true, newsletter: false } }">
    <div class="flex items-center justify-between">
        <div>
            <label class="text-sm font-medium text-gray-700">Email Notifications</label>
            <p class="text-xs text-gray-500">Receive important updates via email</p>
        </div>
        <label class="switch-container switch-md switch-blue">
            <input type="checkbox" name="notifications" class="switch-input" x-model="formData.notifications">
            <div class="switch-track">
                <div class="switch-thumb"></div>
            </div>
        </label>
    </div>
</form></code></pre>
                            </div>
                        </div>

                        <!-- Usage Guide -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Usage Guide</h3>
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                                <h4 class="text-sm font-medium text-blue-900 mb-2">Quick Usage</h4>
                                <p class="text-xs text-blue-800 mb-3">Use these utility classes for easy switch implementation:</p>
                                <ul class="text-xs text-blue-800 space-y-1">
                                    <li><strong>Sizes:</strong> <code>switch-sm</code>, <code>switch-md</code>, <code>switch-lg</code>, <code>switch-xl</code></li>
                                    <li><strong>Colors:</strong> <code>switch-blue</code>, <code>switch-green</code>, <code>switch-red</code>, <code>switch-purple</code>, etc.</li>
                                    <li><strong>Variants:</strong> <code>switch-outlined</code>, <code>switch-gradient</code>, <code>switch-shadow</code></li>
                                    <li><strong>Icons:</strong> <code>switch-with-icons</code> (requires icon spans)</li>
                                    <li><strong>Disabled:</strong> Add <code>disabled</code> class to container</li>
                                </ul>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Minimal Switch -->
<label class="switch-container switch-md switch-blue">
    <input type="checkbox" class="switch-input">
    <div class="switch-track">
        <div class="switch-thumb"></div>
    </div>
    <span class="switch-label">Label</span>
</label></code></pre>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
    