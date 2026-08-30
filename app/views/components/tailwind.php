<!-- Tailwind CSS Component -->
<div class="flex-1 bg-gray-50">
    <div class="p-6">
        <!-- Tailwind CSS Section -->
        <div class="bg-white rounded-lg shadow-sm">
            <!-- Section Header -->
            <div class="border-b px-6 py-4">
                <h2 class="text-xl font-semibold text-gray-900">Tailwind CSS Color Examples</h2>
                <p class="text-sm text-gray-600 mt-1">Examples of color customization with Tailwind CSS</p>
            </div>

            <!-- Tabs -->
            <div class="border-b">
                <nav class="flex px-6">
                    <button class="tab-btn active px-4 py-3 text-sm font-medium border-b-2 border-blue-500 text-blue-600" data-tab="view">View</button>
                    <button class="tab-btn px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700" data-tab="code">Code</button>
                </nav>
            </div>

            <!-- Tab Content -->
            <div class="p-6">
                <!-- View Tab -->
                <div class="tab-content" id="view-content" style="display: block;">
                    <div class="space-y-8">
                        <!-- Built-in Colors -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-3">Built-in Colors</h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <!-- Red Shades -->
                                <div class="bg-red-100 p-4 rounded-lg">bg-red-100</div>
                                <div class="bg-red-300 p-4 rounded-lg">bg-red-300</div>
                                <div class="bg-red-500 p-4 rounded-lg text-white">bg-red-500</div>
                                <div class="bg-red-700 p-4 rounded-lg text-white">bg-red-700</div>

                                <!-- Blue Shades -->
                                <div class="bg-blue-100 p-4 rounded-lg">bg-blue-100</div>
                                <div class="bg-blue-300 p-4 rounded-lg">bg-blue-300</div>
                                <div class="bg-blue-500 p-4 rounded-lg text-white">bg-blue-500</div>
                                <div class="bg-blue-700 p-4 rounded-lg text-white">bg-blue-700</div>

                                <!-- Green Shades -->
                                <div class="bg-green-100 p-4 rounded-lg">bg-green-100</div>
                                <div class="bg-green-300 p-4 rounded-lg">bg-green-300</div>
                                <div class="bg-green-500 p-4 rounded-lg text-white">bg-green-500</div>
                                <div class="bg-green-700 p-4 rounded-lg text-white">bg-green-700</div>
                            </div>
                        </div>

                        <!-- Text Colors -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-3">Text Colors</h3>
                            <div class="space-y-2">
                                <p class="text-red-500">This text is red-500</p>
                                <p class="text-blue-500">This text is blue-500</p>
                                <p class="text-green-500">This text is green-500</p>
                                <p class="text-yellow-500">This text is yellow-500</p>
                                <p class="text-purple-500">This text is purple-500</p>
                                <p class="text-pink-500">This text is pink-500</p>
                            </div>
                        </div>

                        <!-- Border Colors -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-3">Border Colors</h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <div class="border-2 border-red-500 p-4 rounded-lg">border-red-500</div>
                                <div class="border-2 border-blue-500 p-4 rounded-lg">border-blue-500</div>
                                <div class="border-2 border-green-500 p-4 rounded-lg">border-green-500</div>
                                <div class="border-2 border-yellow-500 p-4 rounded-lg">border-yellow-500</div>
                                <div class="border-2 border-purple-500 p-4 rounded-lg">border-purple-500</div>
                                <div class="border-2 border-pink-500 p-4 rounded-lg">border-pink-500</div>
                            </div>
                        </div>

                        <!-- Custom Brand Colors -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-3">Custom Brand Colors</h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <div class="bg-brand-light p-4 rounded-lg border border-brand">bg-brand-light</div>
                                <div class="bg-brand p-4 rounded-lg text-white">bg-brand</div>
                                <div class="bg-brand-dark p-4 rounded-lg text-white">bg-brand-dark</div>

                                <div class="bg-primary-50 p-4 rounded-lg">bg-primary-50</div>
                                <div class="bg-primary-300 p-4 rounded-lg">bg-primary-300</div>
                                <div class="bg-primary-600 p-4 rounded-lg text-white">bg-primary-600</div>
                                <div class="bg-primary-900 p-4 rounded-lg text-white">bg-primary-900</div>
                            </div>
                        </div>

                        <!-- Custom Named Colors -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-3">Custom Named Colors</h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div class="p-4 rounded-lg" style="background-color: var(--color-custom-red);">custom-red</div>
                                <div class="p-4 rounded-lg" style="background-color: var(--color-custom-green);">custom-green</div>
                                <div class="p-4 rounded-lg" style="background-color: var(--color-custom-blue); color: white;">custom-blue</div>
                                <div class="p-4 rounded-lg" style="background-color: var(--color-custom-purple); color: white;">custom-purple</div>
                                <div class="p-4 rounded-lg" style="background-color: var(--color-custom-orange);">custom-orange</div>
                                <div class="p-4 rounded-lg" style="background-color: var(--color-custom-yellow);">custom-yellow</div>
                                <div class="p-4 rounded-lg" style="background-color: var(--color-custom-cyan);">custom-cyan</div>
                                <div class="p-4 rounded-lg" style="background-color: var(--color-custom-pink); color: white;">custom-pink</div>
                            </div>
                        </div>

                        <!-- Inline Custom Colors -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-3">Inline Style Colors</h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <div class="p-4 rounded-lg" style="background-color: #FF5733;">Custom #FF5733</div>
                                <div class="p-4 rounded-lg" style="background-color: #33FF57;">Custom #33FF57</div>
                                <div class="p-4 rounded-lg" style="background-color: #3357FF; color: white;">Custom #3357FF</div>
                                <div class="p-4 rounded-lg" style="background-color: #B233FF; color: white;">Custom #B233FF</div>
                                <div class="p-4 rounded-lg" style="background-color: #FF33A8;">Custom #FF33A8</div>
                                <div class="p-4 rounded-lg" style="background-color: #33FFF6;">Custom #33FFF6</div>
                            </div>
                        </div>

                        <!-- Custom Components Using CSS Variables -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 mb-3">Custom Components Using CSS Variables</h3>
                            <div class="space-y-6">
                                <!-- Custom Buttons -->
                                <div>
                                    <h4 class="text-sm font-medium text-gray-700 mb-2">Custom Buttons</h4>
                                    <div class="flex flex-wrap gap-3">
                                        <button class="custom-button">Primary Button</button>
                                        <button class="custom-button" style="background-color: var(--color-custom-green);">Success Button</button>
                                        <button class="custom-button" style="background-color: var(--color-custom-red);">Danger Button</button>
                                        <button class="custom-button" style="background-color: var(--color-custom-purple);">Purple Button</button>
                                    </div>
                                </div>

                                <!-- Custom Cards -->
                                <div>
                                    <h4 class="text-sm font-medium text-gray-700 mb-2">Custom Cards</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="custom-card">
                                            <h5 class="custom-card-title">Card Title</h5>
                                            <p class="custom-card-body">This card is styled using CSS variables defined in our configuration.</p>
                                            <div class="mt-4">
                                                <button class="custom-button">Learn More</button>
                                            </div>
                                        </div>

                                        <div class="custom-card" style="border-color: var(--color-custom-purple); border-width: 2px;">
                                            <h5 class="custom-card-title" style="color: var(--color-custom-purple);">Custom Purple Card</h5>
                                            <p class="custom-card-body">This card has custom border and title color using CSS variables.</p>
                                            <div class="mt-4">
                                                <button class="custom-button" style="background-color: var(--color-custom-purple);">Learn More</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Code Tab -->
                <div class="tab-content" id="code-content" style="display: none;">
                    <div class="space-y-6">
                        <!-- CDN Usage -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">1. Include Tailwind CSS via CDN</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="cdn-code">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <pre><code class="language-html" id="cdn-code">&lt;!-- Option 1: Use the CDN (limited features) --&gt;
