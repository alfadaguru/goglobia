<?php
// Determine if this is edit or add mode
$pageId = $_GET['id'] ?? 0;
$isEdit = $pageId > 0;

// Fetch existing page data if editing
$page = [];
if ($isEdit) {
    $page = $db->get('cms', '*', ['id' => $pageId]);
    if (!$page) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::page_not_found ?? 'Page not found'
        ];
        redirect(root . admin . '/cms/pages');
    }
}

// Include position to show parent type
$parentPages = $db->select('cms', ['id', 'page_name', 'position'], [
    'id[!]' => $isEdit ? $pageId : 0,
    'ORDER' => ['page_name' => 'ASC']
]);

// DEBUGGING: Let's see what we're getting
// Find ALL pages that have a parent_id set
$allPages = $db->select('cms', ['id', 'page_name', 'parent_id']);

// Extract unique parent IDs manually
$parentIdsArray = [];
foreach ($allPages as $p) {  // CHANGE VARIABLE NAME HERE
    if (!empty($p['parent_id']) && $p['parent_id'] > 0) {
        $parentIdsArray[] = (int)$p['parent_id'];
    }
}
$parentIdsArray = array_unique($parentIdsArray);

// Parse translations if editing
$page_name_translations = [];
$content_translations = [];

if ($isEdit && !empty($page['page_name_translations'])) {
    $page_name_translations = json_decode($page['page_name_translations'], true) ?? [];
}

if ($isEdit && !empty($page['content_translations'])) {
    $content_translations = json_decode($page['content_translations'], true) ?? [];
}

// ADD THESE VARIABLES FOR TRANSLATE.PHP COMPATIBILITY
$cmsPage = $page; // Alias for translate.php compatibility

// Get available languages (exclude English)
$available_languages = [];
$raw_languages = $GLOBALS['languages'] ?? [];
foreach ($raw_languages as $lang) {
    if (isset($lang['lang_code']) && $lang['lang_code'] !== 'en') {
        $available_languages[$lang['lang_code']] = $lang;
    }
}

// If no languages configured, use defaults
if (empty($available_languages)) {
    $available_languages = [
        'ar' => ['lang_code' => 'ar', 'name' => 'العربية'],
        'fr' => ['lang_code' => 'fr', 'name' => 'Français'],
        'es' => ['lang_code' => 'es', 'name' => 'Español'],
        'de' => ['lang_code' => 'de', 'name' => 'Deutsch'],
        'zh' => ['lang_code' => 'zh', 'name' => '中文'],
        'tr' => ['lang_code' => 'tr', 'name' => 'Türkçe'],
    ];
}
?>

