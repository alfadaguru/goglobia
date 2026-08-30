<?php
$setupData = calculateSetupProgress($db);
extract($setupData);
?>

<!-- Get Started Page -->
<div class="min-h-screen">

    <div class="border-b border-gray-200 bg-slate-50">
        <div class="container py-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900"><?=T::get_started?></h1>
                    <p class="text-sm text-gray-600 mt-1"><?=T::complete_platform_setup?></p>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-bold text-blue-600"><?= $progressPercentage ?>%</div>
                    <div class="text-xs text-gray-600"><?= $completedTasks ?> <?=T::of?> <?= $totalTasks ?> <?=T::completed?></div>
                </div>
            </div>
            <div class="mt-4">
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-blue-600 h-2 rounded-full transition-all" style="width: <?= $progressPercentage ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-6 py-6">
        <div class="max-w-7xl mx-auto space-y-6">

            <?php if ($completedCount > 0): ?>
            <div class="card p-0">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">check_circle</span>
                        <h3><?=T::completed_setup?></h3>
                    </div>
                    <div>
                        <span class="text-sm text-green-600"><?= $completedCount ?> <?=T::tasks_completed?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($tasks as $t): ?>
                            <?php if ($t['completed']): ?>
                            <div class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-green-600">check_circle</span>
                                    <div>
                                        <div class="font-medium text-gray-900"><?= htmlspecialchars($t['title']) ?></div>
                                        <div class="text-sm text-gray-600"><?= htmlspecialchars($t['desc']) ?></div>
                                    </div>
                                </div>
                                <a href="<?= $t['link'] ?>" class="btn emerald text-sm">
                                    <span class="material-symbols-outlined text-sm">edit</span>
                                    <?=T::edit?>
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($remainingCount > 0): ?>
            <div class="card p-0">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">pending</span>
                        <h3><?=T::remaining_setup_tasks?></h3>
                    </div>
                    <div>
                        <span class="text-sm text-amber-600"><?= $remainingCount ?> <?=T::tasks_pending?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($tasks as $t): ?>
                            <?php if (!$t['completed']): ?>
                                <?php
                                $severity = $t['severity'];
                                $icon = 'radio_button_unchecked';
                                $bg = 'bg-white';
                                $border = 'border-gray-300';
                                $iconColor = 'text-gray-400';
                                $ctaClass = 'btn text-sm';
                                if ($severity === 'critical') {
                                    $bg = 'bg-red-50';
                                    $border = 'border-red-300';
                                    $iconColor = 'text-red-600';
                                    $ctaClass = 'btn text-sm bg-red-600 hover:bg-red-700 text-white';
                                    $icon = 'error';
                                } elseif ($severity === 'warning') {
                                    $bg = 'bg-amber-50';
                                    $border = 'border-amber-300';
                                    $iconColor = 'text-amber-600';
                                    $ctaClass = 'btn text-sm bg-amber-600 hover:bg-amber-700 text-white';
                                    $icon = 'warning';
                                } elseif ($severity === 'neutral') {
                                    $bg = 'bg-white';
                                    $border = 'border-gray-300';
                                    $iconColor = 'text-gray-400';
                                    $ctaClass = 'btn text-sm';
                                    $icon = 'radio_button_unchecked';
                                }
                                ?>
                                <div class="flex items-center justify-between p-4 <?= $bg ?> border <?= $border ?> rounded-lg hover:border-blue-500 transition-colors">
                                    <div class="flex items-center gap-3">
                                        <span class="material-symbols-outlined <?= $iconColor ?>"><?= $icon ?></span>
                                        <div>
                                            <div class="font-medium text-gray-900"><?= htmlspecialchars($t['title']) ?></div>
                                            <div class="text-sm <?= ($severity === 'critical' ? 'text-red-600' : ($severity === 'warning' ? 'text-amber-600' : 'text-gray-600')) ?>"><?= htmlspecialchars($t['desc']) ?></div>
                                        </div>
                                    </div>
                                    <a href="<?= $t['link'] ?>" class="<?= $ctaClass ?>">
                                        <span class="material-symbols-outlined text-sm"><?= ($severity === 'critical' ? 'payment' : ($severity === 'warning' ? 'email' : 'settings')) ?></span>
                                        <?= $t['completed'] ? T::edit : T::setup ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($progressPercentage >= 100): ?>
            <div class="card p-0">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon text-green-600">check_circle</span>
                        <h3>🎉 <?=T::setup_complete?></h3>
                    </div>
                </div>
                <div class="card-body">
                    <div class="text-center py-8">
                        <span class="material-symbols-outlined text-green-600 text-6xl mb-4">celebration</span>
                        <h3 class="text-2xl font-bold text-gray-900 mb-2"><?=T::congratulations?></h3>
                        <p class="text-gray-600 mb-6"><?=T::platform_fully_configured?></p>
                        <a href="<?=root?>admin/dashboard" class="btn">
                            <span class="material-symbols-outlined text-sm">dashboard</span>
                            <?=T::go_to_dashboard?>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card p-0">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">info</span>
                        <h3><?=T::system_information?></h3>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">

                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-blue-600">code</span>
                                <div class="font-medium text-gray-900"><?=T::php_version?></div>
                            </div>
                            <div class="text-2xl font-bold text-gray-900"><?=phpversion()?></div>
                        </div>

                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-green-600">storage</span>
                                <div class="font-medium text-gray-900"><?=T::mysql_version?></div>
                            </div>
                            <div class="text-2xl font-bold text-gray-900">
                                <?php
                                $mysql_version = $db->query("SELECT VERSION() as version")->fetch();
                                echo $mysql_version['version'] ?? 'N/A';
                                ?>
                            </div>
                        </div>

                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-purple-600">memory</span>
                                <div class="font-medium text-gray-900"><?=T::memory_limit?></div>
                            </div>
                            <div class="text-2xl font-bold text-gray-900"><?=ini_get('memory_limit')?></div>
                        </div>

                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-orange-600">upload_file</span>
                                <div class="font-medium text-gray-900"><?=T::max_upload?></div>
                            </div>
                            <div class="text-2xl font-bold text-gray-900"><?=ini_get('upload_max_filesize')?></div>
                        </div>

                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-indigo-600">schedule</span>
                                <div class="font-medium text-gray-900"><?=T::max_execution?></div>
                            </div>
                            <div class="text-2xl font-bold text-gray-900"><?=ini_get('max_execution_time')?>s</div>
                        </div>

                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-red-600">web</span>
                                <div class="font-medium text-gray-900"><?=T::web_server?></div>
                            </div>
                            <div class="text-lg font-bold text-gray-900"><?=$_SERVER['SERVER_SOFTWARE'] ?? 'N/A'?></div>
                        </div>

                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-teal-600">computer</span>
                                <div class="font-medium text-gray-900"><?=T::os?></div>
                            </div>
                            <div class="text-lg font-bold text-gray-900"><?=PHP_OS?></div>
                        </div>

                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-amber-600">folder</span>
                                <div class="font-medium text-gray-900"><?=T::document_root?></div>
                            </div>
                            <div class="text-xs font-medium text-gray-600 break-all"><?=$_SERVER['DOCUMENT_ROOT'] ?? 'N/A'?></div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="card p-0">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">help</span>
                        <h3><?=T::need_help?></h3>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-sm text-gray-600 mb-4"><?=T::access_documentation_contact_support?></p>
                    <div class="flex flex-wrap gap-3">
                        <a href="https://docs.phptravels.com" class="btn flex items-center gap-2">
                            <span class="material-symbols-outlined">menu_book</span>
                            <?=T::documentation?>
                        </a>
                        <a href="https://app.phptravels.com" class="btn light flex items-center gap-2">
                            <span class="material-symbols-outlined">support_agent</span>
                            <?=T::contact_support?>
                        </a>
                        <a href="https://www.youtube.com/@PhptravelsOfficial" class="btn light flex items-center gap-2">
                            <span class="material-symbols-outlined">video_library</span>
                            <?=T::video_tutorials?>
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>