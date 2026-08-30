<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Badge Components</h1>
                    <p class="text-gray-600 mb-6">Labels, indicators, and badge components with various styles and states for displaying status, counts, and metadata.</p>

                    <!-- Content -->
                    <div class="space-y-10" x-data="badgeController()">

                        <!-- Basic Badges -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Badges</h3>
                            <p class="text-gray-600 mb-4">Standard badge variations with different colors and styles</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="badge-group">
                                    <span class="badge badge-primary">Primary</span>
                                    <span class="badge badge-secondary">Secondary</span>
                                    <span class="badge badge-success">Success</span>
                                    <span class="badge badge-warning">Warning</span>
                                    <span class="badge badge-error">Error</span>
                                    <span class="badge badge-info">Info</span>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><span class="badge badge-primary">Primary</span>
<span class="badge badge-secondary">Secondary</span>
<span class="badge badge-success">Success</span>
<span class="badge badge-warning">Warning</span>
<span class="badge badge-error">Error</span>
<span class="badge badge-info">Info</span></code></pre>
                            </div>
                        </div>

                        <!-- Badge Sizes -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Badge Sizes</h3>
                            <p class="text-gray-600 mb-4">Different badge sizes for various use cases</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="badge-group items-center">
                                    <span class="badge badge-primary badge-sm">Small</span>
                                    <span class="badge badge-primary badge-md">Medium</span>
                                    <span class="badge badge-primary badge-lg">Large</span>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><span class="badge badge-primary badge-sm">Small</span>
<span class="badge badge-primary badge-md">Medium</span>
<span class="badge badge-primary badge-lg">Large</span></code></pre>
                            </div>
                        </div>

                        <!-- Badge Variants -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Badge Variants</h3>
                            <p class="text-gray-600 mb-4">Different visual styles for badges</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="space-y-4">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-700 mb-2">Solid Badges</h4>
                                        <div class="badge-group">
                                            <span class="badge badge-success badge-solid">Solid</span>
                                            <span class="badge badge-warning badge-solid">Solid</span>
                                            <span class="badge badge-error badge-solid">Solid</span>
                                        </div>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-700 mb-2">Outlined Badges</h4>
                                        <div class="badge-group">
                                            <span class="badge badge-success badge-outlined">Outlined</span>
                                            <span class="badge badge-warning badge-outlined">Outlined</span>
                                            <span class="badge badge-error badge-outlined">Outlined</span>
                                        </div>
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-700 mb-2">Ghost Badges</h4>
                                        <div class="badge-group">
                                            <span class="badge badge-success badge-ghost">Ghost</span>
                                            <span class="badge badge-warning badge-ghost">Ghost</span>
                                            <span class="badge badge-error badge-ghost">Ghost</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Solid Badges -->
<span class="badge badge-success badge-solid">Solid</span>

<!-- Outlined Badges -->
<span class="badge badge-success badge-outlined">Outlined</span>

<!-- Ghost Badges -->
<span class="badge badge-success badge-ghost">Ghost</span></code></pre>
                            </div>
                        </div>

                        <!-- Badges with Icons -->
                        <div class="border-l-4 border-yellow-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Badges with Icons</h3>
                            <p class="text-gray-600 mb-4">Badges enhanced with icons for better visual context</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="badge-group">
                                    <span class="badge badge-success badge-with-icon">
                                        <span class="material-symbols-outlined badge-icon">check</span>
                                        Completed
                                    </span>
                                    <span class="badge badge-warning badge-with-icon">
                                        <span class="material-symbols-outlined badge-icon">schedule</span>
                                        Pending
                                    </span>
                                    <span class="badge badge-error badge-with-icon">
                                        <span class="material-symbols-outlined badge-icon">error</span>
                                        Failed
                                    </span>
                                    <span class="badge badge-info badge-with-icon">
                                        <span class="material-symbols-outlined badge-icon">info</span>
                                        Info
                                    </span>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><span class="badge badge-success badge-with-icon">
    <span class="material-symbols-outlined badge-icon">check</span>
    Completed
</span>

<span class="badge badge-warning badge-with-icon">
    <span class="material-symbols-outlined badge-icon">schedule</span>
    Pending
</span></code></pre>
                            </div>
                        </div>

                        <!-- Status Dots -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Status Badges with Dots</h3>
                            <p class="text-gray-600 mb-4">Badges with status indicator dots for quick visual recognition</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="badge-group">
                                    <span class="badge badge-dot badge-success">Online</span>
                                    <span class="badge badge-dot badge-warning">Away</span>
                                    <span class="badge badge-dot badge-error">Offline</span>
                                    <span class="badge badge-dot badge-info">Busy</span>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><span class="badge badge-dot badge-success">Online</span>