&lt;link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet"&gt;

&lt;!-- Option 2: Use the CDN with customization script --&gt;
&lt;script src="https://cdn.tailwindcss.com"&gt;&lt;/script&gt;
&lt;script&gt;
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          primary: '#3490dc',
          secondary: '#ffed4a',
          danger: '#e3342f',
        }
      }
    }
  }
&lt;/script&gt;</code></pre>
                        </div>

                        <!-- Custom Color Configuration -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">2. Configuring Custom Colors with CDN</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="config-code">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <pre><code class="language-html" id="config-code">&lt;!-- Add this BEFORE any content that uses custom colors --&gt;
&lt;script src="https://cdn.tailwindcss.com"&gt;&lt;/script&gt;
&lt;script&gt;
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          'custom-blue': '#1e40af',
          'custom-red': '#dc2626',
          'brand': {
            light: '#f9fafb',
            DEFAULT: '#3490dc',
            dark: '#1e3a8a',
          }
        }
      }
    }
  }
&lt;/script&gt;</code></pre>
                        </div>

                        <!-- Custom Color Usage -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">3. Using Custom Colors in HTML</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="usage-code">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <pre><code class="language-html" id="usage-code">&lt;!-- Using custom colors defined in tailwind.config --&gt;
&lt;div class="bg-custom-blue text-white p-4 rounded"&gt;
  This uses our custom blue color
