<!-- Accordion Component -->
<div class="flex-1 bg-gray-50">
    <div class="p-6">
        <!-- Accordion Components Section -->
        <div class="bg-white rounded-lg shadow-sm">
            <!-- Section Header -->
            <div class="border-b px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-900">Accordion Components</h2>
                <p class="text-sm text-gray-600 mt-1">Collapsible content sections and expandable panels</p>
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
                        <!-- Basic Accordion -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Accordion</h3>
                            <div class="accordion border rounded-lg">
                                <div class="accordion-item">
                                    <button class="accordion-trigger w-full py-4 px-6">
                                        <span>What is included in the basic plan?</span>
                                        <span class="accordion-icon material-symbols-outlined">expand_more</span>
                                    </button>
                                    <div class="accordion-content px-6">
                                        <p>The basic plan includes access to all core features, 5GB of storage, email support, and up to 10 user accounts. You also get basic analytics and reporting capabilities.</p>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <button class="accordion-trigger w-full py-4 px-6">
                                        <span>How can I upgrade my account?</span>
                                        <span class="accordion-icon material-symbols-outlined">expand_more</span>
                                    </button>
                                    <div class="accordion-content px-6">
                                        <p>You can upgrade your account at any time by visiting the billing section in your account settings. Choose from our Pro or Enterprise plans for additional features and storage.</p>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <button class="accordion-trigger w-full py-4 px-6">
                                        <span>Is there a money-back guarantee?</span>
                                        <span class="accordion-icon material-symbols-outlined">expand_more</span>
                                    </button>
                                    <div class="accordion-content px-6">
                                        <p>Yes, we offer a 30-day money-back guarantee on all paid plans. If you're not satisfied with our service, contact our support team for a full refund.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Accordion with Icons -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Accordion with Icons</h3>
                            <div class="accordion border rounded-lg">
                                <div class="accordion-item">
                                    <button class="accordion-trigger w-full py-4 px-6">
                                        <span class="flex items-center">
                                            <span class="material-symbols-outlined text-blue-600 mr-3">account_circle</span>
                                            Account Management
                                        </span>
                                        <span class="accordion-icon material-symbols-outlined">expand_more</span>
                                    </button>
                                    <div class="accordion-content px-6">
                                        <p>Manage your account settings, profile information, and preferences. Update your password, email notifications, and privacy settings from your account dashboard.</p>
                                        <div class="mt-3">
                                            <button class="btn btn-sm">Manage Account</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <button class="accordion-trigger w-full py-4 px-6">
                                        <span class="flex items-center">
                                            <span class="material-symbols-outlined text-green-600 mr-3">security</span>
                                            Security & Privacy
                                        </span>
                                        <span class="accordion-icon material-symbols-outlined">expand_more</span>
                                    </button>
                                    <div class="accordion-content px-6">
                                        <p>Your security is our priority. We use industry-standard encryption and offer two-factor authentication to keep your account secure.</p>
                                        <div class="mt-3 space-y-2">
                                            <div class="flex items-center justify-between">
                                                <span class="text-sm">Two-Factor Authentication</span>
                                                <span class="badge badge-success">Enabled</span>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-sm">SSL Encryption</span>
                                                <span class="badge badge-success">Active</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <button class="accordion-trigger w-full py-4 px-6">
                                        <span class="flex items-center">
                                            <span class="material-symbols-outlined text-purple-600 mr-3">support</span>
                                            Support & Help
                                        </span>
                                        <span class="accordion-icon material-symbols-outlined">expand_more</span>
                                    </button>
                                    <div class="accordion-content px-6">
                                        <p>Get help when you need it. Our support team is available 24/7 to assist with any questions or issues you may have.</p>
                                        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <a href="#" class="block p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                                                <div class="font-medium">Live Chat</div>
                                                <div class="text-sm text-gray-500">Get instant help</div>
                                            </a>
                                            <a href="#" class="block p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                                                <div class="font-medium">Email Support</div>
                                                <div class="text-sm text-gray-500">Send us a message</div>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Nested Accordion -->
                        <div>
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Nested Accordion</h3>
                            <div class="accordion border rounded-lg">
                                <div class="accordion-item">
                                    <button class="accordion-trigger w-full py-4 px-6">
                                        <span>Product Information</span>
                                        <span class="accordion-icon material-symbols-outlined">expand_more</span>
                                    </button>
                                    <div class="accordion-content px-6">
                                        <p class="mb-4">Learn more about our products and services:</p>
                                        <!-- Nested Accordion -->
                                        <div class="accordion border rounded-lg bg-gray-50">
                                            <div class="accordion-item">
                                                <button class="accordion-trigger w-full py-3 px-4">
                                                    <span class="text-sm">Features & Benefits</span>
                                                    <span class="accordion-icon material-symbols-outlined text-sm">expand_more</span>
                                                </button>
                                                <div class="accordion-content px-4">
                                                    <p class="text-sm">Discover the key features that make our product stand out from the competition.</p>
                                                </div>
                                            </div>
                                            <div class="accordion-item">
                                                <button class="accordion-trigger w-full py-3 px-4">
                                                    <span class="text-sm">Technical Specifications</span>
                                                    <span class="accordion-icon material-symbols-outlined text-sm">expand_more</span>
                                                </button>
                                                <div class="accordion-content px-4">
                                                    <p class="text-sm">Detailed technical specifications and system requirements for our product.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item">
                                    <button class="accordion-trigger w-full py-4 px-6">
                                        <span>Pricing & Plans</span>
                                        <span class="accordion-icon material-symbols-outlined">expand_more</span>
                                    </button>
                                    <div class="accordion-content px-6">
                                        <p>Choose the plan that best fits your needs:</p>
                                        <div class="mt-3 space-y-2">
                                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                                <div>
                                                    <div class="font-medium">Basic Plan</div>
                                                    <div class="text-sm text-gray-500">Perfect for individuals</div>
                                                </div>
                                                <div class="text-lg font-bold">$9/mo</div>
                                            </div>
                                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                                <div>
                                                    <div class="font-medium">Pro Plan</div>
                                                    <div class="text-sm text-gray-500">Great for small teams</div>
                                                </div>
                                                <div class="text-lg font-bold">$19/mo</div>
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
                        <!-- Basic Accordion HTML -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">Basic Accordion HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="accordion-html">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="accordion-html">
