<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Checkbox Components</h1>
                    <p class="text-gray-600 mb-6">Checkbox components with custom styling, states, and interactive functionality.</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Basic Checkbox -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Basic Checkbox</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="basic1" class="checkbox-input">
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="basic1" class="cursor-pointer">I agree to the terms and conditions</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="basic2" class="checkbox-input" checked>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="basic2" class="cursor-pointer">Subscribe to newsletter</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="basic3" class="checkbox-input">
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="basic3" class="cursor-pointer">Remember my preferences</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Basic Checkbox</label>
    <div class="checkbox-group">
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="basic1" class="checkbox-input">
                <div class="checkbox-custom">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
            </div>
            <label for="basic1" class="cursor-pointer">I agree to the terms and conditions</label>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Checkbox with Colors -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Colored Checkboxes</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="color1" class="checkbox-input" checked>
                                            <div class="checkbox-custom !border-green-500 checked:!bg-green-500">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="color1" class="cursor-pointer">Green checkbox</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="color2" class="checkbox-input" checked>
                                            <div class="checkbox-custom !border-red-500 checked:!bg-red-500">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="color2" class="cursor-pointer">Red checkbox</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="color3" class="checkbox-input" checked>
                                            <div class="checkbox-custom !border-purple-500 checked:!bg-purple-500">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="color3" class="cursor-pointer">Purple checkbox</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="color4" class="checkbox-input" checked>
                                            <div class="checkbox-custom !border-orange-500 checked:!bg-orange-500">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="color4" class="cursor-pointer">Orange checkbox</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Colored Checkboxes</label>
    <div class="checkbox-group">
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="color1" class="checkbox-input" checked>
                <div class="checkbox-custom !border-green-500 checked:!bg-green-500">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
            </div>
            <label for="color1" class="cursor-pointer">Green checkbox</label>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Checkbox Sizes -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Checkbox Sizes</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="size1" class="checkbox-input" checked>
                                            <div class="checkbox-custom !w-3 !h-3">
                                                <span class="material-symbols-outlined text-white checkbox-icon" style="font-size: 10px;">check</span>
                                            </div>
                                        </div>
                                        <label for="size1" class="cursor-pointer text-sm">Small checkbox</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="size2" class="checkbox-input" checked>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="size2" class="cursor-pointer">Default checkbox</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="size3" class="checkbox-input" checked>
                                            <div class="checkbox-custom !w-5 !h-5">
                                                <span class="material-symbols-outlined text-white text-sm checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="size3" class="cursor-pointer text-lg">Large checkbox</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Checkbox Sizes</label>
    <div class="checkbox-group">
        <!-- Small -->
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="size1" class="checkbox-input" checked>
                <div class="checkbox-custom !w-3 !h-3">
                    <span class="material-symbols-outlined text-white checkbox-icon" style="font-size: 10px;">check</span>
                </div>
            </div>
            <label for="size1" class="cursor-pointer text-sm">Small checkbox</label>
        </div>
        <!-- Large -->
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="size3" class="checkbox-input" checked>
                <div class="checkbox-custom !w-5 !h-5">
                    <span class="material-symbols-outlined text-white text-sm checkbox-icon">check</span>
                </div>
            </div>
            <label for="size3" class="cursor-pointer text-lg">Large checkbox</label>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Interactive Checkbox List -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Interactive Todo List</label>
                                <div x-data="{
                                    tasks: [
                                        { id: 1, text: 'Complete project documentation', completed: false },
                                        { id: 2, text: 'Review code changes', completed: true },
                                        { id: 3, text: 'Update dependencies', completed: false },
                                        { id: 4, text: 'Run test suite', completed: true },
                                        { id: 5, text: 'Deploy to staging', completed: false }
                                    ],
                                    get completedCount() {
                                        return this.tasks.filter(task => task.completed).length;
                                    },
                                    get totalTasks() {
                                        return this.tasks.length;
                                    }
                                }">
                                    <div class="bg-gray-50 p-4 rounded-lg mb-4">
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-sm font-medium">Progress</span>
                                            <span class="text-sm text-gray-600" x-text="completedCount + '/' + totalTasks + ' completed'"></span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" :style="'width: ' + (completedCount / totalTasks * 100) + '%'"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-3">
                                        <template x-for="task in tasks" :key="task.id">
                                            <div class="checkbox-item flex items-center p-3 rounded-lg hover:bg-gray-50 transition-colors">
                                                <div class="checkbox-container mr-3">
                                                    <input type="checkbox" :id="'task-' + task.id" class="checkbox-input" x-model="task.completed">
                                                    <div class="checkbox-custom">
                                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                    </div>
                                                </div>
                                                <label :for="'task-' + task.id" class="cursor-pointer flex-1" :class="task.completed ? 'line-through text-gray-500' : 'text-gray-900'" x-text="task.text"></label>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Interactive Todo List</label>
    <div x-data="{
        tasks: [
            { id: 1, text: 'Complete project documentation', completed: false },
            { id: 2, text: 'Review code changes', completed: true }
        ],
        get completedCount() {
            return this.tasks.filter(task => task.completed).length;
        }
    }">
        <!-- Progress Bar -->
        <div class="bg-gray-50 p-4 rounded-lg mb-4">
            <div class="w-full bg-gray-200 rounded-full h-2">
                <div class="bg-blue-600 h-2 rounded-full" :style="'width: ' + (completedCount / tasks.length * 100) + '%'"></div>
            </div>
        </div>
        
        <!-- Task List -->
        <template x-for="task in tasks" :key="task.id">
            <div class="checkbox-item">
                <div class="checkbox-container">
                    <input type="checkbox" :id="'task-' + task.id" class="checkbox-input" x-model="task.completed">
                    <div class="checkbox-custom">
                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                    </div>
                </div>
                <label :for="'task-' + task.id" class="cursor-pointer" :class="task.completed ? 'line-through text-gray-500' : 'text-gray-900'" x-text="task.text"></label>
            </div>
        </template>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Select All Checkbox -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Select All with Individual Controls</label>
                                <div x-data="{
                                    selectAll: false,
                                    items: [
                                        { id: 1, name: 'John Doe', selected: false },
                                        { id: 2, name: 'Jane Smith', selected: false },
                                        { id: 3, name: 'Bob Johnson', selected: false },
                                        { id: 4, name: 'Alice Brown', selected: false }
                                    ],
                                    toggleAll() {
                                        this.items.forEach(item => item.selected = this.selectAll);
                                    },
                                    updateSelectAll() {
                                        this.selectAll = this.items.every(item => item.selected);
                                    },
                                    get selectedCount() {
                                        return this.items.filter(item => item.selected).length;
                                    }
                                }" 
                                x-init="$watch('selectAll', () => toggleAll())"
                                >
                                    <!-- Select All Header -->
                                    <div class="border-b border-gray-200 pb-3 mb-3">
                                        <div class="checkbox-item">
                                            <div class="checkbox-container">
                                                <input type="checkbox" id="selectAll" class="checkbox-input" x-model="selectAll">
                                                <div class="checkbox-custom">
                                                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                </div>
                                            </div>
                                            <label for="selectAll" class="cursor-pointer font-medium">
                                                Select All (<span x-text="selectedCount"></span> of <span x-text="items.length"></span>)
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <!-- Individual Items -->
                                    <div class="space-y-2">
                                        <template x-for="item in items" :key="item.id">
                                            <div class="checkbox-item pl-4">
                                                <div class="checkbox-container">
                                                    <input type="checkbox" :id="'item-' + item.id" class="checkbox-input" x-model="item.selected" @change="updateSelectAll()">
                                                    <div class="checkbox-custom">
                                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                    </div>
                                                </div>
                                                <label :for="'item-' + item.id" class="cursor-pointer" x-text="item.name"></label>
                                            </div>
                                        </template>
                                    </div>
                                    
                                    <div class="mt-4 text-sm text-gray-600" x-show="selectedCount > 0">
                                        <span x-text="selectedCount"></span> item(s) selected
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Select All with Individual Controls</label>
    <div x-data="{
        selectAll: false,
        items: [
            { id: 1, name: 'John Doe', selected: false },
            { id: 2, name: 'Jane Smith', selected: false }
        ],
        toggleAll() {
            this.items.forEach(item => item.selected = this.selectAll);
        },
        updateSelectAll() {
            this.selectAll = this.items.every(item => item.selected);
        }
    }" x-init="$watch('selectAll', () => toggleAll())">
        <!-- Select All -->
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="selectAll" class="checkbox-input" x-model="selectAll">
                <div class="checkbox-custom">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
            </div>
            <label for="selectAll" class="cursor-pointer font-medium">Select All</label>
        </div>
        
        <!-- Individual Items -->
        <template x-for="item in items" :key="item.id">
            <div class="checkbox-item">
                <div class="checkbox-container">
                    <input type="checkbox" :id="'item-' + item.id" class="checkbox-input" x-model="item.selected" @change="updateSelectAll()">
                    <div class="checkbox-custom">
                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                    </div>
                </div>
                <label :for="'item-' + item.id" class="cursor-pointer" x-text="item.name"></label>
            </div>
        </template>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Disabled State -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Disabled State</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="disabled1" class="checkbox-input" disabled>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="disabled1" class="cursor-not-allowed opacity-50">Disabled unchecked</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="disabled2" class="checkbox-input" checked disabled>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="disabled2" class="cursor-not-allowed opacity-50">Disabled checked</label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Disabled State</label>
    <div class="checkbox-group">
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="disabled1" class="checkbox-input" disabled>
                <div class="checkbox-custom">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
            </div>
            <label for="disabled1" class="cursor-not-allowed opacity-50">Disabled unchecked</label>
        </div>
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="disabled2" class="checkbox-input" checked disabled>
                <div class="checkbox-custom">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
            </div>
            <label for="disabled2" class="cursor-not-allowed opacity-50">Disabled checked</label>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Error State -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Error State</label>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" id="error1" class="checkbox-input">
                                            <div class="checkbox-custom !border-red-300">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="error1" class="cursor-pointer">You must agree to the terms</label>
                                    </div>
                                </div>
                                <div class="text-red-600 text-xs mt-1">Please accept the terms and conditions to continue</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Error State</label>
    <div class="checkbox-group">
        <div class="checkbox-item">
            <div class="checkbox-container">
                <input type="checkbox" id="error1" class="checkbox-input">
                <div class="checkbox-custom !border-red-300">
                    <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                </div>
            </div>
            <label for="error1" class="cursor-pointer">You must agree to the terms</label>
        </div>
    </div>
    <div class="text-red-600 text-xs mt-1">Please accept the terms and conditions to continue</div>
</div></code></pre>
                            </div>
                        </div>

                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>