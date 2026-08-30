<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Buttons</h1>
                    <p class="text-gray-600 mb-6">Button styles & variations using Tailwind CSS components.</p>

                    <!-- Content -->
                    <div class="space-y-10">
                        <!-- Base Button Styles -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Base Button Styles</h3>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="button-group">
                                    <button class="btn">Primary</button>
                                    <button class="btn secondary">Secondary</button>
                                    <button class="btn outline">Outline</button>
                                    <button class="btn ghost">Ghost</button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="button-group">
    <button class="btn">Primary</button>
    <button class="btn secondary">Secondary</button>
    <button class="btn outline">Outline</button>
    <button class="btn ghost">Ghost</button>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Modern Color Variants -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Modern Color Variants</h3>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="button-group">
                                    <button class="btn white">White</button>
                                    <button class="btn light">Light</button>
                                    <button class="btn emerald">Emerald</button>
                                    <button class="btn rose">Rose</button>
                                    <button class="btn slate">Slate</button>
                                    <button class="btn amber">Amber</button>
                                    <button class="btn violet">Violet</button>
                                    <button class="btn cyan">Cyan</button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="button-group">
    <button class="btn white">White</button>
    <button class="btn light">Light</button>
    <button class="btn emerald">Emerald</button>
    <button class="btn rose">Rose</button>
    <button class="btn slate">Slate</button>
    <button class="btn amber">Amber</button>
    <button class="btn violet">Violet</button>
    <button class="btn cyan">Cyan</button>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Button Sizes -->
                        <div class="border-l-4 border-yellow-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Button Sizes</h3>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="flex items-center gap-4">
                                    <button class="btn btn-sm">Small</button>
                                    <button class="btn">Default</button>
                                    <button class="btn btn-lg">Large</button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="flex items-center gap-4">
    <button class="btn btn-sm">Small</button>
    <button class="btn">Default</button>
    <button class="btn btn-lg">Large</button>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Buttons with Icons -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Buttons with Icons</h3>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="button-group">
                                    <button class="btn">
                                        <span class="material-symbols-outlined text-base mr-2">add</span>
                                        Add New
                                    </button>
                                    <button class="btn emerald">
                                        <span class="material-symbols-outlined text-base mr-2">download</span>
                                        Download
                                    </button>
                                    <button class="btn outline">
                                        <span class="material-symbols-outlined text-base mr-2">edit</span>
                                        Edit
                                    </button>
                                    <button class="btn rose">
                                        <span class="material-symbols-outlined text-base mr-2">delete</span>
                                        Delete
                                    </button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="button-group">
    <button class="btn">
        <span class="material-symbols-outlined text-base mr-2">add</span>
        Add New
    </button>
    <button class="btn emerald">
        <span class="material-symbols-outlined text-base mr-2">download</span>
        Download
    </button>
    <button class="btn outline">
        <span class="material-symbols-outlined text-base mr-2">edit</span>
        Edit
    </button>
    <button class="btn rose">
        <span class="material-symbols-outlined text-base mr-2">delete</span>
        Delete
    </button>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Icon Only Buttons -->
                        <div class="border-l-4 border-cyan-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Icon Only Buttons</h3>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="flex gap-2">
                                    <button class="btn w-9 h-9 p-0">
                                        <span class="material-symbols-outlined text-base">search</span>
                                    </button>
                                    <button class="btn emerald w-9 h-9 p-0">
                                        <span class="material-symbols-outlined text-base">notifications</span>
                                    </button>
                                    <button class="btn outline w-9 h-9 p-0">
                                        <span class="material-symbols-outlined text-base">settings</span>
                                    </button>
                                    <button class="btn ghost w-9 h-9 p-0">
                                        <span class="material-symbols-outlined text-base">favorite</span>
                                    </button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="flex gap-2">
    <button class="btn w-9 h-9 p-0">
        <span class="material-symbols-outlined text-base">search</span>
    </button>
    <button class="btn emerald w-9 h-9 p-0">
        <span class="material-symbols-outlined text-base">notifications</span>
    </button>
    <button class="btn outline w-9 h-9 p-0">
        <span class="material-symbols-outlined text-base">settings</span>
    </button>
    <button class="btn ghost w-9 h-9 p-0">
        <span class="material-symbols-outlined text-base">favorite</span>
    </button>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Disabled States -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Disabled States</h3>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="button-group">
                                    <button class="btn" disabled>Primary Disabled</button>
                                    <button class="btn secondary" disabled>Secondary Disabled</button>
                                    <button class="btn outline" disabled>Outline Disabled</button>
                                    <button class="btn ghost" disabled>Ghost Disabled</button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="button-group">
    <button class="btn" disabled>Primary Disabled</button>
    <button class="btn secondary" disabled>Secondary Disabled</button>
    <button class="btn outline" disabled>Outline Disabled</button>
    <button class="btn ghost" disabled>Ghost Disabled</button>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Loading States -->
                        <div class="border-l-4 border-indigo-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Loading States (Like Login Button)</h3>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="button-group">
                                    <button class="btn"
                                            x-data="{ loading: false }"
                                            @click="loading = true; setTimeout(() => loading = false, 2000)"
                                            :disabled="loading"
                                            :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                                        <span x-show="!loading" class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-base">login</span>
                                            <span>Sign In</span>
                                        </span>
                                        <span x-show="loading" class="flex items-center gap-2" x-cloak>
                                            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span>Signing in...</span>
                                        </span>
                                    </button>

                                    <button class="btn emerald"
                                            x-data="{ loading: false }"
                                            @click="loading = true; setTimeout(() => loading = false, 3000)"
                                            :disabled="loading">
                                        <span x-show="!loading" class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-base">download</span>
                                            <span>Download</span>
                                        </span>
                                        <span x-show="loading" class="flex items-center gap-2" x-cloak>
                                            <span class="animate-spin material-symbols-outlined text-base">refresh</span>
                                            <span>Processing...</span>
                                        </span>
                                    </button>

                                    <button class="btn outline"
                                            x-data="{ loading: false }"
                                            @click="loading = true; setTimeout(() => loading = false, 1500)"
                                            :disabled="loading">
                                        <span x-show="!loading" class="flex items-center gap-2">
                                            <span class="material-symbols-outlined text-base">save</span>
                                            <span>Save</span>
                                        </span>
                                        <span x-show="loading" class="flex items-center gap-2" x-cloak>
                                            <span class="animate-pulse material-symbols-outlined text-base">hourglass_empty</span>
                                            <span>Saving...</span>
                                        </span>
                                    </button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Loading Button (Login Style) -->
