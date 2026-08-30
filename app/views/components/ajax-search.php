<div class="container mx-auto">
    <div class="flex min-h-screen">
        <?php require_once "app/views/components/sidebar.php"; ?>

        <!-- Main Content Area -->
        <div class="flex-1 min-w-0 bg-gray-50">
            <div class="p-6 max-w-full overflow-x-hidden">
                <!-- Dashboard Content -->
                <div class="bg-white rounded-lg p-6 shadow-sm">
                    <h1 class="text-2xl font-bold text-gray-900 mb-4">AJAX Search Components</h1>
                    <p class="text-gray-600 mb-6">Searchable select components with AJAX data loading for flights, hotels, and more.</p>

                    <!-- Content -->
                    <div class="space-y-8">

                        <!-- Flight/Airport Search -->
                        <div class="border-l-4 border-blue-500 pl-4">
                            <div class="form-control mb-4">
                                <label>Airport Search (Flight)</label>
                                <div class="relative" x-data="{
                                    search: '', results: [], selected: null, loading: false, hasSearched: false,

                                    get shouldShowDropdown() {
                                        return this.hasSearched && !this.selected && !this.loading && this.results.length > 0;
                                    },

                                    get showNoResults() {
                                        return this.hasSearched && !this.loading && !this.selected && this.results.length === 0 && this.search.length >= 2;
                                    },

                                    async fetch() {
                                        const query = this.search.trim();
                                        if (query.length < 2) {
                                            this.results = [];
                                            this.hasSearched = false;
                                            return;
                                        }

                                        this.loading = true;
                                        this.hasSearched = false;

                                        try {
                                            const formData = new FormData();
                                            formData.append('query', query);

                                            const res = await fetch('<?=root?>flights-airport-suggestion', {
                                                method: 'POST',
                                                body: formData
                                            });

                                            if (!res.ok) throw new Error(`HTTP ${res.status}`);

                                            const text = await res.text();
                                            const data = JSON.parse(text);

                                            this.results = Array.isArray(data) ? data : [];
                                            this.hasSearched = true;
                                        } catch (e) {
                                            console.error('Error:', e);
                                            this.results = [];
                                            this.hasSearched = true;
                                        } finally {
                                            this.loading = false;
                                        }
                                    },

                                    select(a) {
                                        this.selected = a;
                                        this.search = `${a.id} - ${a.airportname || a.name}`;
                                        this.results = [];
                                        this.hasSearched = false;
                                    },

                                    handleInput() {
                                        // If user edits a selected value, clear selection and search again
                                        if (this.selected) {
                                            this.selected = null;
                                        }
                                        this.fetch();
                                    },

                                    clear() {
                                        this.selected = null;
                                        this.search = '';
                                        this.results = [];
                                        this.hasSearched = false;
                                        this.$refs.input.focus();
                                    }
                                }">
                                    <div class="input-group relative">
                                        <span class="input-icon-left material-symbols-outlined text-xl">flight_takeoff</span>
                                        <input
                                            x-ref="input"
                                            type="text"
                                            class="input-with-icon w-full"
                                            :class="selected ? 'pr-12' : 'pr-10'"
                                            placeholder="Type airport code (DXB) or city name..."
                                            x-model="search"
                                            @input.debounce.300ms="handleInput()"
                                            @keydown.escape="results = []"
                                            autocomplete="off"
                                        >

                                        <!-- Loading Spinner -->
                                        <div x-show="loading" class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none">
                                            <div class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
                                        </div>

                                        <!-- Clear Button (Only when selected) -->
                                        <button
                                            type="button"
                                            x-show="selected && !loading"
                                            @click="clear()"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full border-2 border-red-500 hover:bg-red-50 flex items-center justify-center transition-all group"
                                            title="Clear selection"
                                        >
                                            <span class="material-symbols-outlined text-red-400 group-hover:text-red-600 text-base font-light">close</span>
                                        </button>
                                    </div>

                                    <!-- Helper Text -->
                                    <!-- <div x-show="search.length > 0 && search.length < 3 && !selected" x-cloak class="text-xs text-gray-500 mt-1.5">
                                        Type at least 3 characters to search
                                    </div> -->

                                    <!-- Dropdown Results -->
                                    <div
                                        x-show="shouldShowDropdown || showNoResults"
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0 scale-95"
                                        x-transition:enter-end="opacity-100 scale-100"
                                        x-transition:leave="transition ease-in duration-100"
                                        x-transition:leave-start="opacity-100 scale-100"
                                        x-transition:leave-end="opacity-0 scale-95"
                                        class="absolute left-0 right-0 z-50 mt-1 bg-white border border-gray-200 rounded-lg shadow-xl overflow-hidden"
                                        style="max-height: min(320px, 50vh); overflow-y: auto;"
                                    >
                                        <!-- No Results Message -->
                                        <div x-show="showNoResults" class="p-4 text-center">
                                            <div class="flex flex-col items-center gap-2">
                                                <span class="material-symbols-outlined text-gray-400 text-3xl">search_off</span>
                                                <p class="text-sm font-medium text-gray-900">No airports found</p>
                                                <p class="text-xs text-gray-500">Try searching with a different airport code or city name</p>
                                            </div>
                                        </div>

                                        <!-- Results List -->
                                        <template x-for="a in results" :key="a.id">
                                            <div @click="select(a)" class="p-2.5 sm:p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors">
                                                <div class="flex items-center gap-2 sm:gap-3">
                                                    <!-- Airport Image or Placeholder -->
                                                    <div class="w-10 h-10 sm:w-10 sm:h-10 rounded flex-shrink-0 overflow-hidden bg-gray-100">
                                                        <template x-if="a.destination_images?.image_jpeg">
                                                            <img
                                                                :src="a.destination_images.image_jpeg"
                                                                class="w-full h-full object-cover"
                                                                @error="$el.parentElement.innerHTML = '<div class=\'w-full h-full flex items-center justify-center bg-blue-50\'><span class=\'material-symbols-outlined text-blue-400 text-xl\'>flight</span></div>'"
                                                            >
                                                        </template>
                                                        <template x-if="!a.destination_images?.image_jpeg">
                                                            <div class="w-full h-full flex items-center justify-center bg-blue-50">
                                                                <span class="material-symbols-outlined text-blue-400 text-xl sm:text-2xl">flight</span>
                                                            </div>
                                                        </template>
                                                    </div>

                                                    <!-- Airport Details -->
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-1.5 sm:gap-2 flex-wrap">
                                                            <span x-show="a.sub" class="material-symbols-outlined text-gray-400 text-sm sm:text-base">subdirectory_arrow_right</span>
                                                            <span class="text-xs sm:text-sm font-semibold text-gray-900 truncate" x-text="a.airportname || a.name"></span>
                                                            <span class="px-1.5 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 rounded flex-shrink-0" x-text="a.id"></span>
                                                        </div>
                                                        <div class="text-xs text-gray-600 truncate mt-0.5" x-text="a.cityname"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <input type="hidden" name="airport" :value="selected ? JSON.stringify(selected) : ''">
                                </div>
                            </div>

                            <!-- Code Example -->
                            <div>
                                <pre class="line-numbers language-markup"><code class="language-html">&lt;div x-data="{
    search: '', results: [], selected: null, loading: false, hasSearched: false,

    get shouldShowDropdown() {
        return this.hasSearched && !this.selected && !this.loading && this.results.length > 0;
    },

    get showNoResults() {
        return this.hasSearched && !this.loading && !this.selected && this.results.length === 0 && this.search.length >= 2;
    },

    async fetch() {
        if (this.search.trim().length &lt; 2) {
            this.results = [];
            this.hasSearched = false;
            return;
        }

        this.loading = true;
        this.hasSearched = false;

        try {
            const formData = new FormData();
            formData.append('query', this.search.trim());

            const res = await fetch('/flights-airport-suggestion', {
                method: 'POST',
                body: formData
            });

            if (!res.ok) throw new Error('HTTP ' + res.status);

            const text = await res.text();
            const data = JSON.parse(text);

            this.results = Array.isArray(data) ? data : [];
            this.hasSearched = true;
        } catch (e) {
            console.error(e);
            this.results = [];
            this.hasSearched = true;
        } finally {
            this.loading = false;
        }
    },

    select(a) {
        this.selected = a;
        this.search = `${a.id} - ${a.airportname || a.name}`;
        this.results = [];
        this.hasSearched = false;
    },

    clear() {
        this.selected = null;
        this.search = '';
        this.results = [];
        this.hasSearched = false;
        this.$refs.input.focus();
    }
}"&gt;
    &lt;div class="input-group relative"&gt;
        &lt;span class="input-icon-left material-symbols-outlined"&gt;flight_takeoff&lt;/span&gt;
        &lt;input
            x-ref="input"
            x-model="search"
            @input.debounce.300ms="fetch()"
            @keydown.escape="results = []"
            :readonly="selected"
            placeholder="Type airport code or city name..."
        &gt;

        &lt;!-- Loading Spinner --&gt;
        &lt;div x-show="loading" class="absolute right-3 top-1/2 -translate-y-1/2"&gt;
            &lt;div class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"&gt;&lt;/div&gt;
        &lt;/div&gt;

        &lt;!-- Clear Button --&gt;
        &lt;button x-show="selected && !loading" @click="clear()" type="button"&gt;×&lt;/button&gt;
    &lt;/div&gt;

    &lt;!-- Dropdown --&gt;
    &lt;div x-show="shouldShowDropdown || showNoResults" class="dropdown-results"&gt;
        &lt;!-- No Results --&gt;
        &lt;div x-show="showNoResults"&gt;No airports found&lt;/div&gt;

        &lt;!-- Results --&gt;
        &lt;template x-for="a in results" :key="a.id"&gt;
            &lt;div @click="select(a)" class="dropdown-item"&gt;
                &lt;span x-show="a.sub"&gt;→&lt;/span&gt;
                &lt;span x-text="a.airportname || a.name"&gt;&lt;/span&gt;
                &lt;span x-text="a.id"&gt;&lt;/span&gt;
            &lt;/div&gt;
        &lt;/template&gt;
    &lt;/div&gt;

    &lt;input type="hidden" name="airport" :value="selected ? JSON.stringify(selected) : ''"&gt;
