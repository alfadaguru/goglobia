<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">Input Components</h1>
                    <p class="text-gray-600 mb-6">Individual input field components with various styles and functionality.</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Text Input -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Text Input</label>
                                <input type="text" class="input" placeholder="Enter your text">
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Text Input</label>
    <input type="text" class="input" placeholder="Enter your text">
</div></code></pre>
                            </div>
                        </div>

                        <!-- Email Input -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Email Input</label>
                                <input type="email" class="input" placeholder="Enter your email">
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Email Input</label>
    <input type="email" class="input" placeholder="Enter your email">
</div></code></pre>
                            </div>
                        </div>

                        <!-- Password Input -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Password Input</label>
                                <input type="password" class="input" placeholder="Enter your password">
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Password Input</label>
    <input type="password" class="input" placeholder="Enter your password">
</div></code></pre>
                            </div>
                        </div>

                        <!-- Number Input -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Number Input</label>
                                <input type="number" class="input" placeholder="Enter a number">
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Number Input</label>
    <input type="number" class="input" placeholder="Enter a number">
</div></code></pre>
                            </div>
                        </div>

                        <!-- Search Input with Left Icon -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Search with Left Icon</label>
                                <div class="input-group">
                                    <span class="input-icon-left material-symbols-outlined">search</span>
                                    <input type="text" class="input-with-icon" placeholder="Search...">
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Search with Left Icon</label>
    <div class="input-group">
        <span class="input-icon-left material-symbols-outlined">search</span>
        <input type="text" class="input-with-icon" placeholder="Search...">
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Email Input with Left Icon -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Email with Left Icon</label>
                                <div class="input-group">
                                    <span class="input-icon-left material-symbols-outlined">email</span>
                                    <input type="email" class="input-with-icon" placeholder="Enter email">
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Email with Left Icon</label>
    <div class="input-group">
        <span class="input-icon-left material-symbols-outlined">email</span>
        <input type="email" class="input-with-icon" placeholder="Enter email">
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Password Input with Toggle Icon -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Password with Right Icon</label>
                                <div class="input-group" x-data="{ showPassword: false }">
                                    <input :type="showPassword ? 'text' : 'password'" class="input-with-icon-right" placeholder="Enter password">
                                    <span @click="showPassword = !showPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Password with Right Icon</label>
    <div class="input-group" x-data="{ showPassword: false }">
        <input :type="showPassword ? 'text' : 'password'" class="input-with-icon-right" placeholder="Enter password">
        <span @click="showPassword = !showPassword" class="input-icon-right material-symbols-outlined cursor-pointer" x-text="showPassword ? 'visibility_off' : 'visibility'"></span>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Amount Input with Both Icons -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Amount with Both Icons</label>
                                <div class="input-group">
                                    <span class="input-icon-left material-symbols-outlined">attach_money</span>
                                    <input type="number" class="input-with-icons" placeholder="0.00">
                                    <span class="input-icon-right material-symbols-outlined">calculate</span>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Amount with Both Icons</label>
    <div class="input-group">
        <span class="input-icon-left material-symbols-outlined">attach_money</span>
        <input type="number" class="input-with-icons" placeholder="0.00">
        <span class="input-icon-right material-symbols-outlined">calculate</span>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- City Autocomplete Input -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>City Autocomplete</label>
                                <div class="input-dropdown" x-data="{
                                    open: false,
                                    search: '',
                                    cities: ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia'],
                                    get filteredCities() {
                                        if (!this.search) return this.cities;
                                        return this.cities.filter(city =>
                                            city.toLowerCase().includes(this.search.toLowerCase())
                                        );
                                    }
                                }" @click.away="open = false">
                                    <div class="input-group">
                                        <span class="input-icon-left material-symbols-outlined">location_city</span>
                                        <input
                                            type="text"
                                            class="input-with-icon"
                                            placeholder="Search cities..."
                                            x-model="search"
                                            @focus="open = true"
                                            @input="open = true"
                                        >
                                    </div>
                                    <div class="input-dropdown-content" :class="open && filteredCities.length ? 'show' : ''">
                                        <template x-for="city in filteredCities" :key="city">
                                            <div class="input-dropdown-item" @click="search = city; open = false" x-text="city"></div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>City Autocomplete</label>
    <div class="input-dropdown" x-data="{
        open: false,
        search: '',
        cities: ['New York', 'Los Angeles', 'Chicago', 'Houston'],
        get filteredCities() {
            if (!this.search) return this.cities;
            return this.cities.filter(city =>
                city.toLowerCase().includes(this.search.toLowerCase())
            );
        }
    }" @click.away="open = false">
        <div class="input-group">
            <span class="input-icon-left material-symbols-outlined">location_city</span>
            <input
                type="text"
                class="input-with-icon"
                placeholder="Search cities..."
                x-model="search"
                @focus="open = true"
                @input="open = true"
            >
        </div>
        <div class="input-dropdown-content" :class="open && filteredCities.length ? 'show' : ''">
            <template x-for="city in filteredCities" :key="city">
                <div class="input-dropdown-item" @click="search = city; open = false" x-text="city"></div>
            </template>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Country Selector Input -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Country Selector</label>
                                <div class="input-dropdown" x-data="{
                                    open: false,
                                    selected: '',
                                    countries: [
                                        { code: 'US', name: 'United States', flag: '🇺🇸' },
                                        { code: 'UK', name: 'United Kingdom', flag: '🇬🇧' },
                                        { code: 'CA', name: 'Canada', flag: '🇨🇦' },
                                        { code: 'AU', name: 'Australia', flag: '🇦🇺' },
                                        { code: 'DE', name: 'Germany', flag: '🇩🇪' }
                                    ]
                                }" @click.away="open = false">
                                    <div @click="open = !open" class="input cursor-pointer flex items-center justify-between">
                                        <span x-text="selected || 'Select a country'"></span>
                                        <span class="material-symbols-outlined transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                    </div>
                                    <div class="input-dropdown-content" :class="open ? 'show' : ''">
                                        <template x-for="country in countries" :key="country.code">
                                            <div class="input-dropdown-item flex items-center gap-2" @click="selected = country.flag + ' ' + country.name; open = false">
                                                <span x-text="country.flag"></span>
                                                <span x-text="country.name"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Country Selector</label>
    <div class="input-dropdown" x-data="{
        open: false,
        selected: '',
        countries: [
            { code: 'US', name: 'United States', flag: '🇺🇸' },
            { code: 'UK', name: 'United Kingdom', flag: '🇬🇧' },
            { code: 'CA', name: 'Canada', flag: '🇨🇦' },
            { code: 'AU', name: 'Australia', flag: '🇦🇺' },
            { code: 'DE', name: 'Germany', flag: '🇩🇪' }
        ]
    }" @click.away="open = false">
        <div @click="open = !open" class="input cursor-pointer flex items-center justify-between">
            <span x-text="selected || 'Select a country'"></span>
            <span class="material-symbols-outlined transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
        </div>
        <div class="input-dropdown-content" :class="open ? 'show' : ''">
            <template x-for="country in countries" :key="country.code">
                <div class="input-dropdown-item flex items-center gap-2" @click="selected = country.flag + ' ' + country.name; open = false">
                    <span x-text="country.flag"></span>
                    <span x-text="country.name"></span>
                </div>
            </template>
        </div>
    </div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Normal State Input -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Normal State</label>
                                <input type="text" class="input" placeholder="Normal input">
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Normal State</label>
    <input type="text" class="input" placeholder="Normal input">
