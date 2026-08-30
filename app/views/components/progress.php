<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Progress Components</h1>
                    <p class="text-gray-600 mb-6">Progress bars, indicators, and loading components for displaying task completion, loading states, and data visualization.</p>

                    <!-- Content -->
                    <div class="space-y-10" x-data="progressController()">
                        
                        <!-- Basic Progress Bars -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Basic Progress Bars</h3>
                            <p class="text-gray-600 mb-4">Standard progress bars with different colors and completion levels</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="space-y-4">
                                    <div>
                                        <div class="flex justify-between text-sm font-medium text-gray-700 mb-1">
                                            <span>Basic Progress</span>
                                            <span>75%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-blue-500" style="width: 75%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-sm font-medium text-gray-700 mb-1">
                                            <span>Success Progress</span>
                                            <span>100%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-green-500" style="width: 100%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-sm font-medium text-gray-700 mb-1">
                                            <span>Warning Progress</span>
                                            <span>60%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-yellow-500" style="width: 60%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-sm font-medium text-gray-700 mb-1">
                                            <span>Error Progress</span>
                                            <span>45%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-red-500" style="width: 45%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="flex justify-between text-sm font-medium text-gray-700 mb-1">
    <span>Basic Progress</span>
    <span>75%</span>
</div>
<div class="progress">
    <div class="progress-bar bg-blue-500" style="width: 75%"></div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Progress Sizes -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Progress Bar Sizes</h3>
                            <p class="text-gray-600 mb-4">Different heights and sizes for various use cases</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="space-y-4">
                                    <div>
                                        <label class="text-sm font-medium text-gray-700 mb-1 block">Small Progress</label>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar bg-blue-500" style="width: 65%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700 mb-1 block">Medium Progress (Default)</label>
                                        <div class="progress">
                                            <div class="progress-bar bg-blue-500" style="width: 65%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700 mb-1 block">Large Progress</label>
                                        <div class="progress progress-lg">
                                            <div class="progress-bar bg-blue-500" style="width: 65%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-700 mb-1 block">Extra Large Progress</label>
                                        <div class="progress progress-xl">
                                            <div class="progress-bar bg-blue-500" style="width: 65%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="progress progress-sm">
    <div class="progress-bar bg-blue-500" style="width: 65%"></div>
</div>

<div class="progress progress-lg">
    <div class="progress-bar bg-blue-500" style="width: 65%"></div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Animated Progress -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Animated Progress Bars</h3>
                            <p class="text-gray-600 mb-4">Progress bars with smooth animations and loading effects</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="space-y-4">
                                    <div>
                                        <div class="flex justify-between text-sm font-medium text-gray-700 mb-1">
                                            <span>Animated Progress</span>
                                            <span x-text="animatedProgress + '%'"></span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-blue-500 transition-all duration-500 ease-out" 
                                                 :style="'width: ' + animatedProgress + '%'"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-sm font-medium text-gray-700 mb-1">
                                            <span>Striped Progress</span>
                                            <span>70%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar progress-striped bg-green-500" style="width: 70%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-sm font-medium text-gray-700 mb-1">
                                            <span>Animated Stripes</span>
                                            <span>80%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar progress-striped progress-animated bg-purple-500" style="width: 80%"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button @click="startAnimation()" class="btn btn-primary btn-sm">Start Animation</button>
                                    <button @click="resetAnimation()" class="btn btn-secondary btn-sm ml-2">Reset</button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Animated Progress -->
<div class="progress">
    <div class="progress-bar bg-blue-500 transition-all duration-500 ease-out" style="width: 75%"></div>
</div>

<!-- Striped Progress -->
<div class="progress">
    <div class="progress-bar progress-striped bg-green-500" style="width: 70%"></div>
</div>

