<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Select Components</h1>
                    <p class="text-gray-600 mb-6">Select field components with various styles and dropdown functionality.</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Basic Select -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Basic Select</label>
                                <select class="select">
                                    <option value="">Choose an option</option>
                                    <option value="option1">Option 1</option>
                                    <option value="option2">Option 2</option>
                                    <option value="option3">Option 3</option>
                                </select>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Basic Select</label>
    <select class="select">
        <option value="">Choose an option</option>
        <option value="option1">Option 1</option>
        <option value="option2">Option 2</option>
        <option value="option3">Option 3</option>
    </select>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Country Select -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Country Select</label>
                                <select class="select">
                                    <option value="">Select a country</option>
                                    <option value="us">🇺🇸 United States</option>
                                    <option value="uk">🇬🇧 United Kingdom</option>
                                    <option value="ca">🇨🇦 Canada</option>
                                    <option value="au">🇦🇺 Australia</option>
                                    <option value="de">🇩🇪 Germany</option>
                                    <option value="fr">🇫🇷 France</option>
                                    <option value="jp">🇯🇵 Japan</option>
                                </select>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Country Select</label>
    <select class="select">
        <option value="">Select a country</option>
        <option value="us">🇺🇸 United States</option>
        <option value="uk">🇬🇧 United Kingdom</option>
        <option value="ca">🇨🇦 Canada</option>
        <option value="au">🇦🇺 Australia</option>
        <option value="de">🇩🇪 Germany</option>
        <option value="fr">🇫🇷 France</option>
        <option value="jp">🇯🇵 Japan</option>
    </select>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Category Select -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Category Select</label>
                                <select class="select">
                                    <option value="">Select a category</option>
                                    <optgroup label="Technology">
                                        <option value="software">Software</option>
                                        <option value="hardware">Hardware</option>
                                        <option value="mobile">Mobile</option>
                                    </optgroup>
                                    <optgroup label="Business">
                                        <option value="marketing">Marketing</option>
                                        <option value="finance">Finance</option>
                                        <option value="hr">Human Resources</option>
                                    </optgroup>
                                    <optgroup label="Creative">
                                        <option value="design">Design</option>
                                        <option value="photography">Photography</option>
                                        <option value="writing">Writing</option>
                                    </optgroup>
                                </select>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Category Select</label>
    <select class="select">
        <option value="">Select a category</option>
        <optgroup label="Technology">
            <option value="software">Software</option>
            <option value="hardware">Hardware</option>
            <option value="mobile">Mobile</option>
        </optgroup>
        <optgroup label="Business">
            <option value="marketing">Marketing</option>
            <option value="finance">Finance</option>
            <option value="hr">Human Resources</option>
        </optgroup>
        <optgroup label="Creative">
            <option value="design">Design</option>
            <option value="photography">Photography</option>
            <option value="writing">Writing</option>
        </optgroup>
    </select>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Multiple Select -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Multiple Select</label>
                                <select class="select" multiple size="4">
                                    <option value="red">Red</option>
                                    <option value="blue">Blue</option>
                                    <option value="green">Green</option>
                                    <option value="yellow">Yellow</option>
                                    <option value="purple">Purple</option>
                                    <option value="orange">Orange</option>
                                </select>
                                <div class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple options</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Multiple Select</label>
    <select class="select" multiple size="4">
        <option value="red">Red</option>
        <option value="blue">Blue</option>
        <option value="green">Green</option>
        <option value="yellow">Yellow</option>
        <option value="purple">Purple</option>
        <option value="orange">Orange</option>
    </select>
    <div class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple options</div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Custom Dropdown with Alpine.js -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Custom Dropdown</label>
                                <div class="dropdown" x-data="{ 
                                    open: false, 
                                    selected: '', 
                                    options: [
                                        { value: 'admin', label: '👤 Administrator', desc: 'Full system access' },
                                        { value: 'editor', label: '✏️ Editor', desc: 'Content management' },
                                        { value: 'viewer', label: '👁️ Viewer', desc: 'Read-only access' },
                                        { value: 'guest', label: '🚪 Guest', desc: 'Limited access' }
                                    ]
                                }" @click.away="open = false">
                                    <div @click="open = !open" class="input cursor-pointer flex items-center justify-between">
                                        <span x-text="selected || 'Select user role'"></span>
                                        <span class="material-symbols-outlined transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                    </div>
                                    <div class="dropdown-content" :class="open ? 'show' : ''">
                                        <div class="dropdown-menu">
                                            <template x-for="option in options" :key="option.value">
                                                <div class="dropdown-item" @click="selected = option.label; open = false">
                                                    <div>
                                                        <div x-text="option.label" class="font-medium"></div>
                                                        <div x-text="option.desc" class="text-xs text-gray-500"></div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Custom Dropdown</label>
    <div class="dropdown" x-data="{ 
        open: false, 
        selected: '', 
        options: [
            { value: 'admin', label: '👤 Administrator', desc: 'Full system access' },
            { value: 'editor', label: '✏️ Editor', desc: 'Content management' },
            { value: 'viewer', label: '👁️ Viewer', desc: 'Read-only access' },
            { value: 'guest', label: '🚪 Guest', desc: 'Limited access' }
        ]
    }" @click.away="open = false">
        <div @click="open = !open" class="input cursor-pointer flex items-center justify-between">
            <span x-text="selected || 'Select user role'"></span>
            <span class="material-symbols-outlined transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
        </div>
        <div class="dropdown-content" :class="open ? 'show' : ''">
            <div class="dropdown-menu">
                <template x-for="option in options" :key="option.value">
                    <div class="dropdown-item" @click="selected = option.label; open = false">
                        <div>
                            <div x-text="option.label" class="font-medium"></div>
                            <div x-text="option.desc" class="text-xs text-gray-500"></div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Searchable Select -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Searchable Select</label>
                                <div class="dropdown" x-data="{
                                    open: false,
                                    search: '',
                                    selected: '',
                                    technologies: [
                                        '⚛️ React', '🖼️ Vue.js', '🅰️ Angular', '⚡ Svelte',
                                        '🟢 Node.js', '🐍 Python', '☕ Java', '💎 Ruby',
                                        '🚀 Laravel', '🔷 Django', '🌸 Flask', '⚡ Express'
                                    ],
                                    get filteredTechs() {
                                        if (!this.search) return this.technologies;
                                        return this.technologies.filter(tech =>
                                            tech.toLowerCase().includes(this.search.toLowerCase())
                                        );
                                    }
                                }" @click.away="open = false">
                                    <div class="input-group">
                                        <span class="input-icon-left material-symbols-outlined">search</span>
                                        <input
                                            type="text"
                                            class="input-with-icon"
                                            placeholder="Search technologies..."
                                            x-model="search"
                                            @focus="open = true"
                                            @input="open = true"
                                            :value="selected"
                                        >
                                    </div>
                                    <div class="dropdown-content" :class="open && filteredTechs.length ? 'show' : ''">
                                        <div class="dropdown-menu">
                                            <template x-for="tech in filteredTechs" :key="tech">
                                                <div class="dropdown-item" @click="selected = tech; search = tech; open = false" x-text="tech"></div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Searchable Select</label>
    <div class="dropdown" x-data="{
        open: false,
        search: '',
        selected: '',
        technologies: [
            '⚛️ React', '🖼️ Vue.js', '🅰️ Angular', '⚡ Svelte',
            '🟢 Node.js', '🐍 Python', '☕ Java', '💎 Ruby',
            '🚀 Laravel', '🔷 Django', '🌸 Flask', '⚡ Express'
        ],
        get filteredTechs() {
            if (!this.search) return this.technologies;
            return this.technologies.filter(tech =>
                tech.toLowerCase().includes(this.search.toLowerCase())
            );
        }
    }" @click.away="open = false">
        <div class="input-group">
            <span class="input-icon-left material-symbols-outlined">search</span>
            <input
                type="text"
                class="input-with-icon"
                placeholder="Search technologies..."
                x-model="search"
                @focus="open = true"
                @input="open = true"
                :value="selected"
            >
        </div>
        <div class="dropdown-content" :class="open && filteredTechs.length ? 'show' : ''">
            <div class="dropdown-menu">
                <template x-for="tech in filteredTechs" :key="tech">
                    <div class="dropdown-item" @click="selected = tech; search = tech; open = false" x-text="tech"></div>
                </template>
            </div>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Multi-Select with Tags -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Multi-Select with Tags</label>
                                <div class="dropdown" x-data="{
                                    open: false,
                                    search: '',
                                    selected: [],
                                    skills: [
                                        'JavaScript', 'TypeScript', 'React', 'Vue.js', 'Angular',
                                        'Node.js', 'Python', 'PHP', 'Laravel', 'Django',
                                        'MySQL', 'PostgreSQL', 'MongoDB', 'Redis'
                                    ],
                                    get filteredSkills() {
                                        return this.skills.filter(skill => 
                                            !this.selected.includes(skill) && 
                                            skill.toLowerCase().includes(this.search.toLowerCase())
                                        );
                                    },
                                    addSkill(skill) {
                                        if (!this.selected.includes(skill)) {
                                            this.selected.push(skill);
                                        }
                                        this.search = '';
                                        this.open = false;
                                    },
                                    removeSkill(skill) {
                                        this.selected = this.selected.filter(s => s !== skill);
                                    }
                                }" @click.away="open = false">
                                    
                                    <!-- Selected Tags -->
                                    <div class="flex flex-wrap gap-1 mb-2" x-show="selected.length > 0">
                                        <template x-for="skill in selected" :key="skill">
                                            <div class="badge badge-primary flex items-center gap-1">
                                                <span x-text="skill"></span>
                                                <button @click="removeSkill(skill)" class="text-xs hover:bg-primary-600 rounded-full w-4 h-4 flex items-center justify-center">×</button>
                                            </div>
                                        </template>
                                    </div>
                                    
                                    <!-- Search Input -->
                                    <div class="input-group">
                                        <span class="input-icon-left material-symbols-outlined">add_circle</span>
                                        <input
                                            type="text"
                                            class="input-with-icon"
                                            placeholder="Add skills..."
                                            x-model="search"
                                            @focus="open = true"
                                            @input="open = true"
                                        >
                                    </div>
                                    
                                    <!-- Dropdown -->
                                    <div class="dropdown-content" :class="open && filteredSkills.length ? 'show' : ''">
                                        <div class="dropdown-menu">
                                            <template x-for="skill in filteredSkills" :key="skill">
                                                <div class="dropdown-item" @click="addSkill(skill)" x-text="skill"></div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Multi-Select with Tags</label>
    <div class="dropdown" x-data="{
        open: false,
        search: '',
        selected: [],
        skills: ['JavaScript', 'TypeScript', 'React', 'Vue.js', 'Angular'],
        get filteredSkills() {
            return this.skills.filter(skill => 
                !this.selected.includes(skill) && 
                skill.toLowerCase().includes(this.search.toLowerCase())
            );
        },
        addSkill(skill) {
            if (!this.selected.includes(skill)) {
                this.selected.push(skill);
            }
            this.search = '';
            this.open = false;
        },
        removeSkill(skill) {
            this.selected = this.selected.filter(s => s !== skill);
        }
    }" @click.away="open = false">
        <!-- Selected Tags -->
        <div class="flex flex-wrap gap-1 mb-2" x-show="selected.length > 0">
            <template x-for="skill in selected" :key="skill">
                <div class="badge badge-primary flex items-center gap-1">
                    <span x-text="skill"></span>
                    <button @click="removeSkill(skill)" class="text-xs">×</button>
                </div>
            </template>
        </div>
        <!-- Search Input -->
        <div class="input-group">
            <span class="input-icon-left material-symbols-outlined">add_circle</span>
            <input type="text" class="input-with-icon" placeholder="Add skills..." x-model="search" @focus="open = true">
        </div>
        <!-- Dropdown -->
        <div class="dropdown-content" :class="open && filteredSkills.length ? 'show' : ''">
            <div class="dropdown-menu">
                <template x-for="skill in filteredSkills" :key="skill">
                    <div class="dropdown-item" @click="addSkill(skill)" x-text="skill"></div>
                </template>
            </div>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Small Size Select -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Small Size Select</label>
                                <select class="select h-8 text-xs">
                                    <option value="">Small select</option>
                                    <option value="option1">Option 1</option>
                                    <option value="option2">Option 2</option>
                                    <option value="option3">Option 3</option>
                                </select>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Small Size Select</label>
    <select class="select h-8 text-xs">
        <option value="">Small select</option>
        <option value="option1">Option 1</option>
        <option value="option2">Option 2</option>
        <option value="option3">Option 3</option>
    </select>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Large Size Select -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Large Size Select</label>
                                <select class="select h-12 text-base">
                                    <option value="">Large select</option>
                                    <option value="option1">Option 1</option>
                                    <option value="option2">Option 2</option>
                                    <option value="option3">Option 3</option>
                                </select>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Large Size Select</label>
    <select class="select h-12 text-base">
        <option value="">Large select</option>
        <option value="option1">Option 1</option>
        <option value="option2">Option 2</option>
        <option value="option3">Option 3</option>
    </select>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Disabled State Select -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Disabled State</label>
                                <select class="select" disabled>
                                    <option value="">Disabled select</option>
                                    <option value="option1">Option 1</option>
                                    <option value="option2">Option 2</option>
                                </select>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Disabled State</label>
    <select class="select" disabled>
        <option value="">Disabled select</option>
        <option value="option1">Option 1</option>
        <option value="option2">Option 2</option>
    </select>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Error State Select -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Error State</label>
                                <select class="select border-red-300 focus:border-red-500">
                                    <option value="">Select with error</option>
                                    <option value="option1">Option 1</option>
                                    <option value="option2">Option 2</option>
                                </select>
                                <div class="text-red-600 text-xs mt-1">Please select an option</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Error State</label>
    <select class="select border-red-300 focus:border-red-500">
        <option value="">Select with error</option>
        <option value="option1">Option 1</option>
        <option value="option2">Option 2</option>
    </select>
    <div class="text-red-600 text-xs mt-1">Please select an option</div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Success State Select -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Success State</label>
                                <select class="select border-green-300 focus:border-green-500">
                                    <option value="">Select option</option>
                                    <option value="option1" selected>Selected Option</option>
                                    <option value="option2">Option 2</option>
                                </select>
                                <div class="text-green-600 text-xs mt-1">Good choice!</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Success State</label>
    <select class="select border-green-300 focus:border-green-500">
        <option value="">Select option</option>
        <option value="option1" selected>Selected Option</option>
        <option value="option2">Option 2</option>
    </select>
    <div class="text-green-600 text-xs mt-1">Good choice!</div>
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

<style>
[x-cloak] { display: none !important; }
</style>