<button class="btn"
        x-data="{ loading: false }"
        @click="loading = true; setTimeout(() => loading = false, 2000)"
        :disabled="loading"
        :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
    <span x-show="!loading" class="flex items-center gap-2">
        <span class="material-symbols-outlined text-base">login</span>
        <span>Sign In</span>
    </span>
    <span x-show="loading" class="flex items-center gap-2" x-cloak>
        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span>Signing in...</span>
    </span>
</button>

<!-- Simple Loading with Material Icons -->
<button class="btn emerald"
        x-data="{ loading: false }"
        @click="loading = true; setTimeout(() => loading = false, 3000)"
        :disabled="loading">
    <span x-show="!loading" class="flex items-center gap-2">
        <span class="material-symbols-outlined text-base">download</span>
        <span>Download</span>
    </span>
    <span x-show="loading" class="flex items-center gap-2" x-cloak>
        <span class="animate-spin material-symbols-outlined text-base">refresh</span>
        <span>Processing...</span>
    </span>
</button></code></pre>
                            </div>
                        </div>

                        <!-- Button Groups -->
                        <div class="border-l-4 border-teal-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Button Groups</h3>

                            <!-- Live Example -->
                            <div class="mb-4 space-y-4">
                                <!-- Horizontal Group -->
                                <div class="inline-flex rounded-md shadow-sm" role="group">
                                    <button class="btn outline rounded-r-none border-r-0">Left</button>
                                    <button class="btn outline rounded-none border-r-0">Middle</button>
                                    <button class="btn outline rounded-l-none">Right</button>
                                </div>

                                <!-- Icon Button Group -->
                                <div class="inline-flex rounded-md shadow-sm" role="group">
                                    <button class="btn outline rounded-r-none border-r-0 w-9 h-9 p-0">
                                        <span class="material-symbols-outlined text-base">format_bold</span>
                                    </button>
                                    <button class="btn outline rounded-none border-r-0 w-9 h-9 p-0">
                                        <span class="material-symbols-outlined text-base">format_italic</span>
                                    </button>
                                    <button class="btn outline rounded-l-none w-9 h-9 p-0">
                                        <span class="material-symbols-outlined text-base">format_underlined</span>
                                    </button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Horizontal Button Group -->
<div class="inline-flex rounded-md shadow-sm" role="group">
    <button class="btn outline rounded-r-none border-r-0">Left</button>
    <button class="btn outline rounded-none border-r-0">Middle</button>
    <button class="btn outline rounded-l-none">Right</button>
</div>

