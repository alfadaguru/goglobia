<?php
// manage-blog.php
@$SECURE or die('Access Denied!');
?>

<style>
.image-upload-container {
    border: 2px dashed #e5e7eb;
    border-radius: 0.5rem;
    padding: 2rem;
    text-align: center;
    background-color: #f9fafb;
    transition: all 0.2s;
    cursor: pointer;
}
.image-upload-container:hover {
    border-color: #3b82f6;
    background-color: #eff6ff;
}
.image-upload-container.dragover {
    border-color: #10b981;
    background-color: #ecfdf5;
}
.image-preview {
    max-width: 100%;
    max-height: 300px;
    border-radius: 0.5rem;
    margin: 0 auto;
}
</style>

<?php
$mode = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$isAdd = ($mode === 'add');

if ($isEdit) {
    $pageTitle = T::edit_blog_post ?? 'Edit Blog Post';
} else {
    $pageTitle = T::add_blog_post ?? 'Add Blog Post';
}

if ($isAdd) {
    $blog = [
        'id' => 0,
        'post_title' => '',
        'post_desc' => '',
        'post_category' => '',
        'post_img' => '',
        'meta_title' => '',
        'meta_description' => '',
        'meta_keywords' => '',
        'featured' => 0,
        'status' => 1,
        'published_at' => date('Y-m-d H:i:s'),
    ];
    $title_translations = [];
    $desc_translations = [];
}
?>

