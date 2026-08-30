<?php
$setupData = calculateSetupProgress($db);
extract($setupData);

if ($progressPercentage < 100):

    if ($progressPercentage < 30) {
        $alertClass = 'bg-red-50 border-red-300';
        $iconColor = 'text-red-600';
        $textColor = 'text-red-800';
        $progressBarColor = 'bg-red-600';
        $buttonClass = 'bg-red-600 hover:bg-red-700 text-white';
        $icon = 'error';
        $message = T::critical_complete_essential_setup;
    } elseif ($progressPercentage < 70) {
        $alertClass = 'bg-amber-50 border-amber-300';
        $iconColor = 'text-amber-600';
        $textColor = 'text-amber-800';
        $progressBarColor = 'bg-amber-600';
        $buttonClass = 'bg-amber-600 hover:bg-amber-700 text-white';
        $icon = 'warning';
        $message = T::warning_important_configurations_pending;
    } else {
        $alertClass = 'bg-blue-50 border-blue-300';
        $iconColor = 'text-blue-600';
        $textColor = 'text-blue-800';
        $progressBarColor = 'bg-blue-600';
        $buttonClass = 'bg-blue-600 hover:bg-blue-700 text-white';
        $icon = 'info';
        $message = T::almost_there_complete_remaining;
    }
?>
<!-- Setup Progress Alert -->
<div class="border-b border-gray-200 <?= $alertClass ?>" x-data="{ showAlert: true }" x-show="showAlert" x-transition>
    <div class="container py-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4">

            <div class="flex items-start sm:items-center gap-3 sm:gap-4 flex-1 min-w-0">
                <span class="material-symbols-outlined <?= $iconColor ?> flex-shrink-0 text-xl sm:text-2xl mt-0.5 sm:mt-0"><?= $icon ?></span>

                <div class="flex-1 min-w-0">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 mb-2">
                        <h3 class="text-xs sm:text-sm font-semibold <?= $textColor ?>"><?=T::platform_setup?> <?= $progressPercentage ?>% <?=T::complete?></h3>
                        <span class="text-xs <?= $textColor ?> opacity-75"><?= $completedTasks ?> <?=T::of?> <?= $totalTasks ?> <?=T::tasks?></span>
                    </div>
                    <p class="text-xs <?= $textColor ?> opacity-90 mb-2 line-clamp-2 sm:line-clamp-none"><?= $message ?></p>

                    <div class="w-full sm:max-w-md bg-gray-200 rounded-full h-2">
                        <div class="<?= $progressBarColor ?> h-2 rounded-full transition-all duration-500" style="width: <?= $progressPercentage ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0 self-end sm:self-auto">
                <a href="<?= root ?>admin/get-started" class="<?= $buttonClass ?> px-3 sm:px-4 py-2 rounded-md text-xs sm:text-sm font-medium flex items-center gap-1.5 sm:gap-2 transition-colors">
                    <span class="material-symbols-outlined !text-base sm:!text-sm">play_arrow</span>
                    <span class="hidden sm:inline"><?=T::complete_setup?></span>
                    <span class="sm:hidden"><?=T::setup?></span>
                </a>
                <button @click="showAlert = false" class="text-gray-500 hover:text-gray-700 p-1 rounded-full hover:bg-gray-200 transition-colors flex-shrink-0 px-2">
                    <span class="material-symbols-outlined !text-base sm:!text-sm">close</span>
                </button>
            </div>

        </div>
    </div>
</div>

<?php endif; ?>