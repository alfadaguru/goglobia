<!-- Main Content -->
<div class="flex-1 bg-gray-50">
    <div class="p-6">
        <!-- Dashboard Section -->
        <section id="dashboard-section" class="component-section">
            <div class="bg-white rounded-lg p-6 shadow-sm">
                <h1 class="text-2xl font-bold text-gray-900 mb-4">Component Library</h1>
                <p class="text-gray-600 mb-6">Welcome to the PHPTRAVELS component library. Select a component from the sidebar to view examples and implementation details.</p>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg">
                        <div class="flex items-center mb-2">
                            <span class="material-symbols-outlined text-blue-600 mr-2">error</span>
                            <h3 class="font-semibold text-blue-900">Alerts</h3>
                        </div>
                        <p class="text-sm text-blue-700">Error, success, warning & info alerts</p>
                    </div>
                    <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg">
                        <div class="flex items-center mb-2">
                            <span class="material-symbols-outlined text-green-600 mr-2">input</span>
                            <h3 class="font-semibold text-green-900">Forms</h3>
                        </div>
                        <p class="text-sm text-green-700">Inputs, buttons & form elements</p>
                    </div>
                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-4 rounded-lg">
                        <div class="flex items-center mb-2">
                            <span class="material-symbols-outlined text-purple-600 mr-2">view_agenda</span>
                            <h3 class="font-semibold text-purple-900">Layout</h3>
                        </div>
                        <p class="text-sm text-purple-700">Cards, modals & layout components</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Alert Messages Section -->
        <section id="alerts-section" class="component-section hidden">
            <div class="bg-white rounded-lg shadow-sm">
                <!-- Section Header -->
                <div class="border-b px-6 py-4">
                    <h2 class="text-xl font-semibold text-gray-900">Alert Messages</h2>
                    <p class="text-sm text-gray-600 mt-1">Pre-built alert classes for different message types</p>
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
                    <div class="tab-content block" id="view-content">
                        <div class="space-y-6">
                            <!-- Error Alert -->
                            <div>
                                <h3 class="text-sm font-medium text-gray-900 mb-3">Error Alert</h3>
                                <div class="alert alert-error">
                                    <div class="alert-content">
                                        <div class="alert-icon">
                                            <span class="material-symbols-outlined text-lg">error</span>
                                        </div>
                                        <div class="alert-text">
                                            <p>Invalid or expired security token. Please try again.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Success Alert -->
                            <div>
                                <h3 class="text-sm font-medium text-gray-900 mb-3">Success Alert</h3>
                                <div class="alert alert-success">
                                    <div class="alert-content">
                                        <div class="alert-icon">
                                            <span class="material-symbols-outlined text-lg">check_circle</span>
                                        </div>
                                        <div class="alert-text">
                                            <p>Password reset instructions sent to your email</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Warning Alert -->
                            <div>
                                <h3 class="text-sm font-medium text-gray-900 mb-3">Warning Alert</h3>
                                <div class="alert alert-warning">
                                    <div class="alert-content">
                                        <div class="alert-icon">
                                            <span class="material-symbols-outlined text-lg">warning</span>
                                        </div>
                                        <div class="alert-text">
                                            <p>Please review your booking details before confirming</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Info Alert -->
                            <div>
                                <h3 class="text-sm font-medium text-gray-900 mb-3">Info Alert</h3>
                                <div class="alert alert-info">
                                    <div class="alert-content">
                                        <div class="alert-icon">
                                            <span class="material-symbols-outlined text-lg">info</span>
                                        </div>
                                        <div class="alert-text">
                                            <p>Your booking confirmation will be sent within 24 hours</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- HTML Tab -->
                    <div class="tab-content hidden" id="html-content">
                        <div class="space-y-6">
                            <!-- Error Alert HTML -->
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-sm font-medium text-gray-900">Error Alert HTML</h3>
                                    <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="error-html">
                                        <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                        Copy
                                    </button>
                                </div>
                                <pre class="language-markup" id="error-html"><code>&lt;div class="alert alert-error"&gt;
  &lt;div class="alert-content"&gt;
    &lt;div class="alert-icon"&gt;
      &lt;span class="material-symbols-outlined text-lg"&gt;error&lt;/span&gt;
    &lt;/div&gt;
    &lt;div class="alert-text"&gt;
      &lt;p&gt;Your error message here&lt;/p&gt;
    &lt;/div&gt;
  &lt;/div&gt;
&lt;/div&gt;</code></pre>
                            </div>

                            <!-- Success Alert HTML -->
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-sm font-medium text-gray-900">Success Alert HTML</h3>
                                    <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="success-html">
                                        <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                        Copy
                                    </button>
                                </div>
                                <pre class="language-markup" id="success-html"><code>&lt;div class="alert alert-success"&gt;
  &lt;div class="alert-content"&gt;
    &lt;div class="alert-icon"&gt;
      &lt;span class="material-symbols-outlined text-lg"&gt;check_circle&lt;/span&gt;
    &lt;/div&gt;
    &lt;div class="alert-text"&gt;
      &lt;p&gt;Your success message here&lt;/p&gt;
    &lt;/div&gt;
  &lt;/div&gt;
