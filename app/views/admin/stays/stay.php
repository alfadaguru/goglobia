<?php
@$SECURE or die('Access Denied!');
?>
<style>
[draggable="true"]:active {
    opacity: 0.5;
    cursor: grabbing !important;
}
[draggable="true"] {
    cursor: grab;
}
/* Smooth row deletion animation */
tbody tr.row-item {
    transition: transform 0.6s ease-out, opacity 0.6s ease-out;
}

/* Prevent horizontal scrollbar during animation */
#tableBody {
    overflow: hidden;
}
</style>
<?php

// Determine mode: add, edit, or view
$mode = $mode ?? 'add'; // Default to 'add' if not set
$isEdit = ($mode === 'edit');
$isView = ($mode === 'view');
$isAdd = ($mode === 'add');

// Set page title based on mode
if ($isView) {
    $pageTitle = T::view . ': ' . htmlspecialchars($hotel['name'] ?? '');
} elseif ($isEdit) {
    $pageTitle = T::edit . ': ' . htmlspecialchars($hotel['name'] ?? '');
} else {
    $pageTitle = T::add . ' ' . T::stay;
}

$boards = $db->select('stays_settings', ['id', 'name', 'translations'], ['setting_type' => 'board', 'status' => 1]);

// Initialize variables for add mode
if ($isAdd) {
    $hotel = [
        'id' => 0,
        'user_id' => '',
        'name' => '',
        'desc' => '',
        'location' => '',
        'address' => '',
        'stars' => '',
        'rating' => '',
        'currency' => 'USD',
        'discount' => 0,
        'stay_type' => 0,
        'email' => '',
        'phone' => '',
        'website' => '',
        'checkin_time' => '14:00:00',
        'checkout_time' => '12:00:00',
        'booking_age_requirement' => 18,
        'refundable' => 0,
        'featured' => 0,
        'meta_title' => '',
        'meta_keywords' => '',
        'cancellation_policy' => '',
        'privacy_policy' => '',
        'amenity_ids' => '[]',
        'thumbnail' => '',
        'hotel_images' => '',
        'status' => 1,
    ];
    $selected_amenity_ids = [];
    $hotel_images = [];
    $latitude = '';
    $longitude = '';
    $owner = null;
    $selectedUser = null;
}

// Fetch all rooms for this hotel
$rooms = [];
if($isEdit){
    $rooms = $db->select('stays_rooms', '*', ['stay_id' => $hotel_id], ['ORDER' => ['id' => 'DESC']]);
}

// Fetch room types
$room_types = $db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_type'], ['ORDER' => ['name' => 'ASC']]);
// Get amenities for room options
$room_amenities = $db->select('stays_settings', ['id', 'name', 'translations'], ['setting_type' => 'amenity', 'status' => 1]);
?>

<?php if (!$isView): ?>
<script>
    const initialOwner = <?= !empty($owner) ? json_encode($owner) : 'null' ?>;