<script>
function cmsFormData() {
    return {
        loading: false,
        activeTab: 'details',
        activeTranslationTab: '<?php
            $first_lang = '';
            foreach ($available_languages as $lang_code => $lang_data) {
                $first_lang = $lang_code;
                break;
            }
            echo $first_lang;
        ?>',
        slugEdited: <?= !empty($page['slug_url']) ? 'true' : 'false' ?>,
        deleting: null,

        generateSlug(text) {
            return text
                .toLowerCase()
                .trim()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_-]+/g, '-')
                .replace(/^-+|-+$/g, '');
        },

        sanitizeSlug(text) {
            return text
                .toLowerCase()
                .trim()
                .replace(/\s+/g, '-')
                .replace(/[^\w-]/g, '')
                .replace(/-+/g, '-')
                .replace(/^-+|-+$/g, '');
        },

        onPageNameInput(event) {
            const pageName = event.target.value;
            const slugInput = document.getElementById('slug_url');

            if (!this.slugEdited && pageName) {
                slugInput.value = this.generateSlug(pageName);
            }
        },

        onSlugInput(event) {
            const slugInput = event.target;

            if (slugInput.value !== '') {
                this.slugEdited = true;
            }

            const cursorPos = slugInput.selectionStart;
            const oldValue = slugInput.value;
            const newValue = this.sanitizeSlug(oldValue);

            if (oldValue !== newValue) {
                slugInput.value = newValue;
                slugInput.setSelectionRange(cursorPos, cursorPos);
            }
        },

        switchTab(tab) {
            this.activeTab = tab;
            
            if (tab === 'translations') {
                window.location.hash = 'translations:' + this.activeTranslationTab;
            } else {
                window.location.hash = tab;
            }
        },

        switchTranslationTab(langCode) {
            this.activeTranslationTab = langCode;
            window.location.hash = 'translations:' + langCode;

            // Initialize editor for this language tab
            setTimeout(() => {
                const contentTextarea = document.getElementById('cms-trans-content-' + langCode);
                if (contentTextarea && !editorInstances.translations.content[langCode]) {
                    initializeTranslationEditor(contentTextarea, langCode);
                }
            }, 100);
        },

        loadTabFromHash() {
            const hash = window.location.hash.substring(1);
            if (!hash) return;

            if (hash.includes(':')) {
                const [mainTab, langCode] = hash.split(':');

                if (mainTab === 'translations' && langCode) {
                    this.activeTab = 'translations';
                    this.activeTranslationTab = langCode;

                    setTimeout(() => {
                        const contentTextarea = document.getElementById('cms-trans-content-' + langCode);
                        if (contentTextarea && !editorInstances.translations.content[langCode]) {
                            initializeTranslationEditor(contentTextarea, langCode);
                        }
                    }, 200);
                }
            } else {
                const validTabs = ['details', 'translations'];
                if (validTabs.includes(hash)) {
                    this.activeTab = hash;

                    if (hash === 'translations') {
                        setTimeout(() => {
                            const contentTextarea = document.getElementById('cms-trans-content-' + this.activeTranslationTab);
                            if (contentTextarea && !editorInstances.translations.content[this.activeTranslationTab]) {
                                initializeTranslationEditor(contentTextarea, this.activeTranslationTab);
                            }
                        }, 200);
                    }
                }
            }
        },

        submitCmsForm(event) {
            // Update main editor before submission
            if (editorInstances.main) {
                const mainTextarea = document.querySelector('#content');
                if (mainTextarea) {
                    mainTextarea.value = editorInstances.main.getData();
                }
            }

            // Save current hash
            window.location.hash = this.activeTab;

            this.loading = true;
            return true; // Allow form submission
        },

        submitTranslationForm(event) {
            // Update translation editors before submission
            if (editorInstances.translations.content) {
                for (const [langCode, editor] of Object.entries(editorInstances.translations.content)) {
                    if (editor) {
                        const textarea = document.querySelector(`textarea[name='content_translations[${langCode}]']`);
                        if (textarea) {
                            textarea.value = editor.getData();
                        }
                    }
                }
            }

            // Save current hash
            window.location.hash = 'translations:' + this.activeTranslationTab;

            this.loading = true;
            return true; // Allow form submission
        },

        async deleteTranslation(langCode, langName) {
            if (!confirm('<?= T::confirm_delete_translation ?? 'Are you sure you want to delete the translation for' ?> ' + langName + '?')) {
                return;
            }

            this.deleting = langCode;

            try {
                const formData = new FormData();
                formData.append('action', 'delete_translation');
                formData.append('lang_code', langCode);
                formData.append('ajax', '1');
                formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

                const response = await fetch('<?= root . admin ?>/cms/pages/translate/<?= $pageId ?>', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    vt.success(result.message || '<?= T::translation_deleted ?? 'Translation deleted successfully!' ?>');

                    const tabContent = document.getElementById('lang-' + langCode);
                    if (tabContent) {
                        const pageNameInput = tabContent.querySelector(`input[name='page_name_translations[${langCode}]']`);
                        if (pageNameInput) pageNameInput.value = '';

                        if (editorInstances.translations.content[langCode]) {
                            editorInstances.translations.content[langCode].setData('');
                        }

                        const indicator = document.querySelector(`[data-lang-indicator='${langCode}']`);
                        if (indicator) {
                            indicator.classList.remove('bg-green-500');
                            indicator.classList.add('bg-gray-300');
                        }

                        const deleteSection = tabContent.querySelector('.delete-section');
                        if (deleteSection) deleteSection.style.display = 'none';
                    }
                } else {
                    vt.error(result.message || '<?= T::error_deleting ?? 'Error deleting translation. Please try again.' ?>');
                }
            } catch (error) {
                console.error('Delete error:', error);
                vt.error('<?= T::network_error ?? 'Network error. Please check your connection and try again.' ?>');
            } finally {
                this.deleting = null;
            }
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

<div class="container my-4" x-data="cmsFormData()" x-init="init()">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/cms/pages" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg text-white hover:text-white transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-1xl font-bold text-slate-800">
                    <?= $isEdit ? htmlspecialchars($page['page_name'] ?? 'Edit Page') : (T::add_new_page ?? 'Add New Page') ?>
                </h1>
                <?php if ($isEdit): ?>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        #<?= $page['id'] ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">link</span>
                        <?= htmlspecialchars($page['slug_url'] ?? 'N/A') ?>
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">location_on</span>
                        <?= ucfirst(htmlspecialchars($page['position'] ?? 'header')) ?>
                    </span>
                    <?php if (($page['status'] ?? '0') == '1'): ?>
                    <span class="badge success"><?= T::active ?? 'Active' ?></span>
                    <?php else: ?>
                    <span class="badge error"><?= T::inactive ?? 'Inactive' ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Header Controls -->
        <div class="flex items-center gap-3">
            <?php if ($isEdit): ?>
            <!-- Status Dropdown -->
            <div class="flex flex-col gap-1 min-w-[150px]">
                <label for="status" class="text-xs font-medium text-slate-600"><?=T::status?></label>
                <select name="status" id="status" form="cms-form" class="select input text-sm py-1.5 px-3">
                    <option value="1" <?= (!empty($page['status']) && $page['status'] == '1') ? 'selected' : '' ?>><?=T::active?></option>
                    <option value="0" <?= (empty($page['status']) || $page['status'] == '0') ? 'selected' : '' ?>><?=T::inactive?></option>
                </select>
            </div>
            <?php else: ?>
            <!-- Status Dropdown for Add Mode -->
            <div class="flex flex-col gap-1 min-w-[150px]">
                <label for="status" class="text-xs font-medium text-slate-600"><?=T::status?></label>
                <select name="status" id="status" form="cms-form" class="select input text-sm py-1.5 px-3">
                    <option value="1" selected><?=T::active?></option>
                    <option value="0"><?=T::inactive?></option>
                </select>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Tabs Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <!-- Tabs Navigation -->
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px overflow-x-auto">
                <button type="button" @click="switchTab('details')"
                        :class="activeTab === 'details' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">article</span>
                    <?= T::cms_details ?? 'CMS Details' ?>
                </button>

                <!-- REMOVED THE IF CONDITION HERE -->
                <button type="button" @click="switchTab('translations')"
                        :class="activeTab === 'translations' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">translate</span>
                    <?= T::translations ?? 'Translations' ?>
                </button>
            </nav>
        </div>

        <!-- CMS Details Form -->
        <div x-show="activeTab === 'details'" style="display: none;" x-transition>
            <form id="cms-form" method="POST" action="<?= root . admin ?>/cms/pages/manage" @submit="submitCmsForm($event)" class="p-6">
                <?= CSRF::tokenField() ?>
                <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= $pageId ?>">
                <?php endif; ?>

                <div class="space-y-4">
                    <!-- Basic Information - Compact -->
                    <div class="card">
                        <!-- First Row: Page Name 70% + Slug URL 30% -->
                        <div class="grid grid-cols-1 md:grid-cols-10 gap-3">
                            <!-- Page Name - 70% -->
                            <div class="form-control md:col-span-7">
                                <label for="page_name" class="form-label required">
                                    <?= T::page_name ?? 'Page Name' ?>
                                </label>
                                <input type="text"
                                       id="page_name"
                                       name="page_name"
                                       x-ref="pageName"
                                       class="input"
                                       value="<?= htmlspecialchars($page['page_name'] ?? '') ?>"
                                       required
                                       @input="onPageNameInput($event)"
                                       placeholder="<?= T::page_name_help ?? 'Enter the display name of the page' ?>">
                            </div>

                            <!-- Slug URL - 30% -->
                            <div class="form-control md:col-span-3">
                                <label for="slug_url" class="form-label required">
                                    <?= T::slug_url ?? 'Slug URL' ?>
                                </label>
                                <input type="text"
                                       id="slug_url"
                                       name="slug_url"
                                       x-ref="slugUrl"
                                       class="input"
                                       value="<?= htmlspecialchars($page['slug_url'] ?? '') ?>"
                                       required
                                       @input="onSlugInput($event)"
                                       @keydown.space.prevent="
                                           const input = $event.target;
                                           const start = input.selectionStart;
                                           const end = input.selectionEnd;
                                           const value = input.value;
                                           const lastChar = value.charAt(start - 1);

                                           if (lastChar !== '-') {
                                               input.value = value.substring(0, start) + '-' + value.substring(end);
                                               input.setSelectionRange(start + 1, start + 1);
                                           }
                                       "
                                       placeholder="<?= T::slug_help ?? 'URL-friendly version (lowercase, hyphens only)' ?>">
                                <p class="text-xs text-slate-500 mt-1" x-show="!slugEdited">
                                    Auto-generated from page name
                                </p>
                            </div>
                        </div>

                        <!-- Second Row: Order + Parent Page + Position -->
                        <div class="grid grid-cols-1 md:grid-cols-10 gap-3 mt-3">
                            <!-- Order -->
                            <div class="form-control md:col-span-2">
                                <label for="order" class="form-label">
                                    <?= T::order ?? 'Order' ?>
                                </label>
                                <input type="number"
                                       id="order"
                                       name="order"
                                       class="input"
                                       value="<?= htmlspecialchars($page['order'] ?? '0') ?>"
                                       min="0"
                                       placeholder="0">
                            </div>

                            <!-- Parent Page -->
                            <div class="form-control md:col-span-5">
                                <label for="parent_id" class="form-label">
                                    <?= T::parent_page ?? 'Parent Page' ?>
                                </label>
                                <select name="parent_id" id="parent_id" class="select input">
                                    <option value=""><?= T::no_parent ?? 'None (Top Level)' ?></option>
                                    <?php foreach ($parentPages as $parentPage): ?>
                                    <?php
                                        $label = htmlspecialchars($parentPage['page_name']);
                                        
                                        // Add parent indicator if this page has children
                                        if (in_array($parentPage['id'], $parentIdsArray)) {
                                            $positions = [
                                                'header' => T::header ?? 'Header',
                                                'footer' => T::footer ?? 'Footer',
                                                'headerfooter' => T::header_footer ?? 'Header & Footer'
                                            ];
                                            $positionLabel = $positions[$parentPage['position']] ?? ucfirst($parentPage['position']);
                                            $label .= " (Parent: {$positionLabel})";
                                        }
                                    ?>
                                    <option value="<?= $parentPage['id'] ?>" <?= ($page['parent_id'] ?? '') == $parentPage['id'] ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Position -->
                            <div class="form-control md:col-span-3">
                                <label for="position" class="form-label">
                                    <?= T::position ?? 'Position' ?>
                                </label>
                                <select name="position" id="position" class="select input">
                                    <option value="header" <?= ($page['position'] ?? 'header') === 'header' ? 'selected' : '' ?>><?= T::header ?? 'Header' ?></option>
                                    <option value="footer" <?= ($page['position'] ?? '') === 'footer' ? 'selected' : '' ?>><?= T::footer ?? 'Footer' ?></option>
                                    <option value="headerfooter" <?= ($page['position'] ?? '') === 'headerfooter' ? 'selected' : '' ?>><?= T::header_footer ?? 'Header & Footer' ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Content Editor - Full Width -->
                    <div class="card">
                        <div class="mb-3">
                            <label for="content" class="form-label text-lg font-semibold">
                                <?= T::content ?? 'Content' ?>
                            </label>
                            <p class="text-xs text-slate-500 mt-1">Create your page content using the visual editor below</p>
                        </div>

                        <textarea
                            id="content"
                            name="content"
                            class="tinymce-editor"><?= htmlspecialchars($page['content'] ?? '') ?></textarea>
                    </div>

                    <!-- Form Actions -->
                    <div class="card">
                        <div class="flex items-center justify-end gap-3">
                            <a href="<?= root . admin ?>/cms/pages" class="btn white">
                                <?= T::cancel ?? 'Cancel' ?>
                            </a>
                            <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                                <span x-show="!loading" class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg">save</span>
                                    <span><?= $isEdit ? (T::update_page ?? 'Update Page') : (T::add_page ?? 'Add Page') ?></span>
                                </span>
                                <span x-show="loading" class="flex items-center gap-2" style="display: none;">
                                    <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span><?= T::saving ?? 'Saving...' ?></span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Translations Tab -->
        <div x-show="activeTab === 'translations'" style="display: none;" x-transition class="p-6">
            <?php if ($isEdit): ?>
                <!-- SHOW TRANSLATIONS ONLY IF PAGE IS SAVED -->
                <?php include __DIR__.'/translate.php'; ?>
            <?php else: ?>
                <!-- SHOW MESSAGE WHEN PAGE IS NOT SAVED -->
                <div class="bg-gray-50 rounded-lg p-6 text-center">
                    <span class="material-symbols-outlined text-6xl text-gray-300">info</span>
                    <p class="text-gray-600 mt-4">
                        <?= T::save_page_first ?? 'Please save the page first before adding translations' ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- CKEditor 5 with Full Build -->
<script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/super-build/ckeditor.js"></script>
<script>
// Store all editor instances
const editorInstances = {
    main: null,
    translations: {
        content: {}
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
            '|', 'sourceEditing', 'showBlocks',
            '|', 'heading',
            '|', 'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor',
            '|', 'bold', 'italic', 'underline', 'strikethrough', 'subscript', 'superscript', 'code',
            '|', 'link', 'uploadImage', 'insertTable', 'blockQuote', 'mediaEmbed', 'codeBlock', 'htmlEmbed',
            '|', 'alignment',
            '|', 'bulletedList', 'numberedList', 'todoList',
            '|', 'outdent', 'indent',
            '|', 'imageInsert', 'highlight', 'removeFormat',
            '|', 'horizontalLine', 'pageBreak', 'specialCharacters',
            '|', 'findAndReplace', 'selectAll'
        ],
        shouldNotGroupWhenFull: true
    },
    heading: {
        options: [
            { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
            { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
            { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
            { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
            { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' },
            { model: 'heading5', view: 'h5', title: 'Heading 5', class: 'ck-heading_heading5' },
            { model: 'heading6', view: 'h6', title: 'Heading 6', class: 'ck-heading_heading6' }
        ]
    },
    fontSize: {
        options: [8, 9, 10, 11, 12, 14, 16, 18, 20, 22, 24, 26, 28, 36, 48, 72]
    },
    fontFamily: {
        options: [
            'default',
            'Arial, Helvetica, sans-serif',
            'Courier New, Courier, monospace',
            'Georgia, serif',
            'Lucida Sans Unicode, Lucida Grande, sans-serif',
            'Tahoma, Geneva, sans-serif',
            'Times New Roman, Times, serif',
            'Trebuchet MS, Helvetica, sans-serif',
            'Verdana, Geneva, sans-serif'
        ],
        supportAllValues: true
    },
    fontColor: {
        columns: 12,
        colors: [
            { color: '#000000', label: 'Black' },
            { color: '#ffffff', label: 'White', hasBorder: true },
            { color: '#ff0000', label: 'Red' },
            { color: '#00ff00', label: 'Green' },
            { color: '#0000ff', label: 'Blue' },
            { color: '#ffff00', label: 'Yellow' },
            { color: '#ff00ff', label: 'Magenta' },
            { color: '#00ffff', label: 'Cyan' },
            { color: '#808080', label: 'Gray' },
            { color: '#ffa500', label: 'Orange' },
            { color: '#800080', label: 'Purple' },
            { color: '#008000', label: 'Dark Green' }
        ]
    },
    fontBackgroundColor: {
        columns: 12,
        colors: [
            { color: '#000000', label: 'Black' },
            { color: '#ffffff', label: 'White', hasBorder: true },
            { color: '#ff0000', label: 'Red' },
            { color: '#00ff00', label: 'Green' },
            { color: '#0000ff', label: 'Blue' },
            { color: '#ffff00', label: 'Yellow' },
            { color: '#ff00ff', label: 'Magenta' },
            { color: '#00ffff', label: 'Cyan' },
            { color: '#808080', label: 'Gray' },
            { color: '#ffa500', label: 'Orange' },
            { color: '#800080', label: 'Purple' },
            { color: '#008000', label: 'Dark Green' }
        ]
    },
    image: {
        resizeUnit: 'px',
        toolbar: [
            'imageTextAlternative', '|',
            'imageStyle:inline', 'imageStyle:wrapText', 'imageStyle:breakText', '|',
            'toggleImageCaption', 'linkImage', '|',
            'resizeImage'
        ],
        styles: [
            'full',
            'side',
            'alignLeft',
            'alignCenter',
            'alignRight'
        ]
    },
    table: {
        contentToolbar: [
            'tableColumn', 'tableRow', 'mergeTableCells', '|',
            'tableProperties', 'tableCellProperties', '|',
            'toggleTableCaption'
        ]
    },
    list: {
        properties: {
            styles: true,
            startIndex: true,
            reversed: true
        }
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
            },
            toggleDownloadable: {
                mode: 'manual',
                label: 'Downloadable',
                attributes: {
                    download: 'file'
                }
            }
        }
    },
    mediaEmbed: {
        previewsInData: true
    },
    htmlSupport: {
        allow: [
            {
                name: /.*/,
                attributes: true,
                classes: true,
                styles: true
            }
        ]
    },
    simpleUpload: {
        uploadUrl: '<?= root . admin ?>/cms/upload-image',
        withCredentials: true,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('input[name="csrf_token"]').value
        }
    }
};

// Initialize Main Content Editor
document.addEventListener('DOMContentLoaded', function() {
    CKEDITOR.ClassicEditor
        .create(document.querySelector('#content'), ckEditorConfig)
        .then(editor => {
            editorInstances.main = editor;
            const editorElement = editor.ui.view.editable.element;
            editorElement.style.minHeight = '600px';
            editorElement.style.maxHeight = '1000px';
            console.log('Main CKEditor initialized');
        })
        .catch(error => {
            console.error('CKEditor error:', error);
        });
});

// Initialize Translation Editor Function
function initializeTranslationEditor(textarea, langCode) {
    if (!textarea || editorInstances.translations.content[langCode]) {
        return;
    }

    CKEDITOR.ClassicEditor
        .create(textarea, ckEditorConfig)
        .then(editor => {
            editorInstances.translations.content[langCode] = editor;
            const editorElement = editor.ui.view.editable.element;
            editorElement.style.minHeight = '500px';
            editorElement.style.maxHeight = '800px';
            console.log('Translation CKEditor initialized for: ' + langCode);
        })
        .catch(error => {
            console.error('Translation CKEditor error for ' + langCode + ':', error);
        });
}
</script>