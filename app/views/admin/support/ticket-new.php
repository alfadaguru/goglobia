<div class="min-h-screen bg-slate-50">

    <!-- PAGE HEADER -->
    <div class="bg-white border-b border-slate-200 px-6 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 max-w-4xl mx-auto">
            <div>
                <h1 class="text-2xl font-bold text-slate-900"><?= T::create_ticket ?></h1>
                <p class="text-sm text-slate-500 mt-1"><?= T::submit_ticket_helper ?></p>
            </div>
            <a href="<?= root . admin ?>/support/tickets"
                class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition w-full sm:w-auto">
                <span class="material-symbols-outlined text-lg">arrow_back</span>
                <?= T::back_to_tickets ?>
            </a>
        </div>
    </div>

    <div class="p-6">
        <div class="max-w-4xl mx-auto">

            <!-- SUCCESS MESSAGE -->
            <?php if (isset($_SESSION['ticket_success'])): ?>
                <div class="alert-success mb-5">
                    <span class="material-symbols-outlined">check_circle</span>
                    <div>
                        <p class="font-semibold"><?= T::success ?></p>
                        <p class="mt-1"><?= htmlspecialchars($_SESSION['ticket_success']) ?></p>
                    </div>
                </div>
                <?php unset($_SESSION['ticket_success']); endif; ?>

            <!-- ERROR MESSAGE -->
            <?php if (isset($_SESSION['ticket_error'])): ?>
                <div class="alert-error mb-5">
                    <span class="material-symbols-outlined">error</span>
                    <div>
                        <p class="font-semibold"><?= T::error ?></p>
                        <p class="mt-1"><?= htmlspecialchars($_SESSION['ticket_error']) ?></p>
                    </div>
                </div>
                <?php unset($_SESSION['ticket_error']); endif; ?>

            <!-- NEW TICKET FORM -->
            <div class="bg-white rounded-lg border border-slate-200 shadow-sm">
                <div class="bg-gradient-to-r from-primary to-primary/80 px-6 py-4">
                    <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                        <span class="material-symbols-outlined">support_agent</span>
                        <?= T::ticket_information ?>
                    </h2>
                </div>

                <!-- TICKET CREATION FORM WITH CUSTOMER SEARCH -->
                <form action="<?= root . admin ?>/support/tickets/create" method="POST" enctype="multipart/form-data"
                    class="p-6 space-y-6" x-data="{
                    selectedUser: null,
                    searchResults: [],
                    searching: false,
                    showResults: false,

                    // SEARCH USERS VIA API WITH DEBOUNCING
                    async searchUsers(query) {
                        if (query.length < 2) {
                            this.searchResults = [];
                            this.showResults = false;
                            return;
                        }

                        this.searching = true;
                        this.showResults = true;

                        try {
                            const response = await fetch('<?= root ?>api/users/search?q=' + encodeURIComponent(query));
                            if (!response.ok) throw new Error('API request failed');
                            const data = await response.json();
                            this.searchResults = data.users || [];
                        } catch (error) {
                            console.error('Search error:', error);
                            this.searchResults = [];
                            alert('<?= T::failed ?> <?= T::to ?> <?= T::search ?> <?= T::users ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
                        }

                        this.searching = false;
                    },

                    // SELECT USER FROM SEARCH RESULTS
                    selectUser(user) {
                        this.selectedUser = user;
                        this.showResults = false;
                        document.getElementById('user_id').value = user.user_id;
                        document.getElementById('userSearch').value = user.name + ' (' + user.email + ')';
                    },

                    // CLEAR SELECTED USER
                    clearUser() {
                        this.selectedUser = null;
                        document.getElementById('user_id').value = '';
                        document.getElementById('userSearch').value = '';
                        this.searchResults = [];
                    }
                }">

                    <!-- CUSTOMER SEARCH FIELD -->
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            <?= T::select_customer ?> <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <span
                                class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">search</span>
                            <input type="text" id="userSearch" @input.debounce.300ms="searchUsers($event.target.value)"
                                @focus="showResults = searchResults.length > 0"
                                placeholder="<?= T::search_by_name_or_email ?>..." class="input w-full pl-10">

                            <!-- SEARCH RESULTS DROPDOWN -->
                            <div x-show="showResults" x-cloak
                                class="absolute z-10 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-64 overflow-y-auto">
                                <div x-show="searching" class="p-4 text-center text-slate-500">
                                    <span class="material-symbols-outlined animate-spin">refresh</span>
                                    <p class="text-sm mt-2"><?= T::searching ?>...</p>
                                </div>

                                <template x-if="!searching && searchResults.length === 0">
                                    <div class="p-4 text-center text-slate-500">
                                        <span class="material-symbols-outlined text-slate-300">search_off</span>
                                        <p class="text-sm mt-2"><?= T::no_results_found ?></p>
                                    </div>
                                </template>

                                <template x-for="user in searchResults" :key="user.user_id">
                                    <div @mousedown.prevent="selectUser(user)"
                                        class="p-3 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="w-10 h-10 rounded-full bg-gradient-to-br from-primary to-primary/70 flex items-center justify-center text-white font-semibold text-sm">
                                                <span x-text="user.name.charAt(0).toUpperCase()"></span>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-slate-900" x-text="user.name"></p>
                                                <p class="text-xs text-slate-500 truncate" x-text="user.email"></p>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- SELECTED USER DISPLAY -->
                        <div x-show="selectedUser" x-cloak
                            class="mt-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-10 h-10 rounded-full bg-gradient-to-br from-green-600 to-green-400 flex items-center justify-center text-white font-semibold">
                                        <span
                                            x-text="selectedUser ? selectedUser.name.charAt(0).toUpperCase() : ''"></span>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-green-900"
                                            x-text="selectedUser ? selectedUser.name : ''"></p>
                                        <p class="text-xs text-green-700"
                                            x-text="selectedUser ? selectedUser.email : ''"></p>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-600 text-white">
                                        <span class="material-symbols-outlined text-sm">check_circle</span>
                                        <?= T::selected_customer ?>
                                    </span>
                                </div>
                                <button type="button" @click="clearUser()"
                                    class="p-1 text-green-600 hover:text-green-800 transition">
                                    <span class="material-symbols-outlined">close</span>
                                </button>
                            </div>
                        </div>

                        <input type="hidden" id="user_id" name="user_id" required>
                    </div>

                    <!-- TICKET SUBJECT -->
                    <div>
                        <label for="subject" class="block text-sm font-medium text-slate-700 mb-2">
                            <?= T::ticket_subject ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="subject" name="subject" required maxlength="200"
                            placeholder="<?= T::brief ?> <?= T::description ?> <?= T::of ?> <?= T::the ?> <?= T::issue ?>"
                            class="input w-full">
                    </div>

                    <!-- PRIORITY LEVEL -->
                    <div>
                        <label for="priority" class="block text-sm font-medium text-slate-700 mb-2">
                            <?= T::priority ?> <span class="text-red-500">*</span>
                        </label>
                        <select id="priority" name="priority" required class="select w-full">
                            <option value="normal"><?= T::normal ?></option>
                            <option value="high"><?= T::high ?></option>
                            <option value="urgent"><?= T::urgent ?></option>
                        </select>
                    </div>

                    <!-- TICKET DESCRIPTION -->
                    <div>
                        <label for="desc" class="block text-sm font-medium text-slate-700 mb-2">
                            <?= T::description ?> <span class="text-red-500">*</span>
                        </label>
                        <textarea id="desc" name="desc" required rows="6"
                            placeholder="<?= T::detailed ?> <?= T::description ?> <?= T::of ?> <?= T::the ?> <?= T::support ?> <?= T::request ?>..."
                            class="textarea w-full"></textarea>
                    </div>

                    <!-- IMAGE ATTACHMENT -->
                    <div x-data="{
                        imagePreview: '',

                        // HANDLE FILE SELECTION AND PREVIEW
                        handleFileSelect(event) {
                            const file = event.target.files[0];
                            if (file) {
                                // VALIDATE FILE SIZE (MAX 5MB)
                                if (file.size > 5 * 1024 * 1024) {
                                    alert('<?= T::file ?> <?= T::size ?> <?= T::must ?> <?= T::be ?> <?= T::less ?> <?= T::than ?> 5MB');
                                    event.target.value = '';
                                    return;
                                }

                                // VALIDATE FILE TYPE
                                if (!file.type.startsWith('image/')) {
                                    alert('<?= T::only ?> <?= T::image ?> <?= T::files ?> <?= T::are ?> <?= T::allowed ?>');
                                    event.target.value = '';
                                    return;
                                }

                                const reader = new FileReader();
                                reader.onload = (e) => {
                                    this.imagePreview = e.target.result;
                                };
                                reader.onerror = () => {
                                    alert('<?= T::failed ?> <?= T::to ?> <?= T::read ?> <?= T::file ?>');
                                };
                                reader.readAsDataURL(file);
                            }
                        },

                        // CLEAR IMAGE PREVIEW
                        clearImage() {
                            this.imagePreview = '';
                            document.getElementById('attachment').value = '';
                        }
                    }">
                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            <?= T::attachment ?> (<?= T::optional ?>)
                        </label>

                        <div class="space-y-3">
                            <div class="relative">
                                <input type="file" id="attachment" name="attachment" accept="image/*"
                                    @change="handleFileSelect($event)" class="hidden">
                                <label for="attachment"
                                    class="flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-slate-300 rounded-lg hover:border-primary hover:bg-slate-50 transition cursor-pointer">
                                    <span class="material-symbols-outlined text-slate-400">cloud_upload</span>
                                    <span class="text-sm text-slate-600"><?= T::click ?> <?= T::to ?> <?= T::upload ?>
                                        <?= T::image ?></span>
                                </label>
                            </div>

                            <div x-show="imagePreview" x-cloak class="relative">
                                <img :src="imagePreview" alt="Preview"
                                    class="max-w-xs rounded-lg border border-slate-200">
                                <button type="button" @click="clearImage()"
                                    class="absolute top-2 right-2 p-1.5 bg-red-600 text-white rounded-full hover:bg-red-700 transition shadow-lg">
                                    <span class="material-symbols-outlined text-lg">close</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- NOTIFICATION OPTIONS -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="notify_email" value="1" checked
                                class="w-4 h-4 text-primary border-slate-300 rounded focus:ring-primary">
                            <span class="material-symbols-outlined text-primary text-lg">email</span>
                            <span class="text-sm font-medium text-slate-700"><?= T::send_email_notification ?></span>
                        </label>
                    </div>

                    <!-- FORM ACTIONS -->
                    <div class="flex items-center justify-end gap-3 pt-5 border-t border-slate-200">
                        <a href="<?= root . admin ?>/support/tickets"
                            class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
                            <span class="material-symbols-outlined" style="font-size: 24px;">cancel</span>
                            <?= T::cancel ?>
                        </a>
                        <button type="submit" id="createTicketBtn"
                            class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 transition shadow-sm">
                            <span id="createLoader" class="material-symbols-outlined animate-spin text-lg"
                                style="display: none;">refresh</span>
                            <span id="createIcon" class="material-symbols-outlined text-lg">add_circle</span>
                            <span id="createText"><?= T::create_ticket ?></span>
                        </button>
                    </div>

                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="created_by_staff" value="1">
                </form>
            </div>

        </div>
    </div>

</div>

<!-- Material Icons -->
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">

<script>
    // FORM SUBMISSION HANDLER WITH VALIDATION
    document.querySelector('form').addEventListener('submit', function (e) {
        // VALIDATE CUSTOMER SELECTION
        const userId = document.getElementById('user_id').value;
        if (!userId || userId.trim() === '') {
            e.preventDefault();
            alert('<?= T::please ?> <?= T::select ?> <?= T::customer ?>');
            return false;
        }

        // VALIDATE DESCRIPTION
        const desc = document.getElementById('desc').value.trim();
        if (!desc) {
            e.preventDefault();
            alert('<?= T::please ?> <?= T::enter ?> <?= T::description ?>');
            return false;
        }

        // SHOW LOADING STATE ON SUBMIT BUTTON
        const submitBtn = document.getElementById('createTicketBtn');
        const loader = document.getElementById('createLoader');
        const icon = document.getElementById('createIcon');
        const text = document.getElementById('createText');

        submitBtn.disabled = true;
        loader.style.display = 'inline-block';
        icon.style.display = 'none';
        text.textContent = '<?= T::creating ?>...';
    });
</script>

<style>
    [x-cloak] {
        display: none !important;
    }
</style>