<!-- Icon Button Group -->
<div class="inline-flex rounded-md shadow-sm" role="group">
    <button class="btn outline rounded-r-none border-r-0 w-9 h-9 p-0">
        <span class="material-symbols-outlined text-base">format_bold</span>
    </button>
    <button class="btn outline rounded-none border-r-0 w-9 h-9 p-0">
        <span class="material-symbols-outlined text-base">format_italic</span>
    </button>
    <button class="btn outline rounded-l-none w-9 h-9 p-0">
        <span class="material-symbols-outlined text-base">format_underlined</span>
    </button>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Dropdown Buttons -->
                        <div class="border-l-4 border-orange-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Dropdown Buttons</h3>

                            <!-- Live Example -->
                            <div class="mb-4 space-y-4">
                                <!-- Basic Dropdown -->
                                <div class="flex gap-4 flex-wrap">
                                    <div class="dropdown" x-data="{ open: false }" @click.away="open = false">
                                        <button @click="open = !open" class="btn-dropdown">
                                            <span>Actions</span>
                                            <span class="material-symbols-outlined text-base transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </button>
                                        <div class="dropdown-content" :class="open ? 'show' : ''">
                                            <div class="dropdown-menu">
                                                <div class="dropdown-item">
                                                    <span class="material-symbols-outlined text-base">edit</span>
                                                    <span>Edit</span>
                                                </div>
                                                <div class="dropdown-item">
                                                    <span class="material-symbols-outlined text-base">content_copy</span>
                                                    <span>Duplicate</span>
                                                </div>
                                                <div class="dropdown-separator"></div>
                                                <div class="dropdown-item text-red-600">
                                                    <span class="material-symbols-outlined text-base">delete</span>
                                                    <span>Delete</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Outline Dropdown -->
                                    <div class="dropdown" x-data="{ open: false }" @click.away="open = false">
                                        <button @click="open = !open" class="btn-dropdown outline">
                                            <span>Options</span>
                                            <span class="material-symbols-outlined text-base transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </button>
                                        <div class="dropdown-content" :class="open ? 'show' : ''">
                                            <div class="dropdown-menu">
                                                <div class="dropdown-item">Settings</div>
                                                <div class="dropdown-item">Profile</div>
                                                <div class="dropdown-item">Help</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Multi-level Dropdown -->
                                    <div class="dropdown" x-data="{ open: false, submenu: false }" @click.away="open = false; submenu = false">
                                        <button @click="open = !open" class="btn-dropdown secondary">
                                            <span>More</span>
                                            <span class="material-symbols-outlined text-base transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </button>
                                        <div class="dropdown-content" :class="open ? 'show' : ''">
                                            <div class="dropdown-menu">
                                                <div class="dropdown-item">View</div>
                                                <div class="dropdown-submenu" @mouseenter="submenu = true" @mouseleave="submenu = false">
                                                    <div class="dropdown-item">
                                                        <span>Export</span>
                                                        <span class="material-symbols-outlined text-base ml-auto">chevron_right</span>
                                                    </div>
                                                    <div class="dropdown-content" :class="submenu ? 'show' : ''">
                                                        <div class="dropdown-menu">
                                                            <div class="dropdown-item">PDF</div>
                                                            <div class="dropdown-item">Excel</div>
                                                            <div class="dropdown-item">CSV</div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="dropdown-item">Share</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Basic Dropdown Button -->
<div class="dropdown" x-data="{ open: false }" @click.away="open = false">
    <button @click="open = !open" class="btn-dropdown">
        <span>Actions</span>
        <span class="material-symbols-outlined text-base transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
    </button>
    <div class="dropdown-content" :class="open ? 'show' : ''">
        <div class="dropdown-menu">
            <div class="dropdown-item">
                <span class="material-symbols-outlined text-base">edit</span>
                <span>Edit</span>
            </div>
            <div class="dropdown-item">
                <span class="material-symbols-outlined text-base">content_copy</span>
                <span>Duplicate</span>
            </div>
            <div class="dropdown-separator"></div>
            <div class="dropdown-item text-red-600">
                <span class="material-symbols-outlined text-base">delete</span>
                <span>Delete</span>
            </div>
        </div>
    </div>
</div>

<!-- Multi-level Dropdown -->
<div class="dropdown" x-data="{ open: false, submenu: false }" @click.away="open = false; submenu = false">
    <button @click="open = !open" class="btn-dropdown secondary">
        <span>More</span>
        <span class="material-symbols-outlined text-base transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
    </button>
    <div class="dropdown-content" :class="open ? 'show' : ''">
        <div class="dropdown-menu">
            <div class="dropdown-submenu" @mouseenter="submenu = true" @mouseleave="submenu = false">
                <div class="dropdown-item">
                    <span>Export</span>
                    <span class="material-symbols-outlined text-base ml-auto">chevron_right</span>
                </div>
                <div class="dropdown-content" :class="submenu ? 'show' : ''">
                    <div class="dropdown-menu">
                        <div class="dropdown-item">PDF</div>
                        <div class="dropdown-item">Excel</div>
                        <div class="dropdown-item">CSV</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div></code></pre>
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