<?php
@$SECURE or die('Access Denied!'); ?>

<!-- app/views/modules/rail/listing/rail-card.php -->
            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm hover:shadow-md transition-all overflow-hidden">
              
              <!-- CARD HEADER: brand name + price summary -->
              <div class="flex items-center justify-between px-5 pt-4 pb-3">
                <div class="flex items-center gap-2.5 min-w-0">
                  <div class="w-9 h-9 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-outlined text-[#1570ef] text-lg">train</span>
                  </div>
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                      <div class="font-bold text-gray-900 dark:text-gray-100 text-sm leading-tight" x-text="train.train_no"></div>
                      <span x-show="isQuietCarriage(train)"
                        class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">
                        <span class="material-symbols-outlined text-[12px]">volume_off</span>
                        <?= T::quiet_carriage ?>
                      </span>
                      <span x-show="!isOnSale(train)"
                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                        <?= T::opens_soon ?>
                      </span>
                      <span x-show="isOnSale(train) && isSoldOut(train)"
                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300">
                        <?= T::sold_out ?>
                      </span>
                    </div>
                    <div class="text-xs text-gray-400 dark:text-gray-500 truncate" x-text="trainTypeLabel(train.train_no)"></div>
                    <div x-show="!isOnSale(train) && train.saleTime" class="text-[11px] text-amber-700 dark:text-amber-300 mt-0.5">
                      <?= T::tickets_open_at ?>:
                      <span x-text="formatSaleOpens(train.saleTime)"></span>
                    </div>
                  </div>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                  <template x-if="!isSoldOut(train) && isOnSale(train) && getLowestPrice(train) !== null">
                    <div>
                      <div class="text-xs text-gray-400 dark:text-gray-500"><?= T::from ?></div>
                      <div class="text-lg font-bold text-blue-600 dark:text-blue-400 leading-tight">
                        <span x-text="currency"></span> <span x-text="getLowestPrice(train).toFixed(2)"></span>
                      </div>
                      <template x-if="getFilteredSeats(train.seats).length > 1">
                        <div class="text-xs text-gray-400 dark:text-gray-500 leading-none mt-0.5">
                          <?= T::to ?> <span x-text="currency"></span> <span x-text="getHighestPrice(train).toFixed(2)"></span>
                        </div>
                      </template>
                    </div>
                  </template>
                  <template x-if="isSoldOut(train) || !isOnSale(train)">
                    <div class="text-sm font-semibold text-gray-400 dark:text-gray-500" x-text="!isOnSale(train) ? '<?= addslashes(T::not_on_sale) ?>' : '<?= addslashes(T::sold_out) ?>'"></div>
                  </template>
                </div>
              </div>

              <!-- TIMELINE: departure → arrival -->
              <div class="px-5 py-4">
                <div class="flex items-center gap-3">
                  <!-- Departure -->
                  <div class="text-left shrink-0 w-[92px] sm:w-[108px]">
                    <div class="flex items-center gap-1">
                      <span class="material-symbols-outlined text-gray-400 dark:text-gray-500 shrink-0" style="font-size: 18px;">arrow_upward</span>
                      <div class="text-xl sm:text-2xl font-black text-gray-900 dark:text-gray-100 leading-none" x-text="formatTime(train.from_date_time || train.from_time)"></div>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate max-w-[108px]" :title="train.from_station_name" x-text="train.from_station_name"></div>
                  </div>
                  <!-- Line + duration -->
                  <div class="flex-1 flex flex-col items-center px-1 min-w-0">
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1" x-text="formatDuration(train.run_time || (train.to_date_time && train.from_date_time ? (Number(train.to_date_time) - Number(train.from_date_time)) / 60 : 0))"></p>
                    <div class="w-full relative flex items-center">
                      <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
                      <div class="mx-1 w-6 h-6 rounded-full bg-gray-200 dark:bg-gray-600 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-gray-500" style="font-size: 14px;">train</span>
                      </div>
                      <div class="flex-1 h-px bg-gray-300 dark:bg-gray-600"></div>
                    </div>
                  </div>
                  <!-- Arrival -->
                  <div class="text-right shrink-0 w-[92px] sm:w-[108px]">
                    <div class="flex items-center justify-end gap-1">
                      <div class="text-xl sm:text-2xl font-black text-gray-900 dark:text-gray-100 leading-none" x-text="formatTime(train.to_date_time || train.to_time)"></div>
                      <span class="material-symbols-outlined text-gray-400 dark:text-gray-500 shrink-0" style="font-size: 18px;">arrow_downward</span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate max-w-[108px]" :title="train.to_station_name" x-text="train.to_station_name"></div>
                  </div>
                </div>
              </div>

              <!-- ACCOMMODATION CLASSES — HORIZONTAL SCROLL / WRAP -->
              <div class="px-5 pb-4">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                  <div class="flex items-center gap-2 flex-wrap flex-1 min-w-0">
                    <template x-for="(seat, idx) in getFilteredSeats(train.seats)" :key="seat.seat_class || seat.seat_type">
                      <label class="flex items-center gap-2 border rounded-lg px-3 h-11 transition-all cursor-pointer whitespace-nowrap"
                        :class="[
                          selectedSeats[train.train_no] ?
                            (selectedSeats[train.train_no].seat_class === (seat.seat_class || seat.seat_type) ? 'border-blue-500 bg-blue-50/50 dark:bg-blue-900/10' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-blue-300') :
                            (idx === 0 ? 'border-blue-500 bg-blue-50/50 dark:bg-blue-900/10' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-blue-300'),
                          seat.numbs <= 0 ? 'opacity-50 cursor-not-allowed' : ''
                        ]">
                        <input type="radio" :name="'seat_' + train.train_no" :value="seat.seat_class || seat.seat_type"
                          :disabled="seat.numbs <= 0 || !isOnSale(train)"
                          :checked="(selectedSeats[train.train_no] && selectedSeats[train.train_no].seat_class === (seat.seat_class || seat.seat_type)) || (!selectedSeats[train.train_no] && seat.numbs > 0 && idx === getFilteredSeats(train.seats).findIndex(s => Number(s.numbs) > 0))"
                          @change="selectedSeats[train.train_no] = seat"
                          class="w-4 h-4 text-blue-600 cursor-pointer flex-shrink-0">
                        <div class="flex items-center gap-1.5">
                          <span class="font-semibold text-gray-800 dark:text-gray-200 text-sm" x-text="seatName(seat)"></span>
                          <span class="text-blue-600 dark:text-blue-400 font-bold text-sm"><span x-text="currency"></span> <span x-text="parseFloat(seat.price).toFixed(2)"></span></span>
                          <span class="text-[10px] font-normal"
                            :class="Number(seat.numbs) <= 0 ? 'text-red-400' : 'text-gray-400'"
                            x-text="Number(seat.numbs) <= 0 ? '<?= addslashes(T::sold_out) ?>' : ('(' + seat.numbs + ' <?= addslashes(T::left) ?>)')"></span>
                        </div>
                      </label>
                    </template>
                  </div>
                  
                    <div class="w-full lg:w-auto">
                    <button type="button" @click="bookSelectedSeat(train)" :disabled="!canBook(train) || bookingTrainNo"
                      class="px-5 h-11 text-sm font-semibold rounded-lg flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed text-white shadow-sm w-full lg:w-auto transition-all active:scale-[0.98]">
                      <span x-show="!isBooking(train)" class="material-symbols-outlined" style="font-size: 18px;">train</span>
                      <span x-show="isBooking(train)" class="material-symbols-outlined animate-spin" style="font-size: 18px;">progress_activity</span>
                      <span x-text="bookButtonLabel(train)"></span>
                    </button>
                  </div>
                </div>
              </div>

            </div>