</div></code></pre>
                            </div>
                        </div>

                        <!-- Disabled State Input -->
                        <div class="border-l-4 border-gray-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Disabled State</label>
                                <input type="text" class="input" placeholder="Disabled input" disabled>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Disabled State</label>
    <input type="text" class="input" placeholder="Disabled input" disabled>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Error State Input -->
                        <div class="border-l-4 border-red-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Error State</label>
                                <input type="text" class="input border-red-300 focus-visible:border-red-500" placeholder="Input with error" value="Invalid input">
                                <div class="text-red-600 text-xs mt-1">This field is required</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Error State</label>
    <input type="text" class="input border-red-300 focus-visible:border-red-500" placeholder="Input with error" value="Invalid input">
    <div class="text-red-600 text-xs mt-1">This field is required</div>
</div></code></pre>
                            </div>
                        </div>

                        <!-- Success State Input -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Success State</label>
                                <input type="text" class="input border-green-300 focus-visible:border-green-500" placeholder="Valid input" value="Valid input">
                                <div class="text-green-600 text-xs mt-1">Looks good!</div>
                            </div>
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html"><div class="form-control">
    <label>Success State</label>
    <input type="text" class="input border-green-300 focus-visible:border-green-500" placeholder="Valid input" value="Valid input">
    <div class="text-green-600 text-xs mt-1">Looks good!</div>
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