<span class="badge badge-dot badge-warning">Away</span>
<span class="badge badge-dot badge-error">Offline</span>
<span class="badge badge-dot badge-info">Busy</span></code></pre>
                            </div>
                        </div>

                        <!-- Removable Badges -->
                        <div class="border-l-4 border-indigo-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Removable Badges</h3>
                            <p class="text-gray-600 mb-4">Interactive badges that can be removed or dismissed</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="badge-group" x-show="showRemovableBadges">
                                    <template x-for="(tag, index) in removableTags" :key="index">
                                        <span class="badge badge-primary badge-removable">
                                            <span x-text="tag"></span>
                                            <button @click="removeTag(index)" class="badge-remove">
                                                <span class="material-symbols-outlined text-xs">close</span>
                                            </button>
                                        </span>
                                    </template>
                                </div>
                                <button @click="resetTags()" class="btn btn-sm mt-2" x-show="removableTags.length === 0">
                                    Reset Tags
                                </button>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><span class="badge badge-primary badge-removable">
    JavaScript
    <button class="badge-remove">
        <span class="material-symbols-outlined text-xs">close</span>
    </button>
</span></code></pre>
                            </div>
                        </div>

                        <!-- Number Badges -->
                        <div class="border-l-4 border-pink-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Number & Count Badges</h3>
                            <p class="text-gray-600 mb-4">Badges for displaying counts, numbers, and quantities</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="space-y-4">
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm text-gray-700">Notifications</span>
                                        <span class="badge badge-error">5</span>
                                    </div>
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm text-gray-700">Cart Items</span>
                                        <span class="badge badge-success">12</span>
                                    </div>
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm text-gray-700">Messages</span>
                                        <span class="badge badge-info">99+</span>
                                    </div>
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm text-gray-700">New Updates</span>
                                        <span class="badge badge-purple">3</span>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="flex items-center space-x-4">
    <span class="text-sm text-gray-700">Notifications</span>
    <span class="badge badge-error">5</span>
</div>

<div class="flex items-center space-x-4">
    <span class="text-sm text-gray-700">Cart Items</span>
    <span class="badge badge-success">12</span>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Color Variations -->
                        <div class="border-l-4 border-orange-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Extended Color Palette</h3>
                            <p class="text-gray-600 mb-4">Additional color options for various use cases</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="badge-group">
                                    <span class="badge badge-purple">Purple</span>
                                    <span class="badge badge-pink">Pink</span>
                                    <span class="badge badge-indigo">Indigo</span>
                                    <span class="badge badge-gray">Gray</span>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><span class="badge badge-purple">Purple</span>
<span class="badge badge-pink">Pink</span>
<span class="badge badge-indigo">Indigo</span>
<span class="badge badge-gray">Gray</span></code></pre>
                            </div>
                        </div>

                        <!-- Badge in Context -->
                        <div class="border-l-4 border-teal-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Badges in Context</h3>
                            <p class="text-gray-600 mb-4">Examples of badges used in real-world components and layouts</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="space-y-6">
                                    <!-- Card with Badge -->
                                    <div class="card">
                                        <div class="card-header">
                                            <div class="flex items-center justify-between">
                                                <h3 class="text-lg font-semibold">User Profile</h3>
                                                <span class="badge badge-success">Verified</span>
                                            </div>
                                        </div>
                                        <div class="card-content">
                                            <p class="text-sm text-gray-600">Active user with verified account status.</p>
                                        </div>
                                    </div>

                                    <!-- List with Badges -->
                                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                                        <div class="p-4 border-b border-gray-200">
                                            <h4 class="font-medium text-gray-900">Task List</h4>
                                        </div>
                                        <div class="divide-y divide-gray-200">
                                            <div class="flex items-center justify-between p-4">
                                                <span class="text-sm text-gray-900">Setup development environment</span>
                                                <span class="badge badge-success">Complete</span>
                                            </div>
                                            <div class="flex items-center justify-between p-4">
                                                <span class="text-sm text-gray-900">Design user interface</span>
                                                <span class="badge badge-warning">In Progress</span>
                                            </div>
                                            <div class="flex items-center justify-between p-4">
                                                <span class="text-sm text-gray-900">Write documentation</span>
                                                <span class="badge badge-gray">Not Started</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Navigation with Badge -->
                                    <div class="flex space-x-6">
                                        <a href="#" class="flex items-center space-x-2 text-gray-700 hover:text-gray-900">
                                            <span>Dashboard</span>
                                        </a>
                                        <a href="#" class="flex items-center space-x-2 text-gray-700 hover:text-gray-900">
                                            <span>Messages</span>
                                            <span class="badge badge-error badge-sm">3</span>
                                        </a>
                                        <a href="#" class="flex items-center space-x-2 text-gray-700 hover:text-gray-900">
                                            <span>Notifications</span>
                                            <span class="badge badge-warning badge-sm">12</span>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Card with Badge -->