</script>
<script>
function hotelFormData() {
    return {
        // Hotel Form State
        loading: false,
        activeTab: 'general',
        activeTranslationTab: '<?php
            $first_lang = '';
            foreach ($GLOBALS['languages'] ?? [] as $lang_data) {
                if ($lang_data['lang_code'] !== 'en') {
                    $first_lang = $lang_data['lang_code'];
                    break;
                }
            }
            echo $first_lang;
        ?>',
        selectedAmenities: <?= json_encode($selected_amenity_ids ?? []) ?>,
        translationTabInitialized: false,

        // Hotel Image Management
        <?php if ($isEdit): ?>
        imagesToDelete: [],
        defaultImage: '<?= !empty($hotel_images) ? (array_values(array_filter($hotel_images, fn($img) => !empty($img['default'])))[0]['url'] ?? $hotel_images[0]['url']) : '' ?>',
        hotelImages: <?= json_encode($hotel_images ?? []) ?>,
        draggedIndex: null,
        lightboxImage: '',
        showLightbox: false,
        <?php endif; ?>
        previewImages: [],

        // User Search
        userSearch: '<?php if ($isEdit && !empty($owner)): echo htmlspecialchars(trim($owner['first_name'] ?? '') . ' ' . trim($owner['last_name'] ?? '') . ' - ' . ($owner['email'] ?? '')); endif; ?>',
        searchResults: [],
        showUserDropdown: false,
        selectedUser: <?php if ($isEdit && !empty($owner)): echo json_encode($owner); else: echo 'null'; endif; ?>,
        searchingUsers: false,

        // Location Search
        locationSearch: '<?php if ($isEdit && !empty($hotel['location'])): echo htmlspecialchars($hotel['location']); endif; ?>',
        locationResults: [],
        showLocationDropdown: false,
        selectedLocation: <?php if ($isEdit && !empty($hotel['location'])): echo json_encode(['city' => $hotel['location'], 'country' => '', 'latitude' => $latitude, 'longitude' => $longitude]); else: echo 'null'; endif; ?>,
        searchingLocations: false,

        // ========== ROOMS MANAGEMENT ==========
        roomsViewMode: 'list', // 'list', 'add', 'edit'
        currentEditingRoomId: 0,
        roomActiveTab: 'details',
        roomActiveTranslationTab: '<?php echo $first_lang; ?>',
        roomLoading: false,
        roomStateRestored: false,

        // Room Image Management
        roomImagesToDelete: [],
        roomDefaultImage: '',
        roomImages: [],
        roomDraggedIndex: null,
        roomLightboxImage: '',
        showRoomLightbox: false,
        roomPreviewImages: [],

        // Room Options Management
        roomOptions: [],
        editingRoomOptionIndex: -1,
        showRoomOptionForm: false,
        selectedRoomAmenities: [],
        currentRoomData: null,

        // ========== HOTEL TAB METHODS ==========
        switchTab(tab) {
            const previousTab = this.activeTab;
            this.activeTab = tab;

            // When leaving rooms tab, always return to list view
            if (previousTab === 'rooms' && tab !== 'rooms') {
                this.showRoomsList(); // This resets to list view and clears state
            }

            // When entering rooms tab, ensure we're in list view
            if (tab === 'rooms') {
                this.roomsViewMode = 'list';
                this.currentEditingRoomId = 0;
                this.clearRoomState();
            }

            if (tab === 'translations') {
                window.location.hash = tab + ':' + this.activeTranslationTab;

                if (!this.translationTabInitialized) {
                    setTimeout(() => {
                        const descTextarea = document.getElementById('hotel-trans-desc-' + this.activeTranslationTab);
                        if (descTextarea && !editorInstances.translations.desc[this.activeTranslationTab]) {
                            initializeTranslationEditor(descTextarea, this.activeTranslationTab);
                        }
                        this.translationTabInitialized = true;
                    }, 100);
                }
            } else {
                window.location.hash = tab;
            }
        },

        switchTranslationTab(langCode) {
            this.activeTranslationTab = langCode;
            window.location.hash = 'translations:' + langCode;

            setTimeout(() => {
                if (!editorInstances.translations.desc[langCode]) {
                    const descTextarea = document.getElementById('hotel-trans-desc-' + langCode);
                    if (descTextarea) {
                        initializeTranslationEditor(descTextarea, langCode);
                    }
                }
            }, 100);
        },

        loadTabFromHash() {
            // First check sessionStorage (for post-form-submission restoration)
            const savedTab = sessionStorage.getItem('hotel_active_tab');
            if (savedTab) {
                sessionStorage.removeItem('hotel_active_tab'); // Clear it after reading

                if (savedTab.includes(':')) {
                    const [mainTab, langCode] = savedTab.split(':');
                    if (mainTab === 'translations' && langCode) {
                        this.activeTab = 'translations';
                        this.activeTranslationTab = langCode;
                        window.location.hash = savedTab;

                        setTimeout(() => {
                            const descTextarea = document.getElementById('hotel-trans-desc-' + langCode);
                            if (descTextarea && !editorInstances.translations.desc[langCode]) {
                                initializeTranslationEditor(descTextarea, langCode);
                            }
                            this.translationTabInitialized = true;
                        }, 200);
                        return;
                    }
                } else {
                    const validTabs = ['general', 'rooms', 'location', 'contact', 'seo', 'amenities', 'gallery', 'translations'];
                    if (validTabs.includes(savedTab)) {
                        this.activeTab = savedTab;
                        window.location.hash = savedTab;

                        if (savedTab === 'rooms') {
                            this.restoreRoomState();
                        }
                        return;
                    }
                }
            }

            // Then check URL hash
            const hash = window.location.hash.substring(1);
            if (!hash) return;

            if (hash.includes(':')) {
                const [mainTab, langCode] = hash.split(':');

                if (mainTab === 'translations' && langCode) {
                    this.activeTab = 'translations';
                    this.activeTranslationTab = langCode;

                    setTimeout(() => {
                        const descTextarea = document.getElementById('hotel-trans-desc-' + langCode);
                        if (descTextarea && !editorInstances.translations.desc[langCode]) {
                            initializeTranslationEditor(descTextarea, langCode);
                        }
                        this.translationTabInitialized = true;
                    }, 200);
                }
            } else {
                const validTabs = ['general', 'rooms', 'location', 'contact', 'seo', 'amenities', 'gallery', 'translations'];
                if (validTabs.includes(hash)) {
                    this.activeTab = hash;

                    // Restore room state if switching to rooms tab
                    if (hash === 'rooms') {
                        this.restoreRoomState();
                    }

                    if (hash === 'translations') {
                        setTimeout(() => {
                            const descTextarea = document.getElementById('hotel-trans-desc-' + this.activeTranslationTab);
                            if (descTextarea && !editorInstances.translations.desc[this.activeTranslationTab]) {
                                initializeTranslationEditor(descTextarea, this.activeTranslationTab);
                            }
                            this.translationTabInitialized = true;
                        }, 200);
                    }
                }
            }
        },

        toggleAmenity(amenityId) {
            const index = this.selectedAmenities.indexOf(amenityId);
            if (index > -1) {
                this.selectedAmenities.splice(index, 1);
            } else {
                this.selectedAmenities.push(amenityId);
            }
        },

        // ========== HOTEL IMAGE METHODS ==========
        <?php if ($isEdit): ?>
        toggleDeleteImage(imageUrl) {
            const index = this.imagesToDelete.indexOf(imageUrl);
            if (index > -1) {
                this.imagesToDelete.splice(index, 1);
            } else {
                this.imagesToDelete.push(imageUrl);
                if (this.defaultImage === imageUrl) {
                    this.defaultImage = '';
                }
            }
        },

        setDefaultImage(imageUrl) {
            if (!this.imagesToDelete.includes(imageUrl)) {
                this.defaultImage = imageUrl;
            }
        },

        dragStart(index) {
            this.draggedIndex = index;
        },

        dragOver(event, index) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        },

        drop(dropIndex) {
            if (this.draggedIndex === null || this.draggedIndex === dropIndex) {
                this.draggedIndex = null;
                return;
            }

            const newImages = [...this.hotelImages];
            const [draggedItem] = newImages.splice(this.draggedIndex, 1);
            newImages.splice(dropIndex, 0, draggedItem);
            this.hotelImages = newImages;
            this.draggedIndex = null;
        },

        openLightbox(imageUrl) {
            this.lightboxImage = imageUrl;
            this.showLightbox = true;
            document.body.style.overflow = 'hidden';
        },

        closeLightbox() {
            this.showLightbox = false;
            this.lightboxImage = '';
            document.body.style.overflow = '';
        },
        <?php endif; ?>

        handleImageUpload(event) {
            const files = event.target.files;
            for (let i = 0; i < files.length; i++) {
                const file = files[i];

                if (file.size > 5 * 1024 * 1024) {
                    alert('<?= @T::file_too_large ?: "File is too large" ?>: ' + file.name + '. <?= @T::max_5mb ?: "Maximum 5MB allowed" ?>');
                    continue;
                }

                if (!file.type.startsWith('image/')) {
                    alert('<?= @T::invalid_file_type ?: "Invalid file type" ?>: ' + file.name);
                    continue;
                }

                const reader = new FileReader();
                reader.onload = (e) => {
                    this.previewImages.push({
                        preview: e.target.result,
                        name: file.name,
                        size: file.size,
                        file: file
                    });
                };
                reader.readAsDataURL(file);
            }

            event.target.value = '';
        },

        removeNewImage(index) {
            this.previewImages.splice(index, 1);
        },

        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        },

        // ========== USER SEARCH METHODS ==========
        async searchUsers() {
            if (this.userSearch.length < 2) {
                this.searchResults = [];
                this.showUserDropdown = false;
                return;
            }

            this.searchingUsers = true;
            try {
                const formData = new FormData();
                formData.append('search', this.userSearch);

                const response = await fetch('<?= root ?>admin/stays/search-users', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                if (data.success) {
                    this.searchResults = data.users;
                    this.showUserDropdown = this.searchResults.length > 0;
                }
            } catch (error) {
                console.error('Error searching users:', error);
            } finally {
                this.searchingUsers = false;
            }
        },

        selectUser(user) {
            console.log("Selected user:", user); // Debug
            
            // ✅ FIX: Set selectedUser object properly
            this.selectedUser = {
                user_id: user.user_id,
                first_name: user.first_name,
                last_name: user.last_name,
                email: user.email
            };
            
            // ✅ FIX: Update the display search text
            this.userSearch = user.first_name + ' ' + user.last_name + ' - ' + user.email;
            
            // ✅ FIX: CRITICAL - Update the hidden input value
            const userIdInput = document.getElementById('user_id_input');
            if (userIdInput) {
                userIdInput.value = user.user_id;
            }
            
            // ✅ Also check if there's a form element with name="user_id"
            const formUserIdInput = document.querySelector('input[name="user_id"]');
            if (formUserIdInput && formUserIdInput !== userIdInput) {
                formUserIdInput.value = user.user_id;
            }
            
            this.showUserDropdown = false;
            this.searchResults = [];
            
        },

        clearUserSelection() {
            this.selectedUser = null;
            this.userSearch = '';
            this.searchResults = [];
            this.showUserDropdown = false;
            
            // ✅ FIX: Also clear the hidden input
            const userIdInput = document.getElementById('user_id_input');
            if (userIdInput) {
                userIdInput.value = '0'; // or '' depending on your DB
            }
        },

        // ========== LOCATION SEARCH METHODS ==========
        async searchLocations() {
            if (this.locationSearch.length < 2) {
                this.locationResults = [];
                this.showLocationDropdown = false;
                return;
            }

            this.searchingLocations = true;
            try {
                const formData = new FormData();
                formData.append('search', this.locationSearch);

                const response = await fetch('<?= root.admin ?>/stays/search-locations', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                if (data.success) {
                    this.locationResults = data.locations;
                    this.showLocationDropdown = this.locationResults.length > 0;
                }
            } catch (error) {
                console.error('Error searching locations:', error);
            } finally {
                this.searchingLocations = false;
            }
        },

        selectLocation(location) {
            this.selectedLocation = location;
            this.locationSearch = location.city + ', ' + location.country;
            this.showLocationDropdown = false;
            this.locationResults = [];
            document.getElementById('location_input').value = location.city;
            document.getElementById('latitude_input').value = location.latitude || '';
            document.getElementById('longitude_input').value = location.longitude || '';
        },

        clearLocationSelection() {
            this.selectedLocation = null;
            this.locationSearch = '';
            this.locationResults = [];
            this.showLocationDropdown = false;
            document.getElementById('location_input').value = '';
            document.getElementById('latitude_input').value = '';
            document.getElementById('longitude_input').value = '';
        },

        validateForm() {
            const errors = [];
            const requiredFields = [
                { name: 'hotel_name', label: '<?= T::name ?>', tab: 'general' },
                { name: 'currency', label: '<?= T::currency ?>', tab: 'general' },
                { name: 'location', label: '<?= T::location ?>', tab: 'location', customCheck: () => !this.selectedLocation },
                { name: 'address', label: '<?= T::address ?>', tab: 'location' }
            ];

            // Clear previous error styles
            document.querySelectorAll('.input-error, .select-error').forEach(el => {
                el.classList.remove('input-error', 'select-error');
            });
            document.querySelectorAll('.error-message').forEach(el => el.remove());

            // Validate each required field
            requiredFields.forEach(field => {
                let isInvalid = false;
                let element = null;

                if (field.customCheck) {
                    isInvalid = field.customCheck();
                    element = document.querySelector(`input[name="${field.name}"]`)?.closest('.relative') ||
                            document.querySelector(`input[name="${field.name}"]`)?.parentElement;
                } else {
                    element = document.querySelector(`input[name="${field.name}"], select[name="${field.name}"], textarea[name="${field.name}"]`);
                    if (element) {
                        const value = element.value.trim();
                        isInvalid = !value;
                    }
                }

                if (isInvalid) {
                    errors.push({
                        field: field.name,
                        label: field.label,
                        tab: field.tab,
                        element: element
                    });
                }
            });

            return errors;
        },

        showValidationErrors(errors) {
            if (errors.length === 0) return;

            this.switchTab(errors[0].tab);

            errors.forEach(error => {
                if (error.element) {
                    const inputElement = error.element.querySelector('input, select, textarea') || error.element;
                    if (inputElement) {
                        inputElement.classList.add('input-error');
                        inputElement.style.borderColor = '#EF4444';
                        inputElement.style.backgroundColor = '#FEF2F2';
                    }

                    const errorMsg = document.createElement('p');
                    errorMsg.className = 'error-message text-red-600 text-xs mt-1 flex items-center gap-1';
                    errorMsg.innerHTML = `
                        <span class="material-symbols-outlined text-sm">error</span>
                        <span>${error.label} is required</span>
                    `;

                    if (inputElement.parentElement) {
                        inputElement.parentElement.appendChild(errorMsg);
                    }
                }
            });

            const errorList = errors.map(e => e.label).join(', ');
            vt.error(`Please fill all required fields: ${errorList}`);

            if (errors[0].element) {
                setTimeout(() => {
                    errors[0].element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 300);
            }
        },

        // ========== HOTEL FORM SUBMIT ==========
        submitForm(event) {
            event.preventDefault();

            // Validate form
            const errors = this.validateForm();
            if (errors.length > 0) {
                this.showValidationErrors(errors);
                return false;
            }

            this.loading = true;

            // ✅ IMPORTANT FIX: Ensure user_id is set correctly
            const userIdInput = document.getElementById('user_id_input');
            
            if (userIdInput) {
                // If user is selected via search, use that ID
                if (this.selectedUser && this.selectedUser.user_id) {
                    userIdInput.value = this.selectedUser.user_id;
                } 
                // If we are in edit mode (hotel_id > 0) and we have an initial owner (selectedUser) but user hasn't changed it
                else if (this.selectedUser && this.selectedUser.user_id) {
                     userIdInput.value = this.selectedUser.user_id;
                }
                // If the user deliberately cleared the selection
                else if (this.selectedUser === null) {
                    userIdInput.value = '';
                }
            }

            // Update CKEditor content before submitting
            if (editorInstances.main) {
                const mainTextarea = document.querySelector('#hotel_description');
                if (mainTextarea) {
                    mainTextarea.value = editorInstances.main.getData();
                }
            }

            // Update all translation editors
            for (const [langCode, editor] of Object.entries(editorInstances.translations.desc)) {
                if (editor) {
                    const textarea = document.querySelector(`textarea[name='desc_translations[${langCode}]']`);
                    if (textarea) {
                        textarea.value = editor.getData();
                    }
                }
            }

            // Store the active tab in sessionStorage to restore after redirect
            if (this.activeTab === 'translations' && this.activeTranslationTab) {
                sessionStorage.setItem('hotel_active_tab', this.activeTab + ':' + this.activeTranslationTab);
            } else {
                sessionStorage.setItem('hotel_active_tab', this.activeTab);
            }

            // For image uploads, we need to use FormData with fetch
            if (this.previewImages.length > 0) {
                // Ensure user_id is set in the hidden input before creating FormData
                const userIdInput = document.getElementById('user_id_input');
                if (userIdInput && this.selectedUser) {
                    userIdInput.value = this.selectedUser.user_id;
                }

                const formData = new FormData(event.target);

                // Double-check user_id is in FormData
                if (userIdInput && userIdInput.value) {
                    formData.set('user_id', userIdInput.value);
                }

                // Handle images correctly - remove the empty file input
                formData.delete('hotel_images[]');

                // Append actual file objects from previewImages
                this.previewImages.forEach((image) => {
                    if (image.file) {
                        formData.append('hotel_images[]', image.file);
                    }
                });

                // Append amenities
                formData.append('amenity_ids', JSON.stringify(this.selectedAmenities));

                // Append reordered images and deletion data (if in edit mode)
                <?php if ($isEdit): ?>
                    if (this.hotelImages && this.hotelImages.length > 0) {
                        formData.append('reordered_images', JSON.stringify(this.hotelImages));
                    }
                    if (this.imagesToDelete && this.imagesToDelete.length > 0) {
                        formData.append('images_to_delete', JSON.stringify(this.imagesToDelete));
                    }
                    if (this.defaultImage) {
                        formData.append('default_image', this.defaultImage);
                    }
                <?php endif; ?>

                // Submit with fetch for file uploads
                fetch(event.target.action, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(html => {
                    // Replace page content
                    document.open();
                    document.write(html);
                    document.close();
                })
                .catch(error => {
                    console.error('Form submission error:', error);
                    alert('An error occurred while submitting the form. Please try again.');
                    this.loading = false;
                });
            } else {
                // No images to upload, use regular form submission
                // Ensure user_id is set before submission
                const userIdInput = document.getElementById('user_id_input');
                if (userIdInput) {
                    if (this.selectedUser) {
                        userIdInput.value = this.selectedUser.user_id;
                    } else if (!userIdInput.value) { 
                        // If no user selected and input is empty, default to 0 (no owner)
                         userIdInput.value = '0';
                    }
                }
                event.target.submit();
            }

            return false;
        },

        // ========== ROOMS STATE PERSISTENCE ==========
        saveRoomState() {
            if (this.activeTab === 'rooms') {
                const state = {
                    viewMode: this.roomsViewMode,
                    roomId: this.currentEditingRoomId,
                    activeTab: this.roomActiveTab,
                    timestamp: Date.now()
                };
                localStorage.setItem('roomState_<?= $hotel['id'] ?? 'new' ?>', JSON.stringify(state));
            }
        },

        restoreRoomState() {
            if (this.roomStateRestored) return;

            const savedState = localStorage.getItem('roomState_<?= $hotel['id'] ?? 'new' ?>');
            if (savedState) {
                try {
                    const state = JSON.parse(savedState);

                    const FIVE_MINUTES = 5 * 60 * 1000;
                    if (state.timestamp && (Date.now() - state.timestamp > FIVE_MINUTES)) {
                        console.log('Room state expired, clearing...');
                        this.clearRoomState();
                        this.roomStateRestored = true;
                        return;
                    }

                    if (this.activeTab === 'rooms') {
                        if (state.viewMode === 'edit' && state.roomId) {
                            setTimeout(() => {
                                this.showEditRoom(state.roomId);
                                this.roomActiveTab = state.activeTab || 'details';
                                this.roomStateRestored = true;
                            }, 100);
                        } else if (state.viewMode === 'add') {
                            setTimeout(() => {
                                this.showAddRoom();
                                this.roomActiveTab = state.activeTab || 'details';
                                this.roomStateRestored = true;
                            }, 100);
                        } else {
                            this.roomStateRestored = true;
                        }
                    } else {
                        this.clearRoomState();
                        this.roomStateRestored = true;
                    }
                } catch (e) {
                    console.error('Error restoring room state:', e);
                    this.clearRoomState();
                    this.roomStateRestored = true;
                }
            } else {
                this.roomStateRestored = true;
            }
        },

        clearRoomState() {
            localStorage.removeItem('roomState_<?= $hotel['id'] ?? 'new' ?>');
        },

        // ========== ROOMS NAVIGATION METHODS ==========
        showRoomsList() {
            this.roomsViewMode = 'list';
            this.currentEditingRoomId = 0;
            this.resetRoomForm();
            this.clearRoomState();
        },

        backToRoomsList() {
            this.clearRoomState();
            window.location.hash = 'rooms';
            window.location.reload();
        },

        showAddRoom() {
            this.roomsViewMode = 'add';
            this.currentEditingRoomId = 0;
            this.roomActiveTab = 'details';
            this.resetRoomForm();
            this.saveRoomState();
        },

        async showEditRoom(roomId) {
            this.roomsViewMode = 'edit';
            this.currentEditingRoomId = roomId;
            this.roomActiveTab = 'details';

            try {
                const formData = new FormData();
                formData.append('room_id', roomId);
                formData.append('hotel_id', <?php echo $hotel_id ?? 0 ?>);  // ADD hotel_id

                const response = await fetch('<?= root ?>admin/stays/rooms/get-room-data', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        'room_id': roomId,
                        'hotel_id': <?php echo $hotel_id ?? 0 ?>  // ADD hotel_id
                    })
                });

                const data = await response.json();

                if (data.success) {
                    this.currentRoomData = data.room;
                    this.roomImages = data.room.images || [];
                    this.roomOptions = data.room.options || [];
                    this.roomDefaultImage = data.room.default_image || '';
                    this.selectedRoomAmenities = data.room.amenities || [];

                    // Populate form fields
                    this.$nextTick(() => {
                        if (data.room.room_type_id) {
                            document.getElementById('room_type_id').value = data.room.room_type_id;
                        }
                        if (data.room.status !== undefined) {
                            document.getElementById('room_status').checked = data.room.status == 1;
                        }

                        // Populate translations if they exist
                        if (data.room.translations) {
                            const translations = typeof data.room.translations === 'string'
                                ? JSON.parse(data.room.translations)
                                : data.room.translations;

                            for (const [lang, data] of Object.entries(translations)) {
                                if (data.name) {
                                    const input = document.getElementById('room_name_translation_' + lang);
                                    if (input) {
                                        input.value = data.name;
                                    }
                                }
                            }
                        }
                    });

                    this.saveRoomState();
                } else {
                    alert('Failed to load room data');
                    this.showRoomsList();
                }
            } catch (error) {
                console.error('Error loading room:', error);
                alert('An error occurred while loading room data');
                this.showRoomsList();
            }
        },

        async deleteRoom(roomId) {
            if (!confirm('<?= T::are_you_sure_delete_room ?? "Are you sure you want to delete this room?" ?>')) {
                return;
            }

            // Find the row element using data-id attribute
            const rowElement = document.querySelector(`tr.row-item[data-id="${roomId}"]`);

            if (rowElement) {
                // Add slide-out animation
                rowElement.style.transition = 'transform 0.3s ease-out, opacity 0.3s ease-out';
                rowElement.style.transform = 'translateX(100%)';
                rowElement.style.opacity = '0';
            }

            try {
                const formData = new FormData();
                formData.append('room_id', roomId);
                formData.append('hotel_id', <?php echo $hotel_id ?? 0 ?>);

                const response = await fetch('<?= root.admin ?>/stays/rooms/delete', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const responseText = await response.text();

                if (!responseText || responseText.trim() === '') {
                    vt.error('Empty response from server');
                    // Reset animation if failed
                    if (rowElement) {
                        rowElement.style.transform = '';
                        rowElement.style.opacity = '';
                    }
                    return;
                }

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (err) {
                    console.error('Invalid JSON:', err);
                    vt.error('Server returned an invalid response');
                    // Reset animation if failed
                    if (rowElement) {
                        rowElement.style.transform = '';
                        rowElement.style.opacity = '';
                    }
                    return;
                }

                if (result.success) {
                    vt.success(result.message || '<?= T::room_deleted_successfully ?? "Room deleted successfully" ?>');

                    // Wait for animation to complete, then remove the row
                    setTimeout(() => {
                        if (rowElement) {
                            rowElement.remove();
                        }

                        // Check if there are any rooms left
                        const tbody = document.querySelector('#tableBody');
                        const remainingRows = tbody.querySelectorAll('tr.row-item');

                        if (remainingRows.length === 0) {
                            // If no rooms left, show the "no rooms" message
                            tbody.innerHTML = `
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <span class="material-symbols-outlined text-6xl text-gray-300 mb-3">bed</span>
                                            <p class="text-gray-500 text-lg font-medium"><?= T::no_rooms_found ?? 'No rooms found' ?></p>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        } else {
                            // Update row numbers after deletion
                            remainingRows.forEach((row, index) => {
                                const numberCell = row.querySelector('td:nth-child(2)');
                                if (numberCell) {
                                    numberCell.textContent = index + 1;
                                }
                            });
                        }

                        // Update total count in header
                        const totalText = document.querySelector('.px-5.py-4 p.text-sm.text-gray-600');
                        if (totalText) {
                            const newTotal = remainingRows.length;
                            totalText.textContent = `Total: ${newTotal} record${newTotal !== 1 ? 's' : ''}`;
                        }
                    }, 700); // Match the transition duration

                } else {
                    vt.error(result.message || '<?= T::failed_to_delete_room ?? "Failed to delete room" ?>');
                    // Reset animation if failed
                    if (rowElement) {
                        rowElement.style.transform = '';
                        rowElement.style.opacity = '';
                    }
                }

            } catch (error) {
                console.error('Delete error:', error);
                vt.error('An error occurred while deleting the room: ' + error.message);
                // Reset animation if failed
                if (rowElement) {
                    rowElement.style.transform = '';
                    rowElement.style.opacity = '';
                }
            }
        },

        resetRoomForm() {
            this.roomImagesToDelete = [];
            this.roomDefaultImage = '';
            this.roomImages = [];
            this.roomPreviewImages = [];
            this.roomOptions = [];
            this.currentRoomData = null;
            this.selectedRoomAmenities = [];
            this.showRoomOptionForm = false;
            this.editingRoomOptionIndex = -1;
        },

        // ========== ROOM TAB METHODS ==========
        switchRoomTab(tab) {
            this.roomActiveTab = tab;
            this.saveRoomState();
        },

        switchRoomTranslationTab(langCode) {
            this.roomActiveTranslationTab = langCode;
        },

        // ========== ROOM IMAGE METHODS ==========
        toggleDeleteRoomImage(imageUrl) {
            const index = this.roomImagesToDelete.indexOf(imageUrl);
            if (index > -1) {
                this.roomImagesToDelete.splice(index, 1);
            } else {
                this.roomImagesToDelete.push(imageUrl);
                if (this.roomDefaultImage === imageUrl) {
                    this.roomDefaultImage = '';
                }
            }
        },

        getBoardName(boardId) {
            const boards = <?= json_encode(array_map(function($board) {
                return ['id' => $board['id'], 'name' => $board['name']];
            }, $boards)) ?>;

            const board = boards.find(b => b.id == boardId);
            return board ? board.name : '—';
        },

        setRoomDefaultImage(imageUrl) {
            if (!this.roomImagesToDelete.includes(imageUrl)) {
                this.roomDefaultImage = imageUrl;
            }
        },

        roomDragStart(index) {
            this.roomDraggedIndex = index;
        },

        roomDragOver(event, index) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        },

        roomDrop(dropIndex) {
            if (this.roomDraggedIndex === null || this.roomDraggedIndex === dropIndex) {
                this.roomDraggedIndex = null;
                return;
            }

            const newImages = [...this.roomImages];
            const [draggedItem] = newImages.splice(this.roomDraggedIndex, 1);
            newImages.splice(dropIndex, 0, draggedItem);
            this.roomImages = newImages;
            this.roomDraggedIndex = null;
        },

        openRoomLightbox(imageUrl) {
            this.roomLightboxImage = imageUrl;
            this.showRoomLightbox = true;
            document.body.style.overflow = 'hidden';
        },

        closeRoomLightbox() {
            this.showRoomLightbox = false;
            this.roomLightboxImage = '';
            document.body.style.overflow = '';
        },

        handleRoomImageUpload(event) {
            const files = event.target.files;

            for (let i = 0; i < files.length; i++) {
                const file = files[i];

                if (file.size > 5 * 1024 * 1024) {
                    vt.error('File too large: ' + file.name + '. Max 5MB');
                    continue;
                }

                if (!file.type.startsWith('image/')) {
                    vt.error('Invalid file type: ' + file.name);
                    continue;
                }

                const reader = new FileReader();
                reader.onload = (e) => {
                    this.roomPreviewImages.push({
                        preview: e.target.result,
                        name: file.name,
                        size: file.size,
                        file: file  // Store the actual File object
                    });
                };
                reader.readAsDataURL(file);
            }

            // Clear the input so user can select same files again if needed
            event.target.value = '';
        },

        removeNewRoomImage(index) {
            this.roomPreviewImages.splice(index, 1);
        },

        // ========== ROOM OPTIONS METHODS ==========
        addNewRoomOption() {
            this.editingRoomOptionIndex = -1;
            this.showRoomOptionForm = true;
            this.selectedRoomAmenities = [];

            this.$nextTick(() => {
                const form = document.getElementById('room-option-form');
                if (form) form.reset();
            });
        },

        editRoomOption(index) {
            this.editingRoomOptionIndex = index;
            this.showRoomOptionForm = true;

            const option = this.roomOptions[index];
            this.selectedRoomAmenities = JSON.parse(option.amenity_ids || '[]');

            this.$nextTick(() => {
                document.getElementById('room_max_adults').value = option.max_adults;
                document.getElementById('room_max_children').value = option.max_children;
                document.getElementById('room_adult_base_price').value = option.adult_base_price;
                document.getElementById('room_child_base_price').value = option.child_base_price;
                document.getElementById('room_discount_percentage').value = option.discount_percentage;
                document.getElementById('room_extra_bed_available').checked = option.extra_bed_available;
                document.getElementById('room_extra_bed_charge').value = option.extra_bed_charge;
                document.getElementById('room_breakfast_included').checked = option.breakfast_included;
                document.getElementById('room_cancellation_free').checked = option.cancellation_free;
                document.getElementById('room_available_quantity').value = option.available_quantity;
                document.getElementById('room_option_status').checked = option.status;
                document.getElementById('room_board_id').value = option.board_id || '';
            });
        },

        cancelRoomOptionForm() {
            this.showRoomOptionForm = false;
            this.editingRoomOptionIndex = -1;
            this.selectedRoomAmenities = [];
        },

        toggleRoomAmenity(amenityId) {
            const index = this.selectedRoomAmenities.indexOf(amenityId);
            if (index > -1) {
                this.selectedRoomAmenities.splice(index, 1);
            } else {
                this.selectedRoomAmenities.push(amenityId);
            }
        },

        toggleRoomAmenity(amenityId) {
            const index = this.selectedRoomAmenities.indexOf(amenityId);
            if (index > -1) {
                this.selectedRoomAmenities.splice(index, 1);
            } else {
                this.selectedRoomAmenities.push(amenityId);
            }
        },

        addNewRoomOption() {
            this.editingRoomOptionIndex = -1;
            this.showRoomOptionForm = true;
            this.selectedRoomAmenities = [];

            this.$nextTick(() => {
                const form = document.getElementById('room-option-form');
                if (form) {
                    form.reset();
                    // Set default values
                    document.getElementById('room_max_adults').value = 2;
                    document.getElementById('room_max_children').value = 0;
                    document.getElementById('room_available_quantity').value = 1;
                    document.getElementById('room_price').value = 0; 
                    document.getElementById('room_discount_percentage').value = 0;
                    document.getElementById('room_extra_bed_charge').value = 0;
                    document.getElementById('room_option_status').checked = true;
                }
            });
        },

        // Edit Room Option
        editRoomOption(index) {
            this.editingRoomOptionIndex = index;
            this.showRoomOptionForm = true;

            const option = this.roomOptions[index];

            // Parse amenity_ids
            let amenities = [];
            try {
                amenities = typeof option.amenity_ids === 'string'
                    ? JSON.parse(option.amenity_ids)
                    : (Array.isArray(option.amenity_ids) ? option.amenity_ids : []);
            } catch (e) {
                console.error('Error parsing amenity_ids:', e);
                amenities = [];
            }
            this.selectedRoomAmenities = amenities;

            // Populate form fields with UPDATED FIELDS
            this.$nextTick(() => {
                document.getElementById('room_max_adults').value = option.max_adults || 2;
                document.getElementById('room_max_children').value = option.max_children || 0;
                document.getElementById('room_price').value = option.price || 0;
                document.getElementById('room_discount_percentage').value = option.discount_percentage || 0;
                document.getElementById('room_extra_bed_available').checked = option.extra_bed_available == 1;
                document.getElementById('room_extra_bed_charge').value = option.extra_bed_charge || 0;
                document.getElementById('room_breakfast_included').checked = option.breakfast_included == 1;
                document.getElementById('room_cancellation_free').checked = option.cancellation_free == 1;
                document.getElementById('room_refundable').checked = option.refundable == 1;
                document.getElementById('room_available_quantity').value = option.available_quantity || 1;
                document.getElementById('room_option_status').checked = option.status == 1;
                document.getElementById('room_board_id').value = option.board_id || '';
            });
        },

        // Cancel Room Option Form
        cancelRoomOptionForm() {
            this.showRoomOptionForm = false;
            this.editingRoomOptionIndex = -1;
            this.selectedRoomAmenities = [];
        },

        // Toggle Room Amenity Selection
        toggleRoomAmenity(amenityId) {
            const index = this.selectedRoomAmenities.indexOf(amenityId);
            if (index > -1) {
                this.selectedRoomAmenities.splice(index, 1);
            } else {
                this.selectedRoomAmenities.push(amenityId);
            }
        },

        // ========== ROOM OPTION VALIDATION ==========
        validateRoomOptionForm() {
            const errors = [];
            const requiredFields = [
                { name: 'max_adults', label: '<?= T::max_adults ?? 'Max Adults' ?>' },
                { name: 'max_children', label: '<?= T::max_children ?? 'Max Children' ?>' },
                { name: 'available_quantity', label: '<?= T::available_quantity ?? 'Available Quantity' ?>' },
                { name: 'price', label: '<?= T::price ?? 'Price' ?>' },
                { name: 'board_id', label: '<?= T::board ?? 'Board' ?>' }
            ];

            // Clear previous error styles
            document.querySelectorAll('#room-option-form .input-error, #room-option-form .select-error').forEach(el => {
                el.classList.remove('input-error', 'select-error');
            });
            document.querySelectorAll('#room-option-form .error-message').forEach(el => el.remove());

            // Validate each required field
            requiredFields.forEach(field => {
                const element = document.querySelector(`#room-option-form [name="${field.name}"]`);
                if (element) {
                    const value = element.value.trim();
                    const isInvalid = !value || value === '' || value === '0' && field.name === 'price';
                    
                    if (isInvalid) {
                        errors.push({
                            field: field.name,
                            label: field.label,
                            element: element
                        });
                    }
                }
            });

            return errors;
        },

        showRoomOptionValidationErrors(errors) {
            if (errors.length === 0) return;

            errors.forEach(error => {
                if (error.element) {
                    error.element.classList.add('input-error');
                    error.element.style.borderColor = '#EF4444';
                    error.element.style.backgroundColor = '#FEF2F2';

                    const errorMsg = document.createElement('p');
                    errorMsg.className = 'error-message text-red-600 text-xs mt-1 flex items-center gap-1';
                    errorMsg.innerHTML = `
                        <span class="material-symbols-outlined text-sm">error</span>
                        <span>${error.label} is required</span>
                    `;

                    if (error.element.parentElement) {
                        error.element.parentElement.appendChild(errorMsg);
                    }
                }
            });

            const errorList = errors.map(e => e.label).join(', ');
            vt.error(`Please fill all required fields: ${errorList}`);

            if (errors[0].element) {
                setTimeout(() => {
                    errors[0].element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 100);
            }

            setTimeout(() => {
                document.querySelectorAll('#room-option-form .input-error, #room-option-form .select-error').forEach(el => {
                    el.classList.remove('input-error', 'select-error');
                    el.style.borderColor = '';
                    el.style.backgroundColor = '';
                });
                document.querySelectorAll('#room-option-form .error-message').forEach(el => el.remove());
            }, 2000);
        },

        async saveRoomOption(event) {
            event.preventDefault();
            event.stopPropagation();

            // Validate form before submission
            const errors = this.validateRoomOptionForm();
            if (errors.length > 0) {
                this.showRoomOptionValidationErrors(errors);
                return;
            }

            if (!this.currentEditingRoomId || this.currentEditingRoomId === 0) {
                vt.error('Please save the room first before adding options');
                return;
            }

            this.roomOptionLoading = true;

            // Create new FormData from the form
            const formData = new FormData(event.target);

            // Append required data
            formData.append('room_id', this.currentEditingRoomId);
            formData.append('hotel_id', <?= $hotel_id ?? 0 ?>);
            formData.append('option_index', this.editingRoomOptionIndex);

            try {
                const response = await fetch('<?= root.admin ?>/stays/rooms/options/save', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const responseText = await response.text();

                if (!responseText || responseText.trim() === '') {
                    vt.error('Empty response from server');
                    this.roomOptionLoading = false;
                    return;
                }

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (err) {
                    console.error("Invalid JSON from server:", err);
                    vt.error("Server returned an invalid response");
                    this.roomOptionLoading = false;
                    return;
                }

                if (result.success) {
                    vt.success(result.message);

                    // Refresh room data to load updated options
                    await this.showEditRoom(this.currentEditingRoomId);

                    // Restore the options tab
                    this.roomActiveTab = 'options';

                    // Reset option form state
                    this.showRoomOptionForm = false;
                    this.editingRoomOptionIndex = -1;
                    this.selectedRoomAmenities = [];

                } else {
                    vt.error(result.message || "Failed to save option");
                }

            } catch (error) {
                console.error("Fetch error:", error);
                vt.error("An error occurred while saving the option: " + error.message);
            }

            this.roomOptionLoading = false;
        },

        async deleteRoomOption(index) {
            if (!confirm('Are you sure you want to delete this option?')) {
                return;
            }

            if (!this.currentEditingRoomId || this.currentEditingRoomId === 0) {
                vt.error('Please save the room first');
                return;
            }

            this.roomOptionLoading = true;

            const formData = new FormData();
            formData.append('room_id', this.currentEditingRoomId);
            formData.append('option_index', index);
            formData.append('hotel_id', <?= $hotel_id ?? 0 ?>);

            // Add CSRF token if exists
            const csrfToken = document.querySelector('input[name="csrf_token"]');
            if (csrfToken) {
                formData.append('csrf_token', csrfToken.value);
            }

            try {
                const response = await fetch('<?= root.admin ?>/stays/rooms/options/delete', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const responseText = await response.text();

                if (!responseText || responseText.trim() === '') {
                    vt.error("Empty response from server");
                    this.roomOptionLoading = false;
                    return;
                }

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (err) {
                    console.error("Invalid JSON:", err);
                    vt.error("Server returned an invalid response");
                    this.roomOptionLoading = false;
                    return;
                }

                if (result.success) {
                    vt.success(result.message);

                    // Remove from UI list
                    this.roomOptions.splice(index, 1);

                    // Reload room to refresh options (prevents desync)
                    await this.showEditRoom(this.currentEditingRoomId);

                    // Stay on options tab
                    this.roomActiveTab = "options";

                } else {
                    vt.error(result.message || "Failed to delete option");
                }

            } catch (error) {
                console.error("Fetch error:", error);
                vt.error("An error occurred while deleting the option: " + error.message);

            } finally {
                this.roomOptionLoading = false;
            }
        },

        async submitRoomForm(event) {
            event.preventDefault();
            event.stopPropagation();

            this.roomLoading = true;

            // Create FormData from the form element
            const formData = new FormData(event.target);

            // Remove the empty room_images[] that came from the cleared input
            formData.delete('room_images[]');

            // Manually append the actual files from roomPreviewImages
            if (this.roomPreviewImages.length > 0) {
                this.roomPreviewImages.forEach((image, index) => {
                    if (image.file) {
                        formData.append('room_images[]', image.file);
                        console.log('Appended file:', image.file.name);
                    }
                });
            }

            // Append additional data
            formData.append('room_id', this.currentEditingRoomId);
            formData.append('hotel_id', <?php echo $hotel_id ?? '' ?>);
            formData.append('reordered_images', JSON.stringify(this.roomImages));
            formData.append('images_to_delete', JSON.stringify(this.roomImagesToDelete));
            formData.append('default_image', this.roomDefaultImage);

            // Append amenities data
            formData.append('amenities', JSON.stringify(this.selectedRoomAmenities));

            try {
                const response = await fetch('<?= root.admin ?>/stays/rooms/save', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const responseText = await response.text();

                if (!responseText || responseText.trim() === '') {
                    vt.error('Empty response from server');
                    this.roomLoading = false;
                    return;
                }

                try {
                    const result = JSON.parse(responseText);

                    if (result.success) {
                        // Update the current editing room ID if it was a new room
                        if (this.currentEditingRoomId === 0 && result.room_id) {
                            this.currentEditingRoomId = result.room_id;
                            this.roomsViewMode = 'edit';
                        }

                        // Clear preview images after successful upload
                        this.roomPreviewImages = [];

                        // Save the current state before reload
                        this.saveRoomState();

                        // Set hash to rooms tab
                        window.location.hash = 'rooms';

                        // Reload to show updated data
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        vt.error(result.message || 'An error occurred');
                        this.roomLoading = false;
                    }
                } catch (parseError) {
                    vt.error('Server returned an invalid response');
                    this.roomLoading = false;
                }

            } catch (error) {
                console.error('Fetch error:', error);
                vt.error('An error occurred while saving the room: ' + error.message);
                this.roomLoading = false;
            }
        },

        // ========== INITIALIZATION ==========
        init() {
            // Check for initial hash and switch to that tab
            if (window.location.hash) {
                const tab = window.location.hash.substring(1);
                this.switchTab(tab);
            } else {
                // If no hash, default to first tab (general)
                // this.switchTab('general');
            }
            
            // Re-order images initialization
            // this.updateReorderedImagesInput(); // Removed as it is not defined
            
            // Initialize selectedUser from global variable
            if (typeof initialOwner !== 'undefined' && initialOwner) {
                this.selectedUser = initialOwner;
                // Also ensure the hidden input is set (though PHP sets it too)
                this.$nextTick(() => {
                    const userIdInput = document.getElementById('user_id_input');
                    if (userIdInput) userIdInput.value = initialOwner.user_id;
                });
            }

            this.loadTabFromHash();

            window.addEventListener('hashchange', () => {
                this.loadTabFromHash();
            });

            window.addEventListener('beforeunload', () => {
                if (this.activeTab !== 'rooms' || this.roomsViewMode === 'list') {
                    this.clearRoomState();
                }
            });

            if (this.activeTab !== 'rooms') {
                this.clearRoomState();
            }

            window.showEditRoom = (id) => this.showEditRoom(id);
            window.deleteRoom = (id) => this.deleteRoom(id);

            this.setupRoomTableEvents();
        },
        setupRoomTableEvents() {
            // Listen for clicks on edit buttons
            this.$el.addEventListener('click', (e) => {
                const editBtn = e.target.closest('.edit-room-btn');
                const deleteBtn = e.target.closest('.delete-room-btn');

                if (editBtn) {
                    e.preventDefault();
                    let roomId = editBtn.getAttribute('data-id');
                    
                    // Fallback to extraction from onclick if data-id is missing or still contains {id}
                    if (!roomId || roomId === '{id}') {
                        const onclick = editBtn.getAttribute('onclick');
                        const match = onclick ? onclick.match(/\d+/) : null;
                        if (match) roomId = match[0];
                    }

                    if (roomId && roomId !== '{id}') {
                        console.log('Editing room ID:', roomId);
                        this.showEditRoom(parseInt(roomId));
                    } else {
                        console.error('Invalid room ID:', roomId);
                    }
                }

                if (deleteBtn) {
                    e.preventDefault();
                    let roomId = deleteBtn.getAttribute('data-id');

                    // Fallback to extraction from onclick if data-id is missing or still contains {id}
                    if (!roomId || roomId === '{id}') {
                        const onclick = deleteBtn.getAttribute('onclick');
                        const match = onclick ? onclick.match(/\d+/) : null;
                        if (match) roomId = match[0];
                    }

                    if (roomId && roomId !== '{id}' && confirm('Are you sure?')) {
                        console.log('Deleting room ID:', roomId);
                        this.deleteRoom(parseInt(roomId));
                    }
                }
            });
        }
    };
}
</script>
<?php endif; ?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root.admin ?>/stays" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>

        <?php if ($isView): ?>
        <a href="<?= root ?>admin/stays/edit/<?= $hotel['id'] ?>" class="btn flex items-center gap-2">
            <span class="material-symbols-outlined">edit</span>
            <span><?= T::edit ?></span>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($isView): ?>
        <!-- VIEW MODE -->
        <?php
        $default_image = '';
        if (!empty($hotel_images)) {
            $default = array_values(array_filter($hotel_images, fn($img) => !empty($img['default'])));
            $default_image = !empty($default) ? $default[0]['url'] : $hotel_images[0]['url'];
        }
        ?>
        <?php if (!empty($default_image)): ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined">image</span>
                <?= T::images ?>
            </h3>
            <img src="<?= root . $default_image ?>" alt="<?= htmlspecialchars($hotel['name']) ?>" class="w-full max-w-2xl h-auto rounded-lg border">
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Basic Information Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">hotel</span>
                    <?= T::basic_information ?>
                </h3>

                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]">ID:</span>
                        <span class="text-sm text-gray-900 font-semibold">#<?= $hotel['id'] ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::name ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($hotel['name']) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::owner ?>:</span>
                        <span class="text-sm text-gray-900">
                            <?= htmlspecialchars(trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''))) ?>
                            <span class="text-gray-500">(<?= $owner['email'] ?? '' ?>)</span>
                        </span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::location ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($hotel['location']) ?></span>
                    </div>

                    <?php if (!empty($hotel['address'])): ?>
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::address ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($hotel['address']) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($hotel['stars']): ?>
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::stars ?>:</span>
                        <span class="text-sm text-gray-900">
                            <?php for ($i = 0; $i < $hotel['stars']; $i++): ?>
                                <span class="material-symbols-outlined text-yellow-500 text-base">star</span>
                            <?php endfor; ?>
                            (<?= $hotel['stars'] ?> <?= T::stars ?>)
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if ($hotel['rating']): ?>
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::rating ?>:</span>
                        <span class="text-sm text-gray-900 font-semibold"><?= number_format($hotel['rating'], 2) ?> / 5.00</span>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::currency ?>:</span>
                        <span class="text-sm text-gray-900"><?= htmlspecialchars($hotel['currency']) ?></span>
                    </div>

                    <?php if ($hotel['discount']): ?>
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::discount ?>:</span>
                        <span class="text-sm text-green-600 font-semibold"><?= $hotel['discount'] ?>% OFF</span>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::status ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $hotel['status'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                            <?= $hotel['status'] ? T::active : T::inactive ?>
                        </span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::featured ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $hotel['featured'] ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700' ?>">
                            <?= $hotel['featured'] ? T::yes : T::no ?>
                        </span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::refundable ?>:</span>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?= $hotel['refundable'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700' ?>">
                            <?= $hotel['refundable'] ? T::yes : T::no ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Contact & Policies Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined">contact_phone</span>
                    <?= T::contact_and_policies ?>
                </h3>

                <div class="space-y-3">
                    <?php if (!empty($hotel['email'])): ?>
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::email ?>:</span>
                        <a href="mailto:<?= $hotel['email'] ?>" class="text-sm text-blue-600 hover:underline">
                            <?= htmlspecialchars($hotel['email']) ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($hotel['phone'])): ?>
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::phone ?>:</span>
                        <a href="tel:<?= $hotel['phone'] ?>" class="text-sm text-blue-600 hover:underline">
                            <?= htmlspecialchars($hotel['phone']) ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($hotel['website'])): ?>
                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::website ?>:</span>
                        <a href="<?= $hotel['website'] ?>" target="_blank" class="text-sm text-blue-600 hover:underline">
                            <?= htmlspecialchars($hotel['website']) ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::check_in_time ?>:</span>
                        <span class="text-sm text-gray-900"><?= date('h:i A', strtotime($hotel['checkin_time'])) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::check_out_time ?>:</span>
                        <span class="text-sm text-gray-900"><?= date('h:i A', strtotime($hotel['checkout_time'])) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::age_requirement ?>:</span>
                        <span class="text-sm text-gray-900"><?= $hotel['booking_age_requirement'] ?> <?= T::years ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::created_at ?>:</span>
                        <span class="text-sm text-gray-900"><?= date('M d, Y h:i A', strtotime($hotel['created_at'])) ?></span>
                    </div>

                    <div class="flex items-start gap-3">
                        <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::updated_at ?>:</span>
                        <span class="text-sm text-gray-900"><?= date('M d, Y h:i A', strtotime($hotel['updated_at'])) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($latitude) && !empty($longitude) && ($latitude != 0 || $longitude != 0)): ?>
        <!-- Location Coordinates Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mt-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined">location_on</span>
                <?= T::location_coordinates ?>
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex items-start gap-3">
                    <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::latitude ?>:</span>
                    <span class="text-sm text-gray-900 font-mono"><?= htmlspecialchars($latitude) ?></span>
                </div>

                <div class="flex items-start gap-3">
                    <span class="text-sm font-medium text-gray-500 min-w-[120px]"><?= T::longitude ?>:</span>
                    <span class="text-sm text-gray-900 font-mono"><?= htmlspecialchars($longitude) ?></span>
                </div>
            </div>

            <div class="mt-4">
                <a href="https://www.google.com/maps?q=<?= $latitude ?>,<?= $longitude ?>" target="_blank" class="btn secondary inline-flex items-center gap-2">
                    <span class="material-symbols-outlined">map</span>
                    <span><?= T::view_on_map ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($amenities_list)): ?>
        <!-- Amenities Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mt-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined">spa</span>
                <?= T::amenities ?>
            </h3>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                <?php foreach ($amenities_list as $amenity):
                    $amenity_name = $amenity['name'];
                    if (!empty($amenity['translations'])) {
                        $translations = json_decode($amenity['translations'], true);
                        $amenity_name = $translations['en'] ?? $amenity['name'];
                    }
                ?>
                <div class="flex items-center gap-2 bg-gray-50 px-3 py-2 rounded-lg">
                    <span class="material-symbols-outlined text-green-600 text-lg">check_circle</span>
                    <span class="text-sm text-gray-700"><?= htmlspecialchars($amenity_name) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($hotel['meta_title']) || !empty($hotel['meta_keywords'])): ?>
        <!-- SEO Information Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mt-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined">search</span>
                <?= T::seo_information ?>
            </h3>

            <div class="space-y-3">
                <?php if (!empty($hotel['meta_title'])): ?>
                <div class="flex items-start gap-3">
                    <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::meta_title ?>:</span>
                    <span class="text-sm text-gray-900"><?= htmlspecialchars($hotel['meta_title']) ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($hotel['meta_keywords'])): ?>
                <div class="flex items-start gap-3">
                    <span class="text-sm font-medium text-gray-500 min-w-[160px]"><?= T::meta_keywords ?>:</span>
                    <span class="text-sm text-gray-900"><?= htmlspecialchars($hotel['meta_keywords']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ADD/EDIT MODE -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200" x-data="hotelFormData()" x-init="init()">
            <!-- Tabs Navigation -->
            <div class="border-b border-gray-200">
                <nav class="flex -mb-px overflow-x-auto">
                    <button @click="switchTab('general')"
                            :class="activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">info</span>
                        <?= T::general_info ?>
                    </button>

                    <button @click="switchTab('rooms')"
                            :class="activeTab === 'rooms' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">bed</span>
                        <?= T::rooms ?>
                    </button>

                    <button @click="switchTab('location')"
                            :class="activeTab === 'location' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">location_on</span>
                        <?= T::location ?>
                    </button>

                    <button @click="switchTab('contact')"
                            :class="activeTab === 'contact' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">contact_phone</span>
                        <?= T::contact ?>
                    </button>

                    <button @click="switchTab('seo')"
                            :class="activeTab === 'seo' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">search</span>
                        <?= T::seo ?>
                    </button>

                    <button @click="switchTab('amenities')"
                            :class="activeTab === 'amenities' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">spa</span>
                        <?= T::amenities ?>
                    </button>

                    <button @click="switchTab('gallery')"
                            :class="activeTab === 'gallery' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">photo_library</span>
                        <?= T::gallery ?>
                    </button>

                    <button @click="switchTab('translations')"
                            :class="activeTab === 'translations' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">translate</span>
                        <?= T::translations ?>
                    </button>
                </nav>
            </div>

            <!-- Form -->
            <form method="POST"
                action="<?=root.admin?>/stays/<?= $isEdit ? 'edit/' . $hotel['id'] : 'add' ?>"
                @submit="submitForm($event)"
                enctype="multipart/form-data"
                class="p-6"
                x-show="activeTab !== 'rooms'"
                x-transition>
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="active_tab" :value="activeTab">

                <!-- Tab: General Info -->
                <div x-show="activeTab === 'general'" x-transition class="space-y-4">
                    <!-- Basic Information -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">hotel</span>
                            <?= T::basic_information ?>
                        </h3>

                        <!-- Row 1: Owner (50%) and Hotel Name (50%) -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <!-- Owner Search -->
                            <div>
                                <label class="required text-sm block mb-1"><?= T::owner ?> </label>
                                <div class="relative" @click.away="showUserDropdown = false">
                                    <!-- Selected User Display -->
                                    <div x-show="selectedUser" class="input text-sm flex items-center gap-2">
                                        <div class="flex-1 overflow-hidden">
                                            <span class="font-medium text-gray-900 truncate" x-text="selectedUser ? selectedUser.first_name + ' ' + selectedUser.last_name : ''"></span>
                                        </div>
                                        <button type="button" @click.stop="clearUserSelection()" class="flex-shrink-0 rounded p-1">
                                            <span class="material-symbols-outlined text-gray-500 text-base">close</span>
                                        </button>
                                    </div>

                                    <!-- Search Input -->
                                    <div x-show="!selectedUser">
                                        <input type="text"
                                               x-model="userSearch"
                                               @input.debounce.300ms="searchUsers()"
                                               @focus="searchUsers()"
                                               class="input text-sm"
                                               name="searchuser"
                                               placeholder="<?= 'Search by name or email' ?>"
                                               autocomplete="off">

                                        <div x-show="searchingUsers" class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                            <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </div>

                                        <div x-show="showUserDropdown"
                                             class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                                            <template x-for="user in searchResults" :key="user.user_id">
                                                <div @click="selectUser(user)"
                                                     class="px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                    <div class="font-medium text-gray-900 text-sm" x-text="user.first_name + ' ' + user.last_name"></div>
                                                    <div class="text-xs text-gray-500" x-text="user.email"></div>
                                                </div>
                                            </template>

                                            <div x-show="searchResults.length === 0 && userSearch.length >= 2 && !searchingUsers"
                                                 class="px-3 py-2 text-xs text-gray-500 text-center">
                                                <?= 'No users found' ?>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="user_id" id="user_id_input" value="<?= $hotel['user_id'] ?? '' ?>">
                                </div>
                            </div>

                            <!-- Hotel Name -->
                            <div class="form-control">
                                <label class="required text-sm block mb-1"><?= T::name ?> * </label>
                                <input type="text" name="hotel_name" class="input text-sm" value="<?= htmlspecialchars($hotel['name']) ?>" placeholder="<?= T::enter_hotel_name ?>">
                            </div>
                        </div>

                        <!-- Row 2: Accommodation/Discount (50%) and Stars/Rating/Currency (50%) -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <!-- Accommodation & Discount Grouped -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-sm block mb-1"><?= T::accommodation_type ?? 'Accommodation Type' ?></label>
                                    <select name="stay_type" class="select text-sm">
                                        <?php foreach ($accommodation_types ?? [] as $type): ?>
                                            <option value="<?= $type['id'] ?>" <?= $hotel['stay_type'] == $type['id'] ? 'selected' : '' ?>><?= $type['name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="text-sm block mb-1"><?= T::discount ?? 'Discount' ?> (%)</label>
                                    <input type="number" name="discount" class="input text-sm" min="0" max="100" value="<?= $hotel['discount'] ?? 0 ?>" placeholder="0">
                                </div>
                            </div>

                            <!-- Stars, Rating, & Currency Grouped -->
                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="text-sm block mb-1"><?= T::stars ?></label>
                                    <select name="stars" class="select text-sm">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <option value="<?= $i ?>" <?= $hotel['stars'] == $i ? 'selected' : '' ?>><?= $i ?> Star<?= $i > 1 ? 's' : '' ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="text-sm block mb-1"><?= T::rating ?></label>
                                    <select name="rating" class="select text-sm">
                                        <?php
                                        $ratings = [1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5, 5.0];
                                        foreach ($ratings as $r):
                                        ?>
                                            <option value="<?= $r ?>" <?= $hotel['rating'] == $r ? 'selected' : '' ?>><?= number_format($r, 1) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="required text-sm block mb-1"><?= T::currency ?> * </label>
                                    <select name="currency" class="select text-sm">
                                        <option value=""><?= 'Currency' ?></option>
                                        <?php foreach ($currencies ?? [] as $curr): ?>
                                            <option value="<?= $curr['name'] ?>" <?= $hotel['currency'] == $curr['name'] ? 'selected' : '' ?>><?= $curr['name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Hotel Description -->
                        <div class="form-control mt-4">
                            <label class="text-sm"><?= T::description ?></label>
                            <textarea id="hotel_description" name="description" class="ckeditor-hotel-desc"><?= htmlspecialchars($hotel['desc'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Check-in/out & Policies -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">schedule</span>
                            <?= T::check_in_out_policies ?>
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                            <div class="form-control">
                                <label class="text-sm"><?= T::check_in_time ?></label>
                                <input type="time" name="checkin_time" class="input text-sm" value="<?= $hotel['checkin_time'] ?? '12:00' ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm"><?= T::check_out_time ?></label>
                                <input type="time" name="checkout_time" class="input text-sm" value="<?= $hotel['checkout_time'] ?? '12:00' ?>">
                            </div>

                            <div class="form-control">
                                <label class="text-sm"><?= T::age_requirement ?></label>
                                <input type="number" name="booking_age_requirement" class="input text-sm" min="18" value="<?= $hotel['booking_age_requirement'] ?>" placeholder="18">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="refundable" value="1" id="refundable" class="checkbox-input" <?= $hotel['refundable'] ? 'checked' : '' ?>>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="refundable" class="cursor-pointer text-sm font-medium"><?= T::refundable_booking ?></label>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="featured" value="1" id="featured" class="checkbox-input" <?= $hotel['featured'] ? 'checked' : '' ?>>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="featured" class="cursor-pointer text-sm font-medium"><?= T::featured_hotel ?></label>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox" name="status" value="1" id="status" class="checkbox-input" <?= $hotel['status'] ? 'checked' : '' ?>>
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="status" class="cursor-pointer text-sm font-medium"><?= T::active_status ?></label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                            <div class="form-control">
                                <label class="text-sm"><?= 'Cancellation Policy' ?></label>
                                <textarea name="cancellation_policy" class="textarea text-sm" rows="3" placeholder="<?= 'Enter cancellation policy details' ?>"><?= htmlspecialchars($hotel['cancellation_policy'] ?? '') ?></textarea>
                            </div>

                            <div class="form-control">
                                <label class="text-sm"><?= 'Privacy Policy' ?></label>
                                <textarea name="privacy_policy" class="textarea text-sm" rows="3" placeholder="<?= 'Enter privacy policy details' ?>"><?= htmlspecialchars($hotel['privacy_policy'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Location -->
                <div x-show="activeTab === 'location'" x-transition class="space-y-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                            <span class="material-symbols-outlined text-blue-600 text-lg">location_on</span>
                            <?= 'Location Information' ?>
                        </h3>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <!-- Location Search -->
                            <div>
                                <label class="required text-sm block mb-1"><?= T::location ?> * </label>
                                <div class="relative" @click.away="showLocationDropdown = false">
                                    <!-- Selected Location Display (Can be deleted, not edited) -->
                                    <div x-show="selectedLocation" class="input text-sm flex items-center gap-2 bg-green-50 border-green-200">
                                        <div class="flex-1 overflow-hidden">
                                            <span class="font-medium text-gray-900 truncate" x-text="selectedLocation ? selectedLocation.city : ''"></span>
                                        </div>
                                        <button type="button" @click.stop="clearLocationSelection()" class="flex-shrink-0 hover:bg-green-100 rounded p-1">
                                            <span class="material-symbols-outlined text-gray-500 text-base">close</span>
                                        </button>
                                    </div>

                                    <!-- Search Input -->
                                    <div x-show="!selectedLocation">
                                        <input type="text"
                                               x-model="locationSearch"
                                               @input.debounce.300ms="searchLocations()"
                                               @focus="searchLocations()"
                                               class="input text-sm"
                                               placeholder="<?= 'Search city or country' ?>"
                                               autocomplete="off">

                                        <div x-show="searchingLocations" class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                            <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </div>

                                        <div x-show="showLocationDropdown"
                                             class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                                            <template x-for="location in locationResults" :key="location.id">
                                                <div @click="selectLocation(location)"
                                                     class="px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                    <div class="font-medium text-gray-900 text-sm" x-text="location.city + ', ' + location.country"></div>
                                                    <div class="text-xs text-gray-500" x-text="location.country_code"></div>
                                                </div>
                                            </template>

                                            <div x-show="locationResults.length === 0 && locationSearch.length >= 2 && !searchingLocations"
                                                 class="px-3 py-2 text-xs text-gray-500 text-center">
                                                <?= 'No locations found' ?>
                                            </div>
                                        </div>
                                    </div>

                                    <input type="hidden" name="location" id="location_input" value="<?= htmlspecialchars($hotel['location'] ?? '') ?>">
                                </div>
                            </div>

                            <!-- Address -->
                            <div>
                                <label class="text-sm block mb-1"><?= T::address ?> * </label>
                                <input type="text" name="address" class="input text-sm" value="<?= htmlspecialchars($hotel['address'] ?? '') ?>" placeholder="<?= 'Full street address' ?>">
                            </div>
                        </div>

                        <!-- Coordinates -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
                            <div>
                                <label class="text-sm block mb-1"><?= T::latitude ?></label>
                                <input type="text" name="latitude" id="latitude_input" class="input text-sm" value="<?= htmlspecialchars($latitude) ?>" placeholder="<?= 'e.g.' ?> 40.7128" readonly>
                                <p class="text-xs text-gray-500 mt-1"><?= 'Auto-filled from location' ?></p>
                            </div>

                            <div>
                                <label class="text-sm block mb-1"><?= T::longitude ?></label>
                                <input type="text" name="longitude" id="longitude_input" class="input text-sm" value="<?= htmlspecialchars($longitude) ?>" placeholder="<?= 'e.g.' ?> -74.0060" readonly>
                                <p class="text-xs text-gray-500 mt-1"><?= 'Auto-filled from location' ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Contact -->
                <div x-show="activeTab === 'contact'" x-transition class="space-y-6">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">contact_phone</span>
                            <?= T::contact_information ?>
                        </h3>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                            <div class="form-control">
                                <label><?= T::email ?></label>
                                <input type="text" name="email" class="input" value="<?= htmlspecialchars($hotel['email'] ?? '') ?>" placeholder="hotel@example.com">
                            </div>

                            <div class="form-control">
                                <label><?= T::phone ?></label>
                                <input type="text" name="phone" class="input" value="<?= htmlspecialchars($hotel['phone'] ?? '') ?>" placeholder="+1 (555) 123-4567">
                            </div>

                            <div class="form-control">
                                <label><?= T::website ?></label>
                                <input type="text" name="website" class="input" value="<?= htmlspecialchars($hotel['website'] ?? '') ?>" placeholder="https://example.com">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: SEO -->
                <div x-show="activeTab === 'seo'" x-transition class="space-y-6">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">search</span>
                            <?= T::seo_information ?>
                        </h3>

                        <div class="space-y-4">
                            <div class="form-control">
                                <label><?= T::meta_title ?></label>
                                <input type="text" name="meta_title" class="input" value="<?= htmlspecialchars($hotel['meta_title'] ?? '') ?>" placeholder="Enter SEO meta title">
                            </div>

                            <div class="form-control">
                                <label><?= T::meta_keywords ?></label>
                                <textarea name="meta_keywords" class="textarea" rows="3" placeholder="Enter keywords separated by commas"><?= htmlspecialchars($hotel['meta_keywords'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Amenities -->
                <div x-show="activeTab === 'amenities'" x-transition class="space-y-6">
                    <?php if (!empty($amenities)): ?>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">spa</span>
                            <?= T::amenities ?> (<?= count($amenities) ?> <?= 'available' ?>)
                        </h3>

                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                            <?php foreach ($amenities as $amenity):
                                $amenity_name = $amenity['name'];
                                if (!empty($amenity['translations'])) {
                                    $translations = json_decode($amenity['translations'], true);
                                    $amenity_name = $translations['en'] ?? $amenity['name'];
                                }
                                $is_checked = in_array($amenity['id'], $selected_amenity_ids ?? []);
                            ?>


                            <div class="bg-white rounded-lg p-3 border border-gray-200">
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <div class="checkbox-container">
                                            <input type="checkbox"
                                                @click="toggleAmenity(<?= $amenity['id'] ?>)"
                                                <?= $is_checked ? 'checked' : '' ?>
                                                :checked="selectedAmenities.includes(<?= $amenity['id'] ?>)"
                                                id="amenity_<?= $amenity['id'] ?>"
                                                class="checkbox-input">
                                            <div class="checkbox-custom">
                                                <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                            </div>
                                        </div>
                                        <label for="amenity_<?= $amenity['id'] ?>" class="cursor-pointer text-sm"><?= htmlspecialchars($amenity_name) ?></label>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <input type="hidden" name="amenity_ids" :value="JSON.stringify(selectedAmenities)">

                        <!-- Selected Amenities Count -->
                        <div x-show="selectedAmenities.length > 0" class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
                            <div class="flex items-center gap-2 text-blue-700">
                                <span class="material-symbols-outlined">check_circle</span>
                                <span class="font-medium">
                                    <span x-text="selectedAmenities.length"></span> <?= 'amenities' ?> <?= 'selected' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-6xl text-gray-300">spa</span>
                        <p class="text-gray-500 mt-2"><?= 'No amenities available' ?></p>
                        <a href="<?= root.admin ?>/stays/amenities/add" class="btn mt-4 inline-flex items-center gap-2">
                            <span class="material-symbols-outlined">add</span>
                            <span><?= 'Add Amenity' ?></span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tab: Gallery -->
                <div x-show="activeTab === 'gallery'" x-transition class="space-y-6">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">photo_library</span>
                            <?= 'Hotel Gallery' ?>
                        </h3>

                        <?php if ($isEdit): ?>
                        <!-- Existing Images -->
                        <div class="mb-6" x-show="hotelImages.length > 0">
                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">image</span>
                                <?= 'Existing Images' ?> (<span x-text="hotelImages.length"></span>)
                                <span class="text-xs text-gray-500 ml-2"><?= 'Drag to reorder' ?></span>
                            </h4>

                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <template x-for="(img, index) in hotelImages" :key="index">
                                <div class="relative group bg-white rounded-lg border-2 overflow-hidden transition-all duration-200"
                                    draggable="true"
                                    @dragstart="dragStart(index)"
                                    @dragover.prevent="dragOver($event, index)"
                                    @drop.prevent="drop(index)"
                                    @dragend="draggedIndex = null"
                                    :class="{
                                        'opacity-50': imagesToDelete.includes(img.url) || draggedIndex === index,
                                        'border-green-300 hover:border-green-400': img.url === defaultImage,
                                        'border-gray-200 hover:border-blue-400': img.url !== defaultImage,
                                        'cursor-move': !imagesToDelete.includes(img.url),
                                        'cursor-not-allowed': imagesToDelete.includes(img.url)
                                    }"
                                    :style="imagesToDelete.includes(img.url) ? 'pointer-events: none;' : ''">
                                    <div class="aspect-video relative">
                                        <img :src="'<?= root ?>' + img.url"
                                            :alt="'Hotel Image ' + (index + 1)"
                                            class="w-full h-full object-cover pointer-events-none"
                                            draggable="false">

                                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2"
                                             @mousedown.stop @click.stop>
                                            <button type="button"
                                                    @click="openLightbox(img.url)"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-blue-500 hover:bg-blue-600 text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                    title="<?= 'View' ?>">
                                                <span class="material-symbols-outlined text-xl">visibility</span>
                                            </button>

                                            <button type="button"
                                                    @click="setDefaultImage(img.url)"
                                                    x-show="!imagesToDelete.includes(img.url) && img.url !== defaultImage"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-green-500 hover:bg-green-600 text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                    title="<?= 'Set as default' ?>">
                                                <span class="material-symbols-outlined text-xl">check_circle</span>
                                            </button>

                                            <button type="button"
                                                    @click="toggleDeleteImage(img.url)"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                    :class="imagesToDelete.includes(img.url) ? 'bg-gray-500 hover:bg-gray-600' : 'bg-red-500 hover:bg-red-600'">
                                                    <span class="material-symbols-outlined text-xl" x-text="imagesToDelete.includes(img.url) ? 'undo' : 'delete'"></span>
                                            </button>
                                        </div>

                                        <div x-show="img.url === defaultImage && !imagesToDelete.includes(img.url)"
                                            class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                            <span class="material-symbols-outlined text-sm">verified</span>
                                            <span><?= 'Default' ?></span>
                                        </div>

                                        <div x-show="imagesToDelete.includes(img.url)"
                                            class="absolute inset-0 bg-red-500 bg-opacity-80 flex items-center justify-center">
                                            <div class="text-white text-center">
                                                <span class="material-symbols-outlined text-4xl mb-2">delete</span>
                                                <p class="text-sm font-semibold"><?= 'Marked for Deletion' ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                </template>
                            </div>

                            <!-- Hidden input to store reordered images -->
                            <input type="hidden" name="reordered_images" :value="JSON.stringify(hotelImages)">
                        </div>
                        <?php endif; ?>

                        <!-- New Image Preview Grid -->
                        <div x-show="previewImages.length > 0" class="mt-6 mb-4" x-transition>
                            <div class="p-4 bg-green-50 rounded-lg border border-green-200 mb-4">
                                <div class="flex items-center gap-2 text-green-700">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    <span x-text="previewImages.length === 1 ? '1 <?= T::new_image ?> <?= T::ready_to_upload ?>' : previewImages.length + ' <?= T::new_images ?> <?= T::ready_to_upload ?>'"></span>
                                </div>
                            </div>

                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">new_releases</span>
                                <?= 'Image Previews' ?>
                            </h4>

                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <template x-for="(image, index) in previewImages" :key="index">
                                    <div class="relative group bg-white rounded-lg border-2 border-green-300 overflow-hidden hover:border-green-500 transition-all duration-200">
                                        <div class="aspect-video relative">
                                            <img :src="image.preview"
                                                :alt="'New Preview ' + (index + 1)"
                                                class="w-full h-full object-cover">

                                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2">
                                                <button type="button"
                                                        @click="removeNewImage(index)"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity bg-red-500 hover:bg-red-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                    <span class="material-symbols-outlined text-xl">delete</span>
                                                </button>
                                            </div>

                                            <div class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                                <span class="material-symbols-outlined text-sm">fiber_new</span>
                                                <span><?= 'New' ?></span>
                                            </div>
                                        </div>

                                        <!-- <div class="p-3 bg-gray-50">
                                            <p class="text-xs text-gray-600 truncate" x-text="image.name"></p>
                                            <p class="text-xs text-gray-500" x-text="formatFileSize(image.size)"></p>
                                        </div> -->
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Upload New Images -->
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8">
                            <div class="text-center">
                                <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                    <span class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                                </div>

                                <h4 class="text-lg font-semibold text-gray-900 mb-2"><?= $isEdit ? ('Upload More Images') : ('Upload Images') ?></h4>
                                <p class="text-sm text-gray-600 mb-6"><?= 'Drag and drop images here, or click to select files' ?></p>

                                <label for="hotel_images_input" class="btn inline-flex items-center gap-2 cursor-pointer">
                                    <span class="material-symbols-outlined">upload</span>
                                    <span><?= 'Choose Images' ?></span>
                                </label>

                                <input type="file"
                                    id="hotel_images_input"
                                    name="hotel_images[]"
                                    multiple
                                    accept="image/*"
                                    class="hidden"
                                    @change="handleImageUpload($event)">

                                <p class="text-xs text-gray-500 mt-4">
                                    <?= 'Supported formats' ?>: JPG, PNG, WEBP • <?= 'Max size' ?>: 5MB <?= 'per image' ?>
                                </p>
                            </div>
                        </div>

                        <!-- Hidden inputs -->
                        <?php if ($isEdit): ?>
                        <input type="hidden" name="images_to_delete" :value="JSON.stringify(imagesToDelete)">
                        <input type="hidden" name="default_image" :value="defaultImage">
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Translations -->
                <div x-show="activeTab === 'translations'" x-transition class="space-y-6">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined">translate</span>
                            <?= T::translations ?>
                        </h3>

                        <!-- Translations Card with Tabs -->
                        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                            <!-- Language Tabs Navigation (matching main tabs style EXACTLY) -->
                            <div class="border-b border-gray-200">
                                <nav class="flex -mb-px overflow-x-auto">
                                    <?php
                                    foreach ($GLOBALS['languages'] ?? [] as $lang_data):
                                        if ($lang_data['lang_code'] === 'en') continue; // Skip English as it's the original
                                        $lang_name = $lang_data['name'] ?? $lang_data['lang_code'];
                                        $lang_code = $lang_data['lang_code'];

                                        // Check if any translation exists for this language
                                        $has_name = !empty($name_translations[$lang_code]);
                                        $has_desc = !empty($desc_translations[$lang_code]);
                                        $has_address = !empty($address_translations[$lang_code]);
                                        $has_cancellation = !empty($cancellation_policy_translations[$lang_code]);
                                        $has_privacy = !empty($privacy_policy_translations[$lang_code]);
                                        $has_any = $has_name || $has_desc || $has_address || $has_cancellation || $has_privacy;
                                    ?>
                                        <button type="button"
                                                @click="switchTranslationTab('<?= $lang_code ?>')"
                                                :class="activeTranslationTab === '<?= $lang_code ?>' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                                class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                                            <span class="inline-block w-2 h-2 rounded-full <?= $has_any ? 'bg-green-500' : 'bg-gray-300' ?>"></span>
                                            <?= htmlspecialchars($lang_name) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </nav>
                            </div>

                            <!-- Tab Contents -->
                            <div class="p-6">
                                <?php
                                foreach ($GLOBALS['languages'] ?? [] as $lang_data):
                                    if ($lang_data['lang_code'] === 'en') continue; // Skip English
                                    $lang_name = $lang_data['name'] ?? $lang_data['lang_code'];
                                    $lang_code = $lang_data['lang_code'];
                                    $name_value = $name_translations[$lang_code] ?? '';
                                    $desc_value = $desc_translations[$lang_code] ?? '';
                                    $address_value = $address_translations[$lang_code] ?? '';
                                    $cancellation_value = $cancellation_policy_translations[$lang_code] ?? '';
                                    $privacy_value = $privacy_policy_translations[$lang_code] ?? '';
                                ?>
                                    <div x-show="activeTranslationTab === '<?= $lang_code ?>'" x-transition class="space-y-4">

                                        <!-- All Fields in One Section -->
                                        <div class="bg-gray-50 rounded-lg p-4">
                                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                                <span class="material-symbols-outlined text-blue-600 text-lg">translate</span>
                                                <?= T::translations ?? 'Translations' ?>
                                            </h4>

                                            <!-- Hotel Name and Address - Side by Side -->
                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                                                <!-- Hotel Name Translation -->
                                                <div class="form-control">
                                                    <label class="text-sm font-medium">
                                                        <?= T::name ?? 'Hotel Name' ?>
                                                    </label>
                                                    <input type="text"
                                                        name="name_translations[<?= $lang_code ?>]"
                                                        class="input text-sm"
                                                        value="<?= htmlspecialchars($name_value) ?>"
                                                        placeholder="<?= T::enter_translation ?? 'Enter translation' ?>...">
                                                </div>

                                                <!-- Address Translation -->
                                                <div class="form-control">
                                                    <label class="text-sm font-medium">
                                                        <?= T::address ?? 'Address' ?>
                                                    </label>
                                                    <input type="text"
                                                        name="address_translations[<?= $lang_code ?>]"
                                                        class="input text-sm"
                                                        value="<?= htmlspecialchars($address_value) ?>"
                                                        placeholder="<?= T::enter_translation ?? 'Enter translation' ?>...">
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                                                <!-- Privacy Policy Translation -->
                                                <div class="form-control mb-4">
                                                    <label class="text-sm font-medium">
                                                        <?= T::privacy_policy ?? 'Privacy Policy' ?>
                                                    </label>

                                                    <textarea
                                                        id="hotel-trans-privacy-<?= $lang_code ?>"
                                                        name="privacy_policy_translations[<?= $lang_code ?>]"
                                                        class="textarea text-sm"
                                                        placeholder="<?=T::enter_privacy_policy_translation?>"
                                                        data-lang="<?= $lang_code ?>"><?= htmlspecialchars($privacy_value) ?></textarea>
                                                </div>

                                                <!-- Cancellation Policy Translation -->
                                                <div class="form-control">
                                                    <label class="text-sm font-medium">
                                                        <?= T::cancellation_policy ?? 'Cancellation Policy' ?>
                                                    </label>
                                                    <textarea
                                                        id="hotel-trans-cancellation-<?= $lang_code ?>"
                                                        name="cancellation_policy_translations[<?= $lang_code ?>]"
                                                        class="textarea text-sm"
                                                        placeholder="<?=T::enter_cancellation_policy_translation?>"
                                                        data-lang="<?= $lang_code ?>"><?= htmlspecialchars($cancellation_value) ?></textarea>
                                                </div>
                                            </div>

                                            <!-- Hotel Description Translation -->
                                            <div class="form-control mb-4">
                                                <label class="text-sm font-medium">
                                                    <?= T::description ?? 'Hotel Description' ?>
                                                </label>
                                                <textarea
                                                    id="hotel-trans-desc-<?= $lang_code ?>"
                                                    name="desc_translations[<?= $lang_code ?>]"
                                                    class="hotel-trans-desc"
                                                    data-lang="<?= $lang_code ?>"><?= htmlspecialchars($desc_value) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div x-show="activeTab !== 'rooms'" class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                    <a href="<?= root ?>admin/hotels" class="btn white text-sm"><?= T::cancel ?></a>
                    <button type="submit" class="btn text-sm" :disabled="loading">
                        <span :class="loading ? 'hidden' : 'flex'" class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">save</span>
                            <span><?= $isEdit ? T::update : T::submit ?></span>
                        </span>
                        <span :class="loading ? 'flex' : 'hidden'" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span><?= T::processing ?></span>
                        </span>
                    </button>
                </div>
            </form>

            <!-- Tab: Rooms -->
            <div x-show="activeTab === 'rooms'" x-transition class="space-y-4 p-6">
                <?php if ($isEdit): ?>
                        <!-- SHOW ROOMS MANAGEMENT ONLY IF HOTEL IS SAVED -->
                    <?php include 'rooms.php'; ?>
                <?php else: ?>
                    <!-- SHOW MESSAGE WHEN HOTEL IS NOT SAVED -->
                    <div class="bg-gray-50 rounded-lg p-6 text-center">
                        <span class="material-symbols-outlined text-6xl text-gray-300">info</span>
                        <p class="text-gray-600 mt-4">
                            <?= T::save_hotel_first ?? 'Please save the hotel first before managing rooms' ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Lightbox -->
            <?php if ($isEdit): ?>
            <div x-show="showLightbox"
                 @click="closeLightbox()"
                 @keydown.escape.window="closeLightbox()"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-90 p-4"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 style="display: none;">
                <button @click="closeLightbox()" class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors">
                    <span class="material-symbols-outlined text-4xl">close</span>
                </button>
                <img :src="'<?= root ?>' + lightboxImage"
                     @click.stop
                     class="max-w-full max-h-full object-contain rounded-lg shadow-2xl"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-90"
                     x-transition:enter-end="opacity-100 scale-100">
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!$isView): ?>
<!-- CKEditor 5 -->
<script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/super-build/ckeditor.js"></script>

<script>
// Store all editor instances
const editorInstances = {
    main: null,
    translations: {
        desc: {},
    }
};

// CKEditor Configuration
const ckEditorConfig = {
    removePlugins: [
        'RealTimeCollaborativeEditing',
        'RealTimeCollaborativeComments',
        'RealTimeCollaborativeTrackChanges',
        'RealTimeCollaborativeRevisionHistory',
        'PresenceList',
        'Comments',
        'TrackChanges',
        'TrackChangesData',
        'RevisionHistory',
        'Pagination',
        'WProofreader',
        'MathType',
        'SlashCommand',
        'Template',
        'DocumentOutline',
        'FormatPainter',
        'TableOfContents',
        'PasteFromOfficeEnhanced',
        'CaseChange',
        'ExportPdf',
        'ExportWord',
        'ImportWord',
        'MultiLevelList',
        'MentionCustomization',
        'AIAssistant',
        'OpenAITextAdapter'
    ],
    toolbar: {
        items: [
            'undo', 'redo',
            '|', 'heading',
            '|', 'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor',
            '|', 'bold', 'italic', 'underline', 'strikethrough',
            '|', 'link', 'uploadImage', 'insertTable', 'blockQuote', 'mediaEmbed',
            '|', 'alignment',
            '|', 'bulletedList', 'numberedList',
            '|', 'outdent', 'indent',
            '|', 'removeFormat'
        ],
        shouldNotGroupWhenFull: true
    },
    heading: {
        options: [
            { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
            { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
            { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
            { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
            { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' }
        ]
    },
    fontSize: {
        options: [10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 36]
    },
    fontFamily: {
        options: [
            'default',
            'Arial, Helvetica, sans-serif',
            'Georgia, serif',
            'Times New Roman, Times, serif',
            'Verdana, Geneva, sans-serif'
        ]
    },
    image: {
        resizeUnit: 'px',
        toolbar: [
            'imageTextAlternative', '|',
            'imageStyle:inline', 'imageStyle:wrapText', 'imageStyle:breakText', '|',
            'toggleImageCaption', 'linkImage'
        ]
    },
    table: {
        contentToolbar: [
            'tableColumn', 'tableRow', 'mergeTableCells',
            'tableProperties', 'tableCellProperties'
        ]
    },
    link: {
        decorators: {
            openInNewTab: {
                mode: 'manual',
                label: 'Open in a new tab',
                defaultValue: true,
                attributes: {
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }
            }
        }
    },
    simpleUpload: {
        uploadUrl: '<?= root ?>admin/cms/upload-image',
        withCredentials: true,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('input[name="csrf_token"]').value
        }
    }
};

// Initialize Main Hotel Description Editor
document.addEventListener('DOMContentLoaded', function() {
    // Initialize main editor
    CKEDITOR.ClassicEditor
        .create(document.querySelector('#hotel_description'), ckEditorConfig)
        .then(editor => {
            editorInstances.main = editor;
            const editorElement = editor.ui.view.editable.element;
            editorElement.style.minHeight = '400px';
            editorElement.style.maxHeight = '600px';
        })
        .catch(error => {
            console.error('Main CKEditor error:', error);
        });
});

// Initialize Translation Editor Function for Description
function initializeTranslationEditor(textarea, langCode) {
    if (!textarea || editorInstances.translations.desc[langCode]) {
        return;
    }

    CKEDITOR.ClassicEditor
        .create(textarea, ckEditorConfig)
        .then(editor => {
            editorInstances.translations.desc[langCode] = editor;
            const editorElement = editor.ui.view.editable.element;
            editorElement.style.minHeight = '300px';
            editorElement.style.maxHeight = '500px';
        })
        .catch(error => {
            console.error('Translation Description CKEditor error for ' + langCode + ':', error);
        });
}

// Initialize Translation Editor Function for Privacy Policy
function initializePrivacyEditor(textarea, langCode) {
    if (!textarea || editorInstances.translations.privacy[langCode]) {
        return;
    }

    CKEDITOR.ClassicEditor
        .create(textarea, ckEditorConfig)
        .then(editor => {
            editorInstances.translations.privacy[langCode] = editor;
            const editorElement = editor.ui.view.editable.element;
            editorElement.style.minHeight = '250px';
            editorElement.style.maxHeight = '400px';
        })
        .catch(error => {
            console.error('Translation Privacy Policy CKEditor error for ' + langCode + ':', error);
        });
}

// Initialize Translation Editor Function for Cancellation Policy
function initializeCancellationEditor(textarea, langCode) {
    if (!textarea || editorInstances.translations.cancellation[langCode]) {
        return;
    }

    CKEDITOR.ClassicEditor
        .create(textarea, ckEditorConfig)
        .then(editor => {
            editorInstances.translations.cancellation[langCode] = editor;
            const editorElement = editor.ui.view.editable.element;
            editorElement.style.minHeight = '250px';
            editorElement.style.maxHeight = '400px';
        })
        .catch(error => {
            console.error('Translation Cancellation Policy CKEditor error for ' + langCode + ':', error);
        });
}

// Update all editors before form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Update main editor
            if (editorInstances.main) {
                const mainTextarea = document.querySelector('#hotel_description');
                if (mainTextarea) {
                    mainTextarea.value = editorInstances.main.getData();
                }
            }

            // Update all translation description editors
            for (const [langCode, editor] of Object.entries(editorInstances.translations.desc)) {
                if (editor) {
                    const textarea = document.querySelector(`textarea[name='desc_translations[${langCode}]']`);
                    if (textarea) {
                        textarea.value = editor.getData();
                    }
                }
            }
        });
    }
});
</script>
<?php endif; ?>