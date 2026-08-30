<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Toast Notifications</h1>
                    <p class="text-gray-600 mb-6">Interactive toast notifications with multiple types, positions, and customization options using toast.js</p>

                    <!-- Content -->
                    <div class="space-y-10" x-data="notificationController()">
                        
                        <!-- Basic Toast Types -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Toast Types</h3>
                            <p class="text-gray-600 mb-4">Standard toast notifications for different message types</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                    <button @click="showSuccess()" class="btn bg-green-600 text-white hover:bg-green-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">check_circle</span>
                                        <span>Success</span>
                                    </button>
                                    <button @click="showInfo()" class="btn bg-blue-600 text-white hover:bg-blue-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">info</span>
                                        <span>Info</span>
                                    </button>
                                    <button @click="showWarning()" class="btn bg-yellow-600 text-white hover:bg-yellow-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">warning</span>
                                        <span>Warning</span>
                                    </button>
                                    <button @click="showError()" class="btn bg-red-600 text-white hover:bg-red-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">error</span>
                                        <span>Error</span>
                                    </button>
                                </div>
                            </div>

                            <!-- JavaScript Code -->
                            <div>
                                <pre class="line-numbers language-javascript"><code class="language-javascript">// Basic toast notifications
vt.success('Operation completed successfully!');
vt.info('Here is some information for you.');
vt.warn('Please check your input before proceeding.');
vt.error('Something went wrong. Please try again.');</code></pre>
                            </div>
                        </div>

                        <!-- Toast Positions -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Toast Positions</h3>
                            <p class="text-gray-600 mb-4">Show toasts in different screen positions</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                                    <button @click="showPosition('top-left')" class="btn outline flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">north_west</span>
                                        <span>Top Left</span>
                                    </button>
                                    <button @click="showPosition('top-center')" class="btn outline flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">north</span>
                                        <span>Top Center</span>
                                    </button>
                                    <button @click="showPosition('top-right')" class="btn outline flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">north_east</span>
                                        <span>Top Right</span>
                                    </button>
                                    <button @click="showPosition('bottom-left')" class="btn outline flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">south_west</span>
                                        <span>Bottom Left</span>
                                    </button>
                                    <button @click="showPosition('bottom-center')" class="btn outline flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">south</span>
                                        <span>Bottom Center</span>
                                    </button>
                                    <button @click="showPosition('bottom-right')" class="btn outline flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">south_east</span>
                                        <span>Bottom Right</span>
                                    </button>
                                </div>
                            </div>

                            <!-- JavaScript Code -->
                            <div>
                                <pre class="line-numbers language-javascript"><code class="language-javascript">// Toast with custom position
vt.success('Message from top-right!', {
    position: 'top-right'
});

vt.info('Message from bottom-left!', {
    position: 'bottom-left'
});</code></pre>
                            </div>
                        </div>

                        <!-- Duration & Behavior -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Duration & Behavior</h3>
                            <p class="text-gray-600 mb-4">Control toast duration, persistence, and interaction behavior</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                    <button @click="showQuickToast()" class="btn light flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">flash_on</span>
                                        <span>Quick (2s)</span>
                                    </button>
                                    <button @click="showPersistentToast()" class="btn light flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">push_pin</span>
                                        <span>Persistent</span>
                                    </button>
                                    <button @click="showNonClosableToast()" class="btn light flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">lock</span>
                                        <span>Non-closable</span>
                                    </button>
                                    <button @click="showCallbackToast()" class="btn light flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">event</span>
                                        <span>With Callback</span>
                                    </button>
                                </div>
                            </div>

                            <!-- JavaScript Code -->
                            <div>
                                <pre class="line-numbers language-javascript"><code class="language-javascript">// Quick toast (2 seconds)
vt.success('Quick message!', { duration: 2000 });

// Persistent toast (stays until clicked)
vt.info('Click to dismiss', { duration: 0 });

