<?php
$templateId = $_GET['id'] ?? 0;
$isEdit = $templateId > 0;

$template = [];
if ($isEdit) {
    $template = $db->get('notification_templates', '*', ['id' => $templateId]);
    if (!$template) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::template_not_found
        ];
        redirect(root . admin . '/notification-templates');
    }
}

$templateType = $template['type'] ?? 'email';
$bodyContent = $template['body'] ?? '';

$availablePlaceholders = [];
if (!empty($template['available_parameters'])) {
    $parameters = json_decode($template['available_parameters'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parameters)) {
        $availablePlaceholders = $parameters;
    }
}

if (empty($availablePlaceholders)) {
    $availablePlaceholders = [
        'user' => ['name', 'email'],
        'system' => ['site_name', 'site_url']
    ];
}

// Check if subject is required
$isSubjectRequired = $templateType === 'email';
?>

<div class="container my-4" x-data="{
    loading: false,
    selectedType: '<?= $templateType ?>',
    handleSubmit() {
        this.loading = true;
        
        const activeType = this.selectedType;
        const activeEditorId = `body_${activeType}`;
        const activeEditor = editors[activeEditorId];
        
        // Update the hidden input with the current content before submission
        const finalTextarea = document.getElementById('final_body');
        
        if (activeType === 'email' && activeEditor) {
            // For email - get content from CKEditor
            finalTextarea.value = activeEditor.getData();
        } else {
            // For SMS, WhatsApp, Push - get content from textarea
            const bodyTextarea = document.getElementById('body_' + activeType);
            if (bodyTextarea) {
                finalTextarea.value = bodyTextarea.value;
            }
        }
        
        console.log('Submitting data:', finalTextarea.value);
        
        return true;
    }
}">
    <form method="POST" action="<?= root . admin ?>/notification-templates/manage">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="id" value="<?= $templateId ?>">
        <input type="hidden" name="type" value="<?= $templateType ?>">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/notification-templates" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-xl font-bold text-slate-800">
                    <?= htmlspecialchars($template['name'] ?? T::edit_template) ?>
                </h1>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">notifications</span>
                        <?= htmlspecialchars(ucfirst($templateType)) ?>
                    </span>
                    <?php if (($template['status'] ?? '0') == '1'): ?>
                    <span class="badge success"><?= T::active ?></span>
                    <?php else: ?>
                    <span class="badge error"><?= T::inactive ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <div class="flex flex-col gap-1 min-w-[150px]">
                <label for="status" class="text-xs font-medium text-slate-600"><?= T::status ?></label>
                <select name="status" id="status" class="select input text-sm py-1.5 px-3">
                    <option value="1" <?= (!empty($template['status']) && $template['status'] == '1') ? 'selected' : '' ?>><?= T::active ?></option>
                    <option value="0" <?= (empty($template['status']) || $template['status'] == '0') ? 'selected' : '' ?>><?= T::inactive ?></option>
                </select>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="card p-6 space-y-6">
        <?php if ($isSubjectRequired): ?>
        <div class="">
            <div class="form-control">
                <label for="subject" class="form-label required">
                    <?= T::subject ?>
                </label>
                <input type="text"
                       id="subject"
                       name="subject"
                       class="input"
                       value="<?= htmlspecialchars($template['subject'] ?? '') ?>"
                       required
                       placeholder="<?= T::enter_notification_subject ?>">
            </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="subject" value="<?= htmlspecialchars($template['subject'] ?? '') ?>">
        <?php endif; ?>
        
        <input type="hidden" name="available_parameters" id="available_parameters" value='<?= htmlspecialchars($template['available_parameters'] ?? '') ?>'>

        <?php if (!empty($availablePlaceholders)): ?>
        <div class="">
            <h3 class="text-lg font-semibold text-slate-800 mb-4"><?= T::available_parameters ?></h3>
            
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-4 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <span class="material-symbols-outlined text-blue-400">info</span>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-700">
                            <strong><?= T::smart ?> <?= T::insertion ?>:</strong> <?= T::fields_will_be ?> <?= T::automatically_inserted ?> <?= T::where_you_are_currently_typing ?>.
                            <?php if ($isSubjectRequired): ?>
                            <br>• <?= T::if_typing_in ?> <strong><?= T::subject ?></strong> → <?= T::field_goes_to ?> <?= T::subject ?>
                            <?php endif; ?>
                            <br>• <?= T::if_typing_in ?> <strong><?= T::body_message ?></strong> → <?= T::field_goes_to ?> <?= T::active_editor ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-4" x-data="{
                openCategories: {
                    <?php 
                    $first = true;
                    foreach ($availablePlaceholders as $category => $placeholders): 
                        echo "'$category': " . ($first ? 'true' : 'true') . ",\n            ";
                        $first = false;
                    endforeach; 
                    ?>
                },
                toggleCategory(category) {
                    this.openCategories[category] = !this.openCategories[category];
                }
            }">
                <?php foreach ($availablePlaceholders as $category => $placeholders): ?>
                <div class="border border-gray-200 rounded-lg">
                    <button type="button" 
                            class="w-full px-4 py-3 text-left hover:bg-gray-50 focus:outline-none focus:bg-gray-50 transition-colors flex items-center justify-between"
                            @click="toggleCategory('<?= $category ?>')">
                        <h4 class="font-medium text-gray-900 capitalize text-base flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg transform transition-transform" 
                                :class="openCategories['<?= $category ?>'] ? 'rotate-90' : ''">
                                chevron_right
                            </span>
                            <?= $category ?>
                        </h4>
                        <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded">
                            <?= count($placeholders) ?> <?= T::fields ?>
                        </span>
                    </button>
                    
                    <div class="px-4 pb-3" x-show="openCategories['<?= $category ?>']" x-transition>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($placeholders as $placeholder): ?>
                            <button type="button" 
                                    class="placeholder-item inline-flex items-center px-3 py-1 rounded-md text-xs font-medium bg-blue-100 text-blue-800 hover:bg-blue-200 transition-colors cursor-pointer"
                                    data-placeholder="{$<?= $category ?>.<?= $placeholder ?>}"
                                    title="<?= T::click_to_insert ?> {$<?= $category ?>.<?= $placeholder ?>}">
                                {$<?= $category ?>.<?= $placeholder ?>}
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($templateType === 'email'): ?>
        <div class="">
            <div class="mb-3">
                <label class="form-label text-lg font-semibold">
                    <?= T::email_content ?>
                </label>
                <p class="text-xs text-slate-500 mt-1"><?= T::create_your_email_content ?> <?= T::using_the_visual_editor_below ?></p>
            </div>

            <div id="email-editor-container">
                <textarea id="body_email" class="hidden"><?= htmlspecialchars($bodyContent) ?></textarea>
            </div>
        </div>
        <?php else: ?>
        <div class="">
            <div class="mb-3">
                <label class="form-label text-lg font-semibold">
                    <?= 
                        $templateType === 'sms' ? T::sms_content : 
                        ($templateType === 'whatsapp' ? T::whatsapp_content : 
                        T::push_notification_content)
                    ?>
                </label>
                <p class="text-xs text-slate-500 mt-1">
                    <?= 
                        $templateType === 'sms' ? T::create_your_sms_content : 
                        ($templateType === 'whatsapp' ? T::create_your_whatsapp_content : 
                        T::create_your_push_notification_content)
                    ?>
                </p>
            </div>

            <div class="form-control">
                <textarea name="body" 
                          id="body_<?= $templateType ?>" 
                          class="input min-h-[200px] resize-vertical" 
                          rows="8"
                          placeholder="<?= 
                            $templateType === 'sms' ? T::enter_sms_content : 
                            ($templateType === 'whatsapp' ? T::enter_whatsapp_content : 
                            T::enter_push_notification_content)
                          ?>"><?= htmlspecialchars($bodyContent) ?></textarea>
            </div>
        </div>
        <?php endif; ?>

        <input type="hidden" name="body" id="final_body" value="<?= htmlspecialchars($bodyContent) ?>">

        <div class="">
            <div class="flex items-center justify-end gap-3">
                <a href="<?= root . admin ?>/notification-templates" class="btn white">
                    <?= T::cancel ?>
                </a>
                <button type="submit" class="btn" :disabled="loading">
                    <span x-show="!loading" class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">save</span>
                        <span><?= T::update_template ?></span>
                    </span>
                    <span x-show="loading" class="flex items-center gap-2" style="display: none;">
                        <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span><?= T::saving ?></span>
                    </span>
                </button>
            </div>
        </div>
    </div>
    </form>