<!-- Animated Stripes -->
<div class="progress">
    <div class="progress-bar progress-striped progress-animated bg-purple-500" style="width: 80%"></div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Circular Progress -->
                        <div class="border-l-4 border-yellow-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Circular Progress Indicators</h3>
                            <p class="text-gray-600 mb-4">Round progress indicators with percentage display</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                                    <!-- Small Circular Progress -->
                                    <div class="flex flex-col items-center">
                                        <div class="relative w-16 h-16">
                                            <svg class="w-16 h-16 transform -rotate-90">
                                                <circle cx="32" cy="32" r="28" stroke="currentColor" stroke-width="4" fill="transparent" class="text-gray-200"/>
                                                <circle cx="32" cy="32" r="28" stroke="currentColor" stroke-width="4" fill="transparent" 
                                                        stroke-dasharray="175.93" stroke-dashoffset="52.78" class="text-blue-500 transition-all duration-300"/>
                                            </svg>
                                            <div class="absolute inset-0 flex items-center justify-center">
                                                <span class="text-xs font-semibold text-gray-700">70%</span>
                                            </div>
                                        </div>
                                        <span class="text-xs text-gray-600 mt-2">Small</span>
                                    </div>

                                    <!-- Medium Circular Progress -->
                                    <div class="flex flex-col items-center">
                                        <div class="relative w-20 h-20">
                                            <svg class="w-20 h-20 transform -rotate-90">
                                                <circle cx="40" cy="40" r="36" stroke="currentColor" stroke-width="4" fill="transparent" class="text-gray-200"/>
                                                <circle cx="40" cy="40" r="36" stroke="currentColor" stroke-width="4" fill="transparent" 
                                                        stroke-dasharray="226.19" stroke-dashoffset="67.86" class="text-green-500 transition-all duration-300"/>
                                            </svg>
                                            <div class="absolute inset-0 flex items-center justify-center">
                                                <span class="text-sm font-semibold text-gray-700">70%</span>
                                            </div>
                                        </div>
                                        <span class="text-xs text-gray-600 mt-2">Medium</span>
                                    </div>

                                    <!-- Large Circular Progress -->
                                    <div class="flex flex-col items-center">
                                        <div class="relative w-24 h-24">
                                            <svg class="w-24 h-24 transform -rotate-90">
                                                <circle cx="48" cy="48" r="44" stroke="currentColor" stroke-width="4" fill="transparent" class="text-gray-200"/>
                                                <circle cx="48" cy="48" r="44" stroke="currentColor" stroke-width="4" fill="transparent" 
                                                        stroke-dasharray="276.46" stroke-dashoffset="82.94" class="text-purple-500 transition-all duration-300"/>
                                            </svg>
                                            <div class="absolute inset-0 flex items-center justify-center">
                                                <span class="text-sm font-semibold text-gray-700">70%</span>
                                            </div>
                                        </div>
                                        <span class="text-xs text-gray-600 mt-2">Large</span>
                                    </div>

                                    <!-- Animated Circular Progress -->
                                    <div class="flex flex-col items-center">
                                        <div class="relative w-24 h-24">
                                            <svg class="w-24 h-24 transform -rotate-90">
                                                <circle cx="48" cy="48" r="44" stroke="currentColor" stroke-width="4" fill="transparent" class="text-gray-200"/>
                                                <circle cx="48" cy="48" r="44" stroke="currentColor" stroke-width="4" fill="transparent" 
                                                        :stroke-dasharray="276.46" 
                                                        :stroke-dashoffset="276.46 - (276.46 * circularProgress / 100)" 
                                                        class="text-red-500 transition-all duration-500"/>
                                            </svg>
                                            <div class="absolute inset-0 flex items-center justify-center">
                                                <span class="text-sm font-semibold text-gray-700" x-text="circularProgress + '%'"></span>
                                            </div>
                                        </div>
                                        <span class="text-xs text-gray-600 mt-2">Animated</span>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <button @click="startCircularAnimation()" class="btn btn-primary btn-sm">Animate Circular</button>
                                    <button @click="resetCircularAnimation()" class="btn btn-secondary btn-sm ml-2">Reset</button>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="relative w-24 h-24">
    <svg class="w-24 h-24 transform -rotate-90">
        <circle cx="48" cy="48" r="44" stroke="currentColor" stroke-width="4" 
                fill="transparent" class="text-gray-200"/>
        <circle cx="48" cy="48" r="44" stroke="currentColor" stroke-width="4" 
                fill="transparent" stroke-dasharray="276.46" stroke-dashoffset="82.94" 
                class="text-purple-500 transition-all duration-300"/>
    </svg>
    <div class="absolute inset-0 flex items-center justify-center">
        <span class="text-sm font-semibold text-gray-700">70%</span>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Multi-step Progress -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Multi-step Progress</h3>
                            <p class="text-gray-600 mb-4">Step-by-step progress indicators for wizards and forms</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="space-y-6">
                                    <!-- Horizontal Steps -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-700 mb-4">Horizontal Steps</h4>
                                        <div class="flex items-center">
                                            <div class="flex items-center text-blue-600">
                                                <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-white text-sm">check</span>
                                                </div>
                                                <span class="ml-2 text-sm font-medium">Personal Info</span>
                                            </div>
                                            <div class="flex-1 h-0.5 bg-blue-600 mx-4"></div>
                                            <div class="flex items-center text-blue-600">
                                                <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-white text-sm">check</span>
                                                </div>
                                                <span class="ml-2 text-sm font-medium">Account Details</span>
                                            </div>
                                            <div class="flex-1 h-0.5 bg-blue-600 mx-4"></div>
                                            <div class="flex items-center text-blue-600">
                                                <div class="w-8 h-8 bg-blue-600 border-2 border-blue-600 rounded-full flex items-center justify-center">
                                                    <span class="text-white text-sm font-bold">3</span>
                                                </div>
                                                <span class="ml-2 text-sm font-medium">Verification</span>
                                            </div>
                                            <div class="flex-1 h-0.5 bg-gray-300 mx-4"></div>
                                            <div class="flex items-center text-gray-400">
                                                <div class="w-8 h-8 bg-gray-300 rounded-full flex items-center justify-center">
                                                    <span class="text-gray-600 text-sm font-bold">4</span>
                                                </div>
                                                <span class="ml-2 text-sm font-medium">Complete</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Interactive Steps -->
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-700 mb-4">Interactive Steps</h4>
                                        <div class="flex items-center justify-between">
                                            <template x-for="(step, index) in steps" :key="index">
                                                <div class="flex items-center" :class="index < steps.length - 1 ? 'flex-1' : ''">
                                                    <div class="flex items-center">
                                                        <div @click="setCurrentStep(index)" 
                                                             :class="getStepClass(index)" 
                                                             class="w-8 h-8 rounded-full flex items-center justify-center cursor-pointer transition-colors">
                                                            <span x-show="index < currentStep" class="material-symbols-outlined text-white text-sm">check</span>
                                                            <span x-show="index >= currentStep" class="text-sm font-bold" 
                                                                  :class="index === currentStep ? 'text-white' : (index < currentStep ? 'text-white' : 'text-gray-600')" 
                                                                  x-text="index + 1"></span>
                                                        </div>
                                                        <span class="ml-2 text-sm font-medium" 
                                                              :class="index <= currentStep ? 'text-blue-600' : 'text-gray-400'" 
                                                              x-text="step.name"></span>
                                                    </div>
                                                    <div x-show="index < steps.length - 1" 
                                                         :class="index < currentStep ? 'bg-blue-600' : 'bg-gray-300'" 
                                                         class="flex-1 h-0.5 mx-4 transition-colors"></div>
                                                </div>
                                            </template>
                                        </div>
                                        <div class="mt-4 flex space-x-2">
                                            <button @click="previousStep()" :disabled="currentStep === 0" 
                                                    class="btn btn-secondary btn-sm" :class="currentStep === 0 ? 'opacity-50 cursor-not-allowed' : ''">Previous</button>
                                            <button @click="nextStep()" :disabled="currentStep === steps.length - 1" 
                                                    class="btn btn-primary btn-sm" :class="currentStep === steps.length - 1 ? 'opacity-50 cursor-not-allowed' : ''">Next</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Horizontal Steps -->
