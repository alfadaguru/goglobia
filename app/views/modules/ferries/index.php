<?php
// ============================================================================
// FERRIES HOME PAGE
// ============================================================================
// Hero + search widget — same layout as bus/flights/rail (no extra title block).
// ============================================================================
@$SECURE or die('Access Denied!'); ?>

<section class="relative min-h-[400px] flex items-center justify-center pt-12 pb-16 sm:pt-0 sm:pb-0">
    <div class="absolute inset-0 z-0 overflow-hidden">
        <img src="<?= root ?>uploads/global/cover.png" alt="<?= T::ferries ?? 'Ferries' ?>" class="w-full h-full object-cover">
        <div class="absolute inset-0 bg-blue-700 bg-opacity-30"></div>
    </div>
    <div class="relative z-10 text-black container">
        <div class="p-6 rounded-lg bg-white shadow-lg mt-4">
            <?php include __DIR__ . '/ferries-search.php'; ?>
        </div>
    </div>
</section>
