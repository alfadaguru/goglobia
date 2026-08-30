<!-- Tooltips Component -->
<div class="flex-1 bg-gray-50">
    <div class="p-6">
        <!-- Tooltips Section -->
        <div class="bg-white rounded-lg shadow-sm">
            <!-- Section Header -->
            <div class="border-b px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-900">Tooltip Components</h2>
                <p class="text-sm text-gray-600 mt-1">Informative tooltips and hover elements</p>
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
                        <!-- Basic Tooltips -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Tooltips</h3>
                            <p class="text-sm text-gray-600 mb-4">Hover over the items below to see tooltips:</p>
                            <div class="flex flex-wrap gap-6 items-center">
                                <div class="relative inline-block tooltip-container">
                                    <button class="btn outline">Hover Me</button>
                                    <span class="tooltip">Basic tooltip</span>
                                </div>
                                
                                <div class="relative inline-block tooltip-container">
                                    <button class="btn">Help Info</button>
                                    <span class="tooltip">Shows additional information</span>
                                </div>
                                
                                <div class="relative inline-block tooltip-container">
                                    <span class="material-symbols-outlined text-blue-500 cursor-help">help</span>
                                    <span class="tooltip">Click for help</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- HTML Tab -->
                <div class="tab-content" id="html-content" style="display: none;">
                    <div class="space-y-6">
                        <!-- Basic Tooltip HTML -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Basic Tooltip HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="tooltip-html">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="tooltip-html">
<span class="text-blue-400">&lt;div class="relative inline-block tooltip-container"&gt;</span>
    <span class="text-blue-400">&lt;button class="btn outline"&gt;</span>Hover Me<span class="text-blue-400">&lt;/button&gt;</span>
    <span class="text-blue-400">&lt;span class="tooltip"&gt;</span>Basic tooltip<span class="text-blue-400">&lt;/span&gt;</span>
<span class="text-blue-400">&lt;/div&gt;</span>
                            </div>
                        </div>

                        <!-- Icon Tooltip HTML -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Icon Tooltip HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="icon-tooltip-html">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="icon-tooltip-html">
<span class="text-blue-400">&lt;div class="relative inline-block tooltip-container"&gt;</span>
    <span class="text-blue-400">&lt;span class="material-symbols-outlined text-blue-500 cursor-help"&gt;</span>help<span class="text-blue-400">&lt;/span&gt;</span>
    <span class="text-blue-400">&lt;span class="tooltip"&gt;</span>Click for help<span class="text-blue-400">&lt;/span&gt;</span>
<span class="text-blue-400">&lt;/div&gt;</span>
                            </div>
                        </div>

                        <!-- CSS Classes -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Tooltip CSS Classes</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="tooltip-classes">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="tooltip-classes">
<span class="text-green-400">/* Tooltip Components in tailwind.php */</span>

<span class="text-yellow-300">/* Tooltip Container */</span>
<span class="text-blue-400">.tooltip-container</span> - Position relative container

<span class="text-yellow-300">/* Tooltip */</span>
<span class="text-blue-400">.tooltip</span> - Tooltip element styling

<span class="text-yellow-300">/* Additional styling */</span>
Add CSS for show/hide on hover - See styles in tailwind.php
                            </div>
                        </div>

                        <!-- Tooltip JavaScript -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Tooltip CSS for Show/Hide</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="tooltip-css">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="tooltip-css">
.tooltip {
    visibility: hidden;
    opacity: 0;
    transition: all 0.3s ease;
}

.tooltip-container:hover .tooltip {
    visibility: visible;
    opacity: 1;
}
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

    // Add custom tooltip CSS
    const style = document.createElement('style');
    style.textContent = `
        .tooltip-container {
            position: relative;
        }
        
        .tooltip {
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .tooltip-container:hover .tooltip {
            visibility: visible;
            opacity: 1;
        }
    `;
    document.head.appendChild(style);
});
</script>