<section class="relative min-h-[400px] flex items-center justify-center pt-12 pb-16 sm:pt-0 sm:pb-0">
  <div class="absolute inset-0 z-0 overflow-hidden">
    <img src="<?=root?>uploads/global/cover.png" alt="<?= T::bus ?? 'Bus' ?>" class="w-full h-full object-cover">
    <div class="absolute inset-0 bg-blue-700 bg-opacity-30"></div>
  </div>
  <div class="relative z-10 text-black container">
    <div class="p-6 rounded-lg bg-white shadow-lg mt-4">
      <?php include "bus-search.php"; ?>
    </div>
  </div>
</section>
