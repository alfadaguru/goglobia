<?php
// cruises-search.php - Cruise Search Form
@$SECURE or die('Access Denied!'); ?>

<form class="space-y-4" method="GET" action="<?=root?>cruises">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.2fr_1.2fr_1.2fr_1.4fr]">

        <!-- Destination -->
        <div class="relative">
            <div class="input-dropdown relative" x-data="{
                open: false,
                selected: '<?php echo isset($_SESSION['cruise_destination']) ? $_SESSION['cruise_destination'] : 'caribbean'; ?>',
                destinations: [
                    { value: 'caribbean', name: 'Caribbean', icon: 'wb_sunny' },
                    { value: 'mediterranean', name: 'Mediterranean', icon: 'wb_sunny' },
                    { value: 'alaska', name: 'Alaska', icon: 'ac_unit' },
                    { value: 'bahamas', name: 'Bahamas', icon: 'beach_access' },
                    { value: 'bermuda', name: 'Bermuda', icon: 'sailing' },
                    { value: 'hawaii', name: 'Hawaii', icon: 'local_florist' },
                    { value: 'northern_europe', name: 'Northern Europe', icon: 'castle' },
                    { value: 'asia', name: 'Asia', icon: 'temple_buddhist' },
                    { value: 'transatlantic', name: 'Transatlantic', icon: 'directions_boat' }
                ],
                getSelectedName() {
                    return this.destinations.find(dest => dest.value === this.selected)?.name || 'Caribbean';
                },
                selectDestination(dest) {
                    this.selected = dest.value;
                    this.open = false;
                }
            }" @click.away="open = false">
                <input type="hidden" name="destination" :value="selected">
                <div @click="open = !open" class="field-box pr-9 cursor-pointer" :class="open ? 'is-open' : ''">
                    <span class="field-box-icon material-symbols-outlined">anchor</span>
                    <div class="field-box-content">
                        <span class="field-box-label">Destination</span>
                        <span class="field-box-value" x-text="getSelectedName()">Caribbean</span>
                    </div>
                    <span class="material-symbols-outlined field-box-chevron" :class="open ? 'rotate-180' : ''">expand_more</span>
                </div>
                <div class="input-dropdown-content" :class="open ? 'show' : ''">
                    <template x-for="dest in destinations" :key="dest.value">
                        <div class="input-dropdown-item flex items-center gap-2" @click="selectDestination(dest)">
                            <span class="material-symbols-outlined text-sm" x-text="dest.icon"></span>
                            <span x-text="dest.name"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Departure Port -->
        <div @click="$refs.cruisePortInput.focus()" class="field-box" x-data>
            <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M11.5132 19.0857C11.206 19.3048 10.794 19.3048 10.4868 19.0857C6.06043 15.9292 1.36177 9.43901 6.11114 4.74951C7.40775 3.46924 9.16632 2.75 11 2.75C12.8337 2.75 14.5923 3.46924 15.8889 4.74951C20.6382 9.43901 15.9396 15.9292 11.5132 19.0857Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.0013 11C12.0138 11 12.8346 10.1792 12.8346 9.16665C12.8346 8.15412 12.0138 7.33331 11.0013 7.33331C9.98878 7.33331 9.16797 8.15412 9.16797 9.16665C9.16797 10.1792 9.98878 11 11.0013 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
            <div class="field-box-content">
                <label class="field-box-label">Departure Port</label>
                <input x-ref="cruisePortInput" type="text" name="departure_port" placeholder="Port or city" class="field-box-input">
            </div>
        </div>

        <!-- Departure Date -->
        <div @click="document.querySelector('input[name=departure_date]').focus()" class="field-box" x-data>
            <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 22 22" fill="none"><path d="M2.75 11C2.75 7.11093 2.75 6.08306 3.95818 4.87487C5.16637 3.66669 7.11091 3.66669 11 3.66669C14.8891 3.66669 16.8336 3.66669 18.0418 4.87487C19.25 6.08306 19.25 7.11093 19.25 11C19.25 14.8891 19.25 16.8337 18.0418 18.0418C16.8336 19.25 14.8891 19.25 11 19.25C7.11091 19.25 5.16637 19.25 3.95818 18.0418C2.75 16.8337 2.75 14.8891 2.75 11Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.125 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.875 4.58333V2.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.98047 7.33331H19.0221" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
            <div class="field-box-content">
                <label class="field-box-label">Departure Date</label>
                <input type="text" name="departure_date" placeholder="Departure Date" class="CruiseDeparture field-box-input cursor-pointer" readonly value="<?php if(isset($_SESSION['cruise_departure_date'])){ echo $_SESSION['cruise_departure_date']; } else { $d=strtotime("+30 Days"); echo date("d-m-Y", $d); } ?>">
            </div>
        </div>

        <!-- Cruise Length -->
        <div class="relative">
            <div class="input-dropdown relative" x-data="{
                open: false,
                selected: '<?php echo isset($_SESSION['cruise_length']) ? $_SESSION['cruise_length'] : '7'; ?>',
                lengths: [
                    { value: '3-5', name: '3-5 Days', icon: 'schedule' },
                    { value: '6-8', name: '6-8 Days', icon: 'schedule' },
                    { value: '9-13', name: '9-13 Days', icon: 'schedule' },
                    { value: '14+', name: '14+ Days', icon: 'schedule' },
                    { value: 'any', name: 'Any Length', icon: 'all_inclusive' }
                ],
                getSelectedName() {
                    return this.lengths.find(length => length.value === this.selected)?.name || '6-8 Days';
                },
                selectLength(length) {
                    this.selected = length.value;
                    this.open = false;
                }
            }" @click.away="open = false">
                <input type="hidden" name="cruise_length" :value="selected">
                <div @click="open = !open" class="field-box pr-9 cursor-pointer" :class="open ? 'is-open' : ''">
                    <span class="field-box-icon material-symbols-outlined">schedule</span>
                    <div class="field-box-content">
                        <span class="field-box-label">Cruise Length</span>
                        <span class="field-box-value" x-text="getSelectedName()">6-8 Days</span>
                    </div>
                    <span class="material-symbols-outlined field-box-chevron" :class="open ? 'rotate-180' : ''">expand_more</span>
                </div>
                <div class="input-dropdown-content" :class="open ? 'show' : ''">
                    <template x-for="length in lengths" :key="length.value">
                        <div class="input-dropdown-item flex items-center gap-2" @click="selectLength(length)">
                            <span class="material-symbols-outlined text-sm" x-text="length.icon"></span>
                            <span x-text="length.name"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.2fr_1.2fr_1.2fr_58px]">

        <!-- Cruise Line -->
        <div class="relative">
            <div class="input-dropdown relative" x-data="{
                open: false,
                selected: '<?php echo isset($_SESSION['cruise_line']) ? $_SESSION['cruise_line'] : 'any'; ?>',
                cruiseLines: [
                    { value: 'any', name: 'Any Cruise Line', icon: 'directions_boat' },
                    { value: 'royal_caribbean', name: 'Royal Caribbean', icon: 'directions_boat' },
                    { value: 'carnival', name: 'Carnival', icon: 'directions_boat' },
                    { value: 'norwegian', name: 'Norwegian', icon: 'directions_boat' },
                    { value: 'princess', name: 'Princess', icon: 'directions_boat' },
                    { value: 'celebrity', name: 'Celebrity', icon: 'directions_boat' },
                    { value: 'msc', name: 'MSC Cruises', icon: 'directions_boat' },
                    { value: 'disney', name: 'Disney Cruise Line', icon: 'directions_boat' }
                ],
                getSelectedName() {
                    return this.cruiseLines.find(line => line.value === this.selected)?.name || 'Any Cruise Line';
                },
                selectLine(line) {
                    this.selected = line.value;
                    this.open = false;
                }
            }" @click.away="open = false">
                <input type="hidden" name="cruise_line" :value="selected">
                <div @click="open = !open" class="field-box pr-9 cursor-pointer" :class="open ? 'is-open' : ''">
                    <span class="field-box-icon material-symbols-outlined">directions_boat</span>
                    <div class="field-box-content">
                        <span class="field-box-label">Cruise Line</span>
                        <span class="field-box-value" x-text="getSelectedName()">Any Cruise Line</span>
                    </div>
                    <span class="material-symbols-outlined field-box-chevron" :class="open ? 'rotate-180' : ''">expand_more</span>
                </div>
                <div class="input-dropdown-content" :class="open ? 'show' : ''">
                    <template x-for="line in cruiseLines" :key="line.value">
                        <div class="input-dropdown-item flex items-center gap-2" @click="selectLine(line)">
                            <span class="material-symbols-outlined text-sm" x-text="line.icon"></span>
                            <span x-text="line.name"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Room Type -->
        <div class="relative">
            <div class="input-dropdown relative" x-data="{
                open: false,
                selected: '<?php echo isset($_SESSION['cruise_room_type']) ? $_SESSION['cruise_room_type'] : 'balcony'; ?>',
                roomTypes: [
                    { value: 'interior', name: 'Interior', icon: 'bed', desc: 'No window' },
                    { value: 'oceanview', name: 'Ocean View', icon: 'window', desc: 'Window view' },
                    { value: 'balcony', name: 'Balcony', icon: 'balcony', desc: 'Private balcony' },
                    { value: 'suite', name: 'Suite', icon: 'hotel_class', desc: 'Luxury suite' }
                ],
                getSelectedName() {
                    return this.roomTypes.find(room => room.value === this.selected)?.name || 'Balcony';
                },
                selectRoom(room) {
                    this.selected = room.value;
                    this.open = false;
                }
            }" @click.away="open = false">
                <input type="hidden" name="room_type" :value="selected">
                <div @click="open = !open" class="field-box pr-9 cursor-pointer" :class="open ? 'is-open' : ''">
                    <span class="field-box-icon material-symbols-outlined">hotel</span>
                    <div class="field-box-content">
                        <span class="field-box-label">Room Type</span>
                        <span class="field-box-value" x-text="getSelectedName()">Balcony</span>
                    </div>
                    <span class="material-symbols-outlined field-box-chevron" :class="open ? 'rotate-180' : ''">expand_more</span>
                </div>
                <div class="input-dropdown-content" :class="open ? 'show' : ''">
                    <template x-for="room in roomTypes" :key="room.value">
                        <div class="input-dropdown-item" @click="selectRoom(room)">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-sm" x-text="room.icon"></span>
                                <div>
                                    <div x-text="room.name" class="font-medium"></div>
                                    <div x-text="room.desc" class="text-xs text-gray-500"></div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Guests -->
        <div class="relative">
            <div class="input-dropdown relative" x-data="{
                open: false,
                adults: <?php echo isset($_SESSION['cruise_adults']) ? $_SESSION['cruise_adults'] : '2'; ?>,
                children: <?php echo isset($_SESSION['cruise_children']) ? $_SESSION['cruise_children'] : '0'; ?>,
                getTotalGuests() {
                    return this.adults + this.children;
                },
                getGuestText() {
                    let text = this.getTotalGuests() + ' Guest' + (this.getTotalGuests() !== 1 ? 's' : '');
                    if (this.children > 0) {
                        text += ' (' + this.adults + ' Adults, ' + this.children + ' Children)';
                    }
                    return text;
                },
                increment(type) {
                    if (type === 'adults' && this.adults < 8) this.adults++;
                    if (type === 'children' && this.children < 6) this.children++;
                },
                decrement(type) {
                    if (type === 'adults' && this.adults > 1) this.adults--;
                    if (type === 'children' && this.children > 0) this.children--;
                }
            }" @click.away="open = false">
                <input type="hidden" name="adults" :value="adults">
                <input type="hidden" name="children" :value="children">
                <input type="hidden" name="guests" :value="getTotalGuests()">

                <div @click="open = !open" class="field-box pr-9 cursor-pointer" :class="open ? 'is-open' : ''">
                    <svg class="field-box-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M17 19.5C17 17.8431 14.7614 16.5 12 16.5C9.23858 16.5 7 17.8431 7 19.5M21 16.5004C21 15.2702 19.7659 14.2129 18 13.75M3 16.5004C3 15.2702 4.2341 14.2129 6 13.75M18 9.73611C18.6137 9.18679 19 8.3885 19 7.5C19 5.84315 17.6569 4.5 16 4.5C15.2316 4.5 14.5308 4.78885 14 5.26389M6 9.73611C5.38625 9.18679 5 8.3885 5 7.5C5 5.84315 6.34315 4.5 8 4.5C8.76835 4.5 9.46924 4.78885 10 5.26389M12 13.5C10.3431 13.5 9 12.1569 9 10.5C9 8.84315 10.3431 7.5 12 7.5C13.6569 7.5 15 8.84315 15 10.5C15 12.1569 13.6569 13.5 12 13.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="field-box-content">
                        <span class="field-box-label">Guests</span>
                        <span class="field-box-value" x-text="getGuestText()">2 Guests</span>
                    </div>
                    <span class="material-symbols-outlined field-box-chevron" :class="open ? 'rotate-180' : ''">expand_more</span>
                </div>

                <div class="input-dropdown-content" :class="open ? 'show' : ''">
                    <!-- Adults -->
                    <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100">
                        <div>
                            <div class="text-xs font-bold">Adults</div>
                            <div class="text-xs text-gray-500">18+ years old</div>
                        </div>
                        <div class="flex items-center gap-0">
                            <button type="button" @click="decrement('adults')"
                                    class="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                    :disabled="adults <= 1">
                                <span class="material-symbols-outlined" style="font-size: 16px;">remove</span>
                            </button>
                            <span x-text="adults" class="w-8 text-center text-sm font-bold">2</span>
                            <button type="button" @click="increment('adults')"
                                    class="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                    :disabled="adults >= 8">
                                <span class="material-symbols-outlined" style="font-size: 16px;">add</span>
                            </button>
                        </div>
                    </div>

                    <!-- Children -->
                    <div class="flex items-center justify-between px-3 py-2">
                        <div>
                            <div class="text-xs font-bold">Children</div>
                            <div class="text-xs text-gray-500">Under 18 years old</div>
                        </div>
                        <div class="flex items-center gap-0">
                            <button type="button" @click="decrement('children')"
                                    class="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                    :disabled="children <= 0">
                                <span class="material-symbols-outlined" style="font-size: 16px;">remove</span>
                            </button>
                            <span x-text="children" class="w-8 text-center text-sm font-bold">0</span>
                            <button type="button" @click="increment('children')"
                                    class="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50"
                                    :disabled="children >= 6">
                                <span class="material-symbols-outlined" style="font-size: 16px;">add</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Button -->
        <div>
            <button type="submit" class="btn w-full h-[58px] rounded-lg flex items-center justify-center p-0" title="Search Cruises" aria-label="Search Cruises">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
            </button>
        </div>

    </div>
</form>