<div class="flex items-center">
    <div class="flex items-center text-blue-600">
        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center">
            <span class="material-symbols-outlined text-white text-sm">check</span>
        </div>
        <span class="ml-2 text-sm font-medium">Personal Info</span>
    </div>
    <div class="flex-1 h-0.5 bg-blue-600 mx-4"></div>
    <!-- Repeat for other steps -->
</div></code></pre>
                            </div>
                        </div>

                        <!-- Loading Spinners -->
                        <div class="border-l-4 border-indigo-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Loading Spinners</h3>
                            <p class="text-gray-600 mb-4">Various loading indicators and spinners</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                                    <!-- Basic Spinner -->
                                    <div class="flex flex-col items-center">
                                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                        <span class="text-xs text-gray-600 mt-2">Basic Spinner</span>
                                    </div>

                                    <!-- Dots Spinner -->
                                    <div class="flex flex-col items-center">
                                        <div class="flex space-x-1">
                                            <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce"></div>
                                            <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce" style="animation-delay: 0.1s;"></div>
                                            <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce" style="animation-delay: 0.2s;"></div>
                                        </div>
                                        <span class="text-xs text-gray-600 mt-2">Dots</span>
                                    </div>

                                    <!-- Pulse Spinner -->
                                    <div class="flex flex-col items-center">
                                        <div class="relative">
                                            <div class="w-8 h-8 bg-blue-600 rounded-full animate-ping absolute"></div>
                                            <div class="w-8 h-8 bg-blue-600 rounded-full"></div>
                                        </div>
                                        <span class="text-xs text-gray-600 mt-2">Pulse</span>
                                    </div>

                                    <!-- Ring Spinner -->
                                    <div class="flex flex-col items-center">
                                        <div class="animate-spin rounded-full h-8 w-8 border-4 border-gray-200 border-t-blue-600"></div>
                                        <span class="text-xs text-gray-600 mt-2">Ring</span>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- Basic Spinner -->
