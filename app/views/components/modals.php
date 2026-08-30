<!-- Modals Component -->
<div class="flex-1 bg-gray-50">
    <div class="p-6">
        <!-- Modals Section -->
        <div class="bg-white rounded-lg shadow-sm">
            <!-- Section Header -->
            <div class="border-b px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-900">Modal Components</h2>
                <p class="text-sm text-gray-600 mt-1">Modal dialogs and overlays for important interactions</p>
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
                        <!-- Basic Modal -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Modal</h3>
                            <div>
                                <button class="btn" id="openBasicModal">Open Basic Modal</button>
                                
                                <!-- Modal (Hidden by default) -->
                                <div class="modal-overlay" id="basicModal">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h3>Confirmation</h3>
                                            <button class="close-modal" data-modal="basicModal">
                                                <span class="material-symbols-outlined">close</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Are you sure you want to complete this action?</p>
                                            <div class="modal-footer">
                                                <button class="btn outline close-modal" data-modal="basicModal">Cancel</button>
                                                <button class="btn">Confirm</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Confirmation Modal -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Delete Confirmation Modal</h3>
                            <div>
                                <button class="btn rose" id="openDeleteModal">Delete Item</button>
                                
                                <!-- Delete Modal (Hidden by default) -->
                                <div class="modal-overlay" id="deleteModal">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h3>Delete Item</h3>
                                            <button class="close-modal" data-modal="deleteModal">
                                                <span class="material-symbols-outlined">close</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <p>This action cannot be undone. Are you sure you want to delete this item permanently?</p>
                                            <div class="modal-footer">
                                                <button class="btn outline close-modal" data-modal="deleteModal">Cancel</button>
                                                <button class="btn rose">Delete Permanently</button>
                                            </div>
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
                        <!-- Basic Modal HTML -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Basic Modal HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="modal-html">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="modal-html">
<span class="text-blue-400">&lt;button class="btn" id="openModal"&gt;</span>Open Modal<span class="text-blue-400">&lt;/button&gt;</span>

<span class="text-blue-400">&lt;div class="modal-overlay" id="myModal"&gt;</span>
    <span class="text-blue-400">&lt;div class="modal-content"&gt;</span>
        <span class="text-blue-400">&lt;div class="modal-header"&gt;</span>
            <span class="text-blue-400">&lt;h3&gt;</span>Modal Title<span class="text-blue-400">&lt;/h3&gt;</span>
            <span class="text-blue-400">&lt;button class="close-modal" data-modal="myModal"&gt;</span>
                <span class="text-blue-400">&lt;span class="material-symbols-outlined"&gt;</span>close<span class="text-blue-400">&lt;/span&gt;</span>
            <span class="text-blue-400">&lt;/button&gt;</span>
        <span class="text-blue-400">&lt;/div&gt;</span>
        <span class="text-blue-400">&lt;div class="modal-body"&gt;</span>
            <span class="text-blue-400">&lt;p&gt;</span>Modal content goes here.<span class="text-blue-400">&lt;/p&gt;</span>
            <span class="text-blue-400">&lt;div class="modal-footer"&gt;</span>
                <span class="text-blue-400">&lt;button class="btn outline close-modal" data-modal="myModal"&gt;</span>Cancel<span class="text-blue-400">&lt;/button&gt;</span>
                <span class="text-blue-400">&lt;button class="btn"&gt;</span>Confirm<span class="text-blue-400">&lt;/button&gt;</span>
            <span class="text-blue-400">&lt;/div&gt;</span>
        <span class="text-blue-400">&lt;/div&gt;</span>
    <span class="text-blue-400">&lt;/div&gt;</span>
<span class="text-blue-400">&lt;/div&gt;</span>
                            </div>
                        </div>

                        <!-- Modal JavaScript -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Modal JavaScript</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="modal-js">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="modal-js">
<span class="text-green-400">// Open modal</span>
document.getElementById('openModal').addEventListener('click', function() {
    document.getElementById('myModal').style.display = 'flex';
});

<span class="text-green-400">// Close modal on close button click</span>
document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
        const modalId = this.dataset.modal;
        document.getElementById(modalId).style.display = 'none';
    });
});

<span class="text-green-400">// Close modal when clicking outside</span>
window.addEventListener('click', function(event) {
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    });
});
                            </div>
                        </div>

                        <!-- CSS Classes -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Modal CSS Classes</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="modal-classes">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="modal-classes">
<span class="text-green-400">/* Modal Components in tailwind.php */</span>

<span class="text-yellow-300">/* Modal Overlay */</span>
<span class="text-blue-400">.modal-overlay</span> - Full screen overlay background

<span class="text-yellow-300">/* Modal Container */</span>
<span class="text-blue-400">.modal-content</span> - Main modal container

<span class="text-yellow-300">/* Modal Parts */</span>
<span class="text-blue-400">.modal-header</span> - Top section with title and close
<span class="text-blue-400">.modal-body</span> - Content area
<span class="text-blue-400">.modal-footer</span> - Bottom section with buttons
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for tabs, copy functionality, and modals -->
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
    
    // Modal functionality
    // Open modals
    document.getElementById('openBasicModal').addEventListener('click', function() {
        document.getElementById('basicModal').style.display = 'flex';
    });
    
    document.getElementById('openDeleteModal').addEventListener('click', function() {
        document.getElementById('deleteModal').style.display = 'flex';
    });
    
    // Close modals on button click
    document.querySelectorAll('.close-modal').forEach(button => {
        button.addEventListener('click', function() {
            const modalId = this.dataset.modal;
            document.getElementById(modalId).style.display = 'none';
        });
    });
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        });
    });
});
</script>