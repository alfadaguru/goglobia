<?php
// app/views/admin/cms/translate.php
@$SECURE or die('Access Denied!');
?>

<!-- Original Content Card -->
<div class="card mb-6 p-0">
    <div class="card-header">
        <div>
            <span class="card-header-icon">description</span>
            <h3><?= T::original_content ?? 'Original Content' ?> (English)</h3>
        </div>
    </div>
    <div class="card-body">
        <div class="card-info-list">
            <div class="card-info-item">
                <span class="card-info-label"><?= T::page_name ?? 'Page Name' ?></span>
                <span class="card-info-value"><?= htmlspecialchars($cmsPage['page_name']) ?></span>
            </div>
            <div class="card-info-item">
                <span class="card-info-label"><?= T::content ?? 'Content' ?></span>
                <span class="card-info-value">
                    <?php if (isset($cmsPage['content']) && $cmsPage['content']): ?>
                        <div class="max-h-40 overflow-y-auto text-sm text-gray-700">
                            <?= mb_substr(strip_tags($cmsPage['content']), 0, 500) . (mb_strlen(strip_tags($cmsPage['content'])) > 500 ? '...' : '') ?>
                        </div>
                    <?php else: ?>
                        <em class="text-gray-400"><?= T::no_content ?? 'No content' ?></em>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Translations Form -->
<form method="POST" action="<?= root . admin ?>/cms/pages/translate/<?= $cmsPage['id'] ?>" @submit="submitTranslationForm($event)">
    <?= CSRF::tokenField() ?>
    
    <!-- Translations Card -->
    <div class="card mb-6 p-0">
        <div class="card-header">
            <div>
                <span class="card-header-icon text-purple-600">language</span>
                <h3><?= T::translations ?? 'Translations' ?></h3>
            </div>
        </div>
        <div class="card-body">
            
            <!-- Language Tabs -->
            <div class="border-b border-gray-200 mb-4">
                <nav class="flex -mb-px overflow-x-auto gap-2">
                    <?php 
                    $first = true;
                    foreach ($available_languages as $lang_code => $lang_data): 
                        $lang_name = $lang_data['name'] ?? $lang_code;
                        $has_page_name = !empty($page_name_translations[$lang_code]);
                        $has_content = !empty($content_translations[$lang_code]);
                        $has_any = $has_page_name || $has_content;
                    ?>
                        <button type="button" 
                                @click="switchTranslationTab('<?= $lang_code ?>')"
                                :class="activeTranslationTab === '<?= $lang_code ?>' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm flex items-center gap-2">
                            <span class="inline-block w-2 h-2 rounded-full <?= $has_any ? 'bg-green-500' : 'bg-gray-300' ?> mr-1" data-lang-indicator="<?= $lang_code ?>"></span>
                            <?= htmlspecialchars($lang_name) ?>
                        </button>
                    <?php 
                        $first = false;
                    endforeach; 
                    ?>
                </nav>
            </div>

            <!-- Tab Contents -->
            <?php 
            $first = true;
            foreach ($available_languages as $lang_code => $lang_data): 
                $lang_name = $lang_data['name'] ?? $lang_code;
                $page_name_value = $page_name_translations[$lang_code] ?? '';
                $content_value = $content_translations[$lang_code] ?? '';
                $has_translation = !empty($page_name_value) || !empty($content_value);
            ?>
                <div x-show="activeTranslationTab === '<?= $lang_code ?>'" 
                     style="<?= $first ? '' : 'display: none;' ?>" 
                     x-transition 
                     id="lang-<?= $lang_code ?>">
                    
                    <!-- Delete Translation Button -->
                    <div class="flex items-center justify-between mb-4 pb-4 border-b border-dashed border-gray-200 delete-section" style="<?= $has_translation ? '' : 'display:none;' ?>">
                        <span class="text-green-600 text-sm flex items-center gap-1"></span>
                        <button type="button" 
                                class="btn rose btn-sm"
                                @click="deleteTranslation('<?= $lang_code ?>', '<?= htmlspecialchars(addslashes($lang_name)) ?>')"
                                :disabled="deleting === '<?= $lang_code ?>'"
                                :class="deleting === '<?= $lang_code ?>' ? 'opacity-75 cursor-not-allowed' : ''">
                            <span x-show="deleting !== '<?= $lang_code ?>'" class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-base">delete</span>
                                <?= T::delete_translation ?? 'Delete Translation' ?>
                            </span>
                            <span x-show="deleting === '<?= $lang_code ?>'" class="flex items-center gap-1" style="display: none;">
                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <?= T::deleting ?? 'Deleting...' ?>
                            </span>
                        </button>
                    </div>
                    
                    <div class="form-control mb-4">
                        <label>
                            <?= T::page_name ?? 'Page Name' ?>
                        </label>
                        <input type="text" 
                               name="page_name_translations[<?= $lang_code ?>]" 
                               class="input" 
                               value="<?= htmlspecialchars($page_name_value) ?>"
                               placeholder="<?= T::enter_translation ?? 'Enter translation' ?>...">
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-lg font-semibold">
                            <?= T::content ?? 'Content' ?>
                        </label>
                        <p class="text-xs text-slate-500 mt-1"><?=T::create_page_content_visual_editor ?? 'Create your page content using the visual editor below'?></p>
                    </div>

                    <textarea
                        id="cms-trans-content-<?= $lang_code ?>"
                        name="content_translations[<?= $lang_code ?>]"
                        class="tinymce-editor"><?= htmlspecialchars($content_value) ?></textarea>
                </div>
            <?php 
                $first = false;
            endforeach; 
            ?>

        </div>
    </div>
    
    <!-- Action Buttons for Translations -->
    <div class="card">
        <div class="flex items-center justify-end gap-2">
            <a href="<?= root . admin ?>/cms/pages" class="btn light">
                <?= T::cancel ?? 'Cancel' ?>
            </a>
            <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                <span x-show="!loading" class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">save</span>
                    <span><?= T::save_translations ?? 'Save Translations' ?></span>
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
</form>