<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Alerts</h1>
                    <p class="text-gray-600 mb-6">Here you can find various alert messages for different scenarios.</p>

                    <!-- Content -->
                    <div class="space-y-10">
            <!-- Error Alert -->
            <div class="border-l-4 border-red-500 pl-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Error Alert</h3>

                <!-- Live Example -->
                <div class="mb-4">
                    <div class="alert-error">
                        <span class="material-icon material-symbols-outlined">error</span>
                        <p>Invalid or expired security token. Please try again.</p>
                    </div>
                </div>

                <!-- HTML Code -->
                <div>
                    <pre class="line-numbers language-markup"><code class="language-html"><div class="alert-error">
    <span class="material-icon material-symbols-outlined">error</span>
    <p>Invalid or expired security token. Please try again.</p>
</div>

</code></pre>
                </div>
            </div>

            <!-- Success Alert -->
            <div class="border-l-4 border-green-500 pl-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Success Alert</h3>

                <!-- Live Example -->
                <div class="mb-4">
                    <div class="alert-success">
                        <span class="material-icon material-symbols-outlined">check_circle</span>
                        <p>Password reset instructions sent to your email</p>
                    </div>
                </div>

                <!-- HTML Code -->
                <div>
                    <pre class="line-numbers language-markup"><code class="language-html"><div class="alert-success">
    <span class="material-icon material-symbols-outlined">check_circle</span>
    <p>Your success message here</p>
</div></code></pre>
                </div>
            </div>

            <!-- Warning Alert -->
            <div class="border-l-4 border-yellow-500 pl-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Warning Alert</h3>

                <!-- Live Example -->
                <div class="mb-4">
                    <div class="alert-warning">
                        <span class="material-icon material-symbols-outlined">warning</span>
                        <p>Please review your booking details before confirming</p>
                    </div>
                </div>

                <!-- HTML Code -->
                <div>
                    <pre class="line-numbers language-markup"><code class="language-html"><div class="alert-warning">
    <span class="material-icon material-symbols-outlined">warning</span>
    <p>Your warning message here</p>
</div></code></pre>
                </div>
            </div>

            <!-- Info Alert -->
            <div class="border-l-4 border-blue-500 pl-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Info Alert</h3>

                <!-- Live Example -->
                <div class="mb-4">
                    <div class="alert-info">
                        <span class="material-icon material-symbols-outlined">info</span>
                        <p>Your booking confirmation will be sent within 24 hours</p>
                    </div>
                </div>

                <!-- HTML Code -->
                <div>
                    <pre class="line-numbers language-markup"><code class="language-html"><div class="alert-info">
    <span class="material-icon material-symbols-outlined">info</span>
    <p>Your info message here</p>
</div></code></pre>
                </div>
            </div>

            <!-- Simple HTML Test (No Comments) -->
            <div class="border-l-4 border-purple-500 pl-4">
                <h3 class="text-sm font-medium text-gray-900 mb-3">Simple HTML Test (Auto-Converted)</h3>
                <p class="text-sm text-gray-600 mb-3">This demonstrates HTML without comments - JavaScript will auto-convert it:</p>

                <!-- HTML Code - Direct HTML (no comments) -->
                <div>
                    <pre class="line-numbers language-markup"><code class="language-html"><div class="card bg-white rounded-lg shadow-md p-6">
    <div class="card-header flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-800">Card Title</h3>
        <button class="btn btn-primary px-4 py-2 bg-blue-500 text-white rounded">
            Action Button
        </button>
    </div>
    <div class="card-body">
        <p class="text-gray-600 mb-4">This is some content with <strong>bold text</strong> and <em>italic text</em>.</p>
        <ul class="list-disc list-inside space-y-2">
            <li>First item with <a href="#" class="text-blue-500 hover:underline">a link</a></li>
            <li>Second item with nested content</li>
            <li>Third item with <code class="bg-gray-100 px-2 py-1 rounded">inline code</code></li>
        </ul>
        <div class="mt-4 p-4 bg-gray-50 rounded">
            <span class="material-icon text-blue-500">info</span>
            <span class="ml-2">This is a nested component</span>
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
