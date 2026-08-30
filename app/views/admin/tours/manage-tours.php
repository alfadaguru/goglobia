<?php
// manage-tours.php
@$SECURE or die('Access Denied!');
?>
<style>
[draggable="true"]:active { opacity: 0.5; cursor: grabbing !important; }
[draggable="true"] { cursor: grab; }
.activity-image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.75rem; }
.activity-image-item { position: relative; aspect-ratio: 1; border-radius: 0.5rem; overflow: hidden; border: 2px solid #e5e7eb; }
.activity-image-item:hover { border-color: #3b82f6; }
.activity-image-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0); transition: background 0.2s; display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
.activity-image-item:hover .activity-image-overlay { background: rgba(0,0,0,0.4); }
.activity-image-overlay button { opacity: 0; transition: opacity 0.2s; }
.activity-image-item:hover .activity-image-overlay button { opacity: 1; }
</style>
<?php

$mode = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$isView = ($mode === 'view');
$isAdd = ($mode === 'add');

if ($isView) {
    $pageTitle = T::view . ': ' . htmlspecialchars($tour['name'] ?? '');
} elseif ($isEdit) {
    $pageTitle = T::edit . ': ' . htmlspecialchars($tour['name'] ?? '');
} else {
    $pageTitle = T::add . ' ' . (T::tour ?? 'Tour');
}

if ($isAdd) {
    $tour = [
        'id' => 0,
        'name' => '',
        'description' => '',
        'location' => '',
        'address' => '',
        'currency' => 'USD',
        'adult_price' => 0,
        'child_price' => 0,
        'infant_price' => 0,
        'discount_percentage' => 0,
        'max_adults' => 1,
        'max_children' => 0,
        'max_infants' => 0,
        'allow_adults' => 1,
        'allow_children' => 1,
        'allow_infants' => 1,
        'days' => 1,
        'nights' => 0,
        'tour_type_id' => 0,
        'stars' => 0,
        'refundable' => 1,
        'featured' => 0,
        'email' => '',
        'phone' => '',
        'website' => '',
        'meta_title' => '',
        'meta_description' => '',
        'meta_keywords' => '',
        'cancellation_policy' => '',
        'terms_conditions' => '',
        'inclusions' => '[]',
        'exclusions' => '[]',
        'itinerary' => '[]',
        'images' => '',
        'status' => 1,
    ];
    $selected_inclusions = [];
    $selected_exclusions = [];
    $tour_images = [];
    $latitude = '';
    $longitude = '';
    $itinerary_data = [];
}
?>

<?php if (!$isView): ?>
<script>
function tourFormData() {
    return {
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
        selectedInclusions: <?= json_encode($selected_inclusions ?? []) ?>,
        selectedExclusions: <?= json_encode($selected_exclusions ?? []) ?>,
        translationTabInitialized: false,
        allowAdults: <?= !empty($tour['allow_adults']) ? 'true' : 'false' ?>,
        allowChildren: <?= !empty($tour['allow_children']) ? 'true' : 'false' ?>,
        allowInfants: <?= !empty($tour['allow_infants']) ? 'true' : 'false' ?>,
        
        <?php if ($isEdit): ?>
        imagesToDelete: [],
        defaultImage: '<?= !empty($tour_images) ? (array_values(array_filter($tour_images, fn($img) => !empty($img['default'])))[0]['url'] ?? $tour_images[0]['url']) : '' ?>',
        tourImages: <?= json_encode($tour_images ?? []) ?>,
        draggedIndex: null,
        lightboxImage: '',
        showLightbox: false,
        <?php endif; ?>
        previewImages: [],
        
        locationSearch: '<?php if ($isEdit && !empty($tour['location'])): echo htmlspecialchars($tour['location']); endif; ?>',
        locationResults: [],
        showLocationDropdown: false,
        selectedLocation: <?php if ($isEdit && !empty($tour['location'])): echo json_encode(['city' => $tour['location'], 'country' => '', 'latitude' => $latitude, 'longitude' => $longitude]); else: echo 'null'; endif; ?>,
        searchingLocations: false,

        userSearch: '<?php if ($isEdit && !empty($owner)): echo htmlspecialchars(trim($owner['first_name'] ?? '') . ' ' . trim($owner['last_name'] ?? '')); endif; ?>',
        searchResults: [],
        showUserDropdown: false,
        selectedUser: <?php if ($isEdit && !empty($owner)): echo json_encode($owner); else: echo 'null'; endif; ?>,
        searchingUsers: false,
        
        itinerary: <?= json_encode($itinerary_data ?? []) ?>,
        activityImageLightbox: '',
        showActivityLightbox: false,
        
        switchTab(tab) {
            this.activeTab = tab;
            if (tab === 'translations') {
                window.location.hash = tab + ':' + this.activeTranslationTab;
                if (!this.translationTabInitialized) {
                    setTimeout(() => {
                        const descTextarea = document.getElementById('tour-trans-desc-' + this.activeTranslationTab);
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
                    const descTextarea = document.getElementById('tour-trans-desc-' + langCode);
                    if (descTextarea) {
                        initializeTranslationEditor(descTextarea, langCode);
                    }
                }
            }, 100);
        },
        
        loadTabFromHash() {
            const savedTab = sessionStorage.getItem('tour_active_tab');
            if (savedTab) {
                sessionStorage.removeItem('tour_active_tab');
                if (savedTab.includes(':')) {
                    const [mainTab, langCode] = savedTab.split(':');
                    if (mainTab === 'translations' && langCode) {
                        this.activeTab = 'translations';
                        this.activeTranslationTab = langCode;
                        window.location.hash = savedTab;
                        setTimeout(() => {
                            const descTextarea = document.getElementById('tour-trans-desc-' + langCode);
                            if (descTextarea && !editorInstances.translations.desc[langCode]) {
                                initializeTranslationEditor(descTextarea, langCode);
                            }
                            this.translationTabInitialized = true;
                        }, 200);
                        return;
                    }
                } else {
                    const validTabs = ['general', 'pricing', 'location', 'itinerary', 'inclusions', 'gallery', 'seo', 'translations'];
                    if (validTabs.includes(savedTab)) {
                        this.activeTab = savedTab;
                        window.location.hash = savedTab;
                        return;
                    }
                }
            }
            
            const hash = window.location.hash.substring(1);
            if (!hash) return;
            
            if (hash.includes(':')) {
                const [mainTab, langCode] = hash.split(':');
                if (mainTab === 'translations' && langCode) {
                    this.activeTab = 'translations';
                    this.activeTranslationTab = langCode;
                    setTimeout(() => {
                        const descTextarea = document.getElementById('tour-trans-desc-' + langCode);
                        if (descTextarea && !editorInstances.translations.desc[langCode]) {
                            initializeTranslationEditor(descTextarea, langCode);
                        }
                        this.translationTabInitialized = true;
                    }, 200);
                }
            } else {
                const validTabs = ['general', 'pricing', 'location', 'itinerary', 'inclusions', 'gallery', 'seo', 'translations'];
                if (validTabs.includes(hash)) {
                    this.activeTab = hash;
                    if (hash === 'translations') {
                        setTimeout(() => {
                            const descTextarea = document.getElementById('tour-trans-desc-' + this.activeTranslationTab);
                            if (descTextarea && !editorInstances.translations.desc[this.activeTranslationTab]) {
                                initializeTranslationEditor(descTextarea, this.activeTranslationTab);
                            }
                            this.translationTabInitialized = true;
                        }, 200);
                    }
                }
            }
        },
        
        toggleInclusion(id) {
            const index = this.selectedInclusions.indexOf(id);
            if (index > -1) {
                this.selectedInclusions.splice(index, 1);
            } else {
                this.selectedInclusions.push(id);
            }
        },
        
        toggleExclusion(id) {
            const index = this.selectedExclusions.indexOf(id);
            if (index > -1) {
                this.selectedExclusions.splice(index, 1);
            } else {
                this.selectedExclusions.push(id);
            }
        },
        
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
            const newImages = [...this.tourImages];
            const [draggedItem] = newImages.splice(this.draggedIndex, 1);
            newImages.splice(dropIndex, 0, draggedItem);
            this.tourImages = newImages;
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
                const response = await fetch('<?= root.admin ?>/tours/search-locations', {
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
                const response = await fetch('<?= root.admin ?>/tours/search-users', {
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
            this.selectedUser = { user_id: user.user_id, first_name: user.first_name, last_name: user.last_name, email: user.email };
            this.userSearch = user.first_name + ' ' + user.last_name;
            this.showUserDropdown = false;
            this.searchResults = [];
            const userIdInput = document.getElementById('user_id_input');
            if (userIdInput) {
                userIdInput.value = user.user_id;
            }
        },

        clearUserSelection() {
            this.selectedUser = null;
            this.userSearch = '';
            this.searchResults = [];
            this.showUserDropdown = false;
            const userIdInput = document.getElementById('user_id_input');
            if (userIdInput) {
                userIdInput.value = '';
            }
        },
        
        addItineraryDay() {
            this.itinerary.push({
                day: this.itinerary.length + 1,
                title: '',
                description: '',
                location: '',
                latitude: '',
                longitude: '',
                activities: []
            });
        },
        
        removeItineraryDay(index) {
            if (confirm('Are you sure you want to remove this day?')) {
                this.itinerary.splice(index, 1);
                this.itinerary.forEach((item, idx) => {
                    item.day = idx + 1;
                });
            }
        },
        
        addActivity(dayIndex) {
            if (!this.itinerary[dayIndex].activities) {
                this.itinerary[dayIndex].activities = [];
            }
            this.itinerary[dayIndex].activities.push({
                title: '',
                description: '',
                images: []
            });
        },
        
        removeActivity(dayIndex, activityIndex) {
            if (confirm('Are you sure you want to remove this activity?')) {
                this.itinerary[dayIndex].activities.splice(activityIndex, 1);
            }
        },
        
        handleActivityImages(event, dayIndex, activityIndex) {
            const files = event.target.files;
            if (!this.itinerary[dayIndex].activities[activityIndex].images) {
                this.itinerary[dayIndex].activities[activityIndex].images = [];
            }
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                if (file.size > 5 * 1024 * 1024) {
                    alert('File is too large. Maximum 5MB allowed');
                    continue;
                }
                if (!file.type.startsWith('image/')) {
                    alert('Invalid file type. Please select an image');
                    continue;
                }
                
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.itinerary[dayIndex].activities[activityIndex].images.push({
                        preview: e.target.result,
                        file: file,
                        url: '',
                        isNew: true
                    });
                };
                reader.readAsDataURL(file);
            }
            event.target.value = '';
        },
        
        removeActivityImage(dayIndex, activityIndex, imageIndex) {
            this.itinerary[dayIndex].activities[activityIndex].images.splice(imageIndex, 1);
        },
        
        openActivityLightbox(imageUrl) {
            this.activityImageLightbox = imageUrl;
            this.showActivityLightbox = true;
            document.body.style.overflow = 'hidden';
        },
        
        closeActivityLightbox() {
            this.showActivityLightbox = false;
            this.activityImageLightbox = '';
            document.body.style.overflow = '';
        },
        
        dragActivityImageStart(dayIndex, activityIndex, imageIndex) {
            this.draggedActivityImage = { dayIndex, activityIndex, imageIndex };
        },
        
        dropActivityImage(dayIndex, activityIndex, dropIndex) {
            if (!this.draggedActivityImage) return;
            if (this.draggedActivityImage.dayIndex !== dayIndex || 
                this.draggedActivityImage.activityIndex !== activityIndex ||
                this.draggedActivityImage.imageIndex === dropIndex) {
                this.draggedActivityImage = null;
                return;
            }
            
            const images = this.itinerary[dayIndex].activities[activityIndex].images;
            const [draggedItem] = images.splice(this.draggedActivityImage.imageIndex, 1);
            images.splice(dropIndex, 0, draggedItem);
            this.draggedActivityImage = null;
        },
        
        validateForm() {
            const errors = [];
            const requiredFields = [
                { name: 'tour_name', label: '<?= T::name ?? 'Tour Name' ?>', tab: 'general' },
                { name: 'currency', label: '<?= T::currency ?? 'Currency' ?>', tab: 'pricing' },
                { name: 'location', label: '<?= T::location ?? 'Location' ?>', tab: 'location', customCheck: () => !this.selectedLocation },
                { name: 'address', label: '<?= T::address ?? 'Address' ?>', tab: 'location' }
            ];

            document.querySelectorAll('.input-error, .select-error').forEach(el => {
                el.classList.remove('input-error', 'select-error');
            });
            document.querySelectorAll('.error-message').forEach(el => el.remove());

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
        
        submitForm(event) {
            event.preventDefault();
            const errors = this.validateForm();
            if (errors.length > 0) {
                this.showValidationErrors(errors);
                return false;
            }
            
            this.loading = true;
            
            if (editorInstances.main.description) {
                const mainTextarea = document.querySelector('#tour_description');
                if (mainTextarea) {
                    mainTextarea.value = editorInstances.main.description.getData();
                }
            }
            
            for (const [langCode, editor] of Object.entries(editorInstances.translations.desc)) {
                if (editor) {
                    const textarea = document.querySelector(`textarea[name='desc_translations[${langCode}]']`);
                    if (textarea) {
                        textarea.value = editor.getData();
                    }
                }
            }
            
            if (this.activeTab === 'translations' && this.activeTranslationTab) {
                sessionStorage.setItem('tour_active_tab', this.activeTab + ':' + this.activeTranslationTab);
            } else {
                sessionStorage.setItem('tour_active_tab', this.activeTab);
            }
            
            const formData = new FormData(event.target);
            
            this.itinerary.forEach((day, dayIndex) => {
                if (day.activities && day.activities.length > 0) {
                    day.activities.forEach((activity, actIndex) => {
                        if (activity.images && activity.images.length > 0) {
                            activity.images.forEach((img, imgIndex) => {
                                if (img.isNew && img.file) {
                                    const fileKey = 'activity_image_' + day.day + '_' + actIndex + '_' + imgIndex;
                                    formData.append(fileKey, img.file);
                                }
                            });
                        }
                    });
                }
            });
            
            if (this.previewImages.length > 0) {
                formData.delete('tour_images[]');
                this.previewImages.forEach((image) => {
                    if (image.file) {
                        formData.append('tour_images[]', image.file);
                    }
                });
            }
            
            formData.append('inclusions', JSON.stringify(this.selectedInclusions));
            formData.append('exclusions', JSON.stringify(this.selectedExclusions));
            formData.append('itinerary', JSON.stringify(this.itinerary));
            
            <?php if ($isEdit): ?>
                if (this.tourImages && this.tourImages.length > 0) {
                    formData.append('reordered_images', JSON.stringify(this.tourImages));
                }
                if (this.imagesToDelete && this.imagesToDelete.length > 0) {
                    formData.append('images_to_delete', JSON.stringify(this.imagesToDelete));
                }
                if (this.defaultImage) {
                    formData.append('default_image', this.defaultImage);
                }
            <?php endif; ?>

            const userIdInput = document.getElementById('user_id_input');
            if (userIdInput) {
                if (this.selectedUser && this.selectedUser.user_id) {
                    formData.set('user_id', this.selectedUser.user_id);
                } else if (this.selectedUser === null) {
                    formData.set('user_id', '');
                }
            }
            
            fetch(event.target.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                document.open();
                document.write(html);
                document.close();
            })
            .catch(error => {
                console.error('Form submission error:', error);
                alert('An error occurred while submitting the form. Please try again.');
                this.loading = false;
            });
            
            return false;
        },
        
        init() {
            this.loadTabFromHash();
            window.addEventListener('hashchange', () => {
                this.loadTabFromHash();
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

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root.admin ?>/tours" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200" x-data="tourFormData()" x-init="init()">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px overflow-x-auto">
                <button @click="switchTab('general')"
                        :class="activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">info</span>
                    <?= T::general_info ?? 'General Info' ?>
                </button>

                <button @click="switchTab('pricing')"
                        :class="activeTab === 'pricing' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">payments</span>
                    <?= T::pricing ?? 'Pricing' ?>
                </button>

                <button @click="switchTab('location')"
                        :class="activeTab === 'location' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">location_on</span>
                    <?= T::location ?? 'Location' ?>
                </button>

                <button @click="switchTab('itinerary')"
                        :class="activeTab === 'itinerary' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">calendar_month</span>
                    <?= T::itinerary ?? 'Itinerary' ?>
                </button>

                <button @click="switchTab('inclusions')"
                        :class="activeTab === 'inclusions' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">checklist</span>
                    <?= T::inclusions_exclusions ?? 'Inclusions & Exclusions' ?>
                </button>

                <button @click="switchTab('gallery')"
                        :class="activeTab === 'gallery' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">photo_library</span>
                    <?= T::gallery ?? 'Gallery' ?>
                </button>

                <button @click="switchTab('seo')"
                        :class="activeTab === 'seo' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">search</span>
                    <?= T::seo ?? 'SEO' ?>
                </button>

                <button @click="switchTab('translations')"
                        :class="activeTab === 'translations' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">translate</span>
                    <?= T::translations ?? 'Translations' ?>
                </button>
            </nav>
        </div>

        <form method="POST"
            action="<?=root.admin?>/tours/<?= $isEdit ? 'edit/' . $tour['id'] : 'add' ?>"
            @submit="submitForm($event)"
            enctype="multipart/form-data"
            class="p-6"
            x-transition>
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="active_tab" :value="activeTab">
            <input type="hidden" name="user_id" id="user_id_input" value="<?= $isEdit ? htmlspecialchars($tour['user_id'] ?? '') : '' ?>">

            <!-- Tab: General Info -->
            <div x-show="activeTab === 'general'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">tour</span>
                        <?= T::basic_information ?? 'Basic Information' ?>
                    </h3>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                        <div class="form-control">
                            <label class="text-sm block mb-1"><?= T::owner ?? 'Owner' ?></label>
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
                                    <div class="relative">
                                        <input type="text"
                                               x-model="userSearch"
                                               @input.debounce.300ms="searchUsers()"
                                               @focus="searchUsers()"
                                               placeholder="<?= 'Search by name or email...' ?>"
                                               class="input text-sm pr-10"
                                               autocomplete="off">
                                        <div x-show="searchingUsers" class="absolute right-3 top-1/2 -translate-y-1/2">
                                            <div class="animate-spin rounded-full h-4 w-4 border-2 border-gray-400 border-t-transparent"></div>
                                        </div>
                                    </div>

                                    <!-- User Search Dropdown -->
                                    <div x-show="showUserDropdown"
                                         class="absolute z-50 w-full mt-1 bg-white rounded-lg shadow-xl border border-gray-200 max-h-60 overflow-y-auto">
                                        <template x-for="user in searchResults" :key="user.user_id">
                                            <div @click="selectUser(user)"
                                                 class="px-4 py-3 hover:bg-gray-50 border-b border-gray-50 last:border-0 transition-colors cursor-pointer">
                                                <div class="font-medium text-gray-900 text-sm" x-text="user.first_name + ' ' + user.last_name"></div>
                                                <div class="text-xs text-gray-500" x-text="user.email"></div>
                                            </div>
                                        </template>
                                        <div x-show="searchResults.length === 0 && userSearch.length >= 2 && !searchingUsers"
                                             class="px-4 py-3 text-xs text-gray-500 text-center">
                                            No users found
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="user_id" id="user_id_input" value="<?= $tour['user_id'] ?? '' ?>">
                            </div>
                        </div>

                        <div class="form-control">
                            <label class="required text-sm block mb-1"><?= T::name ?? 'Tour Name' ?> *</label>
                            <input type="text" name="tour_name" class="input text-sm" value="<?= htmlspecialchars($tour['name']) ?>" placeholder="<?= T::enter_tour_name ?? 'Enter tour name' ?>">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4 items-end">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="text-sm font-medium block mb-1"><?= T::tour_type ?? 'Tour Type' ?></label>
                                <select name="tour_type_id" class="select text-sm w-full">
                                    <option value="">Select Type</option>
                                    <?php foreach ($tour_types ?? [] as $type): ?>
                                        <option value="<?= $type['id'] ?>" <?= $tour['tour_type_id'] == $type['id'] ? 'selected' : '' ?>><?= $type['setting_label'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="text-sm font-medium block mb-1"><?= T::stars ?? 'Stars' ?></label>
                                <select name="stars" class="select text-sm w-full">
                                    <option value="">Select</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?= $i ?>" <?= $tour['stars'] == $i ? 'selected' : '' ?>><?= $i ?> Star<?= $i > 1 ? 's' : '' ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <div class="form-control">
                                <label class="text-sm font-medium block mb-1"><?= T::days ?? 'Days' ?></label>
                                <input type="number" name="days" class="input text-sm w-full" min="1" value="<?= $tour['days'] ?? 1 ?>" placeholder="1">
                            </div>

                            <div class="form-control">
                                <label class="text-sm font-medium block mb-1"><?= T::nights ?? 'Nights' ?></label>
                                <input type="number" name="nights" class="input text-sm w-full" min="0" value="<?= $tour['nights'] ?? 0 ?>" placeholder="0">
                            </div>

                            <div class="form-control">
                                <label class="text-sm font-medium block mb-1"><?= T::discount ?? 'Discount' ?> (%)</label>
                                <input type="number" name="discount_percentage" class="input text-sm w-full" min="0" max="100" step="0.01" value="<?= $tour['discount_percentage'] ?? 0 ?>" placeholder="0">
                            </div>
                        </div>
                    </div>

                    <div class="form-control mt-4">
                        <label class="text-sm"><?= T::description ?? 'Description' ?></label>
                        <textarea id="tour_description" name="description" class="ckeditor-tour-desc"><?= htmlspecialchars($tour['description'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">settings</span>
                        <?= T::tour_settings ?? 'Tour Settings' ?>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox" name="status" value="1" id="status" class="checkbox-input" <?= $tour['status'] ? 'checked' : '' ?>>
                                        <div class="checkbox-custom">
                                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                        </div>
                                    </div>
                                    <label for="status" class="cursor-pointer text-sm font-medium"><?= T::active ?? 'Active' ?></label>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox" name="featured" value="1" id="featured" class="checkbox-input" <?= $tour['featured'] ? 'checked' : '' ?>>
                                        <div class="checkbox-custom">
                                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                        </div>
                                    </div>
                                    <label for="featured" class="cursor-pointer text-sm font-medium"><?= T::featured ?? 'Featured' ?></label>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox" name="refundable" value="1" id="refundable" class="checkbox-input" <?= $tour['refundable'] ? 'checked' : '' ?>>
                                        <div class="checkbox-custom">
                                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                        </div>
                                    </div>
                                    <label for="refundable" class="cursor-pointer text-sm font-medium"><?= T::refundable ?? 'Refundable' ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">contact_phone</span>
                        <?= T::contact_information ?? 'Contact Information' ?>
                    </h3>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div class="form-control">
                            <label class="text-sm"><?= T::email ?? 'Email' ?></label>
                            <input type="email" name="email" class="input text-sm" value="<?= htmlspecialchars($tour['email'] ?? '') ?>" placeholder="contact@example.com">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::phone ?? 'Phone' ?></label>
                            <input type="text" name="phone" class="input text-sm" value="<?= htmlspecialchars($tour['phone'] ?? '') ?>" placeholder="+1 (555) 123-4567">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::website ?? 'Website' ?></label>
                            <input type="url" name="website" class="input text-sm" value="<?= htmlspecialchars($tour['website'] ?? '') ?>" placeholder="https://example.com">
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">policy</span>
                        <?= T::policies ?? 'Policies' ?>
                    </h3>

                    <div class="grid grid-cols-1 gap-4">
                        <div class="form-control">
                            <label class="text-sm"><?= T::cancellation_policy ?? 'Cancellation Policy' ?></label>
                            <textarea name="cancellation_policy" class="textarea text-sm" rows="4" placeholder="Enter cancellation policy"><?= htmlspecialchars($tour['cancellation_policy'] ?? '') ?></textarea>
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::terms_conditions ?? 'Terms & Conditions' ?></label>
                            <textarea name="terms_conditions" class="textarea text-sm" rows="4" placeholder="Enter terms and conditions"><?= htmlspecialchars($tour['terms_conditions'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Pricing (CONDITIONAL BASED ON ALLOWED PERSONS) -->
            <div x-show="activeTab === 'pricing'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">payments</span>
                        <?= T::pricing_information ?? 'Pricing Information' ?>
                    </h3>

                    <div class="grid grid-cols-1 gap-4 mb-4">
                        <div class="form-control">
                            <label class="required text-sm"><?= T::currency ?? 'Currency' ?> *</label>
                            <select name="currency" class="select text-sm">
                                <option value="">Select Currency</option>
                                <?php foreach ($currencies ?? [] as $curr): ?>
                                    <option value="<?= $curr['name'] ?>" <?= $tour['currency'] == $curr['name'] ? 'selected' : '' ?>><?= $curr['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Conditional Pricing Fields -->
                    <template>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center gap-2 text-yellow-700">
                                <span class="material-symbols-outlined">warning</span>
                                <span class="text-sm font-medium">Please enable at least one person type in the General tab (Adults, Children, or Infants) to set pricing.</span>
                            </div>
                        </div>
                    </template>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                        <div class="form-control">
                            <label class="text-sm"><?= T::adult_price ?? 'Adult Price' ?></label>
                            <input type="number" name="adult_price" class="input text-sm" min="0" step="0.01" value="<?= $tour['adult_price'] ?? 0 ?>" placeholder="0.00">
                        </div>

                        <div class="form-control"n>
                            <label class="text-sm"><?= T::child_price ?? 'Child Price' ?></label>
                            <input type="number" name="child_price" class="input text-sm" min="0" step="0.01" value="<?= $tour['child_price'] ?? 0 ?>" placeholder="0.00">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::infant_price ?? 'Infant Price' ?></label>
                            <input type="number" name="infant_price" class="input text-sm" min="0" step="0.01" value="<?= $tour['infant_price'] ?? 0 ?>" placeholder="0.00">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div class="form-control">
                            <label class="text-sm"><?= T::max_adults ?? 'Max Adults' ?></label>
                            <input type="number" name="max_adults" class="input text-sm" min="1" value="<?= $tour['max_adults'] ?? 1 ?>" placeholder="1">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::max_children ?? 'Max Children' ?></label>
                            <input type="number" name="max_children" class="input text-sm" min="0" value="<?= $tour['max_children'] ?? 0 ?>" placeholder="0">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::max_infants ?? 'Max Infants' ?></label>
                            <input type="number" name="max_infants" class="input text-sm" min="0" value="<?= $tour['max_infants'] ?? 0 ?>" placeholder="0">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Location -->
            <div x-show="activeTab === 'location'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">location_on</span>
                        <?= T::location_information ?? 'Location Information' ?>
                    </h3>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div>
                            <label class="required text-sm block mb-1"><?= T::location ?? 'Location' ?> *</label>
                            <div class="relative" @click.away="showLocationDropdown = false">
                                <div x-show="selectedLocation" class="input text-sm flex items-center gap-2 bg-green-50 border-green-200">
                                    <div class="flex-1 overflow-hidden">
                                        <span class="font-medium text-gray-900 truncate" x-text="selectedLocation ? selectedLocation.city : ''"></span>
                                    </div>
                                    <button type="button" @click.stop="clearLocationSelection()" class="flex-shrink-0 hover:bg-green-100 rounded p-1">
                                        <span class="material-symbols-outlined text-gray-500 text-base">close</span>
                                    </button>
                                </div>

                                <div x-show="!selectedLocation">
                                    <input type="text"
                                           x-model="locationSearch"
                                           @input.debounce.300ms="searchLocations()"
                                           @focus="searchLocations()"
                                           class="input text-sm"
                                           placeholder="Search city or country"
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
                                            No locations found
                                        </div>
                                    </div>
                                </div>

                                <input type="hidden" name="location" id="location_input" value="<?= htmlspecialchars($tour['location'] ?? '') ?>">
                            </div>
                        </div>

                        <div>
                            <label class="text-sm block mb-1"><?= T::address ?? 'Address' ?></label>
                            <input type="text" name="address" class="input text-sm" value="<?= htmlspecialchars($tour['address'] ?? '') ?>" placeholder="Full address">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="text-sm block mb-1"><?= T::latitude ?? 'Latitude' ?></label>
                            <input type="text" name="latitude" id="latitude_input" class="input text-sm" value="<?= htmlspecialchars($latitude) ?>" placeholder="e.g. 40.7128" readonly>
                            <p class="text-xs text-gray-500 mt-1">Auto-filled from location</p>
                        </div>

                        <div>
                            <label class="text-sm block mb-1"><?= T::longitude ?? 'Longitude' ?></label>
                            <input type="text" name="longitude" id="longitude_input" class="input text-sm" value="<?= htmlspecialchars($longitude) ?>" placeholder="e.g. -74.0060" readonly>
                            <p class="text-xs text-gray-500 mt-1">Auto-filled from location</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Itinerary (WITH GALLERY-STYLE ACTIVITY IMAGES) -->
            <div x-show="activeTab === 'itinerary'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="mb-4 pb-2 border-b border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-blue-600 text-lg">calendar_month</span>
                            <?= T::tour_itinerary ?? 'Tour Itinerary' ?>
                        </h3>
                    </div>

                    <template x-if="itinerary.length === 0">
                        <div class="text-center py-8">
                            <span class="material-symbols-outlined text-6xl text-gray-300">event_note</span>
                            <p class="text-gray-500 mt-2 mb-4">No itinerary days added yet</p>
                            <button type="button" @click="addItineraryDay()" class="btn text-sm">
                                <span class="material-symbols-outlined text-lg">add</span>
                                Add Day
                            </button>
                        </div>
                    </template>

                    <div class="space-y-6">
                        <template x-for="(day, dayIndex) in itinerary" :key="dayIndex">
                            <div class="bg-white rounded-lg p-6 border-2 border-gray-200 shadow-sm">
                                <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
                                    <div class="flex items-center gap-3">
                                        <div class="bg-blue-100 text-blue-600 rounded-full w-10 h-10 flex items-center justify-center font-bold text-lg">
                                            <span x-text="day.day"></span>
                                        </div>
                                        <h4 class="font-semibold text-gray-900 text-lg">Day <span x-text="day.day"></span></h4>
                                    </div>
                                    <button type="button" @click="removeItineraryDay(dayIndex)" class="text-red-500 hover:text-red-700 hover:bg-red-50 rounded-lg p-2 transition-colors">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </div>

                                <div class="space-y-4 mb-5">
                                    <div class="form-control">
                                        <label class="text-sm block mb-1">Day Title</label>
                                        <input type="text" x-model="day.title" class="input text-sm" placeholder="e.g., Arrival & City Tour">
                                    </div>

                                    <div class="form-control">
                                        <label class="text-sm block mb-1">Day Description</label>
                                        <textarea x-model="day.description" class="textarea text-sm" rows="3" placeholder="Brief overview of the day..."></textarea>
                                    </div>

                                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-xs block mb-1">Day Location</label>
                                            <div class="relative" 
                                                 x-data="{ showDropdown: false, searching: false, results: [] }"
                                                 @click.away="showDropdown = false">
                                                <input type="text" 
                                                       x-model="day.location" 
                                                       @input.debounce.300ms="
                                                           if (day.location.length >= 2) {
                                                               searching = true;
                                                               fetch('<?= root.admin ?>/tours/search-locations', {
                                                                   method: 'POST',
                                                                   body: (() => {
                                                                       const fd = new FormData();
                                                                       fd.append('search', day.location);
                                                                       return fd;
                                                                   })()
                                                               })
                                                               .then(r => r.json())
                                                               .then(data => {
                                                                   results = data.success ? data.locations : [];
                                                                   showDropdown = results.length > 0;
                                                                   searching = false;
                                                               })
                                                               .catch(() => { searching = false; });
                                                           } else {
                                                               results = [];
                                                               showDropdown = false;
                                                           }
                                                       "
                                                       @focus="if (results.length > 0) showDropdown = true"
                                                       class="input text-xs" 
                                                       placeholder="Search location...">
                                                
                                                <div x-show="searching" class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                                    <svg class="animate-spin h-3 w-3 text-gray-400" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                </div>

                                                <div x-show="showDropdown"
                                                     class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                    <template x-for="location in results" :key="location.id">
                                                        <div @click="
                                                                day.location = location.city + ', ' + location.country;
                                                                day.latitude = location.latitude || '';
                                                                day.longitude = location.longitude || '';
                                                                showDropdown = false;
                                                             "
                                                             class="px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                            <div class="font-medium text-gray-900 text-xs" x-text="location.city + ', ' + location.country"></div>
                                                            <div class="text-[10px] text-gray-500" x-text="location.country_code"></div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs block mb-1">Latitude</label>
                                            <input type="text" x-model="day.latitude" class="input text-xs bg-gray-50" placeholder="Auto-filled" readonly>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs block mb-1">Longitude</label>
                                            <input type="text" x-model="day.longitude" class="input text-xs bg-gray-50" placeholder="Auto-filled" readonly>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                    <div class="mb-4">
                                        <h5 class="font-semibold text-gray-900 flex items-center gap-2">
                                            <span class="material-symbols-outlined text-green-600">local_activity</span>
                                            Activities
                                            <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full" x-text="(day.activities || []).length"></span>
                                        </h5>
                                    </div>

                                    <template x-if="!day.activities || day.activities.length === 0">
                                        <div class="text-center py-6">
                                            <span class="material-symbols-outlined text-4xl text-gray-300">explore</span>
                                            <p class="text-gray-500 text-sm mt-2">No activities added yet</p>
                                        </div>
                                    </template>

                                    <div class="space-y-4">
                                        <template x-for="(activity, actIndex) in day.activities" :key="actIndex">
                                            <div class="bg-white rounded-lg p-4 border border-gray-300">
                                                <div class="flex items-center justify-between mb-3">
                                                    <span class="text-sm font-semibold text-gray-700">Activity <span x-text="actIndex + 1"></span></span>
                                                    <button type="button" @click="removeActivity(dayIndex, actIndex)" class="text-red-500 hover:text-red-700">
                                                        <span class="material-symbols-outlined text-lg">close</span>
                                                    </button>
                                                </div>

                                                <div class="space-y-3">
                                                    <div class="form-control">
                                                        <label class="text-xs block mb-1">Activity Title</label>
                                                        <input type="text" x-model="activity.title" class="input text-xs" placeholder="e.g., Museum Visit">
                                                    </div>

                                                    <div class="form-control">
                                                        <label class="text-xs block mb-1">Description</label>
                                                        <textarea x-model="activity.description" class="textarea text-xs" rows="2" placeholder="Activity details..."></textarea>
                                                    </div>

                                                    <!-- GALLERY-STYLE ACTIVITY IMAGES -->
                                                    <div class="form-control">
                                                        <label class="text-xs block mb-2">Activity Images</label>
                                                        
                                                        <!-- Existing Images Grid -->
                                                        <template x-if="activity.images && activity.images.length > 0">
                                                            <div class="activity-image-grid mb-3">
                                                                <template x-for="(img, imgIndex) in activity.images" :key="imgIndex">
                                                                    <div class="activity-image-item"
                                                                         draggable="true"
                                                                         @dragstart="dragActivityImageStart(dayIndex, actIndex, imgIndex)"
                                                                         @dragover.prevent
                                                                         @drop.prevent="dropActivityImage(dayIndex, actIndex, imgIndex)">
                                                                        <img :src="img.preview || ('<?= root ?>' + img.url)" 
                                                                             class="w-full h-full object-cover"
                                                                             alt="Activity Image">
                                                                        <div class="activity-image-overlay">
                                                                            <button type="button"
                                                                                    @click="openActivityLightbox(img.preview || ('<?= root ?>' + img.url))"
                                                                                    class="bg-blue-500 hover:bg-blue-600 text-white w-8 h-8 rounded-full flex items-center justify-center">
                                                                                <span class="material-symbols-outlined text-sm">visibility</span>
                                                                            </button>
                                                                            <button type="button"
                                                                                    @click="removeActivityImage(dayIndex, actIndex, imgIndex)"
                                                                                    class="bg-red-500 hover:bg-red-600 text-white w-8 h-8 rounded-full flex items-center justify-center">
                                                                                <span class="material-symbols-outlined text-sm">delete</span>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                        
                                                        <!-- Upload Button -->
                                                        <div class="flex items-center gap-2">
                                                            <input type="file" 
                                                                   :id="'activity_images_' + day.day + '_' + actIndex"
                                                                   @change="handleActivityImages($event, dayIndex, actIndex)"
                                                                   accept="image/*"
                                                                   multiple
                                                                   class="hidden">
                                                            <label :for="'activity_images_' + day.day + '_' + actIndex" 
                                                                   class="btn white text-xs flex-1 cursor-pointer flex items-center justify-center gap-1">
                                                                <span class="material-symbols-outlined text-base">add_photo_alternate</span>
                                                                <span x-text="activity.images && activity.images.length > 0 ? 'Add More Images' : 'Upload Images'"></span>
                                                            </label>
                                                        </div>
                                                        <p class="text-[10px] text-gray-500 mt-1">Max 5MB per image. You can upload multiple images and drag to reorder.</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="mt-4 flex justify-end">
                                        <button type="button" @click="addActivity(dayIndex)" class="btn white text-sm">
                                            <span class="material-symbols-outlined text-base">add</span>
                                            Add Activity
                                        </button>
                                    </div>
                                </div>

                                <div x-show="dayIndex === itinerary.length - 1" class="mt-4 flex justify-end">
                                    <button type="button" @click="addItineraryDay()" class="btn text-sm">
                                        <span class="material-symbols-outlined text-lg">add</span>
                                        Add Day
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <input type="hidden" name="itinerary" :value="JSON.stringify(itinerary)">
                </div>
            </div>

            <!-- Tab: Inclusions -->
            <div x-show="activeTab === 'inclusions'" x-transition class="space-y-4">
                <?php if (!empty($inclusions)): ?>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">check_circle</span>
                        <?= T::inclusions ?? 'Inclusions' ?> (<?= count($inclusions) ?>)
                    </h3>

                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        <?php foreach ($inclusions as $inclusion): ?>
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox"
                                            @click="toggleInclusion(<?= $inclusion['id'] ?>)"
                                            <?= in_array($inclusion['id'], $selected_inclusions) ? 'checked' : '' ?>
                                            :checked="selectedInclusions.includes(<?= $inclusion['id'] ?>)"
                                            id="inclusion_<?= $inclusion['id'] ?>"
                                            class="checkbox-input">
                                        <div class="checkbox-custom">
                                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                        </div>
                                    </div>
                                    <label for="inclusion_<?= $inclusion['id'] ?>" class="cursor-pointer text-sm"><?= htmlspecialchars($inclusion['setting_label']) ?></label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" name="inclusions" :value="JSON.stringify(selectedInclusions)">
                </div>
                <?php endif; ?>

                <?php if (!empty($exclusions)): ?>
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">cancel</span>
                        <?= T::exclusions ?? 'Exclusions' ?> (<?= count($exclusions) ?>)
                    </h3>

                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        <?php foreach ($exclusions as $exclusion): ?>
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox"
                                            @click="toggleExclusion(<?= $exclusion['id'] ?>)"
                                            <?= in_array($exclusion['id'], $selected_exclusions) ? 'checked' : '' ?>
                                            :checked="selectedExclusions.includes(<?= $exclusion['id'] ?>)"
                                            id="exclusion_<?= $exclusion['id'] ?>"
                                            class="checkbox-input">
                                        <div class="checkbox-custom">
                                            <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                        </div>
                                    </div>
                                    <label for="exclusion_<?= $exclusion['id'] ?>" class="cursor-pointer text-sm"><?= htmlspecialchars($exclusion['setting_label']) ?></label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" name="exclusions" :value="JSON.stringify(selectedExclusions)">
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Gallery -->
            <div x-show="activeTab === 'gallery'" x-transition class="space-y-6">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined">photo_library</span>
                        <?= T::tour_gallery ?? 'Tour Gallery' ?>
                    </h3>

                    <?php if ($isEdit): ?>
                    <div class="mb-6" x-show="tourImages.length > 0">
                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">image</span>
                            Existing Images (<span x-text="tourImages.length"></span>)
                            <span class="text-xs text-gray-500 ml-2">Drag to reorder</span>
                        </h4>

                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                            <template x-for="(img, index) in tourImages" :key="index">
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
                                        :alt="'Tour Image ' + (index + 1)"
                                        class="w-full h-full object-cover pointer-events-none"
                                        draggable="false">

                                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2"
                                         @mousedown.stop @click.stop>
                                        <button type="button"
                                                @click="openLightbox(img.url)"
                                                class="opacity-0 group-hover:opacity-100 transition-opacity bg-blue-500 hover:bg-blue-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                            <span class="material-symbols-outlined text-xl">visibility</span>
                                        </button>

                                        <button type="button"
                                                @click="setDefaultImage(img.url)"
                                                x-show="!imagesToDelete.includes(img.url) && img.url !== defaultImage"
                                                class="opacity-0 group-hover:opacity-100 transition-opacity bg-green-500 hover:bg-green-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
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
                                        <span>Default</span>
                                    </div>

                                    <div x-show="imagesToDelete.includes(img.url)"
                                        class="absolute inset-0 bg-red-500 bg-opacity-80 flex items-center justify-center">
                                        <div class="text-white text-center">
                                            <span class="material-symbols-outlined text-4xl mb-2">delete</span>
                                            <p class="text-sm font-semibold">Marked for Deletion</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            </template>
                        </div>

                        <input type="hidden" name="reordered_images" :value="JSON.stringify(tourImages)">
                    </div>
                    <?php endif; ?>

                    <div x-show="previewImages.length > 0" class="mt-6 mb-4" x-transition>
                        <div class="p-4 bg-green-50 rounded-lg border border-green-200 mb-4">
                            <div class="flex items-center gap-2 text-green-700">
                                <span class="material-symbols-outlined">check_circle</span>
                                <span class="font-medium">
                                    <span x-text="previewImages.length === 1 ? '1 <?= T::new_image ?> <?= T::ready_to_upload ?>' : previewImages.length + ' <?= T::new_images ?> <?= T::ready_to_upload ?>'"></span>
                                </span>
                            </div>
                        </div>

                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">new_releases</span>
                            Image Previews
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
                                            <span>New</span>
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

                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8">
                        <div class="text-center">
                            <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                <span class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                            </div>

                            <h4 class="text-lg font-semibold text-gray-900 mb-2"><?= $isEdit ? 'Upload More Images' : 'Upload Images' ?></h4>
                            <p class="text-sm text-gray-600 mb-6">Drag and drop images here, or click to select files</p>

                            <label for="tour_images_input" class="btn inline-flex items-center gap-2 cursor-pointer">
                                <span class="material-symbols-outlined">upload</span>
                                <span>Choose Images</span>
                            </label>

                            <input type="file"
                                id="tour_images_input"
                                name="tour_images[]"
                                multiple
                                accept="image/*"
                                class="hidden"
                                @change="handleImageUpload($event)">

                            <p class="text-xs text-gray-500 mt-4">
                                Supported formats: JPG, PNG, WEBP • Max size: 5MB per image
                            </p>
                        </div>
                    </div>

                    <?php if ($isEdit): ?>
                    <input type="hidden" name="images_to_delete" :value="JSON.stringify(imagesToDelete)">
                    <input type="hidden" name="default_image" :value="defaultImage">
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab: SEO -->
            <div x-show="activeTab === 'seo'" x-transition class="space-y-6">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined">search</span>
                        <?= T::seo_information ?? 'SEO Information' ?>
                    </h3>

                    <div class="space-y-4">
                        <div class="form-control">
                            <label class="text-sm"><?= T::meta_title ?? 'Meta Title' ?></label>
                            <input type="text" name="meta_title" class="input text-sm" value="<?= htmlspecialchars($tour['meta_title'] ?? '') ?>" placeholder="Enter SEO meta title">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::meta_description ?? 'Meta Description' ?></label>
                            <textarea name="meta_description" class="textarea text-sm" rows="3" placeholder="Enter meta description"><?= htmlspecialchars($tour['meta_description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::meta_keywords ?? 'Meta Keywords' ?></label>
                            <textarea name="meta_keywords" class="textarea text-sm" rows="3" placeholder="Enter keywords separated by commas"><?= htmlspecialchars($tour['meta_keywords'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Translations -->
            <div x-show="activeTab === 'translations'" x-transition class="space-y-6">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined">translate</span>
                        <?= T::translations ?? 'Translations' ?>
                    </h3>

                    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                        <div class="border-b border-gray-200">
                            <nav class="flex -mb-px overflow-x-auto">
                                <?php
                                foreach ($GLOBALS['languages'] ?? [] as $lang_data):
                                    if ($lang_data['lang_code'] === 'en') continue;
                                    $lang_name = $lang_data['name'] ?? $lang_data['lang_code'];
                                    $lang_code = $lang_data['lang_code'];
                                    $has_name = !empty($name_translations[$lang_code]);
                                    $has_desc = !empty($desc_translations[$lang_code]);
                                    $has_address = !empty($address_translations[$lang_code]);
                                    $has_policy = !empty($cancellation_policy_translations[$lang_code]);
                                    $has_terms = !empty($terms_conditions_translations[$lang_code]);
                                    $has_any = $has_name || $has_desc || $has_address || $has_policy || $has_terms;
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

                        <div class="p-6">
                            <?php
                            foreach ($GLOBALS['languages'] ?? [] as $lang_data):
                                if ($lang_data['lang_code'] === 'en') continue;
                                $lang_name = $lang_data['name'] ?? $lang_data['lang_code'];
                                $lang_code = $lang_data['lang_code'];
                                $name_value = $name_translations[$lang_code] ?? '';
                                $desc_value = $desc_translations[$lang_code] ?? '';
                                $address_value = $address_translations[$lang_code] ?? '';
                                $cancellation_policy_value = $cancellation_policy_translations[$lang_code] ?? '';
                                $terms_conditions_value = $terms_conditions_translations[$lang_code] ?? '';
                            ?>
                                <div x-show="activeTranslationTab === '<?= $lang_code ?>'" x-transition class="space-y-6">
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                            <span class="material-symbols-outlined text-blue-600 text-lg">translate</span>
                                            Basic Information
                                        </h4>

                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                                            <div class="form-control">
                                                <label class="text-sm font-medium"><?= T::name ?? 'Tour Name' ?></label>
                                                <input type="text"
                                                    name="name_translations[<?= $lang_code ?>]"
                                                    class="input text-sm"
                                                    value="<?= htmlspecialchars($name_value) ?>"
                                                    placeholder="Enter translation...">
                                            </div>

                                            <div class="form-control">
                                                <label class="text-sm font-medium"><?= T::address ?? 'Address' ?></label>
                                                <input type="text"
                                                    name="address_translations[<?= $lang_code ?>]"
                                                    class="input text-sm"
                                                    value="<?= htmlspecialchars($address_value) ?>"
                                                    placeholder="Enter translation...">
                                            </div>
                                        </div>

                                        <div class="form-control">
                                            <label class="text-sm font-medium"><?= T::description ?? 'Tour Description' ?></label>
                                            <textarea
                                                id="tour-trans-desc-<?= $lang_code ?>"
                                                name="desc_translations[<?= $lang_code ?>]"
                                                class="tour-trans-desc"
                                                data-lang="<?= $lang_code ?>"><?= htmlspecialchars($desc_value) ?></textarea>
                                        </div>
                                    </div>

                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                            <span class="material-symbols-outlined text-blue-600 text-lg">policy</span>
                                            Policies & Terms
                                        </h4>

                                        <div class="grid grid-cols-1 gap-4">
                                            <div class="form-control">
                                                <label class="text-sm font-medium"><?= T::cancellation_policy ?? 'Cancellation Policy' ?></label>
                                                <textarea
                                                    name="cancellation_policy_translations[<?= $lang_code ?>]"
                                                    class="textarea text-sm"
                                                    rows="4"
                                                    placeholder="Enter translation..."><?= htmlspecialchars($cancellation_policy_value) ?></textarea>
                                            </div>

                                            <div class="form-control">
                                                <label class="text-sm font-medium"><?= T::terms_conditions ?? 'Terms & Conditions' ?></label>
                                                <textarea
                                                    name="terms_conditions_translations[<?= $lang_code ?>]"
                                                    class="textarea text-sm"
                                                    rows="4"
                                                    placeholder="Enter translation..."><?= htmlspecialchars($terms_conditions_value) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                <a href="<?= root ?>admin/tours" class="btn white text-sm"><?= T::cancel ?? 'Cancel' ?></a>
                <button type="submit" class="btn text-sm" :disabled="loading">
                    <span :class="loading ? 'hidden' : 'flex'" class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">save</span>
                        <span><?= $isEdit ? (T::update ?? 'Update') : (T::submit ?? 'Submit') ?></span>
                    </span>
                    <span :class="loading ? 'flex' : 'hidden'" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span><?= T::processing ?? 'Processing' ?>...</span>
                    </span>
                </button>
            </div>
        </form>

        <!-- Tour Gallery Lightbox -->
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
        
        <!-- Activity Image Lightbox -->
        <div x-show="showActivityLightbox"
             @click="closeActivityLightbox()"
             @keydown.escape.window="closeActivityLightbox()"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-90 p-4"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             style="display: none;">
            <button @click="closeActivityLightbox()" class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors">
                <span class="material-symbols-outlined text-4xl">close</span>
            </button>
            <img :src="activityImageLightbox"
                 @click.stop
                 class="max-w-full max-h-full object-contain rounded-lg shadow-2xl"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-90"
                 x-transition:enter-end="opacity-100 scale-100">
        </div>
    </div>
</div>

<?php if (!$isView): ?>
<script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/super-build/ckeditor.js"></script>
<script>
const editorInstances = {
    main: {
        description: null
    },
    translations: {
        desc: {}
    }
};

const ckEditorConfig = {
    removePlugins: ['RealTimeCollaborativeEditing','RealTimeCollaborativeComments','RealTimeCollaborativeTrackChanges','RealTimeCollaborativeRevisionHistory','PresenceList','Comments','TrackChanges','TrackChangesData','RevisionHistory','Pagination','WProofreader','MathType','SlashCommand','Template','DocumentOutline','FormatPainter','TableOfContents','PasteFromOfficeEnhanced','CaseChange','ExportPdf','ExportWord','ImportWord','MultiLevelList','MentionCustomization','AIAssistant','OpenAITextAdapter'],
    toolbar: {
        items: ['undo','redo','|','heading','|','fontSize','fontFamily','fontColor','fontBackgroundColor','|','bold','italic','underline','strikethrough','|','link','uploadImage','insertTable','blockQuote','mediaEmbed','|','alignment','|','bulletedList','numberedList','|','outdent','indent','|','removeFormat'],
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
    fontSize: { options: [10,12,14,16,18,20,22,24,26,28,36] },
    fontFamily: { options: ['default','Arial, Helvetica, sans-serif','Georgia, serif','Times New Roman, Times, serif','Verdana, Geneva, sans-serif'] },
    image: {
        resizeUnit: 'px',
        toolbar: ['imageTextAlternative','|','imageStyle:inline','imageStyle:wrapText','imageStyle:breakText','|','toggleImageCaption','linkImage']
    },
    table: { contentToolbar: ['tableColumn','tableRow','mergeTableCells','tableProperties','tableCellProperties'] },
    link: {
        decorators: {
            openInNewTab: { mode: 'manual', label: 'Open in a new tab', defaultValue: true, attributes: { target: '_blank', rel: 'noopener noreferrer' } }
        }
    },
    simpleUpload: {
        uploadUrl: '<?= root ?>admin/cms/upload-image',
        withCredentials: true,
        headers: { 'X-CSRF-TOKEN': document.querySelector('input[name="csrf_token"]').value }
    }
};

document.addEventListener('DOMContentLoaded', function() {
    CKEDITOR.ClassicEditor
        .create(document.querySelector('#tour_description'), ckEditorConfig)
        .then(editor => {
            editorInstances.main.description = editor;
            const editorElement = editor.ui.view.editable.element;
            editorElement.style.minHeight = '400px';
            editorElement.style.maxHeight = '600px';
        })
        .catch(error => { console.error('Main CKEditor error:', error); });
});

function initializeTranslationEditor(textarea, langCode) {
    if (!textarea) return;
    if (editorInstances.translations.desc[langCode]) return;
    
    CKEDITOR.ClassicEditor
        .create(textarea, ckEditorConfig)
        .then(editor => {
            editorInstances.translations.desc[langCode] = editor;
            const editorElement = editor.ui.view.editable.element;
            editorElement.style.minHeight = '300px';
            editorElement.style.maxHeight = '500px';
        })
        .catch(error => { 
            console.error('Translation desc CKEditor error for ' + langCode + ':', error); 
        });
}
</script>
<?php endif; ?>