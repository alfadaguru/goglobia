<!-- Tabs Component -->
<div class="flex-1 bg-gray-50">
    <div class="p-6">
        <!-- Tab Components Section -->
        <div class="bg-white rounded-lg shadow-sm">
            <!-- Section Header -->
            <div class="border-b px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-900">Tab Components</h2>
                <p class="text-sm text-gray-600 mt-1">Tab navigation and content panels</p>
            </div>

            <!-- Tabs -->
            <div class="border-b">
                <nav class="flex px-6">
                    <button class="tab-btn active px-4 py-3 text-sm font-medium border-b-2 border-blue-500 text-blue-600" data-tab="view">View</button>
                    <button class="tab-btn px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700" data-tab="html">HTML</button>
                </nav>
            </div>

            <!-- Tab Content -->
            <div class="p-6">
                <!-- View Tab -->
                <div class="tab-content" id="view-content" style="display: block;">
                    <div class="space-y-8">
                        <!-- Basic Tabs -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Tabs</h3>
                            <div class="tabs-container">
                                <div class="tabs-list">
                                    <button class="tabs-trigger active" data-target="tab1">Overview</button>
                                    <button class="tabs-trigger" data-target="tab2">Analytics</button>
                                    <button class="tabs-trigger" data-target="tab3">Reports</button>
                                    <button class="tabs-trigger" data-target="tab4">Settings</button>
                                </div>
                                <div class="tabs-content active" id="tab1">
                                    <h3>Overview</h3>
                                    <p>This is the overview tab content. Here you can see general information about your account and recent activity.</p>
                                </div>
                                <div class="tabs-content" id="tab2">
                                    <h3>Analytics</h3>
                                    <p>Analytics tab content shows detailed statistics, charts, and data visualization about your performance metrics.</p>
                                </div>
                                <div class="tabs-content" id="tab3">
                                    <h3>Reports</h3>
                                    <p>Reports section contains downloadable reports, scheduled reports, and custom report generation tools.</p>
                                </div>
                                <div class="tabs-content" id="tab4">
                                    <h3>Settings</h3>
                                    <p>Settings tab allows you to configure your preferences, account settings, and application options.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Tabs with Icons -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Tabs with Icons</h3>
                            <div class="tabs-container">
                                <div class="tabs-list">
                                    <button class="tabs-trigger active" data-target="icon-tab1">
                                        <span class="material-symbols-outlined text-sm mr-2">dashboard</span>
                                        Dashboard
                                    </button>
                                    <button class="tabs-trigger" data-target="icon-tab2">
                                        <span class="material-symbols-outlined text-sm mr-2">people</span>
                                        Users
                                    </button>
                                    <button class="tabs-trigger" data-target="icon-tab3">
                                        <span class="material-symbols-outlined text-sm mr-2">settings</span>
                                        Settings
                                    </button>
                                </div>
                                <div class="tabs-content active" id="icon-tab1">
                                    <h3>Dashboard</h3>
                                    <p>Dashboard overview with key metrics and quick actions for managing your account.</p>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                        <div class="bg-blue-50 p-4 rounded-lg">
                                            <div class="text-2xl font-bold text-blue-600">1,234</div>
                                            <div class="text-sm text-gray-600">Total Users</div>
                                        </div>
                                        <div class="bg-green-50 p-4 rounded-lg">
                                            <div class="text-2xl font-bold text-green-600">$45,230</div>
                                            <div class="text-sm text-gray-600">Revenue</div>
                                        </div>
                                        <div class="bg-purple-50 p-4 rounded-lg">
                                            <div class="text-2xl font-bold text-purple-600">567</div>
                                            <div class="text-sm text-gray-600">Orders</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tabs-content" id="icon-tab2">
                                    <h3>Users</h3>
                                    <p>Manage user accounts, permissions, and user-related settings.</p>
                                    <div class="space-y-3 mt-4">
                                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-sm text-blue-600">person</span>
                                                </div>
                                                <div>
                                                    <p class="font-medium">John Smith</p>
                                                    <p class="text-sm text-gray-500">john@example.com</p>
                                                </div>
                                            </div>
                                            <span class="badge badge-success">Active</span>
                                        </div>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-sm text-green-600">person</span>
                                                </div>
                                                <div>
                                                    <p class="font-medium">Sarah Johnson</p>
                                                    <p class="text-sm text-gray-500">sarah@example.com</p>
                                                </div>
                                            </div>
                                            <span class="badge badge-success">Active</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="tabs-content" id="icon-tab3">
                                    <h3>Settings</h3>
                                    <p>Configure your application preferences and account settings.</p>
                                    <div class="space-y-4 mt-4">
                                        <div class="form-control">
                                            <label for="app-name">Application Name</label>
                                            <input type="text" id="app-name" class="input" value="My Application">
                                        </div>
                                        <div class="form-control">
                                            <label for="timezone">Timezone</label>
                                            <select id="timezone" class="select">
                                                <option>UTC</option>
                                                <option selected>America/New_York</option>
                                                <option>Europe/London</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Vertical Tabs -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Vertical Tabs Example</h3>
                            <div class="flex bg-gray-50 rounded-lg p-4">
                                <div class="w-48 pr-4">
                                    <div class="space-y-1">
                                        <button class="vertical-tab active w-full text-left px-3 py-2 rounded-md text-sm font-medium bg-white text-gray-900 shadow-sm" data-target="vertical-tab1">
                                            <span class="material-symbols-outlined text-sm mr-2">account_circle</span>
                                            Profile
                                        </button>
                                        <button class="vertical-tab w-full text-left px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-white hover:text-gray-900" data-target="vertical-tab2">
                                            <span class="material-symbols-outlined text-sm mr-2">security</span>
                                            Security
                                        </button>
                                        <button class="vertical-tab w-full text-left px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-white hover:text-gray-900" data-target="vertical-tab3">
                                            <span class="material-symbols-outlined text-sm mr-2">notifications</span>
                                            Notifications
                                        </button>
                                        <button class="vertical-tab w-full text-left px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-white hover:text-gray-900" data-target="vertical-tab4">
                                            <span class="material-symbols-outlined text-sm mr-2">help</span>
                                            Help
                                        </button>
                                    </div>
                                </div>
                                <div class="flex-1 bg-white rounded-lg p-4">
                                    <div class="vertical-tab-content active" id="vertical-tab1">
                                        <h4 class="font-semibold mb-3">Profile Settings</h4>
                                        <p class="text-gray-600 mb-4">Manage your personal information and profile preferences.</p>
                                        <div class="space-y-3">
                                            <div class="form-control">
                                                <label for="full-name">Full Name</label>
                                                <input type="text" id="full-name" class="input" value="John Smith">
                                            </div>
                                            <div class="form-control">
                                                <label for="email">Email</label>
                                                <input type="email" id="email" class="input" value="john@example.com">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="vertical-tab-content" id="vertical-tab2">
                                        <h4 class="font-semibold mb-3">Security Settings</h4>
                                        <p class="text-gray-600 mb-4">Configure your account security and authentication preferences.</p>
                                        <div class="space-y-3">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="font-medium">Two-Factor Authentication</p>
                                                    <p class="text-sm text-gray-500">Add an extra layer of security</p>
                                                </div>
                                                <button class="btn btn-sm">Enable</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="vertical-tab-content" id="vertical-tab3">
                                        <h4 class="font-semibold mb-3">Notification Preferences</h4>
                                        <p class="text-gray-600 mb-4">Choose how you want to receive notifications.</p>
                                        <div class="space-y-3">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="font-medium">Email Notifications</p>
                                                    <p class="text-sm text-gray-500">Receive updates via email</p>
                                                </div>
                                                <input type="checkbox" class="checkbox" checked>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="vertical-tab-content" id="vertical-tab4">
                                        <h4 class="font-semibold mb-3">Help & Support</h4>
                                        <p class="text-gray-600 mb-4">Get help and find answers to common questions.</p>
                                        <div class="space-y-3">
                                            <a href="#" class="block p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                                                <p class="font-medium">Documentation</p>
                                                <p class="text-sm text-gray-500">Browse our comprehensive guides</p>
                                            </a>
                                            <a href="#" class="block p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                                                <p class="font-medium">Contact Support</p>
                                                <p class="text-sm text-gray-500">Get in touch with our team</p>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- HTML Tab -->
                <div class="tab-content" id="html-content" style="display: none;">
                    <div class="space-y-6">
                        <!-- Basic Tabs HTML -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Basic Tabs HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="tabs-html">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="tabs-html">
