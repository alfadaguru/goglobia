<!-- Tables Component -->
<div class="flex-1 bg-gray-50">
    <div class="p-6">
        <!-- Tables Section -->
        <div class="bg-white rounded-lg shadow-sm">
            <!-- Section Header -->
            <div class="border-b px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-900">Table Components</h2>
                <p class="text-sm text-gray-600 mt-1">Data tables and table layouts with Tailwind CSS classes</p>
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
                        <!-- Basic Table -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Table</h3>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>John Doe</td>
                                            <td>john@example.com</td>
                                            <td>Admin</td>
                                            <td><span class="badge-success">Active</span></td>
                                        </tr>
                                        <tr>
                                            <td>Jane Smith</td>
                                            <td>jane@example.com</td>
                                            <td>User</td>
                                            <td><span class="badge-warning">Pending</span></td>
                                        </tr>
                                        <tr>
                                            <td>Mike Johnson</td>
                                            <td>mike@example.com</td>
                                            <td>Editor</td>
                                            <td><span class="badge-success">Active</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Table with Actions -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Table with Actions</h3>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Price</th>
                                            <th>Category</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Laptop Pro</td>
                                            <td>$1,299</td>
                                            <td>Electronics</td>
                                            <td>
                                                <div class="flex gap-2">
                                                    <button class="btn btn-sm outline">Edit</button>
                                                    <button class="btn btn-sm rose">Delete</button>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Wireless Mouse</td>
                                            <td>$49</td>
                                            <td>Accessories</td>
                                            <td>
                                                <div class="flex gap-2">
                                                    <button class="btn btn-sm outline">Edit</button>
                                                    <button class="btn btn-sm rose">Delete</button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- HTML Tab -->
                <div class="tab-content" id="html-content" style="display: none;">
                    <div class="space-y-6">
                        <!-- Basic Table HTML -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Basic Table HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="basic-table-html">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="basic-table-html">
<span class="text-blue-400">&lt;div class="table-container"&gt;</span>
    <span class="text-blue-400">&lt;table class="table"&gt;</span>
        <span class="text-blue-400">&lt;thead&gt;</span>
            <span class="text-blue-400">&lt;tr&gt;</span>
                <span class="text-blue-400">&lt;th&gt;</span>Name<span class="text-blue-400">&lt;/th&gt;</span>
                <span class="text-blue-400">&lt;th&gt;</span>Email<span class="text-blue-400">&lt;/th&gt;</span>
                <span class="text-blue-400">&lt;th&gt;</span>Role<span class="text-blue-400">&lt;/th&gt;</span>
            <span class="text-blue-400">&lt;/tr&gt;</span>
        <span class="text-blue-400">&lt;/thead&gt;</span>
        <span class="text-blue-400">&lt;tbody&gt;</span>
            <span class="text-blue-400">&lt;tr&gt;</span>
                <span class="text-blue-400">&lt;td&gt;</span>John Doe<span class="text-blue-400">&lt;/td&gt;</span>
                <span class="text-blue-400">&lt;td&gt;</span>john@example.com<span class="text-blue-400">&lt;/td&gt;</span>
                <span class="text-blue-400">&lt;td&gt;</span>Admin<span class="text-blue-400">&lt;/td&gt;</span>
            <span class="text-blue-400">&lt;/tr&gt;</span>
        <span class="text-blue-400">&lt;/tbody&gt;</span>
    <span class="text-blue-400">&lt;/table&gt;</span>
<span class="text-blue-400">&lt;/div&gt;</span>
                            </div>
                        </div>

                        <!-- CSS Classes -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Table CSS Classes</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="table-classes">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="table-classes">
<span class="text-green-400">/* Table Components in tailwind.php */</span>

<span class="text-yellow-300">/* Table Container */</span>
<span class="text-blue-400">.table-container</span> - Responsive table wrapper

<span class="text-yellow-300">/* Table Base */</span>
<span class="text-blue-400">.table</span> - Base table styling with borders

<span class="text-yellow-300">/* Table Headers */</span>
<span class="text-blue-400">.table th</span> - Header cell styling

<span class="text-yellow-300">/* Table Data */</span>
<span class="text-blue-400">.table td</span> - Data cell styling
<span class="text-blue-400">.table tbody tr</span> - Row hover effects
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
});
</script>