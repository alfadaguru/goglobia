<?php @$SECURE or die('Access Denied!'); ?>
<?php $brand = $brand ?? '#0058E6'; ?>

<!-- Active search only — hide when nothing is being searched -->
<div x-show="loading && !searchExpanded" x-cloak
     x-transition:enter="transition-opacity duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">

  <div class="relative rounded-2xl overflow-hidden border border-[#9ec0f7] shadow-sm mb-6 ai-loading-glow ai-loading-stage"
       style="height:240px;background:linear-gradient(165deg,#003fa8 0%,#0058E6 38%,#3D7EF0 68%,#7aa8f5 100%)">
    <div class="absolute inset-0 pointer-events-none">
      <div class="absolute w-1 h-1 rounded-full bg-white opacity-70" style="top:12%;left:15%;animation:pulse-dot 2.1s .2s infinite ease-in-out"></div>
      <div class="absolute w-1.5 h-1.5 rounded-full bg-white opacity-60" style="top:8%;left:42%;animation:pulse-dot 1.9s .7s infinite ease-in-out"></div>
      <div class="absolute w-1 h-1 rounded-full bg-white opacity-80" style="top:18%;left:68%;animation:pulse-dot 2.3s 1s infinite ease-in-out"></div>
      <div class="absolute w-1 h-1 rounded-full bg-white opacity-50" style="top:28%;left:85%;animation:pulse-dot 2s .4s infinite ease-in-out"></div>
    </div>
    <svg class="anim-cl absolute" style="top:18px;left:20px;opacity:.9" width="110" height="40" viewBox="0 0 110 40">
      <ellipse cx="55" cy="28" rx="54" ry="16" fill="white" opacity=".95"/>
      <ellipse cx="30" cy="22" rx="28" ry="18" fill="white"/>
      <ellipse cx="76" cy="20" rx="25" ry="16" fill="white"/>
    </svg>
    <svg class="anim-cr absolute" style="top:10px;right:60px;opacity:.7" width="80" height="30" viewBox="0 0 80 30">
      <ellipse cx="40" cy="20" rx="39" ry="13" fill="white" opacity=".9"/>
      <ellipse cx="22" cy="16" rx="20" ry="14" fill="white"/>
      <ellipse cx="57" cy="15" rx="18" ry="12" fill="white"/>
    </svg>
    <svg class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 800 220" preserveAspectRatio="xMidYMid slice">
      <path d="M -80 190 Q 200 60 400 110 Q 600 160 880 55"
        fill="none" stroke="rgba(255,255,255,0.55)" stroke-width="2.5"
        stroke-dasharray="10 7" stroke-dashoffset="300"
        style="animation:fade-path 4s linear infinite"/>
    </svg>

    <div class="anim-plane pointer-events-none" aria-hidden="true">
      <svg width="72" height="36" viewBox="0 0 72 36" fill="none" xmlns="http://www.w3.org/2000/svg">
        <ellipse cx="36" cy="18" rx="26" ry="6.5" fill="white"/>
        <path d="M58 18 C66 18 70 16.5 70 15.5 C70 14.5 64 13 58 13 Z" fill="white"/>
        <path d="M28 18 L42 4 L50 4 L40 18 Z" fill="#E8F0FC"/>
        <path d="M28 18 L42 32 L50 32 L40 18 Z" fill="#c7dbf8"/>
        <path d="M12 18 L4 8 L10 8 L18 18 Z" fill="#E8F0FC"/>
        <circle cx="44" cy="16" r="1.4" fill="#0058E6" opacity=".85"/>
        <circle cx="49" cy="16" r="1.4" fill="#0058E6" opacity=".85"/>
        <circle cx="54" cy="16" r="1.4" fill="#0058E6" opacity=".85"/>
        <rect x="34" y="21" width="10" height="3.5" rx="1.5" fill="#9ec0f7"/>
      </svg>
    </div>

    <div class="absolute inset-x-0 bottom-0 px-5 pb-5 pt-12 text-center"
         style="background:linear-gradient(transparent,rgba(0,63,168,.65))">
      <p class="text-white font-semibold text-sm md:text-base drop-shadow tracking-wide"
         x-text="animDisplayMsg"></p>
      <div class="mt-2.5 flex items-center justify-center gap-1.5">
        <span class="inline-flex items-center gap-1.5">
          <span class="w-2 h-2 rounded-full bg-white anim-d1"></span>
          <span class="w-2 h-2 rounded-full bg-white anim-d2"></span>
          <span class="w-2 h-2 rounded-full bg-white anim-d3"></span>
        </span>
      </div>
    </div>
  </div>
</div>

<div x-show="!loading && !searchExpanded && error && !sections.length" x-cloak
     class="mb-6 rounded-2xl border border-red-100 bg-red-50 px-5 py-5 text-center">
  <p class="text-sm font-semibold text-red-700" x-text="error"></p>
  <button type="button"
          class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-red-700 hover:underline"
          @click="openModifySearch()">
    <span class="material-symbols-outlined text-base">edit_note</span>
    Modify Search
  </button>
</div>