&lt;/div&gt;

&lt;div class="bg-custom-red text-white p-4 rounded mt-2"&gt;
  This uses our custom red color
&lt;/div&gt;

&lt;div class="bg-brand text-white p-4 rounded mt-2"&gt;
  This uses our default brand color
&lt;/div&gt;

&lt;div class="bg-brand-light text-brand-dark p-4 rounded mt-2 border border-brand"&gt;
  This uses our brand color variants
&lt;/div&gt;

&lt;!-- Using shades of your primary color with number scale --&gt;
&lt;div class="bg-primary-100 p-4 rounded mt-2"&gt;Primary 100&lt;/div&gt;
&lt;div class="bg-primary-300 p-4 rounded mt-2"&gt;Primary 300&lt;/div&gt;
&lt;div class="bg-primary-500 text-white p-4 rounded mt-2"&gt;Primary 500&lt;/div&gt;
&lt;div class="bg-primary-700 text-white p-4 rounded mt-2"&gt;Primary 700&lt;/div&gt;
&lt;div class="bg-primary-900 text-white p-4 rounded mt-2"&gt;Primary 900&lt;/div&gt;</code></pre>
                        </div>

                        <!-- CSS Variables -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">4. Using CSS Variables</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="css-vars-code">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <pre><code class="language-html" id="css-vars-code">&lt;!-- Define CSS variables in your stylesheet --&gt;
&lt;style&gt;
:root {
  --color-brand-light: #ebf5ff;
  --color-brand: #3b82f6;
  --color-brand-dark: #1e40af;

  --color-custom-red: #dc2626;
  --color-custom-green: #16a34a;
  --color-custom-blue: #2563eb;
}
&lt;/style&gt;

&lt;!-- Then use them in your HTML with inline styles --&gt;
&lt;div class="p-4 rounded-lg" style="background-color: var(--color-brand);"&gt;
  Using brand color
&lt;/div&gt;

&lt;div class="p-4 rounded-lg text-white" style="background-color: var(--color-custom-blue);"&gt;
  Using custom blue
&lt;/div&gt;

&lt;!-- You can also use them in your component CSS --&gt;
&lt;style&gt;
.custom-button {
  background-color: var(--color-brand);
  color: white;
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
}
.custom-button:hover {
  background-color: var(--color-brand-dark);
}
&lt;/style&gt;</code></pre>
                        </div>

                        <!-- Inline Custom Colors -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-gray-900">5. Using Inline Styles for Custom Colors</h3>
                                <button class="copy-btn flex items-center px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 rounded-md transition-colors" data-copy="inline-code">
                                    <span class="material-symbols-outlined text-sm mr-1">content_copy</span>
                                    Copy
                                </button>
                            </div>
                            <pre><code class="language-html" id="inline-code">&lt;!-- Using inline styles for one-off custom colors --&gt;
&lt;div class="p-4 rounded-lg" style="background-color: #FF5733;"&gt;
  Custom background color
&lt;/div&gt;

&lt;p class="p-2" style="color: #3357FF;"&gt;
  Custom text color
&lt;/p&gt;

&lt;div class="p-4 rounded-lg" style="border: 2px solid #B233FF;"&gt;
  Custom border color
&lt;/div&gt;</code></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add some custom CSS classes using the CSS variables -->
<style>
.custom-button {
  background-color: var(--color-brand);
  color: white;
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.15s;
}

.custom-button:hover {
  background-color: var(--color-brand-dark);
}

.custom-card {
  background-color: white;
  border: 1px solid var(--color-brand-light);
  border-radius: 0.5rem;
  padding: 1rem;
  box-shadow: var(--shadow-sm);
}

.custom-card-title {
  color: var(--color-custom-blue);
  font-weight: 600;
  margin-bottom: 0.5rem;
  font-size: 1rem;
}

.custom-card-body {
  color: #4b5563;
  font-size: 0.875rem;
}
</style>

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

    // Copy functionality for code blocks
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