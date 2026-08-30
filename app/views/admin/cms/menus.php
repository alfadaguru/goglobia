<?php
// app/views/admin/cms/menus.php
@$SECURE or die('Access Denied!');
?>

<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css">
<div class="container my-4">
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-<?= $_SESSION['message']['type'] === 'success' ? 'success' : 'danger' ?> mb-4">
            <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="flex flex-col lg:flex-row min-h-screen">
        <!-- Left Sidebar -->
        <div class="w-full lg:w-80 bg-white border-b lg:border-b-0 lg:border-r border-gray-300 p-4 lg:p-6 overflow-y-auto">
            <h2 class="text-xl font-semibold mb-4"><?= T::add_menu_items ?? 'Add Menu Items' ?></h2>

            <!-- Add Menu Item Form (Inline) -->
            <div class="mb-6">
                <div class="bg-gray-50 border border-gray-300 rounded-md">
                    <div class="px-4 py-3 font-medium border-b border-gray-200">
                        <span><?= T::menu_item ?? 'Menu Item' ?></span>
                    </div>

                    <div id="add-menu-item-form" class="p-4">
                        <!-- Menu Category Selection -->
                        <div class="form-control mb-4">
                            <label><?= T::menu_category ?? 'Menu Category' ?></label>
                            <select id="inline-menu-category" class="select w-full" onchange="toggleInlineParentOptions()">
                                <option value="parent"><?= T::main_menu_parent ?? 'Main Menu (Parent)' ?></option>
                                <option value="child"><?= T::sub_menu_child ?? 'Sub Menu (Child)' ?></option>
                            </select>
                        </div>

                        <div class="form-control mb-4">
                            <label><?= T::add_to_menu ?? 'Add to Menu' ?></label>
                            <select id="inline-position" class="select w-full" onchange="updateInlineParentOptions()">
                                <option value="header"><?= T::header_menu ?? 'Header Menu' ?></option>
                                <option value="footer"><?= T::footer_menu ?? 'Footer Menu' ?></option>
                                <option value="headerfooter"><?= T::header_footer_menu ?? 'Header & Footer Menu' ?></option>
                            </select>
                        </div>

                        <!-- Parent Menu Selection (only for child items) -->
                        <div class="form-control mb-4 hidden" id="inline-parent-container">
                            <label><?= T::parent_menu_item ?? 'Parent Menu Item' ?></label>
                            <select id="inline-parent-select" class="select w-full">
                                <option value="0">-- <?= T::select_parent_menu ?? 'Select Parent Menu' ?> --</option>
                            </select>
                        </div>

                        <div class="form-control mb-4">
                            <label><?= T::link_type ?? 'Link Type' ?></label>
                            <select id="inline-link-type" class="select w-full" onchange="toggleInlineLinkFields()">
                                <option value="internal"><?= T::internal_link ?? 'Internal Link' ?></option>
                                <option value="external"><?= T::external_link ?? 'External Link' ?></option>
                            </select>
                        </div>

                        <!-- Internal Link Fields -->
                        <div id="inline-internal-fields">
                            <div class="form-control mb-4">
                                <label><?= T::select_page ?? 'Select Page' ?></label>
                                <select id="inline-page" class="select w-full">
                                    <option value="">-- <?= T::select_page ?? 'Select Page' ?> --</option>
                                    <?php if (!empty($all_pages)): ?>
                                        <?php foreach ($all_pages as $page): ?>
                                            <option value="<?= $page['slug_url'] ?>" data-slug="<?= htmlspecialchars($page['slug_url']) ?>" data-name="<?= htmlspecialchars($page['page_name']) ?>">
                                                <?= htmlspecialchars($page['page_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <!-- External Link Fields -->
                        <div id="inline-external-fields" class="hidden">
                            <div class="form-control mb-4">
                                <label>URL</label>
                                <input type="text" id="inline-url" class="input w-full" placeholder="https://example.com">
                                <small class="text-gray-500 text-xs"><?= T::enter_full_url ?? 'Enter full URL including https://' ?></small>
                            </div>
                        </div>

                        <div class="form-control mb-4">
                            <label><?= T::link_text ?? 'Link Text' ?></label>
                            <input type="text" id="inline-link-text" class="input w-full" placeholder="<?= T::menu_item ?? 'Menu Item' ?>">
                        </div>

                        <div class="form-control mb-4">
                            <label><?= T::open_link_in ?? 'Open Link In' ?></label>
                            <select id="inline-target" class="select w-full">
                                <option value="_self"><?= T::same_window ?? 'Same Window' ?></option>
                                <option value="_blank"><?= T::new_window ?? 'New Window' ?></option>
                            </select>
                        </div>

                        <button class="btn w-full" onclick="addMenuItemInline()">
                            <span class="material-symbols-outlined text-base mr-2">add</span>
                            <?= T::add_to_menu ?? 'Add to Menu' ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="flex-1 p-4 lg:p-8">
            <div class="max-w-5xl">
                <h1 class="text-3xl font-semibold mb-6"><?= T::menus_management ?? 'Menus Management' ?></h1>
                <p class="text-gray-500 mb-4 text-sm"><?= T::drag_instructions ?? 'Drag and drop items to reorder. Drop on another item to create a submenu.' ?></p>

                <!-- Header & Footer Menu -->
                <div class="card mb-6 p-0">
                    <div class="card-header">
                        <div>
                            <h3><?= T::header_footer_menu ?? 'Header & Footer Menu' ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="headerfooter-menu-items" class="menu-container min-h-24 p-2" data-position="headerfooter">
                            <?php if (!empty($cms_headerfooter_menu)): ?>
                                <?php foreach ($cms_headerfooter_menu as $item): ?>
                                    <?php if (empty($item['parent_id']) || $item['parent_id'] == '0'): ?>
                                        <?php renderMenuItem($item, $cms_headerfooter_menu); ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-400 italic"><?= T::no_items_header_footer ?? 'No items in header & footer menu' ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Header Menu -->
                <div class="card mb-6 p-0">
                    <div class="card-header">
                        <div>
                            <h3><?= T::header_menu ?? 'Header Menu' ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="header-menu-items" class="menu-container min-h-24 p-2" data-position="header">
                            <?php if (!empty($cms_header_menu)): ?>
                                <?php foreach ($cms_header_menu as $item): ?>
                                    <?php if (empty($item['parent_id']) || $item['parent_id'] == '0'): ?>
                                        <?php renderMenuItem($item, $cms_header_menu); ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-400 italic"><?= T::no_items_header ?? 'No items in header menu' ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Footer Menu -->
                <div class="card mb-6 p-0">
                    <div class="card-header">
                        <div>
                            <h3><?= T::footer_menu ?? 'Footer Menu' ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="footer-menu-items" class="menu-container min-h-24 p-2" data-position="footer">
                            <?php if (!empty($cms_footer_menu)): ?>
                                <?php foreach ($cms_footer_menu as $item): ?>
                                    <?php if (empty($item['parent_id']) || $item['parent_id'] == '0'): ?>
                                        <?php renderMenuItem($item, $cms_footer_menu); ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-400 italic"><?= T::no_items_footer ?? 'No items in footer menu' ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <span id="saving-message" class="text-amber-500 hidden">
                        <span class="material-symbols-outlined text-base align-middle">sync</span>
                        <?= T::saving ?? 'Saving...' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Menu Item Modal -->
<div id="menuItemModal" class="modal-overlay hidden">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle"><?= T::edit_menu_item ?? 'Edit Menu Item' ?></h3>
            <button class="close-modal" onclick="closeMenuItemModal()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit-item-id" value="">
            <input type="hidden" id="edit-parent-id" value="0">

            <!-- Menu Category Selection -->
            <div class="form-control mb-4">
                <label><?= T::menu_category ?? 'Menu Category' ?></label>
                <select id="menu-category" class="select w-full" onchange="toggleParentMenuOptions()">
                    <option value="parent"><?= T::main_menu_parent ?? 'Main Menu (Parent)' ?></option>
                    <option value="child"><?= T::sub_menu_child ?? 'Sub Menu (Child)' ?></option>
                </select>
                <small class="text-gray-500 text-xs"><?= T::change_category_hint ?? 'Change to move item between main menu and submenu' ?></small>
            </div>

            <div class="form-control mb-4">
                <label><?= T::add_to_menu ?? 'Add to Menu' ?></label>
                <select id="link-position" class="select w-full" onchange="onPositionChange()">
                    <option value="header"><?= T::header_menu ?? 'Header Menu' ?></option>
                    <option value="footer"><?= T::footer_menu ?? 'Footer Menu' ?></option>
                    <option value="headerfooter"><?= T::header_footer_menu ?? 'Header & Footer Menu' ?></option>
                </select>
            </div>

            <!-- Parent Menu Selection (only for child items) -->
            <div class="form-control mb-4 hidden" id="parent-menu-container">
                <label><?= T::parent_menu_item ?? 'Parent Menu Item' ?></label>
                <select id="parent-menu-select" class="select w-full">
                    <option value="0">-- <?= T::select_parent_menu ?? 'Select Parent Menu' ?> --</option>
                </select>
            </div>

            <div class="form-control mb-4">
                <label><?= T::link_type ?? 'Link Type' ?></label>
                <select id="link-type" class="select w-full" onchange="toggleLinkTypeFields()">
                    <option value="internal"><?= T::internal_link ?? 'Internal Link' ?></option>
                    <option value="external"><?= T::external_link ?? 'External Link' ?></option>
                </select>
            </div>

            <!-- Internal Link Fields -->
            <div id="internal-fields">
                <div class="form-control mb-4">
                    <label><?= T::select_page ?? 'Select Page' ?></label>
                    <select id="internal-page" class="select w-full">
                        <option value="">-- <?= T::select_page ?? 'Select Page' ?> --</option>
                        <?php if (!empty($all_pages)): ?>
                            <?php foreach ($all_pages as $page): ?>
                                <option value="<?= $page['slug_url'] ?>" data-slug="<?= htmlspecialchars($page['slug_url']) ?>">
                                    <?= htmlspecialchars($page['page_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <!-- External Link Fields -->
            <div id="external-fields" class="hidden">
                <div class="form-control mb-4">
                    <label>URL</label>
                    <input type="text" id="external-url" class="input w-full" placeholder="https://example.com">
                    <small class="text-gray-500 text-xs"><?= T::enter_full_url ?? 'Enter full URL including https://' ?></small>
                </div>
            </div>

            <div class="form-control mb-4">
                <label><?= T::link_text ?? 'Link Text' ?></label>
                <input type="text" id="link-text" class="input w-full" placeholder="<?= T::menu_item ?? 'Menu Item' ?>">
            </div>

            <div class="form-control mb-4">
                <label><?= T::open_link_in ?? 'Open Link In' ?></label>
                <select id="link-target" class="select w-full">
                    <option value="_self"><?= T::same_window ?? 'Same Window' ?></option>
                    <option value="_blank"><?= T::new_window ?? 'New Window' ?></option>
                </select>
            </div>
            <div class="modal-footer">
                <button class="btn light" onclick="closeMenuItemModal()"><?= T::cancel ?? 'Cancel' ?></button>
                <button class="btn" id="saveMenuItemBtn" onclick="updateMenuItem()"><?= T::update ?? 'Update' ?></button>
            </div>
        </div>
    </div>
</div>

<?php
// Helper function to render menu items recursively
function renderMenuItem($item, $all_items) {
    $children = array_filter($all_items, function($i) use ($item) {
        return $i['parent_id'] == $item['id'];
    });

    $hasChildren = !empty($children);
    $linkType = isset($item['link_type']) ? $item['link_type'] : 'page';
    $isParent = empty($item['parent_id']) || $item['parent_id'] == '0';
    $currentLang = $_SESSION['app_language'] ?? 'en';
    $translatedName = getTranslatedPageName($item, $currentLang);
    ?>
    <div class="menu-item bg-white border border-gray-300 rounded-md mb-2 p-2 sm:p-3" data-id="<?= $item['id'] ?>" data-parent="<?= $item['parent_id'] ?? '0' ?>" data-link-type="<?= $linkType ?>">
        <div class="flex items-center justify-between">
            <div class="flex items-center min-w-0 flex-1 mr-2">
                <span class="drag-handle cursor-move text-gray-400 hover:text-gray-600 mr-2 sm:mr-3 flex-shrink-0">⋮⋮</span>
                <?php if ($hasChildren): ?>
                    <button class="mr-1 sm:mr-2 text-gray-500 bg-transparent border-0 font-bold cursor-pointer p-1 flex-shrink-0" onclick="toggleSubmenu(<?= $item['id'] ?>)">
                        <span class="material-symbols-outlined toggle-icon-<?= $item['id'] ?> text-base">chevron_right</span>
                    </button>
                <?php else: ?>
                    <span class="mr-1 sm:mr-2 w-5 inline-block flex-shrink-0"></span>
                <?php endif; ?>
                <span class="menu-title font-medium truncate">
                    <?= htmlspecialchars($translatedName) ?>
                </span>
            </div>
            <div class="flex gap-2 flex-shrink-0">
                <button class="btn btn-sm btn-info menu-action-btn" onclick="editMenuItem(<?= $item['id'] ?>)" title="<?= T::edit ?? 'Edit' ?>">
                    <span class="material-symbols-outlined text-base">edit</span>
                </button>
                <button class="btn btn-sm rose menu-action-btn" onclick="deleteMenuItem(<?= $item['id'] ?>)" title="<?= T::delete ?? 'Delete' ?>">
                    <span class="material-symbols-outlined text-base">delete</span>
                </button>
            </div>
        </div>
        <?php if ($hasChildren): ?>
            <div class="submenu-container ml-4 sm:ml-8 mt-2" id="submenu-<?= $item['id'] ?>" style="display: none;">
                <?php foreach ($children as $child): ?>
                    <?php renderMenuItem($child, $all_items); ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="submenu-container ml-4 sm:ml-8 mt-2" style="display: none;"></div>
        <?php endif; ?>
    </div>
    <?php
}
?>

<!-- Minimal required styles for sortable and dynamic behaviors -->
<style>
.menu-action-btn {
    width: 2.25rem !important; /* w-9 = 36px */
    height: 2.25rem !important; /* h-9 = 36px */
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ui-sortable-helper {
    opacity: 0.8;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}
.ui-sortable-placeholder {
    visibility: visible !important;
    background: #e0f2fe;
    border: 2px dashed #0ea5e9;
    min-height: 50px;
    border-radius: 0.375rem;
}
.submenu-container.drop-zone-active {
    min-height: 30px !important;
    background: #f0f9ff;
    border: 1px dashed #93c5fd;
    border-radius: 0.375rem;
    margin-top: 0.5rem;
    padding: 0.25rem;
}
/* Utility classes that might not be in your framework */
.w-80 { width: 20rem; }
.min-h-24 { min-height: 6rem; }
/* Add this to your style section */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-overlay.hidden {
    display: none;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
const ADMIN_URL = '<?= root.admin ?>';

let allMenuItems = [];

$(document).ready(function() {
    console.log('Initializing menu manager...');
    collectAllMenuItems();

    function initSortable() {
        if (typeof $.fn.sortable === 'undefined') {
            console.error('jQuery UI Sortable is not loaded.');
            return;
        }

        const sortableOptions = {
            handle: '.drag-handle',
            placeholder: 'ui-sortable-placeholder',
            tolerance: 'pointer',
            cursor: 'move',
            connectWith: '.menu-container, .submenu-container',
            items: '> .menu-item',
            start: function(event, ui) {
                // Just make submenu containers available as drop zones without expanding visually
                $('.submenu-container').addClass('drop-zone-active');
            },
            stop: function(event, ui) {
                const $item = ui.item;
                const $parentContainer = $item.parent();

                // Remove drop zone styling
                $('.submenu-container').removeClass('drop-zone-active');

                if ($parentContainer.hasClass('menu-container')) {
                    $item.attr('data-parent', '0');
                    $item.data('parent', 0);
                } else if ($parentContainer.hasClass('submenu-container')) {
                    const $parentMenuItem = $parentContainer.closest('.menu-item');
                    const parentId = $parentMenuItem.data('id');
                    $item.attr('data-parent', parentId);
                    $item.data('parent', parentId);
                }

                // Save and refresh page
                saveMenuStructureAndRefresh();
            }
        };

        $('.menu-container').sortable(sortableOptions);
        $('.submenu-container').sortable(sortableOptions);
        $('.menu-container, .submenu-container').disableSelection();

        console.log('Sortable initialized successfully');
    }

    initSortable();
});

function collectAllMenuItems() {
    allMenuItems = [];
    $('.menu-item').each(function() {
        const id = $(this).data('id');
        const title = $(this).find('.menu-title').first().text().trim().replace(/External|Parent|Child/g, '').trim();
        const parent = $(this).data('parent');
        const position = $(this).closest('.menu-container').data('position');

        allMenuItems.push({
            id: id,
            title: title,
            position: position,
            parent: parent || 0
        });
    });
}

function populateParentMenus(currentPosition, excludeId = null) {
    const select = $('#parent-menu-select');
    select.empty();
    select.append('<option value="0">-- <?= T::select_parent_menu ?? 'Select Parent Menu' ?> --</option>');

    function getChildren(parentId, level) {
        let items = [];
        allMenuItems.forEach(item => {
            if (item.position === currentPosition &&
                item.id != excludeId &&
                (item.parent || 0) == parentId) {
                if (!isDescendantOf(item.id, excludeId)) {
                    const indent = '—'.repeat(level);
                    items.push({
                        id: item.id,
                        title: (level > 0 ? indent + ' ' : '') + item.title,
                        level: level
                    });
                    items = items.concat(getChildren(item.id, level + 1));
                }
            }
        });
        return items;
    }

    function isDescendantOf(itemId, potentialParentId) {
        if (!potentialParentId) return false;
        let found = false;
        function checkChildren(parentId) {
            allMenuItems.forEach(item => {
                if ((item.parent || 0) == parentId) {
                    if (item.id == potentialParentId) {
                        found = true;
                    } else {
                        checkChildren(item.id);
                    }
                }
            });
        }
        checkChildren(itemId);
        return found;
    }

    const hierarchicalItems = getChildren(0, 0);

    hierarchicalItems.forEach(item => {
        select.append(`<option value="${item.id}">${item.title}</option>`);
    });
}

function toggleParentMenuOptions() {
    const category = $('#menu-category').val();
    const parentContainer = $('#parent-menu-container');

    if (category === 'child') {
        parentContainer.removeClass('hidden');
        const position = $('#link-position').val();
        const editItemId = $('#edit-item-id').val();
        populateParentMenus(position, editItemId);
    } else {
        parentContainer.addClass('hidden');
        $('#parent-menu-select').val('0');
    }
}

function onPositionChange() {
    const category = $('#menu-category').val();
    if (category === 'child') {
        const position = $('#link-position').val();
        const editItemId = $('#edit-item-id').val();
        populateParentMenus(position, editItemId);
    }
}

function toggleInlineLinkFields() {
    const linkType = $('#inline-link-type').val();
    const urlField = $('#inline-url');

    if (linkType === 'internal') {
        $('#inline-internal-fields').removeClass('hidden');
        $('#inline-external-fields').addClass('hidden');
    } else {
        $('#inline-internal-fields').addClass('hidden');
        $('#inline-external-fields').removeClass('hidden');

        // Add https:// ONLY the first time IF empty
        let val = urlField.val().trim();
        if (val === "") {
            urlField.val("https://");
        }
    }
}

function toggleInlineParentOptions() {
    const category = $('#inline-menu-category').val();
    if (category === 'child') {
        $('#inline-parent-container').removeClass('hidden');
        updateInlineParentOptions();
    } else {
        $('#inline-parent-container').addClass('hidden');
        $('#inline-parent-select').val('0');
    }
}

function updateInlineParentOptions() {
    const position = $('#inline-position').val();
    const select = $('#inline-parent-select');
    select.empty();
    select.append('<option value="0">-- <?= T::select_parent_menu ?? 'Select Parent Menu' ?> --</option>');

    function getChildren(parentId, level) {
        let items = [];
        allMenuItems.forEach(item => {
            if (item.position === position && (item.parent || 0) == parentId) {
                const indent = '—'.repeat(level);
                items.push({
                    id: item.id,
                    title: (level > 0 ? indent + ' ' : '') + item.title
                });
                items = items.concat(getChildren(item.id, level + 1));
            }
        });
        return items;
    }

    const hierarchicalItems = getChildren(0, 0);

    hierarchicalItems.forEach(item => {
        select.append(`<option value="${item.id}">${item.title}</option>`);
    });
}

function addMenuItemInline() {
    const linkType = $('#inline-link-type').val();
    const linkText = $('#inline-link-text').val().trim();
    const target = $('#inline-target').val();
    const position = $('#inline-position').val();
    const category = $('#inline-menu-category').val();
    const parentId = category === 'child' ? $('#inline-parent-select').val() : '0';

    let url = '';
    let isExternal = false;

    if (linkType === 'internal') {
        const selectedPage = $('#inline-page').val();
        if (!selectedPage) {
            vt.warn('<?= T::please_select_page ?? 'Please select a page' ?>');
            return;
        }
        url = $('#inline-page option:selected').data('slug');
    } else {
        url = $('#inline-url').val().trim();
        if (!url) {
            vt.warn('<?= T::please_enter_url ?? 'Please enter a URL' ?>');
            return;
        }
        isExternal = true;
    }

    if (!linkText) {
        vt.warn('<?= T::please_enter_link_text ?? 'Please enter link text' ?>');
        return;
    }

    if (category === 'child' && (!parentId || parentId === '0')) {
        vt.warn('<?= T::please_select_parent_menu ?? 'Please select a parent menu' ?>');
        return;
    }

    $.ajax({
        url: ADMIN_URL + '/cms/menus/create-menu-item',
        method: 'POST',
        data: {
            title: linkText,
            url: url,
            target: target,
            position: position,
            parent_id: parentId,
            link_type: isExternal ? 'menu_item' : 'page'
        },
        success: function(response) {
            try {
                const result = JSON.parse(response);
                if (result.success) {
                    vt.success('<?= T::menu_item_added_success ?? 'Menu item added successfully' ?>');
                    $('#inline-link-text').val('');
                    $('#inline-url').val('');
                    $('#inline-page').val('');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    vt.error(result.message || '<?= T::failed_add_menu_item ?? 'Failed to add menu item' ?>');
                }
            } catch(e) {
                vt.success('<?= T::menu_item_added_success ?? 'Menu item added successfully' ?>');
                setTimeout(function() { location.reload(); }, 1000);
            }
        },
        error: function() {
            vt.error('<?= T::failed_add_menu_item_try_again ?? 'Failed to add menu item. Please try again.' ?>');
        }
    });
}

function closeMenuItemModal() {
    document.getElementById('menuItemModal').classList.add('hidden');
    resetModalForm();
}

function resetModalForm() {
    document.getElementById('edit-item-id').value = '';
    document.getElementById('edit-parent-id').value = '0';
    document.getElementById('link-type').value = 'internal';
    document.getElementById('menu-category').value = 'parent';
    document.getElementById('internal-page').value = '';
    document.getElementById('external-url').value = '';
    document.getElementById('link-text').value = '';
    document.getElementById('link-target').value = '_self';
    document.getElementById('link-position').value = 'header';
    document.getElementById('parent-menu-container').classList.add('hidden');
    toggleLinkTypeFields();
}

function toggleLinkTypeFields() {
    const linkType = document.getElementById('link-type').value;
    const internalFields = document.getElementById('internal-fields');
    const externalFields = document.getElementById('external-fields');

    if (linkType === 'internal') {
        internalFields.classList.remove('hidden');
        externalFields.classList.add('hidden');
    } else {
        internalFields.classList.add('hidden');
        externalFields.classList.remove('hidden');
    }
}

window.onclick = function(event) {
    const modal = document.getElementById('menuItemModal');
    if (event.target == modal) { closeMenuItemModal(); }
}

function editMenuItem(itemId) {
    console.log('Edit button clicked for item:', itemId); // Debug line

    // Check if jQuery is available
    if (typeof $ === 'undefined') {
        console.error('jQuery is not loaded!');
        return;
    }

    const modal = document.getElementById('menuItemModal');
    console.log('Modal element:', modal); // Debug line

    document.getElementById('modalTitle').textContent = '<?= T::edit_menu_item ?? 'Edit Menu Item' ?>';
    loadMenuItemData(itemId);
    modal.classList.remove('hidden');
}

function loadMenuItemData(itemId) {
    $.ajax({
        url: ADMIN_URL + '/cms/menus/get-item/' + itemId,
        method: 'GET',
        success: function(response) {
            try {
                const item = JSON.parse(response);
                if (item.success) {
                    const data = item.data;
                    document.getElementById('edit-item-id').value = data.id;
                    document.getElementById('edit-parent-id').value = data.parent_id || '0';
                    document.getElementById('link-text').value = data.page_name;
                    document.getElementById('link-target').value = data.target || '_self';
                    document.getElementById('link-position').value = data.position;

                    const isChild = data.parent_id && data.parent_id != '0';

                    if (isChild) {
                        document.getElementById('menu-category').value = 'child';
                        document.getElementById('parent-menu-container').classList.remove('hidden');
                        populateParentMenus(data.position, data.id);
                        $('#parent-menu-select').val(data.parent_id);
                    } else {
                        document.getElementById('menu-category').value = 'parent';
                        document.getElementById('parent-menu-container').classList.add('hidden');
                    }

                    if (data.link_type === 'custom' || data.link_type === 'external' || data.link_type === 'menu_item' || (data.slug_url && data.slug_url.startsWith('http'))) {
                        document.getElementById('link-type').value = 'external';
                        document.getElementById('external-url').value = data.slug_url;
                    } else {
                        document.getElementById('link-type').value = 'internal';
                        $('#internal-page option').each(function() {
                            if ($(this).data('slug') === data.slug_url) {
                                $(this).prop('selected', true);
                            }
                        });
                    }

                    toggleLinkTypeFields();
                }
            } catch(e) {
                console.error('Failed to load menu item data:', e);
            }
        }
    });
}

function updateMenuItem() {
    const itemId = $('#edit-item-id').val();
    const linkType = $('#link-type').val();
    const linkText = $('#link-text').val().trim();
    const linkTarget = $('#link-target').val();
    const position = $('#link-position').val();
    const category = $('#menu-category').val();
    const parentId = category === 'child' ? $('#parent-menu-select').val() : '0';

    let url = '';
    let isExternal = false;

    if (linkType === 'internal') {
        const selectedPage = $('#internal-page').val();
        if (!selectedPage) {
            vt.warn('<?= T::please_select_page ?? 'Please select a page' ?>');
            return;
        }
        url = $('#internal-page option:selected').data('slug');
    } else {
        url = $('#external-url').val().trim();
        if (!url) {
            vt.warn('<?= T::please_enter_url ?? 'Please enter a URL' ?>');
            return;
        }
        isExternal = true;
    }

    if (!linkText) {
        vt.warn('<?= T::please_enter_link_text ?? 'Please enter link text' ?>');
        return;
    }

    if (category === 'child' && (!parentId || parentId === '0')) {
        vt.warn('<?= T::please_select_parent_menu ?? 'Please select a parent menu' ?>');
        return;
    }

    $.ajax({
        url: ADMIN_URL + '/cms/menus/update-item',
        method: 'POST',
        data: {
            id: itemId,
            title: linkText,
            url: url,
            target: linkTarget,
            position: position,
            parent_id: parentId,
            link_type: isExternal ? 'menu_item' : 'page'
        },
        success: function(response) {
            try {
                const result = JSON.parse(response);
                if (result.success) {
                    vt.success('<?= T::menu_item_updated_success ?? 'Menu item updated successfully' ?>');
                    closeMenuItemModal();
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    vt.error(result.message || '<?= T::failed_update_menu_item ?? 'Failed to update menu item' ?>');
                }
            } catch(e) {
                vt.success('<?= T::menu_item_updated_success ?? 'Menu item updated successfully' ?>');
                closeMenuItemModal();
                setTimeout(function() { location.reload(); }, 1000);
            }
        },
        error: function() {
            vt.error('<?= T::failed_update_menu_item_try_again ?? 'Failed to update menu item. Please try again.' ?>');
        }
    });
}

function deleteMenuItem(itemId) {
    if (!confirm('<?= T::confirm_disable_menu_item ?? 'Are you sure you want to disable this menu item?' ?>')) {
        return;
    }

    $.ajax({
        url: ADMIN_URL + '/cms/menus/delete-item',
        method: 'POST',
        data: { id: itemId },
        success: function(response) {
            try {
                const result = JSON.parse(response);
                if (result.success) {
                    vt.success('<?= T::menu_item_disabled_success ?? 'Menu item disabled successfully' ?>');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    vt.error(result.message || '<?= T::failed_disable_menu_item ?? 'Failed to disable menu item' ?>');
                }
            } catch(e) {
                vt.success('<?= T::menu_item_disabled_success ?? 'Menu item disabled successfully' ?>');
                setTimeout(function() { location.reload(); }, 1000);
            }
        },
        error: function() {
            vt.error('<?= T::failed_disable_menu_item_try_again ?? 'Failed to disable menu item. Please try again.' ?>');
        }
    });
}

function toggleSubmenu(itemId) {
    const $item = $('[data-id="' + itemId + '"]');
    const $submenu = $item.find('> .submenu-container');
    const $toggle = $item.find('> div > button').first();
    const $icon = $toggle.find('.material-symbols-outlined');

    if ($submenu.children('.menu-item').length > 0) {
        $submenu.slideToggle(200, function() {
            if ($submenu.is(':visible')) {
                $icon.text('expand_more');
            } else {
                $icon.text('chevron_right');
            }
        });
    }
}

let autoSaveTimer = null;

function autoSaveMenuStructure() {
    if (autoSaveTimer) { clearTimeout(autoSaveTimer); }
    $('#saving-message').removeClass('hidden');
    autoSaveTimer = setTimeout(function() { saveMenuStructure(); }, 1000);
}

function saveMenuStructure() {
    const menuData = [];

    $('.menu-container').each(function() {
        const position = $(this).data('position');
        let orderIndex = 0;

        $(this).children('.menu-item').each(function() {
            collectMenuItems($(this), position, orderIndex, 0, menuData);
            orderIndex++;
        });
    });

    $.ajax({
        url: ADMIN_URL + '/cms/menus/update-structure',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(menuData),
        success: function(response) {
            try {
                const result = JSON.parse(response);
                $('#saving-message').addClass('hidden');
                if (result.success) {
                    vt.success('<?= T::menu_saved_auto ?? 'Menu saved' ?>');
                    collectAllMenuItems();
                } else {
                    vt.error(result.message || '<?= T::failed_save_menu ?? 'Failed to save menu' ?>');
                }
            } catch(e) {
                $('#saving-message').addClass('hidden');
                vt.success('<?= T::menu_saved_auto ?? 'Menu saved' ?>');
                collectAllMenuItems();
            }
        },
        error: function() {
            $('#saving-message').addClass('hidden');
            vt.error('<?= T::failed_save_menu_structure ?? 'Failed to save menu structure' ?>');
        }
    });
}

function saveMenuStructureAndRefresh() {
    const menuData = [];

    $('.menu-container').each(function() {
        const position = $(this).data('position');
        let orderIndex = 0;

        $(this).children('.menu-item').each(function() {
            collectMenuItems($(this), position, orderIndex, 0, menuData);
            orderIndex++;
        });
    });

    $('#saving-message').removeClass('hidden');

    $.ajax({
        url: ADMIN_URL + '/cms/menus/update-structure',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(menuData),
        success: function(response) {
            location.reload();
        },
        error: function() {
            $('#saving-message').addClass('hidden');
            vt.error('<?= T::failed_save_menu_structure ?? 'Failed to save menu structure' ?>');
        }
    });
}

function collectMenuItems($item, position, orderIndex, parentId, menuData) {
    const itemId = $item.data('id');

    menuData.push({
        id: itemId,
        position: position,
        order_index: orderIndex,
        parent_id: parentId
    });

    let childIndex = 0;
    $item.find('> .submenu-container > .menu-item').each(function() {
        collectMenuItems($(this), position, childIndex, itemId, menuData);
        childIndex++;
    });
}
</script>