// Non-closable toast
vt.warn('Auto-dismiss only', { 
    closable: false, 
    duration: 3000 
});

// Toast with callback
vt.success('Action completed!', {
    callback: () => {
        console.log('Toast was dismissed');
    }
});</code></pre>
                            </div>
                        </div>

                        <!-- Toast with Titles -->
                        <div class="border-l-4 border-yellow-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Toast with Titles</h3>
                            <p class="text-gray-600 mb-4">Add titles to your toast notifications for better context</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <button @click="showTitledSuccess()" class="btn bg-green-600 text-white hover:bg-green-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">task_alt</span>
                                        <span>Success with Title</span>
                                    </button>
                                    <button @click="showTitledError()" class="btn bg-red-600 text-white hover:bg-red-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">error_outline</span>
                                        <span>Error with Title</span>
                                    </button>
                                </div>
                            </div>

                            <!-- JavaScript Code -->
                            <div>
                                <pre class="line-numbers language-javascript"><code class="language-javascript">// Toast with title
vt.success('Your changes have been saved successfully!', {
    title: 'Settings Updated'
});

vt.error('Please check your internet connection and try again.', {
    title: 'Connection Failed'
});</code></pre>
                            </div>
                        </div>

                        <!-- Real-world Examples -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Real-world Examples</h3>
                            <p class="text-gray-600 mb-4">Practical examples of toast notifications in common scenarios</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <button @click="simulateFormSave()" class="btn bg-blue-600 text-white hover:bg-blue-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">save</span>
                                        <span>Form Save</span>
                                    </button>
                                    <button @click="simulateFileUpload()" class="btn bg-purple-600 text-white hover:bg-purple-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">cloud_upload</span>
                                        <span>File Upload</span>
                                    </button>
                                    <button @click="simulateNetworkError()" class="btn bg-orange-600 text-white hover:bg-orange-700 flex items-center justify-center space-x-2">
                                        <span class="material-symbols-outlined">wifi_off</span>
                                        <span>Network Error</span>
                                    </button>
                                </div>
                            </div>

                            <!-- JavaScript Code -->
                            <div>
                                <pre class="line-numbers language-javascript"><code class="language-javascript">// Form submission feedback