<span class="text-blue-400">&lt;div class="accordion border rounded-lg"&gt;</span>
    <span class="text-blue-400">&lt;div class="accordion-item"&gt;</span>
        <span class="text-blue-400">&lt;button class="accordion-trigger w-full py-4 px-6"&gt;</span>
            <span class="text-blue-400">&lt;span&gt;</span>Accordion Title<span class="text-blue-400">&lt;/span&gt;</span>
            <span class="text-blue-400">&lt;span class="accordion-icon material-symbols-outlined"&gt;</span>expand_more<span class="text-blue-400">&lt;/span&gt;</span>
        <span class="text-blue-400">&lt;/button&gt;</span>
        <span class="text-blue-400">&lt;div class="accordion-content px-6"&gt;</span>
            <span class="text-blue-400">&lt;p&gt;</span>Accordion content goes here<span class="text-blue-400">&lt;/p&gt;</span>
        <span class="text-blue-400">&lt;/div&gt;</span>
    <span class="text-blue-400">&lt;/div&gt;</span>
<span class="text-blue-400">&lt;/div&gt;</span>
                            </div>
                        </div>

                        <!-- JavaScript for Accordion -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">JavaScript for Accordion</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="accordion-js">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <div class="bg-gray-900 rounded-lg p-4 text-white text-sm font-mono overflow-x-auto" id="accordion-js">
<span class="text-green-400">// Accordion functionality</span>
<span class="text-yellow-300">document</span>.<span class="text-blue-400">querySelectorAll</span>(<span class="text-red-300">'.accordion-trigger'</span>).<span class="text-blue-400">forEach</span>(<span class="text-yellow-300">trigger</span> <span class="text-white">=&gt;</span> {
    <span class="text-yellow-300">trigger</span>.<span class="text-blue-400">addEventListener</span>(<span class="text-red-300">'click'</span>, () <span class="text-white">=&gt;</span> {
        <span class="text-purple-400">const</span> <span class="text-yellow-300">content</span> = <span class="text-yellow-300">trigger</span>.<span class="text-yellow-300">nextElementSibling</span>;
        <span class="text-purple-400">const</span> <span class="text-yellow-300">icon</span> = <span class="text-yellow-300">trigger</span>.<span class="text-blue-400">querySelector</span>(<span class="text-red-300">'.accordion-icon'</span>);
        
        <span class="text-purple-400">if</span> (<span class="text-yellow-300">content</span>.<span class="text-yellow-300">style</span>.<span class="text-yellow-300">display</span> === <span class="text-red-300">'block'</span>) {
            <span class="text-yellow-300">content</span>.<span class="text-yellow-300">style</span>.<span class="text-yellow-300">display</span> = <span class="text-red-300">'none'</span>;
            <span class="text-yellow-300">icon</span>.<span class="text-yellow-300">style</span>.<span class="text-yellow-300">transform</span> = <span class="text-red-300">'rotate(0deg)'</span>;
        } <span class="text-purple-400">else</span> {
            <span class="text-yellow-300">content</span>.<span class="text-yellow-300">style</span>.<span class="text-yellow-300">display</span> = <span class="text-red-300">'block'</span>;
            <span class="text-yellow-300">icon</span>.<span class="text-yellow-300">style</span>.<span class="text-yellow-300">transform</span> = <span class="text-red-300">'rotate(180deg)'</span>;
        }
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

<!-- JavaScript for tabs, accordion and copy functionality -->
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

    // Accordion functionality
    document.querySelectorAll('.accordion-trigger').forEach(trigger => {
        trigger.addEventListener('click', () => {
            const content = trigger.nextElementSibling;
            const icon = trigger.querySelector('.accordion-icon');
            
            if (content.style.display === 'block') {
                content.style.display = 'none';
                icon.style.transform = 'rotate(0deg)';
            } else {
                content.style.display = 'block';
                icon.style.transform = 'rotate(180deg)';
            }
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