&lt;/div&gt;</code></pre>
                            </div>
                        </div>

                        <!-- Usage Guide -->
                        <div class="border-l-4 border-green-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-3">How to Use</h3>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                                <ul class="list-disc list-inside text-sm text-green-900 space-y-2">
                                    <li>Type at least 2 characters to start searching</li>
                                    <li>Search by airport code (DXB, LHE, JFK)</li>
                                    <li>Search by city name (Dubai, London, New York)</li>
                                    <li>Search by airport name</li>
                                    <li>Select from dropdown to choose airport</li>
                                    <li>Selected data is stored in hidden input for form submission</li>
                                    <li>Click red X button to clear selection and search again</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Features -->
                        <div class="border-l-4 border-purple-500 pl-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-3">Features</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-purple-600">speed</span>
                                        <span class="font-medium text-purple-900">Debounced Search</span>
                                    </div>
                                    <p class="text-sm text-purple-800">300ms delay to reduce API calls</p>
                                </div>
                                <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-purple-600">image</span>
                                        <span class="font-medium text-purple-900">Airport Images</span>
                                    </div>
                                    <p class="text-sm text-purple-800">Visual airport/city images from Kayak</p>
                                </div>
                                <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-purple-600">sync</span>
                                        <span class="font-medium text-purple-900">Real-time AJAX</span>
                                    </div>
                                    <p class="text-sm text-purple-800">Live search without page reload</p>
                                </div>
                                <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="material-symbols-outlined text-purple-600">done_all</span>
                                        <span class="font-medium text-purple-900">Form Ready</span>
                                    </div>
                                    <p class="text-sm text-purple-800">Hidden input for easy form submission</p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