</div>

<script>
let editors = {};
let lastActiveField = 'body';
let currentActiveEditor = null;

document.addEventListener('DOMContentLoaded', function() {
    // Initialize editor only for email type
    <?php if ($templateType === 'email'): ?>
    initializeEditorForType('email');
    <?php endif; ?>

    function initializeEditorForType(type) {
        const editorId = `body_${type}`;
        const containerId = `${type}-editor-container`;
        
        const textarea = document.getElementById(editorId);
        const container = document.getElementById(containerId);
        
        if (textarea && container) {
            container.innerHTML = '<div id="' + editorId + '_editor"></div>';
            
            CKEDITOR.ClassicEditor
                .create(document.getElementById(editorId + '_editor'), {
                    initialData: textarea.value,
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
                            '|', 'bold', 'italic', 'underline',
                            '|', 'link', 'insertTable', 'blockQuote', 'mediaEmbed',
                            '|', 'bulletedList', 'numberedList',
                            '|', 'alignment',
                            '|', 'outdent', 'indent'
                        ],
                        shouldNotGroupWhenFull: true
                    },
                    heading: {
                        options: [
                            { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                            { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                            { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                            { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
                        ]
                    }
                })
                .then(newEditor => {
                    editors[editorId] = newEditor;
                    currentActiveEditor = newEditor;

                    const editorElement = newEditor.ui.view.editable.element;
                    
                    // Update textarea and hidden input when editor content changes
                    newEditor.model.document.on('change:data', () => {
                        document.getElementById('final_body').value = newEditor.getData();
                    });
                    
                    // Track focus for placeholder insertion
                    newEditor.ui.focusTracker.on('change:isFocused', (evt, name, isFocused) => {
                        if (isFocused) {
                            lastActiveField = editorId;
                            currentActiveEditor = newEditor;
                        }
                    });
                    
                    newEditor.editing.view.document.on('click', () => {
                        lastActiveField = editorId;
                        currentActiveEditor = newEditor;
                    });
                })
                .catch(error => {
                    console.error('CKEditor error for ' + editorId + ':', error);
                });
        }
    }

    const subjectInput = document.getElementById('subject');
    if (subjectInput) {
        subjectInput.addEventListener('focus', function() {
            lastActiveField = 'subject';
            currentActiveEditor = null;
        });
        
        subjectInput.addEventListener('click', function() {
            lastActiveField = 'subject';
            currentActiveEditor = null;
        });
    }

    // For non-email types, update the hidden input when typing
    <?php if ($templateType !== 'email'): ?>
    const bodyTextarea = document.getElementById('body_<?= $templateType ?>');
    if (bodyTextarea) {
        bodyTextarea.addEventListener('input', function() {
            document.getElementById('final_body').value = this.value;
        });
        
        // Also update on blur to ensure content is saved
        bodyTextarea.addEventListener('blur', function() {
            document.getElementById('final_body').value = this.value;
        });
        
        // Set focus tracking for non-email textareas
        bodyTextarea.addEventListener('focus', function() {
            lastActiveField = 'body_<?= $templateType ?>';
            currentActiveEditor = null;
        });
        
        bodyTextarea.addEventListener('click', function() {
            lastActiveField = 'body_<?= $templateType ?>';
            currentActiveEditor = null;
        });
    }
    <?php endif; ?>

    document.querySelectorAll('.placeholder-item').forEach(item => {
        item.addEventListener('click', function() {
            const placeholder = this.getAttribute('data-placeholder');
            
            if (lastActiveField === 'subject') {
                const subjectInput = document.getElementById('subject');
                const currentPos = subjectInput.selectionStart || subjectInput.value.length;
                const currentValue = subjectInput.value;
                const newValue = currentValue.slice(0, currentPos) + placeholder + currentValue.slice(currentPos);
                subjectInput.value = newValue;
                subjectInput.focus();
                subjectInput.setSelectionRange(currentPos + placeholder.length, currentPos + placeholder.length);
                
                this.classList.add('bg-green-200', 'text-green-900');
                setTimeout(() => {
                    this.classList.remove('bg-green-200', 'text-green-900');
                }, 300);
                
            } else if (currentActiveEditor) {
                // For email editor
                currentActiveEditor.model.change(writer => {
                    const insertPosition = currentActiveEditor.model.document.selection.getFirstPosition();
                    writer.insertText(placeholder, insertPosition);
                });
                
                this.classList.add('bg-green-200', 'text-green-900');
                setTimeout(() => {
                    this.classList.remove('bg-green-200', 'text-green-900');
                }, 300);
            } else {
                // For SMS, WhatsApp, Push - insert into textarea
                const bodyTextarea = document.getElementById('body_<?= $templateType ?>');
                if (bodyTextarea) {
                    const currentPos = bodyTextarea.selectionStart || bodyTextarea.value.length;
                    const currentValue = bodyTextarea.value;
                    const newValue = currentValue.slice(0, currentPos) + placeholder + currentValue.slice(currentPos);
                    bodyTextarea.value = newValue;
                    bodyTextarea.focus();
                    bodyTextarea.setSelectionRange(currentPos + placeholder.length, currentPos + placeholder.length);
                    
                    this.classList.add('bg-green-200', 'text-green-900');
                    setTimeout(() => {
                        this.classList.remove('bg-green-200', 'text-green-900');
                    }, 300);
                    
                    // Update the hidden input immediately after insertion
                    document.getElementById('final_body').value = bodyTextarea.value;
                }
            }
        });
    });
});
</script>

<?php if ($templateType === 'email'): ?>
<style>
    .ck-restricted-editing_mode_standard {
        min-height: 300px;
    }
</style>
<script src="https://cdn.ckeditor.com/ckeditor5/41.0.0/super-build/ckeditor.js"></script>
<?php endif; ?>