<div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>

<!-- Dots Spinner -->
<div class="flex space-x-1">
    <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce"></div>
    <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce" style="animation-delay: 0.1s;"></div>
    <div class="w-2 h-2 bg-blue-600 rounded-full animate-bounce" style="animation-delay: 0.2s;"></div>
</div>

<!-- Ring Spinner -->
<div class="animate-spin rounded-full h-8 w-8 border-4 border-gray-200 border-t-blue-600"></div></code></pre>
                            </div>
                        </div>

                        <!-- Progress with Labels -->
                        <div class="border-l-4 border-pink-500 pl-4">
                            <h3 class="text-sm font-medium text-gray-900 mb-3">Progress with Labels and Status</h3>
                            <p class="text-gray-600 mb-4">Progress bars with detailed labels, descriptions, and status indicators</p>

                            <!-- Live Example -->
                            <div class="mb-4">
                                <div class="space-y-6">
                                    <!-- File Upload Progress -->
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <div class="flex items-center justify-between mb-2">
                                            <div class="flex items-center">
                                                <span class="material-symbols-outlined text-gray-600 mr-2">description</span>
                                                <span class="text-sm font-medium text-gray-900">document.pdf</span>
                                                <span class="text-xs text-gray-500 ml-2">(2.4 MB)</span>
                                            </div>
                                            <span class="text-xs text-gray-500">85% complete</span>
                                        </div>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar bg-blue-500" style="width: 85%"></div>
                                        </div>
                                        <div class="flex justify-between text-xs text-gray-500 mt-1">
                                            <span>2.0 MB uploaded</span>
                                            <span>30 seconds remaining</span>
                                        </div>
                                    </div>

                                    <!-- Task Progress -->
                                    <div class="space-y-3">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <span class="material-symbols-outlined text-green-600 mr-2">check_circle</span>
                                                <span class="text-sm text-gray-900">Setup Database</span>
                                            </div>
                                            <span class="badge badge-success badge-sm">Complete</span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <span class="material-symbols-outlined text-blue-600 mr-2 animate-spin">refresh</span>
                                                <span class="text-sm text-gray-900">Installing Dependencies</span>
                                            </div>
                                            <span class="badge badge-info badge-sm">In Progress</span>
                                        </div>
                                        <div class="progress progress-sm ml-6">
                                            <div class="progress-bar bg-blue-500" style="width: 60%"></div>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <span class="material-symbols-outlined text-gray-400 mr-2">schedule</span>
                                                <span class="text-sm text-gray-500">Configure Settings</span>
                                            </div>
                                            <span class="badge badge-gray badge-sm">Pending</span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <span class="material-symbols-outlined text-gray-400 mr-2">schedule</span>
                                                <span class="text-sm text-gray-500">Deploy Application</span>
                                            </div>
                                            <span class="badge badge-gray badge-sm">Pending</span>
                                        </div>
                                    </div>

                                    <!-- Skill Progress -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <div class="flex justify-between items-center mb-1">
                                                <span class="text-sm font-medium text-gray-700">JavaScript</span>
                                                <span class="text-sm text-gray-500">90%</span>
                                            </div>
                                            <div class="progress progress-sm">
                                                <div class="progress-bar bg-yellow-500" style="width: 90%"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="flex justify-between items-center mb-1">
                                                <span class="text-sm font-medium text-gray-700">React</span>
                                                <span class="text-sm text-gray-500">85%</span>
                                            </div>
                                            <div class="progress progress-sm">
                                                <div class="progress-bar bg-blue-500" style="width: 85%"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="flex justify-between items-center mb-1">
                                                <span class="text-sm font-medium text-gray-700">PHP</span>
                                                <span class="text-sm text-gray-500">80%</span>
                                            </div>
                                            <div class="progress progress-sm">
                                                <div class="progress-bar bg-purple-500" style="width: 80%"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="flex justify-between items-center mb-1">
                                                <span class="text-sm font-medium text-gray-700">Python</span>
                                                <span class="text-sm text-gray-500">75%</span>
                                            </div>
                                            <div class="progress progress-sm">
                                                <div class="progress-bar bg-green-500" style="width: 75%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- HTML Code -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><!-- File Upload Progress -->