<span class="text-blue-400">&lt;div class="tabs-container"&gt;</span>
    <span class="text-blue-400">&lt;div class="tabs-list"&gt;</span>
        <span class="text-blue-400">&lt;button class="tabs-trigger active" data-target="tab1"&gt;</span>Tab 1<span class="text-blue-400">&lt;/button&gt;</span>
        <span class="text-blue-400">&lt;button class="tabs-trigger" data-target="tab2"&gt;</span>Tab 2<span class="text-blue-400">&lt;/button&gt;</span>
    <span class="text-blue-400">&lt;/div&gt;</span>
    <span class="text-blue-400">&lt;div class="tabs-content active" id="tab1"&gt;</span>
        <span class="text-blue-400">&lt;h3&gt;</span>Tab 1 Content<span class="text-blue-400">&lt;/h3&gt;</span>
        <span class="text-blue-400">&lt;p&gt;</span>Content for tab 1<span class="text-blue-400">&lt;/p&gt;</span>
    <span class="text-blue-400">&lt;/div&gt;</span>
    <span class="text-blue-400">&lt;div class="tabs-content" id="tab2"&gt;</span>
        <span class="text-blue-400">&lt;h3&gt;</span>Tab 2 Content<span class="text-blue-400">&lt;/h3&gt;</span>
        <span class="text-blue-400">&lt;p&gt;</span>Content for tab 2<span class="text-blue-400">&lt;/p&gt;</span>
    <span class="text-blue-400">&lt;/div&gt;</span>
