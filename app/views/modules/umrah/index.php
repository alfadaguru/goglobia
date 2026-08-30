<section class="relative min-h-[400px] flex items-center justify-center pt-12 pb-16 sm:pt-0 sm:pb-0">
  <div class="absolute inset-0 z-0 overflow-hidden">
    <img src="<?=root?>uploads/global/cover.png" alt="Umrah packages" class="w-full h-full object-cover">
    <div class="absolute inset-0 bg-blue-700 bg-opacity-30"></div>
  </div>
  <div class="relative z-10 text-center text-black container border-0">
    <div class="p-6 rounded-lg bg-white shadow-lg mt-4 border-0">
      <?php require_once "umrah-search.php"; ?>
    </div>
  </div>
</section>

<!-- Featured Section -->
<div class="container my-8">
    <div class="mt-10 flex items-center justify-between border-b pb-4 mb-8">
        <h2 class="text-2xl font-bold text-gray-800"><?= T::featured_packages ?></h2>
        <a href="<?= root ?>umrah/listing" class="text-blue-600 font-semibold hover:underline flex items-center gap-1">
            <?= T::view_all ?>
            <span class="material-symbols-outlined text-sm">arrow_forward</span>
        </a>
    </div>
    <!-- ... Rest of featured content can go here ... -->
</div>