<div class="bg-gray-50 p-4 rounded-lg">
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center">
            <span class="material-symbols-outlined text-gray-600 mr-2">description</span>
            <span class="text-sm font-medium text-gray-900">document.pdf</span>
            <span class="text-xs text-gray-500 ml-2">(2.4 MB)</span>
        </div>
        <span class="text-xs text-gray-500">85% complete</span>
    </div>
    <div class="progress progress-sm">
        <div class="progress-bar bg-blue-500" style="width: 85%"></div>
    </div>
    <div class="flex justify-between text-xs text-gray-500 mt-1">
        <span>2.0 MB uploaded</span>
        <span>30 seconds remaining</span>
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

<!-- Custom CSS for Progress Components -->
<style>
/* Progress Bar Base Styles */
.progress {
    width: 100%;
    background-color: #e5e7eb;
    border-radius: 9999px;
    overflow: hidden;
    height: 8px;
}

.progress-sm {
    height: 4px;
}

.progress-lg {
    height: 12px;
}

.progress-xl {
    height: 16px;
}

.progress-bar {
    height: 100%;
    transition: all 0.3s ease-out;
}

/* Striped Progress */
.progress-striped {
    background-image: linear-gradient(
        45deg,
        rgba(255, 255, 255, 0.15) 25%,
        transparent 25%,
        transparent 50%,
        rgba(255, 255, 255, 0.15) 50%,
        rgba(255, 255, 255, 0.15) 75%,
        transparent 75%,
        transparent
    );
    background-size: 1rem 1rem;
}

/* Animated Stripes */
.progress-animated {
    animation: progress-bar-stripes 1s linear infinite;
}

@keyframes progress-bar-stripes {
    0% {
        background-position: 1rem 0;
    }
    100% {
        background-position: 0 0;
    }
}

/* Button styles for consistency */
.btn {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    transition: background-color 0.2s;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background-color: #2563eb;
    color: white;
}

.btn-primary:hover {
    background-color: #1d4ed8;
}

.btn-secondary {
    background-color: #d1d5db;
    color: #374151;
}

.btn-secondary:hover {
    background-color: #9ca3af;
}

.btn-sm {
    padding: 0.25rem 0.75rem;
    font-size: 0.875rem;
}

/* Badge styles for consistency */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-success {
    background-color: #dcfce7;
    color: #166534;
}

.badge-info {
    background-color: #dbeafe;
    color: #1e40af;
}

.badge-gray {
    background-color: #f3f4f6;
    color: #1f2937;
}

.badge-sm {
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
}
</style>

<script>
function progressController() {
    return {
        // Animated progress data
        animatedProgress: 0,
        circularProgress: 0,
        
        // Steps data
        currentStep: 2,
        steps: [
            { name: 'Personal Info', completed: true },
            { name: 'Account Details', completed: true },
            { name: 'Verification', completed: false },
            { name: 'Complete', completed: false }
        ],

        // Animation functions
        startAnimation() {
            this.animatedProgress = 0;
            const interval = setInterval(() => {
                this.animatedProgress += 2;
                if (this.animatedProgress >= 75) {
                    clearInterval(interval);
                }
            }, 50);
        },

        resetAnimation() {
            this.animatedProgress = 0;
        },

        startCircularAnimation() {
            this.circularProgress = 0;
            const interval = setInterval(() => {
                this.circularProgress += 2;
                if (this.circularProgress >= 85) {
                    clearInterval(interval);
                }
            }, 50);
        },

        resetCircularAnimation() {
            this.circularProgress = 0;
        },

        // Step functions
        setCurrentStep(step) {
            this.currentStep = step;
        },

        nextStep() {
            if (this.currentStep < this.steps.length - 1) {
                this.currentStep++;
            }
        },

        previousStep() {
            if (this.currentStep > 0) {
                this.currentStep--;
            }
        },

        getStepClass(index) {
            if (index < this.currentStep) {
                return 'bg-blue-600 text-white';
            } else if (index === this.currentStep) {
                return 'bg-blue-600 text-white border-2 border-blue-600';
            } else {
                return 'bg-gray-300 text-gray-600';
            }
        }
    }
}
</script>

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