<span class="text-blue-400">&lt;/div&gt;</span>
                            </div>
                        </div>

                        <!-- JavaScript for Tabs -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">JavaScript for Tabs</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="tabs-js">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="tabs-js">
<span class="text-green-400">// Tab functionality</span>
<span class="text-yellow-300">document</span>.<span class="text-blue-400">querySelectorAll</span>(<span class="text-red-300">'.tabs-trigger'</span>).<span class="text-blue-400">forEach</span>(<span class="text-yellow-300">trigger</span> <span class="text-white">=&gt;</span> {
    <span class="text-yellow-300">trigger</span>.<span class="text-blue-400">addEventListener</span>(<span class="text-red-300">'click'</span>, () <span class="text-white">=&gt;</span> {
        <span class="text-green-400">// Remove active from all triggers</span>
        <span class="text-yellow-300">document</span>.<span class="text-blue-400">querySelectorAll</span>(<span class="text-red-300">'.tabs-trigger'</span>).<span class="text-blue-400">forEach</span>(<span class="text-yellow-300">t</span> <span class="text-white">=&gt;</span> <span class="text-yellow-300">t</span>.<span class="text-yellow-300">classList</span>.<span class="text-blue-400">remove</span>(<span class="text-red-300">'active'</span>));
        <span class="text-green-400">// Hide all content</span>
        <span class="text-yellow-300">document</span>.<span class="text-blue-400">querySelectorAll</span>(<span class="text-red-300">'.tabs-content'</span>).<span class="text-blue-400">forEach</span>(<span class="text-yellow-300">c</span> <span class="text-white">=&gt;</span> <span class="text-yellow-300">c</span>.<span class="text-yellow-300">classList</span>.<span class="text-blue-400">remove</span>(<span class="text-red-300">'active'</span>));
        <span class="text-green-400">// Activate clicked trigger</span>
        <span class="text-yellow-300">trigger</span>.<span class="text-yellow-300">classList</span>.<span class="text-blue-400">add</span>(<span class="text-red-300">'active'</span>);
        <span class="text-green-400">// Show target content</span>
        <span class="text-yellow-300">document</span>.<span class="text-blue-400">getElementById</span>(<span class="text-yellow-300">trigger</span>.<span class="text-yellow-300">dataset</span>.<span class="text-yellow-300">target</span>).<span class="text-yellow-300">classList</span>.<span class="text-blue-400">add</span>(<span class="text-red-300">'active'</span>);
    });
});
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for tabs and copy functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Main Tab functionality (View/HTML tabs)
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const tabName = button.dataset.tab;
            
            // Update button states
            tabButtons.forEach(btn => {
                btn.classList.remove('active', 'border-blue-500', 'text-blue-600');
                btn.classList.add('border-transparent', 'text-gray-500');
            });
            button.classList.add('active', 'border-blue-500', 'text-blue-600');
            button.classList.remove('border-transparent', 'text-gray-500');
            
            // Update content
            tabContents.forEach(content => {
                content.style.display = 'none';
            });
            document.getElementById(tabName + '-content').style.display = 'block';
        });
    });

    // Demo Tabs functionality
    document.querySelectorAll('.tabs-trigger').forEach(trigger => {
        trigger.addEventListener('click', () => {
            const targetId = trigger.dataset.target;
            const container = trigger.closest('.tabs-container');
            
            // Remove active from all triggers in this container
            container.querySelectorAll('.tabs-trigger').forEach(t => t.classList.remove('active'));
            // Hide all content in this container
            container.querySelectorAll('.tabs-content').forEach(c => c.classList.remove('active'));
            
            // Activate clicked trigger
            trigger.classList.add('active');
            // Show target content
            document.getElementById(targetId).classList.add('active');
        });
    });

    // Vertical Tabs functionality
    document.querySelectorAll('.vertical-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const targetId = tab.dataset.target;
            
            // Remove active from all vertical tabs
            document.querySelectorAll('.vertical-tab').forEach(t => {
                t.classList.remove('active', 'bg-white', 'text-gray-900', 'shadow-sm');
                t.classList.add('text-gray-600', 'hover:bg-white', 'hover:text-gray-900');
            });
            // Hide all vertical tab content
            document.querySelectorAll('.vertical-tab-content').forEach(c => c.classList.remove('active'));
            
            // Activate clicked tab
            tab.classList.add('active', 'bg-white', 'text-gray-900', 'shadow-sm');
            tab.classList.remove('text-gray-600', 'hover:bg-white', 'hover:text-gray-900');
            // Show target content
            document.getElementById(targetId).classList.add('active');
        });
    });
    
    // Copy functionality
    const copyButtons = document.querySelectorAll('.copy-btn');
    copyButtons.forEach(button => {
        button.addEventListener('click', () => {
            const targetId = button.dataset.copy;
            const targetElement = document.getElementById(targetId);
            if (targetElement) {
                const textToCopy = targetElement.textContent;
                navigator.clipboard.writeText(textToCopy).then(() => {
                    // Update button text temporarily
                    const originalText = button.innerHTML;
                    button.innerHTML = '<span class="material-symbols-outlined text-sm mr-1">check</span>Copied';
                    button.classList.add('bg-green-100', 'text-green-700');
                    
                    setTimeout(() => {
                        button.innerHTML = originalText;
                        button.classList.remove('bg-green-100', 'text-green-700');
                    }, 2000);
                });
            }
        });
    });
});
</script>

<style>
.vertical-tab-content {
    display: none;
}
.vertical-tab-content.active {
    display: block;
}
</style>