<script>
function blogFormData() {
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
        translationTabInitialized: false,
        imagePreview: '<?= !empty($blog['post_img']) ? root . $blog['post_img'] : '' ?>',
        isDragover: false,
        deleteOldImage: false,
        
        switchTab(tab) {
            this.activeTab = tab;
            if (tab === 'translations') {
                window.location.hash = tab + ':' + this.activeTranslationTab;
                if (!this.translationTabInitialized) {
                    setTimeout(() => {
                        const descTextarea = document.getElementById('blog-trans-desc-' + this.activeTranslationTab);
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
                    const descTextarea = document.getElementById('blog-trans-desc-' + langCode);
                    if (descTextarea) {
                        initializeTranslationEditor(descTextarea, langCode);
                    }
                }
            }, 100);
        },
        
        loadTabFromHash() {
            const savedTab = sessionStorage.getItem('blog_active_tab');
            if (savedTab) {
                sessionStorage.removeItem('blog_active_tab');
                if (savedTab.includes(':')) {
                    const [mainTab, langCode] = savedTab.split(':');
                    if (mainTab === 'translations' && langCode) {
                        this.activeTab = 'translations';
                        this.activeTranslationTab = langCode;
                        window.location.hash = savedTab;
                        setTimeout(() => {
                            const descTextarea = document.getElementById('blog-trans-desc-' + langCode);
                            if (descTextarea && !editorInstances.translations.desc[langCode]) {
                                initializeTranslationEditor(descTextarea, langCode);
                            }
                            this.translationTabInitialized = true;
                        }, 200);
                        return;
                    }
                } else {
                    const validTabs = ['general', 'seo', 'translations'];
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
                        const descTextarea = document.getElementById('blog-trans-desc-' + langCode);
                        if (descTextarea && !editorInstances.translations.desc[langCode]) {
                            initializeTranslationEditor(descTextarea, langCode);
                        }
                        this.translationTabInitialized = true;
                    }, 200);
                }
            } else {
                const validTabs = ['general', 'seo', 'translations'];
                if (validTabs.includes(hash)) {
                    this.activeTab = hash;
                    if (hash === 'translations') {
                        setTimeout(() => {
                            const descTextarea = document.getElementById('blog-trans-desc-' + this.activeTranslationTab);
                            if (descTextarea && !editorInstances.translations.desc[this.activeTranslationTab]) {
                                initializeTranslationEditor(descTextarea, this.activeTranslationTab);
                            }
                            this.translationTabInitialized = true;
                        }, 200);
                    }
                }
            }
        },
        
        handleImageUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            if (file.size > 5 * 1024 * 1024) {
                alert('<?= @T::file_too_large ?: "File is too large" ?>. <?= @T::max_5mb ?: "Maximum 5MB allowed" ?>');
                return;
            }
            
            if (!file.type.startsWith('image/')) {
                alert('<?= @T::invalid_file_type ?: "Invalid file type" ?>');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = (e) => {
                this.imagePreview = e.target.result;
            };
            reader.readAsDataURL(file);
        },
        
        handleDragOver(event) {
            event.preventDefault();
            this.isDragover = true;
        },
        
        handleDragLeave(event) {
            event.preventDefault();
            this.isDragover = false;
        },
        
        handleDrop(event) {
            event.preventDefault();
            this.isDragover = false;
            
            const file = event.dataTransfer.files[0];
            if (file) {
                const input = document.getElementById('post_img_input');
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                input.files = dataTransfer.files;
                this.handleImageUpload({ target: input });
            }
        },
        
        removeImage() {
            this.imagePreview = '';
            this.deleteOldImage = true;
            document.getElementById('post_img_input').value = '';
        },
        
        validateForm() {
            const errors = [];
            const requiredFields = [
                { name: 'post_title', label: '<?= T::title ?? "Title" ?>', tab: 'general' },
                { name: 'post_desc', label: '<?= T::description ?? "Description" ?>', tab: 'general', customCheck: () => !editorInstances.main.description || editorInstances.main.description.getData().trim().length === 0 }
            ];

            document.querySelectorAll('.input-error, .textarea-error').forEach(el => {
                el.classList.remove('input-error', 'textarea-error');
            });
            document.querySelectorAll('.error-message').forEach(el => el.remove());

            requiredFields.forEach(field => {
                let isInvalid = false;
                let element = null;

                if (field.customCheck) {
                    isInvalid = field.customCheck();
                    element = document.querySelector(`textarea[name="${field.name}"]`)?.parentElement;
                } else {
                    element = document.querySelector(`input[name="${field.name}"], textarea[name="${field.name}"]`);
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
                    const inputElement = error.element.querySelector('input, textarea') || error.element;
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
            
            // Get CKEditor content
            if (editorInstances.main.description) {
                const mainTextarea = document.querySelector('#post_desc');
                if (mainTextarea) {
                    mainTextarea.value = editorInstances.main.description.getData();
                }
            }
            
            // Get translation editors content
            for (const [langCode, editor] of Object.entries(editorInstances.translations.desc)) {
                if (editor) {
                    const textarea = document.querySelector(`textarea[name='desc_translations[${langCode}]']`);
                    if (textarea) {
                        textarea.value = editor.getData();
                    }
                }
            }
            
            if (this.activeTab === 'translations' && this.activeTranslationTab) {
                sessionStorage.setItem('blog_active_tab', this.activeTab + ':' + this.activeTranslationTab);
            } else {
                sessionStorage.setItem('blog_active_tab', this.activeTab);
            }
            
            // Add delete image flag if needed
            if (this.deleteOldImage && <?= $isEdit ? 'true' : 'false' ?>) {
                const deleteInput = document.createElement('input');
                deleteInput.type = 'hidden';
                deleteInput.name = 'delete_old_image';
                deleteInput.value = '1';
                event.target.appendChild(deleteInput);
            }
            
            event.target.submit();
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
            <a href="<?= root.admin ?>/blogs" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200" x-data="blogFormData()" x-init="init()">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px overflow-x-auto">
                <button @click="switchTab('general')"
                        :class="activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">article</span>
                    <?= T::general ?? 'General' ?>
                </button>

                <button @click="switchTab('seo')"
                        :class="activeTab === 'seo' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">search</span>
                    <?= T::seo ?? 'SEO' ?>
                </button>

                <button @click="switchTab('translations')"
                        :class="activeTab === 'translations' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">translate</span>
                    <?= T::translations ?? 'Translations' ?>
                </button>
            </nav>
        </div>

        <form method="POST"
            action="<?=root.admin?>/blogs/<?= $isEdit ? 'edit/' . $blog['id'] : 'add' ?>"
            @submit="submitForm($event)"
            enctype="multipart/form-data"
            class="p-6"
            x-transition>
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="active_tab" :value="activeTab">
            
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= $blog['id'] ?>">
            <?php endif; ?>

            <!-- Tab: General -->
            <div x-show="activeTab === 'general'" x-transition class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 space-y-4">
                        <div class="form-control">
                            <label class="required text-sm font-medium"><?= T::title ?? 'Title' ?> *</label>
                            <input type="text" name="post_title" class="input text-sm" 
                                   value="<?= htmlspecialchars($blog['post_title']) ?>" 
                                   placeholder="<?= T::enter_blog_title ?? 'Enter blog title' ?>">
                        </div>

                        <div class="form-control">
                            <label class="required text-sm font-medium"><?= T::description ?? 'Description' ?> *</label>
                            <textarea id="post_desc" name="post_desc" class="ckeditor-blog-desc"><?= htmlspecialchars($blog['post_desc'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                <span class="material-symbols-outlined text-blue-600 text-lg">image</span>
                                <?= T::featured_image ?? 'Featured Image' ?>
                            </h4>

                            <div class="space-y-4">
                                <div :class="['image-upload-container', isDragover ? 'dragover' : '']"
                                     @dragover.prevent="handleDragOver"
                                     @dragleave.prevent="handleDragLeave"
                                     @drop.prevent="handleDrop"
                                     onclick="document.getElementById('post_img_input').click()">
                                    
                                    <input type="file" 
                                           id="post_img_input" 
                                           name="post_img" 
                                           accept="image/*" 
                                           class="hidden"
                                           @change="handleImageUpload">
                                    
                                    <div x-show="!imagePreview" class="py-8">
                                        <div class="mx-auto w-16 h-16 mb-4 bg-gray-200 rounded-full flex items-center justify-center">
                                            <span class="material-symbols-outlined text-gray-400 text-3xl">add_photo_alternate</span>
                                        </div>
                                        <p class="text-sm text-gray-600 mb-1">
                                            <?= T::drag_drop_image ?? 'Drag & drop image here' ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <?= T::or_click_to_browse ?? 'or click to browse' ?>
                                        </p>
                                    </div>
                                    
                                    <div x-show="imagePreview" class="space-y-3">
                                        <img :src="imagePreview" alt="Preview" class="image-preview">
                                        <button type="button" 
                                                @click.stop="removeImage()"
                                                class="btn danger text-xs w-full">
                                            <span class="material-symbols-outlined text-sm">delete</span>
                                            <?= T::remove_image ?? 'Remove Image' ?>
                                        </button>
                                    </div>
                                </div>
                                
                                <p class="text-xs text-gray-500 text-center">
                                    <?= T::recommended_size ?? 'Recommended size' ?>: 1200×630px • <?= T::max_file_size ?? 'Max file size' ?>: 5MB
                                </p>
                            </div>
                        </div>

                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                <span class="material-symbols-outlined text-blue-600 text-lg">settings</span>
                                <?= T::settings ?? 'Settings' ?>
                            </h4>

                            <div class="space-y-3">
                                <div class="form-control">
                                    <label class="text-sm font-medium"><?= T::category ?? 'Category' ?></label>
                                    <select name="post_category" class="select text-sm">
                                        <option value=""><?= T::select_category ?? 'Select Category' ?></option>
                                        <?php foreach ($categories ?? [] as $cat): ?>
                                            <option value="<?= $cat['id'] ?>" <?= $blog['post_category'] == $cat['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cat['cat_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="text-sm font-medium"><?= T::published_date ?? 'Published Date' ?></label>
                                    <input type="datetime-local" name="published_at" class="input text-sm"
                                           value="<?= !empty($blog['published_at']) ? date('Y-m-d\TH:i', strtotime($blog['published_at'])) : date('Y-m-d\TH:i') ?>">
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="bg-white rounded-lg p-3 border border-gray-200">
                                        <div class="checkbox-group">
                                            <div class="checkbox-item">
                                                <div class="checkbox-container">
                                                    <input type="checkbox" name="featured" value="1" id="featured" 
                                                           class="checkbox-input" <?= $blog['featured'] ? 'checked' : '' ?>>
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
                                                    <input type="checkbox" name="status" value="1" id="status" 
                                                           class="checkbox-input" <?= $blog['status'] ? 'checked' : '' ?>>
                                                    <div class="checkbox-custom">
                                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                                    </div>
                                                </div>
                                                <label for="status" class="cursor-pointer text-sm font-medium"><?= T::active ?? 'Active' ?></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: SEO -->
            <div x-show="activeTab === 'seo'" x-transition class="space-y-6">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">search</span>
                        <?= T::seo_information ?? 'SEO Information' ?>
                    </h4>

                    <div class="space-y-4">
                        <div class="form-control">
                            <label class="text-sm font-medium"><?= T::meta_title ?? 'Meta Title' ?></label>
                            <input type="text" name="meta_title" class="input text-sm" 
                                   value="<?= htmlspecialchars($blog['meta_title'] ?? '') ?>" 
                                   placeholder="<?= T::enter_meta_title ?? 'Enter meta title' ?>">
                            <p class="text-xs text-gray-500 mt-1">
                                <?= T::recommended_length ?? 'Recommended length' ?>: 50-60 <?= T::characters ?? 'characters' ?>
                            </p>
                        </div>

                        <div class="form-control">
                            <label class="text-sm font-medium"><?= T::meta_description ?? 'Meta Description' ?></label>
                            <textarea name="meta_description" class="textarea text-sm" rows="3" 
                                      placeholder="<?= T::enter_meta_description ?? 'Enter meta description' ?>"><?= htmlspecialchars($blog['meta_description'] ?? '') ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">
                                <?= T::recommended_length ?? 'Recommended length' ?>: 150-160 <?= T::characters ?? 'characters' ?>
                            </p>
                        </div>

                        <div class="form-control">
                            <label class="text-sm font-medium"><?= T::meta_keywords ?? 'Meta Keywords' ?></label>
                            <textarea name="meta_keywords" class="textarea text-sm" rows="2" 
                                      placeholder="<?= T::enter_keywords_separated_by_commas ?? 'Enter keywords separated by commas' ?>"><?= htmlspecialchars($blog['meta_keywords'] ?? '') ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">
                                <?= T::separate_with_commas ?? 'Separate keywords with commas' ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Translations -->
            <div x-show="activeTab === 'translations'" x-transition class="space-y-6">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div class="border-b border-gray-200">
                        <nav class="flex -mb-px overflow-x-auto">
                            <?php
                            foreach ($GLOBALS['languages'] ?? [] as $lang_data):
                                if ($lang_data['lang_code'] === 'en') continue;
                                $lang_name = $lang_data['name'] ?? $lang_data['lang_code'];
                                $lang_code = $lang_data['lang_code'];
                                $has_title = !empty($title_translations[$lang_code]);
                                $has_desc = !empty($desc_translations[$lang_code]);
                                $has_any = $has_title || $has_desc;
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
                            $title_value = $title_translations[$lang_code] ?? '';
                            $desc_value = $desc_translations[$lang_code] ?? '';
                        ?>
                            <div x-show="activeTranslationTab === '<?= $lang_code ?>'" x-transition class="space-y-6">
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                        <span class="material-symbols-outlined text-blue-600 text-lg">translate</span>
                                        <?= htmlspecialchars($lang_name) ?> <?= T::translations ?? 'Translations' ?>
                                    </h4>

                                    <div class="space-y-4">
                                        <div class="form-control">
                                            <label class="text-sm font-medium"><?= T::title ?? 'Title' ?></label>
                                            <input type="text"
                                                name="title_translations[<?= $lang_code ?>]"
                                                class="input text-sm"
                                                value="<?= htmlspecialchars($title_value) ?>"
                                                placeholder="<?= T::enter_translation ?? 'Enter translation' ?>">
                                        </div>

                                        <div class="form-control">
                                            <label class="text-sm font-medium"><?= T::description ?? 'Description' ?></label>
                                            <textarea
                                                id="blog-trans-desc-<?= $lang_code ?>"
                                                name="desc_translations[<?= $lang_code ?>]"
                                                class="blog-trans-desc"
                                                data-lang="<?= $lang_code ?>"><?= htmlspecialchars($desc_value) ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                <a href="<?= root ?>admin/blogs" class="btn white text-sm"><?= T::cancel ?? 'Cancel' ?></a>
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
    </div>
</div>

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
    // Main blog description editor
    CKEDITOR.ClassicEditor
        .create(document.querySelector('#post_desc'), ckEditorConfig)
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