function handleFormSubmit() {
    vt.info('Saving your changes...', { 
        title: 'Please Wait',
        duration: 0 
    });
    
    // Simulate API call
    setTimeout(() => {
        vt.success('All changes have been saved successfully!', {
            title: 'Form Saved',
            position: 'top-right'
        });
    }, 2000);
}</code></pre>
                            </div>
                        </div>

                        <!-- Custom Toast Testing -->
                        <div class="border-l-4 border-indigo-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Custom Toast Testing</h3>
                            <p class="text-gray-600 mb-4">Create and test your own custom toast notifications</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                    <!-- Custom Toast Form -->
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                                            <input x-model="customMessage" type="text" placeholder="Enter your message..." class="input">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Title (Optional)</label>
                                            <input x-model="customTitle" type="text" placeholder="Enter title..." class="input">
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                                <select x-model="customType" class="select">
                                                    <option value="success">Success</option>
                                                    <option value="info">Info</option>
                                                    <option value="warn">Warning</option>
                                                    <option value="error">Error</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                                                <select x-model="customPosition" class="select">
                                                    <option value="top-left">Top Left</option>
                                                    <option value="top-center">Top Center</option>
                                                    <option value="top-right">Top Right</option>
                                                    <option value="bottom-left">Bottom Left</option>
                                                    <option value="bottom-center">Bottom Center</option>
                                                    <option value="bottom-right">Bottom Right</option>
                                                </select>
                                            </div>
                                        </div>
                                        <button @click="showCustomToast()" class="btn w-full flex items-center justify-center space-x-2">
                                            <span class="material-symbols-outlined">play_arrow</span>
                                            <span>Show Custom Toast</span>
                                        </button>
                                    </div>
                                    
                                    <!-- Generated Code Preview -->
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-semibold text-gray-700 mb-2">Generated Code:</h4>
                                        <pre class="text-sm"><code x-text="generateCode()" class="language-javascript"></code></pre>
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
function notificationController() {
    return {
        // Custom toast form data
        customMessage: 'This is a custom toast message!',
        customTitle: '',
        customType: 'success',
        customPosition: 'top-center',

        // Basic toast methods
        showSuccess() {
            vt.success('Operation completed successfully! Everything is working as expected.');
        },

        showInfo() {
            vt.info('Here is some useful information for you to consider before proceeding.');
        },

        showWarning() {
            vt.warn('Please review your input carefully before submitting the form.');
        },

        showError() {
            vt.error('Something went wrong while processing your request. Please try again.');
        },

        // Position demonstrations
        showPosition(position) {
            vt.info(`This toast is displayed in ${position} position!`, {
                position: position,
                title: 'Position Demo'
            });
        },

        // Duration & behavior examples
        showQuickToast() {
            vt.success('This message disappears quickly!', { 
                duration: 2000,
                title: 'Quick Toast'
            });
        },

        showPersistentToast() {
            vt.info('This toast stays until you click it. Perfect for important messages!', { 
                duration: 0,
                title: 'Persistent Toast'
            });
        },

        showNonClosableToast() {
            vt.warn('This toast cannot be closed manually and will auto-dismiss in 4 seconds.', { 
                closable: false, 
                duration: 4000,
                title: 'Auto-dismiss Only'
            });
        },

        showCallbackToast() {
            vt.success('This toast has a callback function that runs when dismissed.', {
                title: 'Callback Demo',
                callback: () => {
                    vt.info('Callback executed! The previous toast was dismissed.', {
                        title: 'Callback Triggered',
                        position: 'bottom-right'
                    });
                }
            });
        },

        // Titled toast examples
        showTitledSuccess() {
            vt.success('Your profile information has been updated successfully and all changes are now live!', {
                title: 'Profile Updated'
            });
        },

        showTitledError() {
            vt.error('The server encountered an unexpected error while processing your request. Please check your connection and try again.', {
                title: 'Server Error'
            });
        },

        // Real-world scenario simulations
        simulateFormSave() {
            vt.info('Saving your changes, please wait...', { 
                title: 'Saving Form',
                duration: 0,
                closable: false
            });
            
            setTimeout(() => {
                vt.success('All form data has been saved successfully!', {
                    title: 'Form Saved',
                    position: 'top-right'
                });
            }, 2500);
        },

        simulateFileUpload() {
            vt.info('Uploading file to server, please do not close this window...', {
                title: 'File Upload in Progress',
                closable: false,
                duration: 0
            });
            
            setTimeout(() => {
                vt.success('File uploaded successfully! You can now continue with your work.', {
                    title: 'Upload Complete',
                    position: 'top-right'
                });
            }, 3500);
        },

        simulateNetworkError() {
            vt.error('Unable to connect to the server. Please check your internet connection and try again.', {
                title: 'Network Connection Failed',
                position: 'top-right',
                duration: 8000
            });
        },

        // Custom toast method
        showCustomToast() {
            const options = {
                position: this.customPosition
            };

            if (this.customTitle.trim()) {
                options.title = this.customTitle;
            }

            vt[this.customType](this.customMessage, options);
        },

        // Generate code preview
        generateCode() {
            const options = [];
            
            if (this.customTitle.trim()) {
                options.push(`title: '${this.customTitle}'`);
            }
            if (this.customPosition !== 'top-center') {
                options.push(`position: '${this.customPosition}'`);
            }

            const optionsStr = options.length > 0 ? `, {\n    ${options.join(',\n    ')}\n}` : '';
            
            return `vt.${this.customType}('${this.customMessage}'${optionsStr});`;
        }
    }
}
</script>