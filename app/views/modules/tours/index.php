<section class="relative min-h-[400px] flex items-center justify-center pt-12 pb-16 sm:pt-0 sm:pb-0">
  <div class="absolute inset-0 z-0 overflow-hidden">
    <img src="<?=root?>uploads/global/cover.png" alt="Dream vacation destination" class="w-full h-full object-cover" >
    <div class="absolute inset-0 bg-blue-700 bg-opacity-30"></div>
  </div>
   <div class="relative z-10 text-black container">
  <!-- Page heading (SEO/a11y: every page needs one H1). -->
  <div class="mb-4 text-center sm:text-left">
    <h1 class="text-white text-2xl sm:text-3xl font-bold" style="text-shadow:0 2px 8px rgba(0,0,0,.45)">Book tours &amp; experiences</h1>
    <p class="text-white/90 text-sm sm:text-base mt-1" style="text-shadow:0 1px 4px rgba(0,0,0,.4)">Hand-picked activities and things to do at great prices.</p>
  </div>
  <div class="p-6 rounded-lg bg-white shadow-lg mt-4">
    <?php include "tours-search.php"; ?>
  </div>

  </div>
</section>

<!-- <svg class="hero-svg relative bottom-0 left-0 w-full h-[50px] fill-white z-100 -mt-[127px]"
  xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 10" preserveAspectRatio="none">
  <path d="M0 10 0 0 A 90 59, 0, 0, 0, 100 0 L 100 10 Z"></path>
</svg> -->

<?php // include "Featured.php"; ?>