&lt;/div&gt;</code></pre>
                            </div>

                            <!-- CSS Classes -->
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-sm font-medium text-gray-900">CSS Classes</h3>
                                    <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="css-classes">
                                        <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                        Copy
                                    </button>
                                </div>
                                <pre class="language-markup" id="css-classes"><code>/* Custom Alert Classes in tailwind.php */

/* Error Alert */
&lt;div class="alert alert-error"&gt;
  &lt;div class="alert-content"&gt;
    &lt;div class="alert-icon"&gt;
      &lt;span class="material-symbols-outlined text-lg"&gt;error&lt;/span&gt;
    &lt;/div&gt;
    &lt;div class="alert-text"&gt;
      &lt;p&gt;Error message&lt;/p&gt;
    &lt;/div&gt;
  &lt;/div&gt;
&lt;/div&gt;

/* Success Alert */
&lt;div class="alert alert-success"&gt;
  &lt;div class="alert-content"&gt;
    &lt;div class="alert-icon"&gt;
      &lt;span class="material-symbols-outlined text-lg"&gt;check_circle&lt;/span&gt;
    &lt;/div&gt;
    &lt;div class="alert-text"&gt;
      &lt;p&gt;Success message&lt;/p&gt;
    &lt;/div&gt;
  &lt;/div&gt;
&lt;/div&gt;

/* Warning Alert */
&lt;div class="alert alert-warning"&gt;
  &lt;div class="alert-content"&gt;
    &lt;div class="alert-icon"&gt;
      &lt;span class="material-symbols-outlined text-lg"&gt;warning&lt;/span&gt;
    &lt;/div&gt;
    &lt;div class="alert-text"&gt;
      &lt;p&gt;Warning message&lt;/p&gt;
    &lt;/div&gt;
  &lt;/div&gt;
&lt;/div&gt;

/* Info Alert */
&lt;div class="alert alert-info"&gt;
  &lt;div class="alert-content"&gt;
    &lt;div class="alert-icon"&gt;
      &lt;span class="material-symbols-outlined text-lg"&gt;info&lt;/span&gt;
    &lt;/div&gt;
    &lt;div class="alert-text"&gt;
      &lt;p&gt;Info message&lt;/p&gt;
    &lt;/div&gt;
  &lt;/div&gt;
&lt;/div&gt;</code></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Other sections will be similar structure -->
        <section id="notifications-section" class="component-section hidden">
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Notifications</h2>
                <div class="bg-gray-50 border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-gray-400 mb-4 block">construction</span>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Under Development</h3>
                    <p class="text-gray-500">Notification components will be added here.</p>
                </div>
            </div>
        </section>

        <section id="toasts-section" class="component-section hidden">
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Toast Messages</h2>
                <div class="bg-gray-50 border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-gray-400 mb-4 block">construction</span>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Under Development</h3>
                    <p class="text-gray-500">Toast message components will be added here.</p>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- JavaScript for functionality -->
<script>
// Initialize page functionality
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar navigation
    const sidebarItems = document.querySelectorAll('.sidebar-item');
    const sections = document.querySelectorAll('.component-section');
    
    // Show section based on hash or default to dashboard
    function showSection(sectionName) {
        sections.forEach(section => section.classList.add('hidden'));
        sidebarItems.forEach(item => item.classList.remove('bg-blue-50', 'text-blue-700'));
        
        const targetSection = document.getElementById(sectionName + '-section');
        const targetSidebarItem = document.querySelector(`[data-section="${sectionName}"]`);
        
        if (targetSection) {
            targetSection.classList.remove('hidden');
        }
        if (targetSidebarItem) {
            targetSidebarItem.classList.add('bg-blue-50', 'text-blue-700');
        }
        
        // Trigger Prism highlight refresh when showing a new section
        if (typeof Prism !== 'undefined') {
            Prism.highlightAll();
        }
    }
    
    // Handle sidebar clicks
    sidebarItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const section = item.dataset.section;
            window.location.hash = section;
            showSection(section);
        });
    });
    
    // Handle hash changes
    function handleHashChange() {
        const hash = window.location.hash.substring(1) || 'dashboard';
        showSection(hash);
    }
    
    window.addEventListener('hashchange', handleHashChange);
    handleHashChange(); // Initial load
    
    // Tab functionality
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
                content.classList.add('hidden');
            });
            document.getElementById(tabName + '-content').classList.remove('hidden');
            
            // Trigger Prism highlight refresh when changing tabs
            if (typeof Prism !== 'undefined') {
                Prism.highlightAll();
            }
        });
    });
});
</script>