<div class="card">
    <div class="card-header">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold">User Profile</h3>
            <span class="badge badge-success">Verified</span>
        </div>
    </div>
</div>

<!-- Navigation with Badge -->
<a href="#" class="flex items-center space-x-2">
    <span>Messages</span>
    <span class="badge badge-error badge-sm">3</span>
</a></code></pre>
                            </div>
                        </div>

                        <!-- Interactive Badge Builder -->
                        <div class="border-l-4 border-cyan-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Interactive Badge Builder</h3>
                            <p class="text-gray-600 mb-4">Create and customize your own badges</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                    <!-- Badge Customizer -->
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Badge Text</label>
                                            <input x-model="customBadge.text" type="text" placeholder="Enter badge text..." class="input">
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Color</label>
                                                <select x-model="customBadge.color" class="select">
                                                    <option value="primary">Primary</option>
                                                    <option value="secondary">Secondary</option>
                                                    <option value="success">Success</option>
                                                    <option value="warning">Warning</option>
                                                    <option value="error">Error</option>
                                                    <option value="info">Info</option>
                                                    <option value="purple">Purple</option>
                                                    <option value="pink">Pink</option>
                                                    <option value="indigo">Indigo</option>
                                                    <option value="gray">Gray</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Size</label>
                                                <select x-model="customBadge.size" class="select">
                                                    <option value="sm">Small</option>
                                                    <option value="md">Medium</option>
                                                    <option value="lg">Large</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Variant</label>
                                            <select x-model="customBadge.variant" class="select">
                                                <option value="">Default</option>
                                                <option value="solid">Solid</option>
                                                <option value="outlined">Outlined</option>
                                                <option value="ghost">Ghost</option>
                                            </select>
                                        </div>
                                        <div class="flex items-center space-x-4">
                                            <label class="flex items-center">
                                                <input x-model="customBadge.hasIcon" type="checkbox" class="checkbox mr-2">
                                                <span class="text-sm text-gray-700">Add Icon</span>
                                            </label>
                                            <label class="flex items-center">
                                                <input x-model="customBadge.hasDot" type="checkbox" class="checkbox mr-2">
                                                <span class="text-sm text-gray-700">Status Dot</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Badge Preview -->
                                    <div class="space-y-4">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-700 mb-3">Preview:</h4>
                                            <div class="p-4 bg-gray-50 rounded-lg flex items-center justify-center">
                                                <span :class="generateBadgeClass()" class="badge">
                                                    <span x-show="customBadge.hasIcon" class="material-symbols-outlined badge-icon">star</span>
                                                    <span x-text="customBadge.text || 'Sample Badge'"></span>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Generated Code -->
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-700 mb-2">Generated HTML:</h4>
                                            <pre class="text-sm bg-gray-100 p-3 rounded border overflow-x-auto"><code x-text="generateBadgeHTML()"></code></pre>
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
</div>

<script>
function badgeController() {
    return {
        // Removable badges data
        removableTags: ['JavaScript', 'React', 'Vue.js', 'Angular', 'Node.js'],
        showRemovableBadges: true,

        // Custom badge builder
        customBadge: {
            text: 'Custom Badge',
            color: 'primary',
            size: 'md',
            variant: '',
            hasIcon: false,
            hasDot: false
        },

        // Remove tag function
        removeTag(index) {
            this.removableTags.splice(index, 1);
            if (this.removableTags.length === 0) {
                this.showRemovableBadges = false;
            }
        },

        // Reset tags function
        resetTags() {
            this.removableTags = ['JavaScript', 'React', 'Vue.js', 'Angular', 'Node.js'];
            this.showRemovableBadges = true;
        },

        // Generate badge class
        generateBadgeClass() {
            let classes = [`badge-${this.customBadge.color}`, `badge-${this.customBadge.size}`];

            if (this.customBadge.variant) {
                classes.push(`badge-${this.customBadge.variant}`);
            }

            if (this.customBadge.hasIcon) {
                classes.push('badge-with-icon');
            }

            if (this.customBadge.hasDot) {
                classes.push('badge-dot');
            }

            return classes.join(' ');
        },

        // Generate HTML code
        generateBadgeHTML() {
            const classes = ['badge', this.generateBadgeClass()].join(' ');
            const text = this.customBadge.text || 'Sample Badge';

            let html = `<span class="${classes}">`;

            if (this.customBadge.hasIcon) {
                html += '\n    <span class="material-symbols-outlined badge-icon">star</span>';
            }

            html += `\n    ${text}`;
            html += '\n</span>';

            return html;
        }
    }
}
</script>