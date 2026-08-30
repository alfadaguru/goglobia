<?php
// app/views/admin/visa/manage-visa.php
@$SECURE or die('Access Denied!');

$isEdit = ($mode === 'edit');
$isAdd = ($mode === 'add');

if ($isAdd) {
    $visa = [
        'id' => 0,
        'from_country_id' => '',
        'to_country_id' => '',
        'description' => '',
        'requirements' => '[]',
        'currency' => 'USD',
        'status' => 1,
        'img' => '',
        'prices' => '[]'
    ];
    $visa_images = [];
    $default_image = '';
} else {
    // Parse images for edit mode
    $visa_images = [];
    $default_image = '';

    if (!empty($visa['img'])) {
        $images_data = json_decode($visa['img'], true);
        if (is_array($images_data)) {
            foreach ($images_data as $img) {
                $visa_images[] = [
                    'url' => $img['url'] ?? $img,
                    'default' => ($img['default'] ?? false) === true
                ];
                if (!empty($img['default']) && $img['default'] === true) {
                    $default_image = $img['url'] ?? $img;
                }
            }
        }
    }

    // Set first image as default if no default is set
    if (empty($default_image) && !empty($visa_images)) {
        $default_image = $visa_images[0]['url'];
    }
}

$pageTitle = $isEdit ? (T::edit_visa ?? 'Edit Visa') : (T::add_visa ?? 'Add Visa');
?>

<style>
    [draggable="true"]:active {
        opacity: 0.5;
        cursor: grabbing !important;
    }

    [draggable="true"] {
        cursor: grab;
    }
</style>

