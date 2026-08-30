<?php
@$SECURE or die('Access Denied!');
$brand = $brand ?? '#0058E6';
?>
<!-- Selected trip summary (main page) — same system as AI trip Summary -->
<div class="bg-white rounded-2xl shadow-sm border border-[#c7dbf8] overflow-hidden">
  <div class="px-4 py-3 border-b flex items-center justify-between gap-3"
       style="background:rgba(0,88,230,.06);border-color:#c7dbf8">
    <div class="min-w-0 flex items-center gap-2">
      <span class="material-symbols-outlined" style="color:<?= $brand ?>">shopping_bag</span>
      <div class="min-w-0">
        <h3 class="text-sm font-semibold text-gray-900">Your trip selection</h3>
        <p class="text-[11px] text-gray-500 truncate">
          <span x-text="selectedCount"></span> item<span x-text="selectedCount===1?'':'s'"></span>
          <template x-if="selectedCount">
            <span>
              <span class="mx-1">·</span>
              Due online
              <span x-text="currency"></span>
              <span x-text="formatMoney(selectedPayableTotal)"></span>
            </span>
          </template>
        </p>
      </div>
    </div>
    <button type="button"
            x-show="sections.length"
            x-cloak
            class="btn light text-xs py-1.5 px-3 shrink-0 inline-flex items-center gap-1"
            @click="openDrawer()">
      <span class="material-symbols-outlined text-sm">edit</span>
      Edit
    </button>
  </div>

  <div class="divide-y divide-gray-100" x-show="selectedCount">
    <template x-for="mod in orderedModules" :key="'sum-'+mod">
      <div class="px-4 py-3">
        <div class="flex items-center gap-2 mb-2.5">
          <span class="material-symbols-outlined text-[18px]" style="color:<?= $brand ?>"
                x-text="moduleIcon(mod)"></span>
          <h4 class="text-xs font-bold uppercase tracking-wide text-gray-700" x-text="moduleLabel(mod)"></h4>
          <span class="text-xs text-gray-400 font-normal normal-case"
                x-text="'('+selectedByModule[mod].length+')'"></span>
        </div>

        <div class="space-y-2">
          <template x-for="sel in selectedByModule[mod]" :key="sel.key">
            <div class="card overflow-hidden p-0 mb-0">
              <div class="flex gap-3 p-3 sm:p-4">
                <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-lg bg-gray-50 border border-gray-100 overflow-hidden shrink-0 flex items-center justify-center">
                  <template x-if="selectionImage(sel)">
                    <img :src="selectionImage(sel)" :alt="sel.title || ''"
                         class="w-full h-full object-cover"
                         @error="$el.style.display='none'">
                  </template>
                  <template x-if="!selectionImage(sel)">
                    <span class="material-symbols-outlined text-2xl text-gray-300" x-text="moduleIcon(mod)"></span>
                  </template>
                </div>

                <div class="min-w-0 flex-1">
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-gray-900 line-clamp-2" x-text="sel.title"></p>
                      <p class="text-xs text-gray-500 mt-0.5 line-clamp-1" x-text="sel.subtitle"></p>
                    </div>
                    <button type="button"
                            class="btn light text-red-600 shrink-0 p-1 h-8 w-8 inline-flex items-center justify-center"
                            @click="removeSelected(sel.key)"
                            title="Remove">
                      <span class="material-symbols-outlined text-base">close</span>
                    </button>
                  </div>

                  <p class="mt-2 text-sm font-bold text-gray-900">
                    <template x-if="isVisaSelection(sel) && (sel.is_inquiry_only || sel.item?.is_inquiry_only || !(Number(sel.price) > 0))">
                      <span class="text-blue-700">Inquiry · Price on request</span>
                    </template>
                    <template x-if="!(isVisaSelection(sel) && (sel.is_inquiry_only || sel.item?.is_inquiry_only || !(Number(sel.price) > 0)))">
                      <span>
                        <span x-text="sel.currency||currency"></span>
                        <span x-text="formatMoney(sel.price)"></span>
                        <span x-show="isVisaSelection(sel)" class="text-xs font-medium text-blue-700 ml-1">· no online pay</span>
                      </span>
                    </template>
                  </p>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </template>
  </div>

  <div x-show="selectedCount" class="px-4 py-3 border-t border-[#c7dbf8] bg-[#F5F8FE]/80">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="text-[11px] font-medium text-gray-500">Due online</p>
        <p class="text-base font-bold text-gray-900 leading-tight">
          <span x-text="currency"></span>
          <span x-text="formatMoney(selectedPayableTotal)"></span>
        </p>
        <p x-show="hasVisaInCart" class="text-[10px] text-blue-700 mt-0.5">Visa inquiry not included</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button"
                class="btn light text-xs py-2 px-3"
                @click="clearSelected()">
          Clear
        </button>
        <button type="button"
                class="btn inline-flex items-center justify-center gap-1.5 disabled:opacity-50"
                :disabled="!selectedCount || bookingRedirecting"
                @click="goToBooking()">
          <span class="material-symbols-outlined text-base"
                x-text="bookingRedirecting ? 'progress_activity' : 'shopping_cart_checkout'"
                :class="bookingRedirecting ? 'animate-spin' : ''"></span>
          <span x-text="bookingRedirecting ? 'Preparing booking…' : 'Continue to booking'"></span>
        </button>
      </div>
    </div>
  </div>

  <div x-show="!selectedCount" class="p-8 text-center">
    <span class="material-symbols-outlined text-5xl text-gray-200 block mb-2">add_shopping_cart</span>
    <p class="text-sm text-gray-500 mb-4">Pick options from your search results to build this trip.</p>
    <button type="button"
            x-show="sections.length"
            x-cloak
            class="btn light inline-flex items-center gap-1"
            @click="openDrawer()">
      <span class="material-symbols-outlined text-base">edit</span>
      Edit
    </button>
  </div>
</div>
