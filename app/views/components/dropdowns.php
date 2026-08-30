<!-- Dropdowns Component -->
<div class="flex-1 bg-gray-50">
    <div class="p-6">
        <!-- Dropdowns Section -->
        <div class="bg-white rounded-lg shadow-sm">
            <!-- Section Header -->
            <div class="border-b px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-900">Dropdown Components</h2>
                <p class="text-sm text-gray-600 mt-1">Dropdown menus and selects with interactive functionality</p>
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
                        <!-- Basic Dropdown -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Dropdown</h3>
                            <div class="flex gap-4">
                                <div class="dropdown">
                                    <button class="btn outline dropdown-trigger" data-dropdown="basic-dropdown">
                                        <span>Options</span>
                                        <span class="material-symbols-outlined text-sm">expand_more</span>
                                    </button>
                                    <div class="dropdown-content" id="basic-dropdown">
                                        <div class="dropdown-menu">
                                            <a href="#" class="dropdown-item">Edit Profile</a>
                                            <a href="#" class="dropdown-item">Settings</a>
                                            <div class="dropdown-separator"></div>
                                            <a href="#" class="dropdown-item">Sign Out</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="dropdown">
                                    <button class="btn dropdown-trigger" data-dropdown="action-dropdown">
                                        <span>Actions</span>
                                        <span class="material-symbols-outlined text-sm">arrow_drop_down</span>
                                    </button>
                                    <div class="dropdown-content" id="action-dropdown">
                                        <div class="dropdown-menu">
                                            <a href="#" class="dropdown-item">
                                                <span class="material-symbols-outlined text-sm mr-2">edit</span>
                                                Edit
                                            </a>
                                            <a href="#" class="dropdown-item">
                                                <span class="material-symbols-outlined text-sm mr-2">content_copy</span>
                                                Duplicate
                                            </a>
                                            <div class="dropdown-separator"></div>
                                            <a href="#" class="dropdown-item text-red-600">
                                                <span class="material-symbols-outlined text-sm mr-2">delete</span>
                                                Delete
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Input Dropdown -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Input with Dropdown</h3>
                            <div class="max-w-sm">
                                <div class="input-dropdown">
                                    <input type="text" class="input" placeholder="Search countries..." id="country-input">
                                    <div class="input-dropdown-content" id="country-dropdown">
                                        <div class="input-dropdown-item" data-value="us">United States</div>
                                        <div class="input-dropdown-item" data-value="uk">United Kingdom</div>
                                        <div class="input-dropdown-item" data-value="ca">Canada</div>
                                        <div class="input-dropdown-item" data-value="au">Australia</div>
                                        <div class="input-dropdown-item" data-value="de">Germany</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Custom Select -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Custom Select</h3>
                            <div class="max-w-sm">
                                <select class="select">
                                    <option>Choose an option</option>
                                    <option>Option 1</option>
                                    <option>Option 2</option>
                                    <option>Option 3</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- HTML Tab -->
                <div class="tab-content" id="html-content" style="display: none;">
                    <div class="space-y-6">
                        <!-- Basic Dropdown HTML -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Basic Dropdown HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="dropdown-html">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="dropdown-html">
<span class="text-blue-400">&lt;div class="dropdown"&gt;</span>
    <span class="text-blue-400">&lt;button class="btn outline dropdown-trigger"&gt;</span>
        <span class="text-blue-400">&lt;span&gt;</span>Options<span class="text-blue-400">&lt;/span&gt;</span>
        <span class="text-blue-400">&lt;span class="material-symbols-outlined"&gt;</span>expand_more<span class="text-blue-400">&lt;/span&gt;</span>
    <span class="text-blue-400">&lt;/button&gt;</span>
    <span class="text-blue-400">&lt;div class="dropdown-content"&gt;</span>
        <span class="text-blue-400">&lt;div class="dropdown-menu"&gt;</span>
            <span class="text-blue-400">&lt;a href="#" class="dropdown-item"&gt;</span>Edit Profile<span class="text-blue-400">&lt;/a&gt;</span>
            <span class="text-blue-400">&lt;a href="#" class="dropdown-item"&gt;</span>Settings<span class="text-blue-400">&lt;/a&gt;</span>
            <span class="text-blue-400">&lt;div class="dropdown-separator"&gt;&lt;/div&gt;</span>
            <span class="text-blue-400">&lt;a href="#" class="dropdown-item"&gt;</span>Sign Out<span class="text-blue-400">&lt;/a&gt;</span>
        <span class="text-blue-400">&lt;/div&gt;</span>
    <span class="text-blue-400">&lt;/div&gt;</span>
