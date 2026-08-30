<?php
// app/views/admin/notification-templates/templates.php

// Fetch all templates grouped by type and category
$templates = $db->select('notification_templates', '*', ['ORDER' => ['type' => 'ASC', 'group' => 'ASC', 'name' => 'ASC']]);

// Group by type and then by group
$groupedTemplates = [];
foreach ($templates as $template) {
    $groupedTemplates[$template['type']][$template['group']][] = $template;
}

$allTypes = array_keys($groupedTemplates);
$activeTab = $_GET['tab'] ?? ($allTypes[0] ?? 'email');

// Count templates by type
$templateCounts = [];
foreach ($templates as $template) {
    $type = $template['type'];
    if (!isset($templateCounts[$type])) {
        $templateCounts[$type] = 0;
    }
    $templateCounts[$type]++;
}

// Get unique groups from database
$allGroups = $db->select('notification_templates', 'group', ['GROUP' => 'group']);
?>

<div class="container my-4">
    <div class="max-w-6xl mx-auto">

        <!-- Success / Error Message -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
                <span class="material-symbols-outlined">
                    <?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?>
                </span>
                <div><?= $_SESSION['message']['text'] ?></div>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <!-- Header -->
        <div class="flex items-center justify-between mb-4">
            <div>
                <h1 class="text-xl font-bold text-slate-800"><?= T::notification_templates ?></h1>
                <p class="text-slate-600 text-sm mt-0.5"><?= T::manage_all_notification_templates ?></p>
            </div>
        </div>

        <!-- TABS -->
        <?php if (!empty($allTypes)): ?>
            <div class="mb-4">
                <nav class="flex space-x-4 border-b border-gray-200">
                    <?php foreach ($allTypes as $type): ?>
                        <button onclick="switchTemplateTab('<?= $type ?>')"
                                id="btn-<?= $type ?>"
                                class="tab-btn-template flex items-center gap-2 py-2.5 px-1 border-b-2 text-sm font-medium transition-colors
                                       <?= $type === $activeTab ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?>">

                            <span class="material-symbols-outlined" style="font-size: 18px;">
                                <?php
                                    echo $type === 'email' ? 'email'
                                         : ($type === 'whatsapp' ? 'chat'
                                         : ($type === 'sms' ? 'sms'
                                         : 'notifications'));
                                ?>
                            </span>
                            <span class="capitalize"><?= $type ?></span>
                            <span class="bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs font-semibold">
                                <?= $templateCounts[$type] ?? 0 ?>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </nav>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENTS -->
        <?php foreach ($allTypes as $type): ?>
            <div id="tab-<?= $type ?>" class="tab-content-template <?= $type === $activeTab ? 'block' : 'hidden' ?>">
                
                <?php if (isset($groupedTemplates[$type])): ?>
                    <div class="space-y-3">
                        <?php foreach ($groupedTemplates[$type] as $group => $groupTemplates): ?>
                            <!-- Group Card -->
                            <div class="card p-0 overflow-hidden border border-slate-200">
                                <!-- Group Header -->
                                <div class="bg-slate-50 px-4 py-2.5 border-b border-slate-200">
                                    <div class="flex items-center gap-2.5">
                                        <span class="material-symbols-outlined text-slate-600" style="font-size: 18px;">
                                            <?php
                                                echo $group === 'user_auth' ? 'lock'
                                                     : ($group === 'flights' ? 'flight'
                                                     : ($group === 'hotels' ? 'hotel'
                                                     : ($group === 'tours' ? 'tour'
                                                     : ($group === 'cars' ? 'directions_car'
                                                     : ($group === 'visa' ? 'assignment'
                                                     : ($group === 'newsletter' ? 'campaign'
                                                     : 'settings'))))));
                                            ?>
                                        </span>
                                        <h3 class="font-semibold text-slate-800 text-sm">
                                            <?= ucfirst(str_replace('_', ' ', $group)) ?>
                                        </h3>
                                        <span class="text-slate-500 text-xs">
                                            (<?= count($groupTemplates) ?> template<?= count($groupTemplates) > 1 ? 's' : '' ?>)
                                        </span>
                                    </div>
                                </div>

                                <!-- Templates List -->
                                <div class="">
                                    <?php foreach ($groupTemplates as $template): ?>
                                        <div class="px-4 py-1.5 hover:bg-slate-50 transition-colors">
                                            <div class="flex items-center justify-between gap-4">
                                                <div class="flex-1 min-w-0">
                                                    <h4 class="font-medium text-slate-800 text-sm truncate">
                                                        <?= htmlspecialchars($template['name']) ?>
                                                    </h4>
                                                </div>
                                                
                                                <div class="flex items-center gap-3 flex-shrink-0">
                                                    <!-- Status Toggle Switch -->
                                                    <form method="POST" action="<?= root . admin ?>/notification-templates/toggle-status" 
                                                        class="inline-flex">
                                                        <?= CSRF::tokenField() ?>
                                                        <input type="hidden" name="id" value="<?= $template['id'] ?>">
                                                        <input type="hidden" name="status" value="<?= $template['status'] == '1' ? '0' : '1' ?>">
                                                        <label class="switch-container switch-sm switch-blue">
                                                            <input type="checkbox" 
                                                                class="switch-input" 
                                                                <?= $template['status'] == '1' ? 'checked' : '' ?>
                                                                onchange="this.form.submit()">
                                                            <div class="switch-track">
                                                                <div class="switch-thumb"></div>
                                                            </div>
                                                        </label>
                                                    </form>
                                                    
                                                    <!-- Edit Button -->
                                                    <a href="<?= root . admin ?>/notification-templates/edit/<?= $template['id'] ?>" 
                                                       class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-700 text-sm font-medium transition-colors">
                                                        <span class="material-symbols-outlined" style="font-size: 16px;">edit</span>
                                                        <span><?= T::edit ?></span>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card p-8 text-center">
                        <span class="material-symbols-outlined text-slate-300 text-4xl mb-2 block">notifications_off</span>
                        <h3 class="text-base font-medium text-slate-600 mb-1">No templates found</h3>
                        <p class="text-slate-500 text-sm">No <?= $type ?> templates have been created yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (empty($allTypes)): ?>
            <div class="card p-8 text-center">
                <span class="material-symbols-outlined text-slate-300 text-4xl mb-2 block">notifications_off</span>
                <h3 class="text-base font-medium text-slate-600 mb-1">No notification templates</h3>
                <p class="text-slate-500 text-sm">No notification templates have been created yet.</p>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