<script>
    function visaFormData() {
        return {
            // Tab State
            activeTab: 'general',
            isEdit: <?= $isEdit ? 'true' : 'false' ?>,
            loading: false,

            // Pricing Variants Management
            prices: <?= !empty($visa['prices']) ? $visa['prices'] : '[]' ?>,

            // Requirements Management
            requirements: <?= !empty($visa['requirements']) ? $visa['requirements'] : '[]' ?>,

            // Processing Speeds Definition (Dynamic from PHP)
            processingSpeeds: <?= json_encode($processingSpeeds ?? []) ?>,
            visaTypes: <?= json_encode($visaTypes ?? []) ?>,
            entryTypes: <?= json_encode($entryTypes ?? []) ?>,
            durations: [
                { value: 15, name: '15 Days' },
                { value: 30, name: '30 Days' },
                { value: 45, name: '45 Days' },
                { value: 60, name: '60 Days' },
                { value: 90, name: '90 Days' },
                { value: 180, name: '180 Days' },
                { value: 365, name: '365 Days' }
            ],

            // Image Management
            <?php if ($isEdit): ?>
                            imagesToDelete: [],
                defaultImage: '<?= $default_image ?>',
                visaImages: <?= json_encode($visa_images) ?>,
                draggedIndex: null,
                lightboxImage: '',
                showLightbox: false,
            <?php endif; ?>
        previewImages: [],

            // ========== TAB METHODS ==========
            switchTab(tab) {
                this.activeTab = tab;
                window.location.hash = tab;
            },

            loadTabFromHash() {
                // First check sessionStorage (for post-form-submission restoration)
                const savedTab = sessionStorage.getItem('visa_active_tab');
                if (savedTab) {
                    sessionStorage.removeItem('visa_active_tab');

                    const validTabs = ['general', 'pricing', 'requirements', 'gallery'];
                    if (validTabs.includes(savedTab)) {
                        this.activeTab = savedTab;
                        window.location.hash = savedTab;
                        return;
                    }
                }

                // Then check URL hash
                const hash = window.location.hash.substring(1);
                if (!hash) return;

                const validTabs = ['general', 'pricing', 'requirements', 'gallery'];
                if (validTabs.includes(hash)) {
                    this.activeTab = hash;
                }
            },

            // ========== REQUIREMENTS METHODS ==========
            addRequirement() {
                this.requirements.push('');
            },

            removeRequirement(index) {
                this.requirements.splice(index, 1);
            },

            // ========== PRICING VARIANTS METHODS ==========
            addPriceVariant() {
                this.prices.push({
                    visa_type: '',
                    entry_type: '',
                    duration_days: '',
                    processing_speed: 'standard',
                    govt_fee: '',
                    service_fee: '',
                    total_price: '',
                    currency: 'USD'
                });
            },

            removePriceVariant(index) {
                this.prices.splice(index, 1);
            },

            calculateVariantTotal(index) {
                const variant = this.prices[index];
                const govtFee = parseFloat(variant.govt_fee) || 0;
                const serviceFee = parseFloat(variant.service_fee) || 0;

                this.prices[index].total_price = (govtFee + serviceFee).toFixed(2);
            },

            // Ensure at least one variant exists
            init() {
                this.loadTabFromHash();
                if (this.prices.length === 0) {
                    this.addPriceVariant();
                }
            },

            // ========== IMAGE METHODS ==========
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

                    const newImages = [...this.visaImages];
                    const [draggedItem] = newImages.splice(this.draggedIndex, 1);
                    newImages.splice(dropIndex, 0, draggedItem);
                    this.visaImages = newImages;
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
                        alert('<?= T::file_too_large ?? "File is too large" ?>: ' + file.name + '. <?= T::max_5mb ?? "Maximum 5MB allowed" ?>');
                        continue;
                    }

                    if (!file.type.startsWith('image/')) {
                        alert('<?= T::invalid_file_type ?? "Invalid file type" ?>: ' + file.name);
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

            // ========== FORM VALIDATION ==========
            validateForm() {
                const errors = [];
                const requiredFields = [
                    { name: 'from_country_id', label: '<?= T::from_country ?? 'From Country' ?>', tab: 'general' },
                    { name: 'to_country_id', label: '<?= T::to_country ?? 'To Country' ?>', tab: 'general' },
                    { name: 'visa_type', label: '<?= T::visa_type ?? 'Visa Type' ?>', tab: 'general' },
                    { name: 'entry_type', label: '<?= T::entry_type ?? 'Entry Type' ?>', tab: 'general' },
                    { name: 'duration_days', label: '<?= T::duration ?? 'Duration' ?>', tab: 'general' },
                    { name: 'currency', label: '<?= T::currency ?? 'Currency' ?>', tab: 'pricing' }
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

                    element = document.querySelector(`input[name="${field.name}"], select[name="${field.name}"], textarea[name="${field.name}"]`);
                    if (element) {
                        const value = element.value.trim();
                        isInvalid = !value || value === '0' || value === '';
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

                // Switch to the tab with the first error
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

            // ========== FORM SUBMIT ==========
            submitForm(event) {
                event.preventDefault();

                // Validate form
                const errors = this.validateForm();
                if (errors.length > 0) {
                    this.showValidationErrors(errors);
                    return false;
                }

                this.loading = true;

                // Store the active tab in sessionStorage to restore after redirect (ONLY for edit)
                if (this.isEdit) {
                    sessionStorage.setItem('visa_active_tab', this.activeTab);
                }

                // For image uploads, we need to use FormData with fetch
                if (this.previewImages.length > 0) {
                    const formData = new FormData(event.target);

                    // Remove the empty file input
                    formData.delete('visa_images[]');

                    // Append actual file objects from previewImages
                    this.previewImages.forEach((image) => {
                        if (image.file) {
                            formData.append('visa_images[]', image.file);
                        }
                    });

                    // Append reordered images and deletion data (if in edit mode)
                    <?php if ($isEdit): ?>
                        if (this.visaImages && this.visaImages.length > 0) {
                            formData.append('reordered_images', JSON.stringify(this.visaImages));
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
                    event.target.submit();
                }

                return false;
            },

            // ========== INITIALIZATION ==========
            init() {
                this.loadTabFromHash();

                window.addEventListener('hashchange', () => {
                    this.loadTabFromHash();
                });
            }
        };
    }
</script>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span
                class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/visa"
                class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200" x-data="visaFormData()" x-init="init()">
        <!-- Tabs Navigation -->
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px overflow-x-auto">
                <button @click="switchTab('general')"
                    :class="activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">info</span>
                    <?= T::general_info ?? 'General Info' ?>
                </button>

                <button @click="switchTab('pricing')"
                    :class="activeTab === 'pricing' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">payments</span>
                    <?= T::pricing ?? 'Pricing' ?>
                </button>

                <button @click="switchTab('requirements')"
                    :class="activeTab === 'requirements' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">description</span>
                    <?= T::requirements ?? 'Requirements' ?>
                </button>

                <button @click="switchTab('gallery')"
                    :class="activeTab === 'gallery' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">photo_library</span>
                    <?= T::gallery ?? 'Gallery' ?>
                </button>
            </nav>
        </div>

        <!-- Form -->
        <form method="POST" action="<?= root . admin ?>/visa/<?= $isEdit ? 'edit/' . $visa['id'] : 'add' ?>"
            @submit="submitForm($event)" enctype="multipart/form-data" class="p-6">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="active_tab" :value="activeTab">
            <input type="hidden" name="prices" :value="JSON.stringify(prices)">

            <!-- Tab: General Info -->
            <div x-show="activeTab === 'general'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">info</span>
                        <?= T::basic_information ?? 'Basic Information' ?>
                    </h3>


                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                        <div class="form-control">
                            <label class="required text-sm"><?= T::from_country ?? 'From Country' ?> *</label>
                            <select name="from_country_id" class="select text-sm">
                                <option value=""><?= T::select_country ?? 'Select Country' ?></option>
                                <?php foreach ($countries as $country): ?>
                                    <option value="<?= $country['id'] ?>" <?= $visa['from_country_id'] == $country['id'] ? 'selected' : '' ?>><?= htmlspecialchars($country['nicename']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="required text-sm"><?= T::to_country ?? 'To Country' ?> *</label>
                            <select name="to_country_id" class="select text-sm">
                                <option value=""><?= T::select_country ?? 'Select Country' ?></option>
                                <?php foreach ($countries as $country): ?>
                                    <option value="<?= $country['id'] ?>" <?= $visa['to_country_id'] == $country['id'] ? 'selected' : '' ?>><?= htmlspecialchars($country['nicename']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>


                    <div class="form-control mb-4">
                        <label class="text-sm"><?= T::description ?? 'Description' ?></label>
                        <textarea name="description" class="textarea text-sm" rows="4"
                            placeholder="<?= T::enter_description ?? 'Enter visa description' ?>"><?= htmlspecialchars($visa['description'] ?? '') ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">


                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox" name="status" value="1" id="status"
                                            class="checkbox-input" <?= $visa['status'] ? 'checked' : '' ?>>
                                        <div class="checkbox-custom">
                                            <span
                                                class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                        </div>
                                    </div>
                                    <label for="status"
                                        class="cursor-pointer text-sm font-medium"><?= T::active ?? 'Active' ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Pricing -->
            <div x-show="activeTab === 'pricing'" x-transition class="space-y-4">
                <template x-for="(price, index) in prices" :key="index">
                    <div class="bg-gray-50 rounded-lg p-5 border border-gray-200 relative mb-6">
                        <div
                            class="absolute -top-3 left-4 bg-white px-2 py-0.5 rounded border border-gray-200 text-xs font-bold text-blue-600 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">payments</span>
                            <?= T::variant ?? 'Variant' ?> #<span x-text="index + 1"></span>
                        </div>

                        <button type="button" @click="removePriceVariant(index)" x-show="prices.length > 1"
                            class="absolute -top-3 -right-3 bg-red-100 text-red-600 hover:bg-red-200 w-8 h-8 rounded-full border border-red-200 flex items-center justify-center transition-colors shadow-sm">
                            <span class="material-symbols-outlined text-sm">delete</span>
                        </button>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4 mt-2">
                            <div class="form-control" x-data="{ open: false }" @click.away="open = false">
                                <label class="required text-sm"><?= T::visa_type ?? 'Visa Type' ?> *</label>
                                <div class="input-dropdown">
                                    <div @click="open = !open"
                                        class="input cursor-pointer flex items-center justify-between">
                                        <span
                                            x-text="visaTypes.find(t => t.value === prices[index].visa_type)?.name || '<?= T::select ?? 'Select' ?>'"></span>
                                        <span class="material-symbols-outlined transition-transform text-sm"
                                            :class="open ? 'rotate-180' : ''">expand_more</span>
                                    </div>
                                    <div class="input-dropdown-content" :class="open ? 'show' : ''">
                                        <template x-for="type in visaTypes" :key="type.value">
                                            <div class="input-dropdown-item"
                                                @click="prices[index].visa_type = type.value; open = false">
                                                <div class="flex items-center gap-2">
                                                    <span class="material-symbols-outlined text-sm"
                                                        x-text="type.icon || 'description'"></span>
                                                    <span x-text="type.name"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <div class="form-control" x-data="{ open: false }" @click.away="open = false">
                                <label class="required text-sm"><?= T::entry_type ?? 'Entry Type' ?> *</label>
                                <div class="input-dropdown">
                                    <div @click="open = !open"
                                        class="input cursor-pointer flex items-center justify-between">
                                        <span
                                            x-text="entryTypes.find(e => e.value === prices[index].entry_type)?.name || '<?= T::select ?? 'Select' ?>'"></span>
                                        <span class="material-symbols-outlined transition-transform text-sm"
                                            :class="open ? 'rotate-180' : ''">expand_more</span>
                                    </div>
                                    <div class="input-dropdown-content" :class="open ? 'show' : ''">
                                        <template x-for="entry in entryTypes" :key="entry.value">
                                            <div class="input-dropdown-item"
                                                @click="prices[index].entry_type = entry.value; open = false">
                                                <div class="flex items-center gap-2">
                                                    <span class="material-symbols-outlined text-sm"
                                                        x-text="entry.icon || 'input'"></span>
                                                    <span x-text="entry.name"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="form-control" x-data="{ open: false }" @click.away="open = false">
                                <label class="required text-sm"><?= T::duration ?? 'Duration' ?>
                                    (<?= T::days ?? 'Days' ?>) *</label>
                                <div class="input-dropdown">
                                    <div @click="open = !open"
                                        class="input cursor-pointer flex items-center justify-between">
                                        <span
                                            x-text="durations.find(d => d.value == prices[index].duration_days)?.name || prices[index].duration_days || '<?= T::select ?? 'Select' ?>'"></span>
                                        <span class="material-symbols-outlined transition-transform text-sm"
                                            :class="open ? 'rotate-180' : ''">expand_more</span>
                                    </div>
                                    <div class="input-dropdown-content" :class="open ? 'show' : ''">
                                        <template x-for="dur in durations" :key="dur.value">
                                            <div class="input-dropdown-item"
                                                @click="prices[index].duration_days = dur.value; open = false">
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="material-symbols-outlined text-sm">calendar_today</span>
                                                    <span x-text="dur.name"></span>
                                                </div>
                                            </div>
                                        </template>
                                        <!-- Custom input option -->
                                        <div class="p-2 border-t border-gray-100">
                                            <input type="number" x-model="prices[index].duration_days" @click.stop
                                                @keydown.enter.prevent="open = false" placeholder="Custom Days..."
                                                class="w-full px-3 py-1.5 text-xs border border-gray-200 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-control" x-data="{ open: false }" @click.away="open = false">
                                <label class="text-sm"><?= T::processing_speed ?? 'Processing Speed' ?> *</label>
                                <div class="input-dropdown">
                                    <div @click="open = !open"
                                        class="input cursor-pointer flex items-center justify-between">
                                        <span
                                            x-text="processingSpeeds.find(s => s.value === prices[index].processing_speed)?.name || '<?= T::select_speed ?? 'Select Speed' ?>'"></span>
                                        <span class="material-symbols-outlined transition-transform text-sm"
                                            :class="open ? 'rotate-180' : ''">expand_more</span>
                                    </div>
                                    <div class="input-dropdown-content" :class="open ? 'show' : ''">
                                        <template x-for="speed in processingSpeeds" :key="speed.value">
                                            <div class="input-dropdown-item"
                                                @click="prices[index].processing_speed = speed.value; open = false">
                                                <div class="flex items-center gap-2">
                                                    <span class="material-symbols-outlined text-sm"
                                                        x-text="speed.icon || 'schedule'"></span>
                                                    <div>
                                                        <div x-text="speed.name" class="font-medium"></div>
                                                        <div x-text="speed.description" class="text-xs text-gray-500"
                                                            x-show="speed.description"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="form-control">
                                <label class="text-sm"><?= T::visa_fee ?? 'Visa Fee' ?></label>
                                <input type="number" x-model="prices[index].govt_fee" class="input text-sm" step="0.01"
                                    min="0" placeholder="0.00" @input="calculateVariantTotal(index)">
                            </div>

                            <div class="form-control">
                                <label class="text-sm"><?= T::commission ?? 'Commission' ?></label>
                                <input type="number" x-model="prices[index].service_fee" class="input text-sm"
                                    step="0.01" min="0" placeholder="0.00" @input="calculateVariantTotal(index)">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="required text-sm"><?= T::total_price ?? 'Total Price' ?> *</label>
                                <input type="number" x-model="prices[index].total_price"
                                    class="input text-sm bg-gray-100" step="0.01" min="0" placeholder="0.00" readonly>
                            </div>

                            <div class="form-control">
                                <label class="required text-sm"><?= T::currency ?? 'Currency' ?> *</label>
                                <select x-model="prices[index].currency" class="select text-sm">
                                    <?php foreach ($currencies as $curr): ?>
                                        <option value="<?= $curr['name'] ?>"><?= $curr['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </template>

                <button type="button" @click="addPriceVariant()"
                    class="btn secondary w-full flex items-center justify-center gap-2 border-dashed border-2 py-4">
                    <span class="material-symbols-outlined">add_circle</span>
                    <span><?= T::add_new_variant ?? 'Add New Variant' ?></span>
                </button>
            </div>

            <!-- Tab: Requirements -->
            <div x-show="activeTab === 'requirements'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">checklist</span>
                        <?= T::visa_requirements ?? 'Visa Requirements' ?>
                    </h3>

                    <div id="requirements-container">
                        <template x-for="(req, index) in requirements" :key="index">
                            <div class="flex items-center gap-3 mb-3">
                                <input type="text" x-model="requirements[index]" class="input text-sm flex-1"
                                    placeholder="<?= T::enter_requirement ?? 'Enter requirement' ?>">
                                <button type="button" @click="removeRequirement(index)"
                                    class="btn secondary w-10 h-10 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </template>
                    </div>

                    <button type="button" @click="addRequirement()" class="btn secondary mt-3 flex items-center gap-2">
                        <span class="material-symbols-outlined">add</span>
                        <span><?= T::add_requirement ?? 'Add Requirement' ?></span>
                    </button>

                    <input type="hidden" name="requirements" :value="JSON.stringify(requirements)">
                </div>
            </div>

            <!-- Tab: Gallery -->
            <div x-show="activeTab === 'gallery'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">photo_library</span>
                        <?= T::gallery ?? 'Visa Gallery' ?>
                    </h3>

                    <?php if ($isEdit && !empty($visa_images)): ?>
                        <!-- Existing Images (Edit Mode Only) -->
                        <div class="mb-6">
                            <h4
                                class="text-sm font-semibold text-gray-900 mb-4 flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg shrink-0">image</span>
                                    <span>
                                        <?= T::existing_images ?? 'Existing Images' ?>
                                        (<span x-text="visaImages.length"></span>)
                                    </span>
                                </div>

                                <span class="text-xs text-gray-500 sm:ml-2">
                                    <?= T::drag_to_reorder ?? 'Drag to reorder' ?>
                                </span>
                            </h4>

                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <template x-for="(img, index) in visaImages" :key="index">
                                    <div class="relative group bg-white rounded-lg border-2 overflow-hidden transition-all duration-200"
                                        draggable="true" @dragstart="dragStart(index)"
                                        @dragover.prevent="dragOver($event, index)" @drop.prevent="drop(index)"
                                        @dragend="draggedIndex = null" :class="{
                                        'opacity-50': imagesToDelete.includes(img.url) || draggedIndex === index,
                                        'border-green-300 hover:border-green-400': img.url === defaultImage,
                                        'border-gray-200 hover:border-blue-400': img.url !== defaultImage,
                                        'cursor-move': !imagesToDelete.includes(img.url),
                                        'cursor-not-allowed': imagesToDelete.includes(img.url)
                                    }" :style="imagesToDelete.includes(img.url) ? 'pointer-events: none;' : ''">
                                        <div class="aspect-video relative">
                                            <img :src="'<?= root ?>' + img.url"
                                                :alt="'<?= T::visa ?? 'Visa' ?> <?= T::image ?? 'Image' ?> ' + (index + 1)"
                                                class="w-full h-full object-cover pointer-events-none" draggable="false"
                                                onerror="this.onerror=null;this.src='<?= root ?>uploads/no_img.jpg'">

                                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-1 sm:gap-2"
                                                @mousedown.stop @click.stop>
                                                <button type="button" @click="openLightbox(img.url)"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-blue-500 hover:bg-blue-600 text-white w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center"
                                                    title="<?= T::view ?? 'View' ?>">
                                                    <span class="material-symbols-outlined text-base sm:text-xl">visibility</span>
                                                </button>

                                                <button type="button" @click="setDefaultImage(img.url)"
                                                    x-show="!imagesToDelete.includes(img.url) && img.url !== defaultImage"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity bg-green-500 hover:bg-green-600 text-white w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center"
                                                    title="<?= T::set_as_default ?? 'Set as default' ?>">
                                                    <span class="material-symbols-outlined text-base sm:text-xl">check_circle</span>
                                                </button>

                                                <button type="button" @click="toggleDeleteImage(img.url)"
                                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-white w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center"
                                                    :class="imagesToDelete.includes(img.url) ? 'bg-gray-500 hover:bg-gray-600' : 'bg-red-500 hover:bg-red-600'">
                                                    <span class="material-symbols-outlined text-base sm:text-xl"
                                                        x-text="imagesToDelete.includes(img.url) ? 'undo' : 'delete'"></span>
                                                </button>
                                            </div>

                                            <div x-show="img.url === defaultImage && !imagesToDelete.includes(img.url)"
                                                class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg group-hover:hidden">
                                                <span class="material-symbols-outlined text-sm">verified</span>
                                                <span><?= T::default ?? 'Default' ?></span>
                                            </div>

                                            <div x-show="imagesToDelete.includes(img.url)"
                                                class="absolute inset-0 bg-red-500 bg-opacity-80 flex items-center justify-center p-2">
                                                <div class="text-white text-center">
                                                    <span class="material-symbols-outlined text-2xl sm:text-4xl mb-1 sm:mb-2">delete</span>
                                                    <p class="text-xs sm:text-sm font-semibold">
                                                        <?= T::marked_for_deletion ?? 'Marked for Deletion' ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- New Image Preview Grid -->
                    <div x-show="previewImages.length > 0" class="mt-6 mb-4" x-transition>
                        <div class="p-4 bg-green-50 rounded-lg border border-green-200 mb-4">
                            <div class="flex items-center gap-2 text-green-700">
                                <span class="material-symbols-outlined">check_circle</span>
                                <span class="font-medium">
                                    <span
                                        x-text="previewImages.length === 1 ? '1 <?= T::new_image ?> <?= T::ready_to_upload ?>' : previewImages.length + ' <?= T::new_images ?> <?= T::ready_to_upload ?>'"></span>
                                </span>
                            </div>
                        </div>

                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">new_releases</span>
                            <?= T::image_previews ?? 'Image Previews' ?>
                        </h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                            <template x-for="(image, index) in previewImages" :key="index">
                                <div
                                    class="relative group bg-white rounded-lg border-2 border-green-300 overflow-hidden hover:border-green-500 transition-all duration-200">
                                    <div class="aspect-video relative">
                                        <img :src="image.preview"
                                            :alt="'<?= T::new_preview ?? 'New Preview' ?> ' + (index + 1)"
                                            class="w-full h-full object-cover">

                                        <div
                                            class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-1 sm:gap-2">
                                            <button type="button" @click="removeNewImage(index)"
                                                class="opacity-0 group-hover:opacity-100 transition-opacity bg-red-500 hover:bg-red-600 text-white w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center">
                                                <span class="material-symbols-outlined text-base sm:text-xl">delete</span>
                                            </button>
                                        </div>

                                        <div
                                            class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg group-hover:hidden">
                                            <span class="material-symbols-outlined text-sm">fiber_new</span>
                                            <span><?= T::new ?? 'New' ?></span>
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
                    <div
                        class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8">
                        <div class="text-center">
                            <div
                                class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                <span
                                    class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                            </div>

                            <h4 class="text-lg font-semibold text-gray-900 mb-2">
                                <?= T::upload_images ?? 'Upload Images' ?>
                            </h4>
                            <p class="text-sm text-gray-600 mb-6">
                                <?= T::drag_drop_images_hint ?? 'Drag and drop images here, or click to select files' ?>
                            </p>

                            <label for="visa_images_input" class="btn inline-flex items-center gap-2 cursor-pointer">
                                <span class="material-symbols-outlined">upload</span>
                                <span><?= T::choose_images ?? 'Choose Images' ?></span>
                            </label>

                            <input type="file" id="visa_images_input" name="visa_images[]" multiple accept="image/*"
                                class="hidden" @change="handleImageUpload($event)">

                            <p class="text-xs text-gray-500 mt-4">
                                <?= T::image_upload_requirements ?? 'Supported formats: JPG, PNG, WEBP • Max size: 5MB per image' ?>
                            </p>
                        </div>
                    </div>

                    <!-- Hidden inputs for tracking changes -->
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="reordered_images" :value="JSON.stringify(visaImages)">
                        <input type="hidden" name="images_to_delete" :value="JSON.stringify(imagesToDelete)">
                        <input type="hidden" name="default_image" :value="defaultImage">
                    <?php endif; ?>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                <a href="<?= root ?>admin/visa" class="btn white text-sm"><?= T::cancel ?? 'Cancel' ?></a>
                <button type="submit" class="btn text-sm" :disabled="loading">
                    <span :class="loading ? 'hidden' : 'flex'" class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">save</span>
                        <span><?= $isEdit ? (T::update ?? 'Update') : (T::submit ?? 'Submit') ?></span>
                    </span>
                    <span :class="loading ? 'flex' : 'hidden'" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        <span><?= T::processing ?? 'Processing' ?></span>
                    </span>
                </button>
            </div>
        </form>

        <!-- Lightbox -->
        <div x-show="showLightbox" @click="closeLightbox()" @keydown.escape.window="closeLightbox()"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-90 p-4"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;">
            <button @click="closeLightbox()"
                class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors">
                <span class="material-symbols-outlined text-4xl">close</span>
            </button>
            <img :src="'<?= root ?>' + lightboxImage" @click.stop
                class="max-w-full max-h-full object-contain rounded-lg shadow-2xl"
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100">
        </div>
    </div>
</div>