<span class="text-blue-400">&lt;/div&gt;</span>
                            </div>
                        </div>

                        <!-- Input Dropdown HTML -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Input Dropdown HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="input-dropdown-html">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="input-dropdown-html">
<span class="text-blue-400">&lt;div class="input-dropdown"&gt;</span>
    <span class="text-blue-400">&lt;input type="text" class="input" placeholder="Search..."&gt;</span>
    <span class="text-blue-400">&lt;div class="input-dropdown-content"&gt;</span>
        <span class="text-blue-400">&lt;div class="input-dropdown-item"&gt;</span>Option 1<span class="text-blue-400">&lt;/div&gt;</span>
        <span class="text-blue-400">&lt;div class="input-dropdown-item"&gt;</span>Option 2<span class="text-blue-400">&lt;/div&gt;</span>
        <span class="text-blue-400">&lt;div class="input-dropdown-item"&gt;</span>Option 3<span class="text-blue-400">&lt;/div&gt;</span>
    <span class="text-blue-400">&lt;/div&gt;</span>
<span class="text-blue-400">&lt;/div&gt;</span>
                            </div>
                        </div>

                        <!-- CSS Classes -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Dropdown CSS Classes</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="dropdown-classes">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="dropdown-classes">
<span class="text-green-400">/* Dropdown Components in tailwind.php */</span>

<span class="text-yellow-300">/* Basic Dropdown */</span>
<span class="text-blue-400">.dropdown</span> - Container
<span class="text-blue-400">.dropdown-content</span> - Menu container
<span class="text-blue-400">.dropdown-menu</span> - Menu wrapper
<span class="text-blue-400">.dropdown-item</span> - Menu item
<span class="text-blue-400">.dropdown-separator</span> - Separator line

<span class="text-yellow-300">/* Input Dropdown */</span>
<span class="text-blue-400">.input-dropdown</span> - Container
<span class="text-blue-400">.input-dropdown-content</span> - Options container
<span class="text-blue-400">.input-dropdown-item</span> - Option item

<span class="text-yellow-300">/* Custom Select */</span>
<span class="text-blue-400">.select</span> - Styled select element
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for tabs, copy functionality, and dropdowns -->
<script>
document.addEventListener('DOMContentLoaded', function() {
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
                content.style.display = 'none';
            });
            document.getElementById(tabName + '-content').style.display = 'block';
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
    
    // Dropdown functionality
    const dropdownTriggers = document.querySelectorAll('.dropdown-trigger');
    dropdownTriggers.forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropdownId = trigger.dataset.dropdown;
            const dropdown = document.getElementById(dropdownId);
            
            // Close all other dropdowns
            document.querySelectorAll('.dropdown-content').forEach(content => {
                if (content.id !== dropdownId) {
                    content.classList.remove('show');
                }
            });
            
            // Toggle current dropdown
            dropdown.classList.toggle('show');
        });
    });
    
    // Input dropdown functionality
    const countryInput = document.getElementById('country-input');
    const countryDropdown = document.getElementById('country-dropdown');
    
    if (countryInput && countryDropdown) {
        countryInput.addEventListener('focus', () => {
            countryDropdown.classList.add('show');
        });
        
        countryInput.addEventListener('input', (e) => {
            const value = e.target.value.toLowerCase();
            const items = countryDropdown.querySelectorAll('.input-dropdown-item');
            
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(value) ? 'block' : 'none';
            });
        });
        
        // Select item functionality
        const dropdownItems = countryDropdown.querySelectorAll('.input-dropdown-item');
        dropdownItems.forEach(item => {
            item.addEventListener('click', () => {
                countryInput.value = item.textContent;
                countryDropdown.classList.remove('show');
            });
        });
    }
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-content, .input-dropdown-content').forEach(content => {
            content.classList.remove('show');
        });
    });
});
</script>