// Tab Switching System
let currentActiveTemplateTab = '<?= $activeTab ?>';

function switchTemplateTab(tabName) {
    currentActiveTemplateTab = tabName;

    // Hide all tab contents
    document.querySelectorAll('.tab-content-template').forEach(tab => {
        tab.classList.add('hidden');
        tab.classList.remove('block');
    });

    // Remove active classes from all buttons
    document.querySelectorAll('.tab-btn-template').forEach(btn => {
        btn.classList.remove('border-blue-500', 'text-blue-600');
        btn.classList.add('border-transparent', 'text-gray-500');
    });

    // Show active tab
    const activeTab = document.getElementById('tab-' + tabName);
    if (activeTab) {
        activeTab.classList.remove('hidden');
        activeTab.classList.add('block');
    }

    // Highlight active button
    const activeBtn = document.getElementById('btn-' + tabName);
    if (activeBtn) {
        activeBtn.classList.remove('border-transparent', 'text-gray-500');
        activeBtn.classList.add('border-blue-500', 'text-blue-600');
    }

    // Update URL hash
    history.replaceState(null, null, '#' + tabName);
}

// Load tab from URL hash on page load
function initializeTemplateTabs() {
    const hash = window.location.hash.substring(1);
    const validTabs = <?= json_encode($allTypes) ?>;

    if (hash && validTabs.includes(hash)) {
        switchTemplateTab(hash);
    } else {
        const firstTab = validTabs[0];
        if (firstTab && firstTab !== currentActiveTemplateTab) {
            switchTemplateTab(firstTab);
        }
    }
}

// Event listeners
window.addEventListener('DOMContentLoaded', initializeTemplateTabs);
window.addEventListener('load', function() {
    setTimeout(initializeTemplateTabs, 100);
});
window.addEventListener('hashchange', function () {
    const hash = window.location.hash.substring(1);
    const validTabs = <?= json_encode($allTypes) ?>;

    if (hash && validTabs.includes(hash)) {
        switchTemplateTab(hash);
    }
});

// Toggle switch confirmation
document.addEventListener('submit', function(e) {
    const form = e.target.closest('form[action*="toggle-status"]');
    if (form) {
        const isActive = form.querySelector('input[name="status"]').value === '1';
        const templateName = form.closest('.hover\\:bg-slate-50').querySelector('h4').textContent.trim();
        
        if (!confirm(`Are you sure you want to ${isActive ? 'activate' : 'deactivate'} "${templateName}" template?`)) {
            e.preventDefault();
        }
    }
});
</script>