<?php
// manage-umrah.php
@$SECURE or die('Access Denied!');
?>
<style>
    [draggable="true"]:active {
        opacity: 0.5;
        cursor: grabbing !important;
    }

    [draggable="true"] {
        cursor: grab;
    }

    .activity-image-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 0.75rem;
    }

    .activity-image-item {
        position: relative;
        aspect-ratio: 1;
        border-radius: 0.5rem;
        overflow: hidden;
        border: 2px solid #e5e7eb;
    }

    .activity-image-item:hover {
        border-color: #3b82f6;
    }

    .activity-image-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0);
        transition: background 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .activity-image-item:hover .activity-image-overlay {
        background: rgba(0, 0, 0, 0.4);
    }

    .activity-image-overlay button {
        opacity: 0;
        transition: opacity 0.2s;
    }

    .activity-image-item:hover .activity-image-overlay button {
        opacity: 1;
    }
</style>
<?php

$mode = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$isView = ($mode === 'view');
$isAdd = ($mode === 'add');

if ($isView) {
    $pageTitle = T::view . ': ' . htmlspecialchars($umrah['name'] ?? '');
} elseif ($isEdit) {
    $pageTitle = T::edit . ': ' . htmlspecialchars($umrah['name'] ?? '');
} else {
    $pageTitle = T::add . ' ' . (T::umrah ?? 'Umrah');
}

if ($isAdd) {
    $umrah = [
        'id' => 0,
        'name' => '',
        'description' => '',
        'location' => '',
        'address' => '',
        'currency' => 'USD',
        'adult_price' => 0,
        'child_price' => 0,
        'infant_price' => 0,
        'discount_percentage' => 0,
        'max_adults' => 1,
        'max_children' => 0,
        'max_infants' => 0,
        'days' => 1,
        'nights' => 0,
        'umrah_type_id' => 0,
        'stars' => 0,
        'refundable' => 1,
        'featured' => 0,
        'email' => '',
        'phone' => '',
        'website' => '',
        'meta_title' => '',
        'meta_description' => '',
        'meta_keywords' => '',
        'cancellation_policy' => '',
        'terms_conditions' => '',
        'services' => '[]',
        'exclusions' => '[]',
        'itinerary' => '[]',
        'images' => '',
        'status' => 1,
        'supplier_id' => 0,
        'related_umrah' => '[]',
        'suggested_umrah' => '[]',
    ];
    $selected_services = [];
    $selected_amenities = [];
    $umrah_images = [];
    $latitude = '';
    $longitude = '';
    $itinerary_data = [];
}

if ($isEdit) {
    $latitude = $umrah['latitude'] ?? '';
    $longitude = $umrah['longitude'] ?? '';
}

// Fetch room types (Phase 4)
$room_types = $db->select('umrah_settings', ['id', 'setting_label(name)'], ['setting_type' => 'room_type'], ['ORDER' => ['setting_label' => 'ASC']]);
?>

<?php
$service_types_map = [];
if (!empty($services)) {
    foreach ($services as $service) {
        $service_types_map[$service['id']] = $service['setting_type'] ?? 'service';
    }
}
?>

<?php if (!$isView): ?>
    <script>
        function umrahFormData() {
            return {
                loading: false,
                activeTab: 'general',
                activeTranslationTab: '<?php
                $first_lang = '';
                foreach ($GLOBALS['languages'] ?? [] as $lang_data) {
                    if ($lang_data['lang_code'] !== 'en') {
                        $first_lang = $lang_data['lang_code'];
                        break;
                    }
                }
                echo $first_lang;
                ?>',
            selectedServices: <?= json_encode($selected_services ?? []) ?>,
                locationSearch: '<?= htmlspecialchars($umrah['location'] ?? '') ?>',
                    locationResults: [],
                        showLocationDropdown: false,
                            searchingLocations: false,
                                mainLatitude: '<?= $latitude ?? '' ?>',
                                    mainLongitude: '<?= $longitude ?? '' ?>',
                                        translationTabInitialized: false,


                                            <?php if ($isEdit): ?>
                imagesToDelete: [],
                    defaultImage: '<?= !empty($umrah_images) ? (array_values(array_filter($umrah_images, fn($img) => !empty($img['default'])))[0]['url'] ?? $umrah_images[0]['url']) : '' ?>',
                        umrahImages: <?= json_encode($umrah_images ?? []) ?>,
                            draggedIndex: null,
                                lightboxImage: '',
                                    showLightbox: false,
                                    <?php endif; ?>
            previewImages: [],

                userSearch: '<?php if ($isEdit && !empty($owner)):
                    echo htmlspecialchars(trim($owner['first_name'] ?? '') . ' ' . trim($owner['last_name'] ?? ''));
                endif; ?>',
                    searchResults: [],
                        showUserDropdown: false,
                            selectedUser: <?php if ($isEdit && !empty($owner)):
                                echo json_encode($owner);
                            else:
                                echo 'null';
                            endif; ?>,
                                searchingUsers: false,

                                    itinerary: <?= json_encode($itinerary_data ?? []) ?>,
                                        activityImageLightbox: '',
                                            showActivityLightbox: false,
                                                stayLightboxImage: '',
                                                    showStayLightbox: false,
                                                        roomLightboxImage: '',
                                                            showRoomLightbox: false,

                                                                status: <?= ($umrah['status'] ?? 1) ? 'true' : 'false' ?>,
                                                                    featured: <?= ($umrah['featured'] ?? 0) ? 'true' : 'false' ?>,
                                                                        refundable: <?= ($umrah['refundable'] ?? 1) ? 'true' : 'false' ?>,

                                                                            // Flights & Stays Data
                                                                            flightsData: <?= !empty($umrah['flights_data']) ? $umrah['flights_data'] : '[]' ?>,
                                                                                staysData: (<?= !empty($umrah['stays_data']) ? $umrah['stays_data'] : '[]' ?>).map(s => ({
                                                                                    ...s,
                                                                                    activeTab: 'general',
                                                                                    locationResults: [],
                                                                                    searchingLocations: false,
                                                                                    showLocationDropdown: false,
                                                                                    locationSearch: s.location || '',
                                                                                    images: s.images || [],
                                                                                    previewImages: [],
                                                                                    imagesToDelete: [],
                                                                                    rooms: (s.rooms || []).map(r => ({
                                                                                        ...r,
                                                                                        id: r.id || (Date.now() + Math.random()),
                                                                                        room_type: r.room_type || r.type || r.name || '',
                                                                                        room_occupancy: r.room_occupancy || r.occupancy || '',
                                                                                        images: r.images || [],
                                                                                        previewImages: [],
                                                                                        imagesToDelete: [],
                                                                                        defaultImage: r.defaultImage || (r.images && r.images.length > 1 ? (r.images.find(i => i.default) || r.images[0]).url : (r.images && r.images.length > 0 ? r.images[0].url : ''))
                                                                                    }))
                                                                                })),
                                                                                    transfersData: (<?= !empty($umrah['travelings_data']) ? $umrah['travelings_data'] : '[]' ?>).map(t => {
                                                                                        let travel_date = '';
                                                                                        let travel_time = '';
                                                                                        if (t.date) {
                                                                                            const dt = new Date(t.date);
                                                                                            if (!isNaN(dt.getTime())) {
                                                                                                travel_date = dt.toISOString().split('T')[0];
                                                                                                travel_time = dt.toTimeString().split(' ')[0].substring(0, 5);
                                                                                            }
                                                                                        }
                                                                                        return {
                                                                                            ...t,
                                                                                            travel_date: travel_date,
                                                                                            travel_time: travel_time,
                                                                                            activeTab: 'general',
                                                                                            images: t.images || [],
                                                                                            previewImages: [],
                                                                                            imagesToDelete: [],
                                                                                            defaultImage: t.defaultImage || (t.images && t.images.length > 0 ? (t.images.find(i => i.default) || t.images[0]).url : '')
                                                                                        };
                                                                                    }),
                                                                                        transferLightboxImage: '',
                                                                                            showTransferLightbox: false,
                                                                                                draggedTransferImageIndex: null,
                                                                                                    draggedTransferIndex: null,
                                                                                                        serviceTypes: <?= json_encode($service_types_map) ?>,

                                                                                                            isTypeSelected(type) {
                if (!this.serviceTypes) return false;
                return this.selectedServices.some(id => {
                    const sType = this.serviceTypes[id] || this.serviceTypes[String(id)];
                    return sType === type;
                });
            },

            toggleService(id) {
                const strId = String(id);
                const index = this.selectedServices.map(String).indexOf(strId);

                if (index > -1) {
                    this.selectedServices.splice(index, 1);
                } else {
                    this.selectedServices.push(parseInt(id));

                    // Auto-initialize data arrays if empty
                    const type = this.serviceTypes[id] || this.serviceTypes[strId];
                    if (type === 'hotel' && this.staysData.length === 0) {
                        this.addStay();
                    } else if (type === 'car' && this.transfersData.length === 0) {
                        this.addTransfer();
                    } else if (type === 'flight' && this.flightsData.length === 0) {
                        this.addFlightEntry();
                    }
                }
            },

            // Create a blank segment object
            newSegment(prefill = {}) {
                return {
                    airline: prefill.airline || '',
                    airlineSearch: prefill.airlineSearch || '',
                    iata: prefill.iata || '',
                    airlineResults: [],
                    showAirlineDropdown: false,
                    flight_no: '',
                    departure_airport: prefill.departure_airport || '',
                    fromSearch: prefill.fromSearch || '',
                    fromResults: [],
                    showFromDropdown: false,
                    arrival_airport: '',
                    toSearch: '',
                    toResults: [],
                    showToDropdown: false,
                    departure_date: prefill.departure_date || '',
                    departure_day: prefill.departure_day || '',
                    departure_time: '',
                    arrival_date: prefill.arrival_date || '',
                    arrival_day: prefill.arrival_day || '',
                    arrival_time: '',
                    duration: '',
                    class: prefill.class || 'economy',
                    cabin_baggage: prefill.cabin_baggage || '',
                    baggage: prefill.baggage || ''
                };
            },

            // Create a blank flight entry with one empty segment
            newFlightEntry() {
                return {
                    id: Date.now() + Math.random(),
                    tripType: 'one_way',
                    flightType: 'fixed',
                    segments: [this.newSegment()],
                    returnSegments: [this.newSegment()]
                };
            },

            // Add a new top-level flight entry
            addFlightEntry() {
                this.flightsData.push(this.newFlightEntry());
            },

            // Remove a top-level flight entry
            removeFlightEntry(flightIdx) {
                if (this.flightsData.length > 1) {
                    this.flightsData.splice(flightIdx, 1);
                }
            },

            // Hotel/Stay Methods
            addStay() {
                this.staysData.push({
                    hotel_name: '',
                    stay_type: '1',
                    stars: '1',
                    activeTab: 'general',
                    location: '',
                    locationSearch: '',
                    locationResults: [],
                    showLocationDropdown: false,
                    searchingLocations: false,
                    address: '',
                    check_in: '',
                    check_out: '',
                    checkin_time: '12:00',
                    checkout_time: '12:00',
                    description: '',
                    images: [],
                    previewImages: [],
                    imagesToDelete: [],
                    rooms: [this.newStayRoom()]
                });
            },

            newStayRoom() {
                return {
                    id: Date.now() + Math.random(),
                    room_type: '',
                    room_occupancy: '',
                    images: [],
                    previewImages: [],
                    imagesToDelete: [],
                    defaultImage: '',
                    locationSearch: '',
                    locationResults: [],
                    showLocationDropdown: false
                };
            },


            removeStay(index) {
                if (this.staysData.length > 1) {
                    this.staysData.splice(index, 1);
                }
            },

                // Stay Location Search Methods
                async searchStayLocations(index) {
                const stay = this.staysData[index];
                if (!stay.locationSearch || stay.locationSearch.length < 2) {
                    stay.locationResults = [];
                    stay.showLocationDropdown = false;
                    return;
                }

                stay.searchingLocations = true;
                try {
                    const formData = new FormData();
                    formData.append('search', stay.locationSearch);

                    const response = await fetch('<?= root . admin ?>/stays/search-locations', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();
                    if (data.success) {
                        stay.locationResults = data.locations;
                        stay.showLocationDropdown = stay.locationResults.length > 0;
                    }
                } catch (error) {
                    console.error('Error searching stay locations:', error);
                } finally {
                    stay.searchingLocations = false;
                }
            },

            selectStayLocation(index, location) {
                const stay = this.staysData[index];
                stay.location = location.city;
                stay.locationSearch = location.city + ', ' + location.country;
                stay.latitude = location.latitude || '';
                stay.longitude = location.longitude || '';
                stay.showLocationDropdown = false;
                stay.locationResults = [];
            },

            clearStayLocationSelection(index) {
                const stay = this.staysData[index];
                stay.location = '';
                stay.locationSearch = '';
                stay.locationResults = [];
                stay.showLocationDropdown = false;
                stay.latitude = '';
                stay.longitude = '';
            },

                // Main Destination Search Methods
                async searchMainLocations() {
                if (!this.locationSearch || this.locationSearch.length < 2) {
                    this.locationResults = [];
                    this.showLocationDropdown = false;
                    return;
                }

                this.searchingLocations = true;
                try {
                    const formData = new FormData();
                    formData.append('query', this.locationSearch);

                    const response = await fetch('<?= root ?>umrah-destination-suggestion', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();
                    this.locationResults = Array.isArray(data) ? data : [];
                    this.showLocationDropdown = this.locationResults.length > 0;
                } catch (error) {
                    console.error('Error searching locations:', error);
                } finally {
                    this.searchingLocations = false;
                }
            },

            selectMainLocation(location) {
                this.locationSearch = location.cityname || location.airportname || location.name;
                this.mainLatitude = location.lat || location.latitude || '';
                this.mainLongitude = location.lon || location.longitude || '';
                this.locationResults = [];
                this.showLocationDropdown = false;
            },

            // Preset coordinates for the 3 allowed Umrah destinations
            umrahDestinationPresets: {
                'Makkah': { lat: '21.4225', lng: '39.8262' },
                'Madinah': { lat: '24.4709', lng: '39.6113' },
                'Jeddah': { lat: '21.4858', lng: '39.1925' },
            },
            applyPresetDestination(city) {
                const preset = this.umrahDestinationPresets[city];
                if (preset) {
                    this.mainLatitude = preset.lat;
                    this.mainLongitude = preset.lng;
                } else {
                    this.mainLatitude = '';
                    this.mainLongitude = '';
                }
            },

            initStaysData() {
                this.staysData.forEach(stay => {
                    if (stay.stay_type === undefined) stay.stay_type = '1';
                    if (stay.stars === undefined) stay.stars = '1';
                    if (stay.activeTab === undefined) stay.activeTab = 'general';
                    if (stay.address === undefined) stay.address = '';
                    if (stay.checkin_time === undefined) stay.checkin_time = '12:00';
                    if (stay.checkout_time === undefined) stay.checkout_time = '12:00';
                    if (stay.description === undefined) stay.description = '';
                    if (stay.images === undefined) stay.images = [];
                    if (stay.previewImages === undefined) stay.previewImages = [];
                    if (stay.imagesToDelete === undefined) stay.imagesToDelete = [];
                    if (stay.defaultImage === undefined) stay.defaultImage = (stay.images && stay.images.length > 0) ? (stay.images.find(img => img.default)?.url || stay.images[0].url) : '';

                    if (stay.room_type === undefined) stay.room_type = '';
                    if (stay.room_occupancy === undefined) stay.room_occupancy = '';
                    if (stay.roomImages === undefined) stay.roomImages = [];
                    if (stay.roomPreviewImages === undefined) stay.roomPreviewImages = [];
                    if (stay.roomImagesToDelete === undefined) stay.roomImagesToDelete = [];
                    if (stay.roomDefaultImage === undefined) stay.roomDefaultImage = (stay.roomImages && stay.roomImages.length > 0) ? (stay.roomImages.find(img => img.default)?.url || stay.roomImages[0].url) : '';

                    if (stay.rooms === undefined) stay.rooms = [];

                    // Synchronize roomImages if stay.rooms has data (for backward compatibility or nested models)
                    if ((!stay.roomImages || stay.roomImages.length === 0) && stay.rooms.length > 0 && stay.rooms[0].images && stay.rooms[0].images.length > 0) {
                        stay.roomImages = JSON.parse(JSON.stringify(stay.rooms[0].images));
                        if (stay.rooms[0].defaultImage) stay.roomDefaultImage = stay.rooms[0].defaultImage;
                    }

                    stay.rooms.forEach(room => {
                        if (room.type === undefined && room.room_type !== undefined) { room.type = room.room_type; delete room.room_type; }
                        if (room.occupancy === undefined && room.room_occupancy !== undefined) { room.occupancy = room.room_occupancy; delete room.room_occupancy; }

                        if (room.type === undefined) room.type = '';
                        if (room.occupancy === undefined) room.occupancy = '';

                        if (room.images === undefined) room.images = [];
                        if (room.previewImages === undefined) room.previewImages = [];
                        if (room.imagesToDelete === undefined) room.imagesToDelete = [];
                        if (room.defaultImage === undefined) room.defaultImage = (room.images && room.images.length > 0) ? (room.images.find(img => img.default)?.url || room.images[0].url) : '';

                        // Remove legacy room fields
                        delete room.price;
                        delete room.max_adults;
                        delete room.status;
                        delete room.room_type;
                        delete room.room_occupancy;
                    });

                    // Remove legacy/unwanted fields (Phase 2, 3, 4)
                    delete stay.rating;
                    delete stay.currency;
                    delete stay.latitude;
                    delete stay.longitude;
                    delete stay.notes;
                    delete stay.age_requirement;
                    delete stay.featured;
                    delete stay.status;
                    delete stay.cancellation_policy;
                    delete stay.privacy_policy;
                    delete stay.refundable;
                    delete stay.discount;

                    // Search properties (dynamic)
                    stay.locationSearch = stay.location || '';
                    stay.locationResults = [];
                    stay.showLocationDropdown = false;
                    stay.searchingLocations = false;
                });
            },

            initFlightsData() {
                this.flightsData.forEach(flight => {
                    if (flight.flightType === undefined || flight.flightType === 'recurring') {
                        flight.flightType = 'fixed';
                    }
                    if (!flight.tripType) flight.tripType = 'one_way';
                    if (!flight.returnSegments || !flight.returnSegments.length) {
                        flight.returnSegments = [this.newSegment()];
                    }

                    const initSeg = (seg) => {
                        if (seg.departure_date === undefined) seg.departure_date = '';
                        if (seg.arrival_date === undefined) seg.arrival_date = '';
                        if (!seg.iata) seg.iata = '';   // ← ADD
                        if (!seg.class) seg.class = 'economy'; // ← ADD
                        if (!seg.cabin_baggage) seg.cabin_baggage = '';   // ← ADD
                        if (!seg.baggage) seg.baggage = '';   // ← ADD
                    };

                    flight.segments.forEach(initSeg);
                    flight.returnSegments.forEach(initSeg);
                });
            },

            // Nested Room Management
            addStayRoom(stayIndex) {
                this.staysData[stayIndex].rooms.push({
                    id: Date.now() + Math.random(),
                    type: '',
                    occupancy: '',
                    images: [],
                    previewImages: [],
                    imagesToDelete: [],
                    defaultImage: ''
                });
            },
            removeStayRoom(stayIndex, roomIndex) {
                this.staysData[stayIndex].rooms.splice(roomIndex, 1);
            },

            // Stay Image Management
            handleStayImageUpload(stayIndex, event) {
                const files = event.target.files;
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    if (file.size > 5 * 1024 * 1024) continue;
                    if (!file.type.startsWith('image/')) continue;

                    const reader = new FileReader();
                    reader.onload = (e) => {
                        this.staysData[stayIndex].previewImages.push({
                            preview: e.target.result,
                            name: file.name,
                            size: file.size,
                            file: file
                        });
                    };
                    reader.readAsDataURL(file);
                }
                event.target.value = '';
            },
            removeStayPreviewImage(stayIndex, imgIndex) {
                this.staysData[stayIndex].previewImages.splice(imgIndex, 1);
            },


            // Room Gallery Management (Phase 4)
            handleRoomImageUpload(stayIndex, roomIndex, event) {
                const files = event.target.files;
                const room = this.staysData[stayIndex].rooms[roomIndex];
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    if (file.size > 5 * 1024 * 1024) { continue; }
                    if (!file.type.startsWith('image/')) { continue; }

                    const reader = new FileReader();
                    reader.onload = (e) => {
                        room.previewImages.push({
                            preview: e.target.result,
                            name: file.name,
                            size: file.size,
                            file: file
                        });
                    };
                    reader.readAsDataURL(file);
                }
                event.target.value = '';
            },
            removeRoomPreviewImage(stayIndex, roomIndex, imgIndex) {
                this.staysData[stayIndex].rooms[roomIndex].previewImages.splice(imgIndex, 1);
            },
            toggleDeleteRoomImage(stayIndex, roomIndex, imageUrl) {
                const room = this.staysData[stayIndex].rooms[roomIndex];
                if (!room.imagesToDelete) room.imagesToDelete = [];
                const idx = room.imagesToDelete.indexOf(imageUrl);
                if (idx > -1) {
                    room.imagesToDelete.splice(idx, 1);
                } else {
                    room.imagesToDelete.push(imageUrl);
                    if (room.defaultImage === imageUrl) room.defaultImage = '';
                }
            },
            setRoomDefaultImage(stayIndex, roomIndex, imageUrl) {
                const room = this.staysData[stayIndex].rooms[roomIndex];
                if (!room.imagesToDelete || !room.imagesToDelete.includes(imageUrl)) {
                    room.defaultImage = imageUrl;
                }
            },

            // Drag and Drop (Global or specific)
            stayDraggedIndex: null,
                roomDraggedIndex: null,

                    dragStartStay(stayIndex, imgIndex) {
                this.stayDraggedIndex = { stayIndex, imgIndex };
            },
            dragOverStay(event) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            },
            dropStay(stayIndex, dropIndex) {
                if (!this.stayDraggedIndex || this.stayDraggedIndex.stayIndex !== stayIndex) return;
                const dragIndex = this.stayDraggedIndex.imgIndex;
                if (dragIndex === dropIndex) return;

                const stay = this.staysData[stayIndex];
                const newImages = [...stay.images];
                const [draggedItem] = newImages.splice(dragIndex, 1);
                newImages.splice(dropIndex, 0, draggedItem);
                stay.images = newImages;
                this.stayDraggedIndex = null;
            },

            dragStartRoom(stayIndex, roomIndex, imgIndex) {
                this.roomDraggedIndex = { stayIndex, roomIndex, imgIndex };
            },
            dragOverRoom(event) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            },
            dropRoom(stayIndex, roomIndex, dropIndex) {
                if (!this.roomDraggedIndex || this.roomDraggedIndex.stayIndex !== stayIndex || this.roomDraggedIndex.roomIndex !== roomIndex) return;
                const dragIndex = this.roomDraggedIndex.imgIndex;
                if (dragIndex === dropIndex) return;

                const room = this.staysData[stayIndex].rooms[roomIndex];
                const newImages = [...room.images];
                const [draggedItem] = newImages.splice(dragIndex, 1);
                newImages.splice(dropIndex, 0, draggedItem);
                room.images = newImages;
                this.roomDraggedIndex = null;
            },

            formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
            },

            // Transfer/Car Methods
            addTransfer() {
                this.transfersData.push({
                    type: '',
                    from: '',
                    to: '',
                    travel_date: '',
                    travel_time: '',
                    notes: '',
                    activeTab: 'general',
                    images: [],
                    previewImages: [],
                    imagesToDelete: [],
                    defaultImage: ''
                });
            },
            removeTransfer(index) {
                if (this.transfersData.length > 1) {
                    this.transfersData.splice(index, 1);
                }
            },

            handleTransferGalleryUpload(transferIndex, event) {
                const transfer = this.transfersData[transferIndex];
                if (!transfer) return;
                const files = Array.from(event.target.files);
                files.forEach(function (file) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        transfer.previewImages.push({ url: e.target.result, file: file });
                    };
                    reader.readAsDataURL(file);
                });
            },

            // Transfer/Car Images
            toggleTransferImageDelete(transferIndex, imageUrl) {
                const transfer = this.transfersData[transferIndex];
                if (!transfer) return;
                if (!transfer.imagesToDelete) transfer.imagesToDelete = [];

                const idx = transfer.imagesToDelete.indexOf(imageUrl);
                if (idx > -1) {
                    transfer.imagesToDelete.splice(idx, 1);
                } else {
                    transfer.imagesToDelete.push(imageUrl);
                    if (transfer.defaultImage === imageUrl) transfer.defaultImage = '';
                }
            },

            setTransferDefaultImage(transferIndex, imageUrl) {
                const transfer = this.transfersData[transferIndex];
                if (!transfer) return;
                if (!transfer.imagesToDelete || !transfer.imagesToDelete.includes(imageUrl)) {
                    transfer.defaultImage = imageUrl;
                }
            },

            removeTransferPreviewImage(transferIndex, imgIndex) {
                const transfer = this.transfersData[transferIndex];
                if (!transfer) return;
                transfer.previewImages.splice(imgIndex, 1);
            },

            // Main Gallery Management
            toggleDeleteImage(imageUrl) {
                if (!this.imagesToDelete) this.imagesToDelete = [];
                const idx = this.imagesToDelete.indexOf(imageUrl);
                if (idx > -1) {
                    this.imagesToDelete.splice(idx, 1);
                } else {
                    this.imagesToDelete.push(imageUrl);
                    if (this.defaultImage === imageUrl) this.defaultImage = '';
                }
            },

            setDefaultImage(imageUrl) {
                if (!this.imagesToDelete || !this.imagesToDelete.includes(imageUrl)) {
                    this.defaultImage = imageUrl;
                }
            },

            removeNewImage(imgIndex) {
                this.previewImages.splice(imgIndex, 1);
            },


            // Add a connecting segment to an existing flight entry
            addSegment(flightIdx) {
                const flight = this.flightsData[flightIdx];
                const lastSeg = flight.segments[flight.segments.length - 1];

                // Check if last segment is filled before adding new
                const missing = [];
                const needsSelect = [];

                if (!lastSeg.airline || String(lastSeg.airline).trim() === '') {
                    if (lastSeg.airlineSearch && lastSeg.airlineSearch.trim() !== '') {
                        needsSelect.push("Airline");
                    } else {
                        missing.push("Airline");
                    }
                }

                if (!lastSeg.departure_airport || String(lastSeg.departure_airport).trim() === '') {
                    if (lastSeg.fromSearch && lastSeg.fromSearch.trim() !== '') {
                        needsSelect.push("Departure Airport");
                    } else {
                        missing.push("Departure Airport");
                    }
                }

                if (!lastSeg.arrival_airport || String(lastSeg.arrival_airport).trim() === '') {
                    if (lastSeg.toSearch && lastSeg.toSearch.trim() !== '') {
                        needsSelect.push("Arrival Airport");
                    } else {
                        missing.push("Arrival Airport");
                    }
                }

                if (!lastSeg.departure_date || String(lastSeg.departure_date).trim() === '') missing.push("Departure Date");
                if (!lastSeg.arrival_date || String(lastSeg.arrival_date).trim() === '') missing.push("Arrival Date");

                if (needsSelect.length > 0) {
                    vt.error("Please SELECT " + needsSelect.join(", ") + " from the dropdown results.");
                    return;
                }

                if (missing.length > 0) {
                    vt.error("Required: " + missing.join(", ") + ".");
                    return;
                }

                // Suggest next leg values
                const nextPrefill = {
                    airline: lastSeg.airline,
                    airlineSearch: lastSeg.airlineSearch,
                    departure_airport: lastSeg.arrival_airport,
                    fromSearch: lastSeg.toSearch,
                    departure_date: lastSeg.arrival_date
                };

                flight.segments.push(this.newSegment(nextPrefill));
            },

            // Add a flight entry
            addFlightEntry() {
                this.flightsData.push(this.newFlightEntry());
            },

            // Remove a flight entry
            removeFlightEntry(index) {
                if (this.flightsData.length > 1) {
                    this.flightsData.splice(index, 1);
                }
            },

            // Add a connecting segment (Outbound)
            addSegment(flightIdx) {
                const flight = this.flightsData[flightIdx];
                const lastSeg = flight.segments[flight.segments.length - 1];

                const nextPrefill = {
                    airline: lastSeg.airline,
                    airlineSearch: lastSeg.airlineSearch,
                    iata: lastSeg.iata,
                    departure_airport: lastSeg.arrival_airport,
                    fromSearch: lastSeg.toSearch,
                    departure_date: lastSeg.arrival_date,
                    departure_day: lastSeg.arrival_day,
                    class: lastSeg.class,
                    cabin_baggage: lastSeg.cabin_baggage,
                    baggage: lastSeg.baggage
                };

                flight.segments.push(this.newSegment(nextPrefill));
            },

            // Remove a connecting segment (Outbound)
            removeSegment(flightIdx, segIdx) {
                const flight = this.flightsData[flightIdx];
                if (flight.segments.length > 1) {
                    flight.segments.splice(segIdx, 1);
                }
            },

            // Add a return segment
            addReturnSegment(flightIdx) {
                const flight = this.flightsData[flightIdx];
                const lastSeg = flight.returnSegments[flight.returnSegments.length - 1];

                const nextPrefill = {
                    airline: lastSeg.airline,
                    airlineSearch: lastSeg.airlineSearch,
                    iata: lastSeg.iata,
                    departure_airport: lastSeg.arrival_airport,
                    fromSearch: lastSeg.toSearch,
                    departure_date: lastSeg.arrival_date,
                    departure_day: lastSeg.arrival_day,
                    class: lastSeg.class,
                    cabin_baggage: lastSeg.cabin_baggage,
                    baggage: lastSeg.baggage
                };

                flight.returnSegments.push(this.newSegment(nextPrefill));
            },

            // Remove a return segment
            removeReturnSegment(flightIdx, segIdx) {
                const flight = this.flightsData[flightIdx];
                if (flight.returnSegments.length > 1) {
                    flight.returnSegments.splice(segIdx, 1);
                }
            },


            // Copy a return segment (duplicate)
            copyReturnSegment(flightIdx, segIdx) {
                const flight = this.flightsData[flightIdx];
                const seg = flight.returnSegments[segIdx];
                const clone = JSON.parse(JSON.stringify(seg));
                clone.id = Date.now() + Math.random();
                clone.departure_date = '';
                clone.departure_time = '';
                clone.arrival_date = '';
                clone.arrival_time = '';
                clone.duration = '';
                flight.returnSegments.splice(segIdx + 1, 0, clone);
            },

            // Swap from/to airports in a return segment
            swapReturnAirports(flightIdx, segIdx) {
                const flight = this.flightsData[flightIdx];
                const seg = flight.returnSegments[segIdx];
                const tempAirport = seg.departure_airport;
                seg.departure_airport = seg.arrival_airport;
                seg.arrival_airport = tempAirport;
                const tempSearch = seg.fromSearch;
                seg.fromSearch = seg.toSearch;
                seg.toSearch = tempSearch;
            },

            // Calculate and update duration for a return segment
            updateReturnSegmentDuration(flightIdx, segIdx) {
                const flight = this.flightsData[flightIdx];
                const seg = flight.returnSegments[segIdx];
                if (!seg.departure_time || !seg.arrival_time) { seg.duration = ''; return; }
                const depDate = seg.departure_date ? new Date(seg.departure_date + 'T' + seg.departure_time) : new Date('2000-01-01T' + seg.departure_time);
                let arrDate = seg.arrival_date ? new Date(seg.arrival_date + 'T' + seg.arrival_time) : new Date('2000-01-01T' + seg.arrival_time);
                if (arrDate < depDate) arrDate.setDate(arrDate.getDate() + 1);
                const diffMs = arrDate - depDate;
                if (diffMs < 0) { seg.duration = ''; return; }
                const hours = Math.floor(diffMs / 3600000);
                const mins = Math.floor((diffMs % 3600000) / 60000);
                seg.duration = hours > 0 ? (mins > 0 ? `${hours}h ${mins}m` : `${hours}h`) : `${mins}m`;
            },
            // Copy and reverse outbound journey to return journey
            copyToReturnSegments(flightIdx) {
                const flight = this.flightsData[flightIdx];
                // Clone and reverse segments
                const newReturn = [...flight.segments].reverse().map(seg => {
                    const clone = JSON.parse(JSON.stringify(seg));
                    // Swap airports
                    const tempAir = clone.departure_airport;
                    clone.departure_airport = clone.arrival_airport;
                    clone.arrival_airport = tempAir;

                    const tempSearch = clone.fromSearch;
                    clone.fromSearch = clone.toSearch;
                    clone.toSearch = tempSearch;

                    // Clear times for manual entry or offset calculation if needed
                    clone.departure_time = '';
                    clone.arrival_time = '';
                    clone.departure_date = '';
                    clone.arrival_date = '';

                    return clone;
                });
                flight.returnSegments = newReturn;
            },

            handleTripTypeChange(flight, type, flightIdx) {
                flight.tripType = type;
                if (type === 'round_trip') {
                    this.copyToReturnSegments(flightIdx);
                }
            },

            // Swap airports in a segment
            swapAirports(seg) {
                const tempAirport = seg.departure_airport;
                seg.departure_airport = seg.arrival_airport;
                seg.arrival_airport = tempAirport;

                const tempSearch = seg.fromSearch;
                seg.fromSearch = seg.toSearch;
                seg.toSearch = tempSearch;
            },

                // Airline Search Methods
                async searchAirlines(flightIdx, segIdx, isReturn = false) {
                const flight = this.flightsData[flightIdx];
                const seg = isReturn ? flight.returnSegments[segIdx] : flight.segments[segIdx];

                if (!seg.airlineSearch || seg.airlineSearch.length < 2) {
                    seg.airlineResults = [];
                    seg.showAirlineDropdown = false;
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('search', seg.airlineSearch);
                    const response = await fetch('<?= root ?>admin/flights/search-airlines', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (data.success) {
                        seg.airlineResults = data.airlines;
                        seg.showAirlineDropdown = seg.airlineResults.length > 0;
                    }
                } catch (error) { console.error(error); }
            },


            selectAirline(flightIdx, segIdx, airline, isReturn = false) {
                const flight = this.flightsData[flightIdx];
                const seg = isReturn ? flight.returnSegments[segIdx] : flight.segments[segIdx];
                seg.airline = airline.name;
                seg.code = airline.code;
                seg.airlineSearch = airline.name + ' (' + airline.iata + ')';
                seg.showAirlineDropdown = false;
                seg.airlineResults = [];
            },
        
                async searchReturnAirline(flightIdx, segIdx) {
                const flight = this.flightsData[flightIdx];
                const seg = flight.returnSegments[segIdx];

                if (!seg.airlineSearch || seg.airlineSearch.length < 2) {
                    seg.airlineResults = [];
                    seg.showAirlineDropdown = false;
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('search', seg.airlineSearch);
                    const response = await fetch('<?= root ?>admin/flights/search-airlines', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (data.success) {
                        seg.airlineResults = data.airlines;
                        seg.showAirlineDropdown = seg.airlineResults.length > 0;
                    }
                } catch (error) { console.error(error); }
            },

            selectReturnAirline(flightIdx, segIdx, airline) {
                const seg = this.flightsData[flightIdx].returnSegments[segIdx];
                seg.airline = airline.name;
                seg.code = airline.code;
                seg.airlineSearch = airline.name + ' (' + airline.code + ')';
                seg.showAirlineDropdown = false;
                seg.airlineResults = [];
            },

                async searchReturnFrom(flightIdx, segIdx) {
                const seg = this.flightsData[flightIdx].returnSegments[segIdx];
                if (!seg.fromSearch || seg.fromSearch.length < 2) {
                    seg.fromResults = [];
                    seg.showFromDropdown = false;
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('search', seg.fromSearch);
                    const response = await fetch('<?= root ?>admin/flights/search-airports', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (data.success) {
                        seg.fromResults = data.airports;
                        seg.showFromDropdown = seg.fromResults.length > 0;
                    }
                } catch (error) { console.error(error); }
            },

            selectReturnFrom(flightIdx, segIdx, airport) {
                const seg = this.flightsData[flightIdx].returnSegments[segIdx];
                seg.departure_airport = airport.code + ' - ' + airport.city;
                seg.fromSearch = airport.city + ' (' + airport.code + ')';
                seg.showFromDropdown = false;
                seg.fromResults = [];
            },

                async searchReturnTo(flightIdx, segIdx) {
                const seg = this.flightsData[flightIdx].returnSegments[segIdx];
                if (!seg.toSearch || seg.toSearch.length < 2) {
                    seg.toResults = [];
                    seg.showToDropdown = false;
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('search', seg.toSearch);
                    const response = await fetch('<?= root ?>admin/flights/search-airports', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (data.success) {
                        seg.toResults = data.airports;
                        seg.showToDropdown = seg.toResults.length > 0;
                    }
                } catch (error) { console.error(error); }
            },

            selectReturnTo(flightIdx, segIdx, airport) {
                const seg = this.flightsData[flightIdx].returnSegments[segIdx];
                seg.arrival_airport = airport.code + ' - ' + airport.city;
                seg.toSearch = airport.city + ' (' + airport.code + ')';
                seg.showToDropdown = false;
                seg.toResults = [];
            },
                // Airport Search Methods
                async searchFrom(flightIdx, segIdx, isReturn = false) {
                const flight = this.flightsData[flightIdx];
                const seg = isReturn ? flight.returnSegments[segIdx] : flight.segments[segIdx];
                if (!seg.fromSearch || seg.fromSearch.length < 2) {
                    seg.fromResults = [];
                    seg.showFromDropdown = false;
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('search', seg.fromSearch);
                    const response = await fetch('<?= root ?>admin/flights/search-airports', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (data.success) {
                        seg.fromResults = data.airports;
                        seg.showFromDropdown = seg.fromResults.length > 0;
                    }
                } catch (error) { console.error(error); }
            },

            selectFrom(flightIdx, segIdx, airport, isReturn = false) {
                const flight = this.flightsData[flightIdx];
                const seg = isReturn ? flight.returnSegments[segIdx] : flight.segments[segIdx];
                seg.departure_airport = airport.code + ' - ' + airport.city;
                seg.fromSearch = airport.city + ' (' + airport.code + ')';
                seg.showFromDropdown = false;
                seg.fromResults = [];
            },

                async searchTo(flightIdx, segIdx, isReturn = false) {
                const flight = this.flightsData[flightIdx];
                const seg = isReturn ? flight.returnSegments[segIdx] : flight.segments[segIdx];
                if (!seg.toSearch || seg.toSearch.length < 2) {
                    seg.toResults = [];
                    seg.showToDropdown = false;
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('search', seg.toSearch);
                    const response = await fetch('<?= root ?>admin/flights/search-airports', { method: 'POST', body: formData });
                    const data = await response.json();
                    if (data.success) {
                        seg.toResults = data.airports;
                        seg.showToDropdown = seg.toResults.length > 0;
                    }
                } catch (error) { console.error(error); }
            },

            selectTo(flightIdx, segIdx, airport, isReturn = false) {
                const flight = this.flightsData[flightIdx];
                const seg = isReturn ? flight.returnSegments[segIdx] : flight.segments[segIdx];
                seg.arrival_airport = airport.code + ' - ' + airport.city;
                seg.toSearch = airport.city + ' (' + airport.code + ')';
                seg.showToDropdown = false;
                seg.toResults = [];
            },

            // Helper: Calculate duration between two times/dates
            updateSegmentDuration(flightIdx, segIdx, isReturn = false) {
                const flight = this.flightsData[flightIdx];
                const seg = isReturn ? flight.returnSegments[segIdx] : flight.segments[segIdx];

                seg.duration = this.calculateDuration(
                    seg.departure_time,
                    seg.arrival_time,
                    seg.departure_date,
                    seg.arrival_date,
                    flight.flightType
                );
            },

            calculateDuration(departureTime, arrivalTime, departureDate, arrivalDate, flightType) {
                if (!departureTime || !arrivalTime) return '';

                if (flightType === 'fixed' && departureDate && arrivalDate) {
                    const depDateTime = new Date(departureDate + 'T' + departureTime);
                    const arrDateTime = new Date(arrivalDate + 'T' + arrivalTime);

                    if (isNaN(depDateTime.getTime()) || isNaN(arrDateTime.getTime())) return '';

                    let diffMs = arrDateTime - depDateTime;
                    if (diffMs < 0) diffMs += 86400000;

                    return this.formatDuration(diffMs);
                }

                const [depHours, depMinutes] = departureTime.split(':').map(Number);
                const [arrHours, arrMinutes] = arrivalTime.split(':').map(Number);

                let totalMinutes = (arrHours * 60 + arrMinutes) - (depHours * 60 + depMinutes);
                if (totalMinutes < 0) totalMinutes += 1440;

                return this.formatDuration(totalMinutes * 60000);
            },

            formatDuration(ms) {
                const totalMinutes = Math.floor(ms / 60000);
                const hours = Math.floor(totalMinutes / 60);
                const minutes = totalMinutes % 60;

                if (hours === 0) return `${minutes}m`;
                if (minutes === 0) return `${hours}h`;
                return `${hours}h ${minutes}m`;
            },

            calculateLayover(currentSeg, nextSeg) {
                if (!currentSeg || !nextSeg || !currentSeg.arrival_date || !currentSeg.arrival_time || !nextSeg.departure_date || !nextSeg.departure_time) return '';

                const arr = new Date(currentSeg.arrival_date + 'T' + currentSeg.arrival_time);
                const dep = new Date(nextSeg.departure_date + 'T' + nextSeg.departure_time);

                if (isNaN(arr.getTime()) || isNaN(dep.getTime())) return '';

                const diffMs = dep - arr;
                if (diffMs < 0) return '';

                return this.formatDuration(diffMs);
            },

            calculateTotalJourneyTime(flight) {
                if (!flight || !flight.segments || flight.segments.length === 0) return '';

                let totalMs = 0;
                for (let i = 0; i < flight.segments.length; i++) {
                    const seg = flight.segments[i];
                    if (seg.departure_time && seg.arrival_time && seg.departure_date && seg.arrival_date) {
                        const dep = new Date(seg.departure_date + 'T' + seg.departure_time);
                        const arr = new Date(seg.arrival_date + 'T' + seg.arrival_time);
                        if (!isNaN(dep.getTime()) && !isNaN(arr.getTime())) {
                            let diff = arr - dep;
                            if (diff < 0) diff += 86400000;
                            totalMs += diff;
                        }
                    }

                    if (i < flight.segments.length - 1) {
                        const seg1 = flight.segments[i];
                        const seg2 = flight.segments[i + 1];
                        if (seg1.arrival_time && seg1.arrival_date && seg2.departure_time && seg2.departure_date) {
                            const arr1 = new Date(seg1.arrival_date + 'T' + seg1.arrival_time);
                            const dep2 = new Date(seg2.departure_date + 'T' + seg2.departure_time);
                            if (!isNaN(arr1.getTime()) && !isNaN(dep2.getTime())) {
                                const layover = dep2 - arr1;
                                if (layover > 0) totalMs += layover;
                            }
                        }
                    }
                }

                return totalMs > 0 ? this.formatDuration(totalMs) : '';
            },

            switchTab(tab) {
                this.activeTab = tab;
                if (tab === 'translations') {
                    window.location.hash = tab + ':' + this.activeTranslationTab;
                    if (!this.translationTabInitialized) {
                        setTimeout(() => {
                            const descTextarea = document.getElementById('umrah-trans-desc-' + this.activeTranslationTab);
                            if (descTextarea && !editorInstances.translations.desc[this.activeTranslationTab]) {
                                initializeTranslationEditor(descTextarea, this.activeTranslationTab);
                            }
                            this.translationTabInitialized = true;
                        }, 100);
                    }
                } else {
                    window.location.hash = tab;
                }
            },

            switchTranslationTab(langCode) {
                this.activeTranslationTab = langCode;
                window.location.hash = 'translations:' + langCode;
                setTimeout(() => {
                    if (!editorInstances.translations.desc[langCode]) {
                        const descTextarea = document.getElementById('umrah-trans-desc-' + langCode);
                        if (descTextarea) {
                            initializeTranslationEditor(descTextarea, langCode);
                        }
                    }
                }, 100);
            },

            loadTabFromHash() {
                const savedTab = sessionStorage.getItem('umrah_active_tab');
                if (savedTab) {
                    sessionStorage.removeItem('umrah_active_tab');
                    if (savedTab.includes(':')) {
                        const [mainTab, langCode] = savedTab.split(':');
                        if (mainTab === 'translations' && langCode) {
                            this.activeTab = 'translations';
                            this.activeTranslationTab = langCode;
                            window.location.hash = savedTab;
                            setTimeout(() => {
                                const descTextarea = document.getElementById('umrah-trans-desc-' + langCode);
                                if (descTextarea && !editorInstances.translations.desc[langCode]) {
                                    initializeTranslationEditor(descTextarea, langCode);
                                }
                                this.translationTabInitialized = true;
                            }, 200);
                            return;
                        }
                    } else {
                        const validTabs = ['general', 'pricing', 'itinerary', 'services', 'gallery', 'seo', 'translations'];
                        if (validTabs.includes(savedTab)) {
                            this.activeTab = savedTab;
                            window.location.hash = savedTab;
                            return;
                        }
                    }
                }

                const hash = window.location.hash.substring(1);
                if (!hash) return;

                if (hash.includes(':')) {
                    const [mainTab, langCode] = hash.split(':');
                    if (mainTab === 'translations' && langCode) {
                        this.activeTab = 'translations';
                        this.activeTranslationTab = langCode;
                        setTimeout(() => {
                            const descTextarea = document.getElementById('umrah-trans-desc-' + langCode);
                            if (descTextarea && !editorInstances.translations.desc[langCode]) {
                                initializeTranslationEditor(descTextarea, langCode);
                            }
                            this.translationTabInitialized = true;
                        }, 200);
                    }
                } else {
                    const validTabs = ['general', 'pricing', 'itinerary', 'services', 'gallery', 'seo', 'translations'];
                    if (validTabs.includes(hash)) {
                        this.activeTab = hash;
                        if (hash === 'translations') {
                            setTimeout(() => {
                                const descTextarea = document.getElementById('umrah-trans-desc-' + this.activeTranslationTab);
                                if (descTextarea && !editorInstances.translations.desc[this.activeTranslationTab]) {
                                    initializeTranslationEditor(descTextarea, this.activeTranslationTab);
                                }
                                this.translationTabInitialized = true;
                            }, 200);
                        }
                    }
                }
            },


            <?php if ($isEdit): ?>
                toggleDeleteImage(imageUrl) {
                    const index = this.imagesToDelete.indexOf(imageUrl);
                    if (index > -1) {
                        this.imagesToDelete.splice(index, 1);
                    } else {
                        this.imagesToDelete.push(imageUrl);
                        if (this.defaultImage === imageUrl) {
                            this.defaultImage = '';
                        }
                    }
                },

                setDefaultImage(imageUrl) {
                    if (!this.imagesToDelete.includes(imageUrl)) {
                        this.defaultImage = imageUrl;
                    }
                },

                dragStart(index) {
                    this.draggedIndex = index;
                },

                dragOver(event, index) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                },

                drop(dropIndex) {
                    if (this.draggedIndex === null || this.draggedIndex === dropIndex) {
                        this.draggedIndex = null;
                        return;
                    }
                    const newImages = [...this.umrahImages];
                    const [draggedItem] = newImages.splice(this.draggedIndex, 1);
                    newImages.splice(dropIndex, 0, draggedItem);
                    this.umrahImages = newImages;
                    this.draggedIndex = null;
                },

                openLightbox(imageUrl) {
                    this.lightboxImage = imageUrl;
                    this.showLightbox = true;
                    document.body.style.overflow = 'hidden';
                },

                closeLightbox() {
                    this.showLightbox = false;
                    this.lightboxImage = '';
                    document.body.style.overflow = '';
                },

            <?php endif; ?>

            closeTransferLightbox() {
                this.showTransferLightbox = false;
                this.transferLightboxImage = '';
                document.body.style.overflow = '';
            },

            handleImageUpload(event) {
                const files = event.target.files;
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    if (file.size > 5 * 1024 * 1024) {
                        alert('<?= @T::file_too_large ?: "File is too large" ?>: ' + file.name + '. <?= @T::max_5mb ?: "Maximum 5MB allowed" ?>');
                        continue;
                    }
                    if (!file.type.startsWith('image/')) {
                        alert('<?= @T::invalid_file_type ?: "Invalid file type" ?>: ' + file.name);
                        continue;
                    }
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        this.previewImages.push({
                            preview: e.target.result,
                            name: file.name,
                            size: file.size,
                            file: file
                        });
                    };
                    reader.readAsDataURL(file);
                }
                event.target.value = '';
            },

            removeNewImage(index) {
                this.previewImages.splice(index, 1);
            },

            formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
            },
        

                async searchUsers() {
                if (this.userSearch.length < 2) {
                    this.searchResults = [];
                    this.showUserDropdown = false;
                    return;
                }
                this.searchingUsers = true;
                try {
                    const formData = new FormData();
                    formData.append('search', this.userSearch);
                    const response = await fetch('<?= root . admin ?>/umrah/search-users', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if (data.success) {
                        this.searchResults = data.users;
                        this.showUserDropdown = this.searchResults.length > 0;
                    }
                } catch (error) {
                    console.error('Error searching users:', error);
                } finally {
                    this.searchingUsers = false;
                }
            },

            selectUser(user) {
                this.selectedUser = { user_id: user.user_id, first_name: user.first_name, last_name: user.last_name, email: user.email };
                this.userSearch = user.first_name + ' ' + user.last_name;
                this.showUserDropdown = false;
                this.searchResults = [];
                const userIdInput = document.getElementById('user_id_input');
                if (userIdInput) {
                    userIdInput.value = user.user_id;
                }
            },

            clearUserSelection() {
                this.selectedUser = null;
                this.userSearch = '';
                this.searchResults = [];
                this.showUserDropdown = false;
                const userIdInput = document.getElementById('user_id_input');
                if (userIdInput) {
                    userIdInput.value = '';
                }
            },

            addItineraryDay() {
                this.itinerary.push({
                    day: this.itinerary.length + 1,
                    title: '',
                    description: '',
                    activities: []
                });
            },

            removeItineraryDay(index) {
                if (confirm('<?= T::confirm_remove_day ?? 'Are you sure you want to remove this day?' ?>')) {
                    this.itinerary.splice(index, 1);
                    this.itinerary.forEach((item, idx) => {
                        item.day = idx + 1;
                    });
                }
            },

            addActivity(dayIndex) {
                if (!this.itinerary[dayIndex].activities) {
                    this.itinerary[dayIndex].activities = [];
                }
                this.itinerary[dayIndex].activities.push({
                    title: '',
                    description: '',
                    images: []
                });
            },

            removeActivity(dayIndex, activityIndex) {
                if (confirm('<?= T::confirm_remove_activity ?? 'Are you sure you want to remove this activity?' ?>')) {
                    this.itinerary[dayIndex].activities.splice(activityIndex, 1);
                }
            },

            handleActivityImages(event, dayIndex, activityIndex) {
                const files = event.target.files;
                if (!this.itinerary[dayIndex].activities[activityIndex].images) {
                    this.itinerary[dayIndex].activities[activityIndex].images = [];
                }

                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    if (file.size > 5 * 1024 * 1024) {
                        alert('File is too large. Maximum 5MB allowed');
                        continue;
                    }
                    if (!file.type.startsWith('image/')) {
                        alert('Invalid file type. Please select an image');
                        continue;
                    }

                    const reader = new FileReader();
                    reader.onload = (e) => {
                        this.itinerary[dayIndex].activities[activityIndex].images.push({
                            preview: e.target.result,
                            file: file,
                            url: '',
                            isNew: true
                        });
                    };
                    reader.readAsDataURL(file);
                }
                event.target.value = '';
            },

            removeActivityImage(dayIndex, activityIndex, imageIndex) {
                this.itinerary[dayIndex].activities[activityIndex].images.splice(imageIndex, 1);
            },

            openStayLightbox(imageUrl) {
                this.stayLightboxImage = imageUrl;
                this.showStayLightbox = true;
                document.body.style.overflow = 'hidden';
            },
            closeStayLightbox() {
                this.showStayLightbox = false;
                this.stayLightboxImage = '';
                document.body.style.overflow = '';
            },
            openRoomLightbox(imageUrl) {
                this.roomLightboxImage = imageUrl;
                this.showRoomLightbox = true;
                document.body.style.overflow = 'hidden';
            },
            closeRoomLightbox() {
                this.showRoomLightbox = false;
                this.roomLightboxImage = '';
                document.body.style.overflow = '';
            },
            openActivityLightbox(imageUrl) {
                this.activityImageLightbox = imageUrl;
                this.showActivityLightbox = true;
                document.body.style.overflow = 'hidden';
            },

            closeActivityLightbox() {
                this.showActivityLightbox = false;
                this.activityImageLightbox = '';
                document.body.style.overflow = '';
            },

            dragActivityImageStart(dayIndex, activityIndex, imageIndex) {
                this.draggedActivityImage = { dayIndex, activityIndex, imageIndex };
            },

            dropActivityImage(dayIndex, activityIndex, dropIndex) {
                if (!this.draggedActivityImage) return;
                if (this.draggedActivityImage.dayIndex !== dayIndex ||
                    this.draggedActivityImage.activityIndex !== activityIndex ||
                    this.draggedActivityImage.imageIndex === dropIndex) {
                    this.draggedActivityImage = null;
                    return;
                }

                const images = this.itinerary[dayIndex].activities[activityIndex].images;
                const [draggedItem] = images.splice(this.draggedActivityImage.imageIndex, 1);
                images.splice(dropIndex, 0, draggedItem);
                this.draggedActivityImage = null;
            },

            validateForm() {
                const errors = [];
                const requiredFields = [
                    { name: 'umrah_name', label: '<?= T::name ?? 'Umrah Name' ?>', tab: 'general' },
                    { name: 'currency', label: '<?= T::currency ?? 'Currency' ?>', tab: 'pricing' }
                ];

                document.querySelectorAll('.input-error, .select-error').forEach(el => {
                    el.classList.remove('input-error', 'select-error');
                });
                document.querySelectorAll('.error-message').forEach(el => el.remove());

                requiredFields.forEach(field => {
                    let isInvalid = false;
                    let element = null;

                    if (field.customCheck) {
                        isInvalid = field.customCheck();
                        element = document.querySelector(`input[name="${field.name}"]`)?.closest('.relative') ||
                            document.querySelector(`input[name="${field.name}"]`)?.parentElement;
                    } else {
                        element = document.querySelector(`input[name="${field.name}"], select[name="${field.name}"], textarea[name="${field.name}"]`);
                        if (element) {
                            const value = element.value.trim();
                            isInvalid = !value;
                        }
                    }

                    if (isInvalid) {
                        errors.push({
                            field: field.name,
                            label: field.label,
                            tab: field.tab,
                            element: element
                        });
                    }
                });

                // Strict Dynamic Services Validation
                if (this.isTypeSelected('flight')) {
                    this.flightsData.forEach((flight, fIdx) => {
                        flight.segments.forEach((seg, sIdx) => {
                            const flightLabel = this.flightsData.length > 1 ? `Flight #${fIdx + 1} - ` : "";
                            const legName = sIdx === 0 ? "Direct Leg" : `Connecting Leg ${sIdx}`;
                            const prefix = `${flightLabel}${legName}`;

                            // Airline
                            if (!seg.airline || String(seg.airline).trim() === '') {
                                if (seg.airlineSearch && seg.airlineSearch.trim() !== '') {
                                    errors.push({ label: `Flight: Please SELECT Airline for ${prefix}`, tab: 'services' });
                                } else {
                                    errors.push({ label: `Flight: Airline for ${prefix}`, tab: 'services' });
                                }
                            }

                            // Departure Airport
                            if (!seg.departure_airport || String(seg.departure_airport).trim() === '') {
                                if (seg.fromSearch && seg.fromSearch.trim() !== '') {
                                    errors.push({ label: `Flight: Please SELECT Departure Airport for ${prefix}`, tab: 'services' });
                                } else {
                                    errors.push({ label: `Flight: Departure Airport for ${prefix}`, tab: 'services' });
                                }
                            }

                            // Arrival Airport
                            if (!seg.arrival_airport || String(seg.arrival_airport).trim() === '') {
                                if (seg.toSearch && seg.toSearch.trim() !== '') {
                                    errors.push({ label: `Flight: Please SELECT Arrival Airport for ${prefix}`, tab: 'services' });
                                } else {
                                    errors.push({ label: `Flight: Arrival Airport for ${prefix}`, tab: 'services' });
                                }
                            }

                            if (!seg.departure_date) errors.push({ label: `Flight: Departure Date for ${prefix}`, tab: 'services' });
                            if (!seg.arrival_date) errors.push({ label: `Flight: Arrival Date for ${prefix}`, tab: 'services' });
                        });
                    });
                }

                if (this.isTypeSelected('hotel')) {
                    this.staysData.forEach((stay, idx) => {
                        const hotelLabel = this.staysData.length > 1 ? `Stay #${idx + 1}` : "Stay";
                        if (!stay.hotel_name) errors.push({ label: `${hotelLabel}: Name`, tab: 'services' });
                        if (!stay.location) errors.push({ label: `${hotelLabel}: Location`, tab: 'services' });
                        if (!stay.check_in) errors.push({ label: `${hotelLabel}: Check-in`, tab: 'services' });
                        if (!stay.check_out) errors.push({ label: `${hotelLabel}: Check-out`, tab: 'services' });

                        if (stay.rooms) {
                            stay.rooms.forEach((room, rIdx) => {
                                if (!room.type || room.type.trim() === '') {
                                    errors.push({ label: `${hotelLabel} - Room #${rIdx + 1}: Type`, tab: 'services' });
                                }
                            });
                        }
                    });
                }

                if (this.isTypeSelected('car')) {
                    this.transfersData.forEach((transfer, idx) => {
                        const transferLabel = this.transfersData.length > 1 ? `<?= T::transfer ?? 'Transfer' ?> #${idx + 1}` : "<?= T::transfer ?? 'Transfer' ?>";
                        if (!transfer.type) errors.push({ label: `${transferLabel}: Vehicle Type`, tab: 'services' });
                        if (!transfer.from) errors.push({ label: `${transferLabel}: Pickup From`, tab: 'services' });
                        if (!transfer.to) errors.push({ label: `${transferLabel}: Drop-off To`, tab: 'services' });
                        if (!transfer.travel_date) errors.push({ label: `${transferLabel}: Date/Time`, tab: 'services' });
                    });
                }

                return errors;
            },

            showValidationErrors(errors) {
                if (errors.length === 0) return;

                this.switchTab(errors[0].tab);

                errors.forEach(error => {
                    if (error.element) {
                        const inputElement = error.element.querySelector('input, select, textarea') || error.element;
                        if (inputElement) {
                            inputElement.classList.add('input-error');
                            inputElement.style.borderColor = '#EF4444';
                            inputElement.style.backgroundColor = '#FEF2F2';
                        }

                        const errorMsg = document.createElement('p');
                        errorMsg.className = 'error-message text-red-600 text-xs mt-1 flex items-center gap-1';
                        errorMsg.innerHTML = `
                        <span class="material-symbols-outlined text-sm">error</span>
                        <span>${error.label} <?= T::is_required ?? 'is required' ?></span>
                    `;

                        if (inputElement.parentElement) {
                            inputElement.parentElement.appendChild(errorMsg);
                        }
                    }
                });

                const errorList = errors.map(e => e.label).join(', ');
                vt.error(`<?= T::please_fill_required_fields ?? 'Please fill all required fields' ?>: ${errorList}`);
                if (errors[0].element) {
                    setTimeout(() => {
                        errors[0].element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 300);
                }
            },

            submitForm(event) {
                event.preventDefault();

                const errors = this.validateForm();
                if (errors.length > 0) {
                    this.showValidationErrors(errors);
                    return false;
                }

                this.loading = true;


                try {
                    if (editorInstances.main.description) {
                        const mainTextarea = document.querySelector('#umrah_description');
                        if (mainTextarea) {
                            mainTextarea.value = editorInstances.main.description.getData();
                        }
                    }

                    for (const [langCode, editor] of Object.entries(editorInstances.translations.desc)) {
                        if (editor) {
                            const textarea = document.querySelector(`textarea[name='desc_translations[${langCode}]']`);
                            if (textarea) {
                                textarea.value = editor.getData();
                            }
                        }
                    }

                    if (this.activeTab === 'translations' && this.activeTranslationTab) {
                        sessionStorage.setItem('umrah_active_tab', this.activeTab + ':' + this.activeTranslationTab);
                    } else {
                        sessionStorage.setItem('umrah_active_tab', this.activeTab);
                    }

                    const formData = new FormData(event.target);

                    // 1. Activity Images (Itinerary)
                    this.itinerary.forEach((day, dayIndex) => {
                        if (day.activities && day.activities.length > 0) {
                            day.activities.forEach((activity, actIndex) => {
                                if (activity.images && activity.images.length > 0) {
                                    activity.images.forEach((img, imgIndex) => {
                                        if (img.isNew && img.file) {
                                            const fileKey = 'activity_image_' + day.day + '_' + actIndex + '_' + imgIndex;
                                            formData.append(fileKey, img.file);
                                        }
                                    });
                                }
                            });
                        }
                    });

                    // 2. Main Gallery Images
                    if (this.previewImages.length > 0) {
                        formData.delete('umrah_images[]');
                        this.previewImages.forEach((image) => {
                            if (image.file) {
                                formData.append('umrah_images[]', image.file);
                            }
                        });
                    }

                    // 3. Stay & Room Images
                    this.staysData.forEach((stay, stayIdx) => {
                        // Stay Main Images
                        if (stay.previewImages && stay.previewImages.length > 0) {
                            formData.delete('stay_images_' + stayIdx + '[]');
                            stay.previewImages.forEach((img, imgIdx) => {
                                if (img.file) {
                                    formData.append('stay_images_' + stayIdx + '[]', img.file);
                                }
                            });
                        }

                        // Room Gallery Images - Nested Rooms
                        if (stay.rooms && stay.rooms.length > 0) {
                            stay.rooms.forEach((room, roomIdx) => {
                                if (room.previewImages && room.previewImages.length > 0) {
                                    formData.delete('room_images_' + stayIdx + '_' + roomIdx + '[]');
                                    room.previewImages.forEach((img, imgIdx) => {
                                        if (img.file) {
                                            formData.append('room_images_' + stayIdx + '_' + roomIdx + '[]', img.file);
                                        }
                                    });
                                }
                            });
                        }
                    });

                    // 3c. Transfer Gallery Images
                    this.transfersData.forEach((transfer, transferIdx) => {
                        if (transfer.previewImages && transfer.previewImages.length > 0) {
                            formData.delete('transfer_images_' + transferIdx + '[]');
                            transfer.previewImages.forEach((img, imgIdx) => {
                                if (img.file) {
                                    formData.append('transfer_images_' + transferIdx + '[]', img.file);
                                }
                            });
                        }
                    });

                    // 4. Clean JSON data (Remove Base64 previews and temporary properties)
                    const cleanStaysData = JSON.parse(JSON.stringify(this.staysData));
                    cleanStaysData.forEach(stay => {
                        delete stay.previewImages;
                        delete stay.roomPreviewImages;
                        delete stay.locationResults;
                        delete stay.searchingLocations;
                        delete stay.showLocationDropdown;
                        delete stay.locationSearch;

                        if (stay.rooms) {
                            stay.rooms.forEach(room => {
                                delete room.previewImages;
                                delete room.imagesToDelete;
                            });
                        }
                    });

                    const cleanItinerary = JSON.parse(JSON.stringify(this.itinerary));
                    cleanItinerary.forEach(day => {
                        if (day.activities) {
                            day.activities.forEach(act => {
                                if (act.images) {
                                    act.images.forEach(img => {
                                        delete img.preview;
                                    });
                                }
                            });
                        }
                    });

                    const cleanTransfersData = JSON.parse(JSON.stringify(this.transfersData));
                    cleanTransfersData.forEach(transfer => {
                        if (transfer.travel_date && transfer.travel_time) {
                            transfer.date = transfer.travel_date + 'T' + transfer.travel_time;
                        } else if (transfer.travel_date) {
                            transfer.date = transfer.travel_date;
                        } else {
                            transfer.date = '';
                        }
                        delete transfer.travel_date;
                        delete transfer.travel_time;
                        delete transfer.previewImages;
                        delete transfer.activeTab;
                    });

                    // Set final JSON strings
                    formData.set('flights_data', JSON.stringify(this.flightsData));
                    formData.set('stays_data', JSON.stringify(cleanStaysData));
                    formData.set('transfers_data', JSON.stringify(cleanTransfersData));
                    formData.set('services', JSON.stringify(this.selectedServices));
                    formData.set('itinerary', JSON.stringify(cleanItinerary));

                    <?php if ($isEdit): ?>
                        if (this.umrahImages && this.umrahImages.length > 0) {
                            formData.set('reordered_images', JSON.stringify(this.umrahImages));
                        }
                        if (this.imagesToDelete && this.imagesToDelete.length > 0) {
                            formData.set('images_to_delete', JSON.stringify(this.imagesToDelete));
                        }
                        if (this.defaultImage) {
                            formData.set('default_image', this.defaultImage);
                        }
                    <?php endif; ?>

                    const userIdInput = document.getElementById('user_id_input');
                    if (userIdInput) {
                        if (this.selectedUser && this.selectedUser.user_id) {
                            formData.set('user_id', this.selectedUser.user_id);
                        } else if (this.selectedUser === null) {
                            formData.set('user_id', '');
                        }
                    }

                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 120000); // 120 seconds timeout

                    fetch(event.target.action, {
                        method: 'POST',
                        body: formData,
                        signal: controller.signal
                    })
                        .then(response => {
                            // If server redirected (e.g. add -> edit), navigate the browser there
                            // so the URL bar reflects the new location.
                            if (response.redirected && response.url && response.url !== window.location.href) {
                                window.location.href = response.url;
                                return null;
                            }
                            return response.text();
                        })
                        .then(html => {
                            if (html === null) return;
                            // Replace page content
                            document.open();
                            document.write(html);
                            document.close();
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                            if (typeof vt !== 'undefined') {
                                vt.error('Submission failed: ' + error.message);
                            } else {
                                alert('Submission failed: ' + error.message);
                            }
                            this.loading = false;
                        });
                } catch (err) {
                    console.error('JS Submission Logic Error:', err);
                    if (typeof vt !== 'undefined') {
                        vt.error('JS Error during submission: ' + err.message);
                    } else {
                        alert('JS Error during submission: ' + err.message);
                    }
                    this.loading = false;
                }

                return false;
            },

            // Calculate flight duration between two times
            _legacy_calculateDuration(departureTime, arrivalTime, departureDate, arrivalDate) {
                if (!departureTime || !arrivalTime || !departureDate || !arrivalDate) return '';

                const depDateTime = new Date(departureDate + 'T' + departureTime);
                const arrDateTime = new Date(arrivalDate + 'T' + arrivalTime);

                if (isNaN(depDateTime.getTime()) || isNaN(arrDateTime.getTime())) return '';

                let diffMs = arrDateTime - depDateTime;
                if (diffMs < 0) return 'Invalid';

                return this.formatDuration(diffMs);
            },

            _legacy_calculateLayover(seg1, seg2) {
                if (!seg1 || !seg2 || !seg1.arrival_time || !seg1.arrival_date || !seg2.departure_time || !seg2.departure_date) return '';

                const arr1 = new Date(seg1.arrival_date + 'T' + seg1.arrival_time);
                const dep2 = new Date(seg2.departure_date + 'T' + seg2.departure_time);

                if (isNaN(arr1.getTime()) || isNaN(dep2.getTime())) return '';

                const diffMs = dep2 - arr1;
                if (diffMs < 0) return 'Invalid';

                return this.formatDuration(diffMs);
            },

            _legacy_calculateTotalJourneyTime(flight) {
                if (!flight || !flight.segments || flight.segments.length === 0) return '';

                let totalMs = 0;
                for (let i = 0; i < flight.segments.length; i++) {
                    const seg = flight.segments[i];
                    if (seg.departure_time && seg.arrival_time && seg.departure_date && seg.arrival_date) {
                        const dep = new Date(seg.departure_date + 'T' + seg.departure_time);
                        const arr = new Date(seg.arrival_date + 'T' + seg.arrival_time);
                        if (!isNaN(dep.getTime()) && !isNaN(arr.getTime())) {
                            totalMs += (arr - dep);
                        }
                    }

                    if (i < flight.segments.length - 1) {
                        const seg1 = flight.segments[i];
                        const seg2 = flight.segments[i + 1];
                        if (seg1.arrival_time && seg1.arrival_date && seg2.departure_time && seg2.departure_date) {
                            const arr1 = new Date(seg1.arrival_date + 'T' + seg1.arrival_time);
                            const dep2 = new Date(seg2.departure_date + 'T' + seg2.departure_time);
                            if (!isNaN(arr1.getTime()) && !isNaN(dep2.getTime())) {
                                totalMs += (dep2 - arr1);
                            }
                        }
                    }
                }

                return totalMs > 0 ? this.formatDuration(totalMs) : '';
            },

            formatDuration(ms) {
                const totalMinutes = Math.floor(ms / 60000);
                const hours = Math.floor(totalMinutes / 60);
                const minutes = totalMinutes % 60;

                if (hours === 0) return `${minutes}m`;
                if (minutes === 0) return `${hours}h`;
                return `${hours}h ${minutes}m`;
            },

            updateSegmentDuration(flightIdx, segIdx) {
                const seg = this.flightsData[flightIdx].segments[segIdx];
                seg.duration = this.calculateDuration(
                    seg.departure_time,
                    seg.arrival_time,
                    seg.departure_date,
                    seg.arrival_date
                );
            },

            init() {
                // Prevent browser from auto-scrolling to hash anchor on page load
                if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
                window.scrollTo(0, 0);
                requestAnimationFrame(() => window.scrollTo(0, 0));

                this.initStaysData();
                this.initFlightsData();
                this.loadTabFromHash();
                window.addEventListener('hashchange', () => {
                    this.loadTabFromHash();
                });
            }
        };
        }
    </script>
<?php endif; ?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span
                class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root . admin ?>/umrah"
                class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200" x-data="umrahFormData()" x-init="init()">
        <div class="border-b border-gray-200">
            <nav class="flex overflow-x-auto -mb-px">
                <button @click="switchTab('general')"
                    :class="activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">info</span>
                    <?= T::general_info ?? 'General Info' ?>
                </button>

                <button @click="switchTab('pricing')"
                    :class="activeTab === 'pricing' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">payments</span>
                    <?= T::pricing ?? 'Pricing' ?>
                </button>


                <button @click="switchTab('itinerary')"
                    :class="activeTab === 'itinerary' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">calendar_month</span>
                    <?= T::itinerary ?? 'Itinerary' ?>
                </button>

                <button @click="switchTab('services')"
                    :class="activeTab === 'services' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">checklist</span>
                    <?= T::services ?? 'Services' ?>
                </button>

                <button @click="switchTab('gallery')"
                    :class="activeTab === 'gallery' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">photo_library</span>
                    <?= T::gallery ?? 'Gallery' ?>
                </button>

                <button @click="switchTab('seo')"
                    :class="activeTab === 'seo' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">search</span>
                    <?= T::seo ?? 'SEO' ?>
                </button>

                <button @click="switchTab('translations')"
                    :class="activeTab === 'translations' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">translate</span>
                    <?= T::translations ?? 'Translations' ?>
                </button>
            </nav>
        </div>

        <form method="POST" action="<?= root . admin ?>/umrah/<?= $isEdit ? 'edit/' . $umrah['id'] : 'add' ?>"
            @submit="submitForm($event)" enctype="multipart/form-data" class="p-6" x-transition>
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="active_tab" :value="activeTab">
            <input type="hidden" name="user_id" value="<?= $isEdit ? htmlspecialchars($umrah['user_id'] ?? '') : '' ?>">

            <!-- Tab: General Info -->
            <div x-show="activeTab === 'general'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">mosque</span>
                        <?= T::basic_information ?? 'Basic Information' ?>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div class="form-control md:col-span-1">
                            <label class="text-sm font-medium block mb-1"><?= T::owner ?? 'Owner' ?></label>
                            <div class="relative" @click.away="showUserDropdown = false">
                                <!-- Selected User Display -->
                                <div x-show="selectedUser" class="input text-sm flex items-center gap-2">
                                    <div class="flex-1 overflow-hidden">
                                        <span class="font-medium text-gray-900 truncate"
                                            x-text="selectedUser ? selectedUser.first_name + ' ' + selectedUser.last_name : ''"></span>
                                    </div>
                                    <button type="button" @click.stop="clearUserSelection()"
                                        class="flex-shrink-0 rounded p-1">
                                        <span class="material-symbols-outlined text-gray-500 text-base">close</span>
                                    </button>
                                </div>

                                <!-- Search Input -->
                                <div x-show="!selectedUser">
                                    <div class="relative">
                                        <input type="text" x-model="userSearch" @input.debounce.300ms="searchUsers()"
                                            @focus="searchUsers()"
                                            placeholder="<?= T::search_by_name_or_email ?? 'Search by name or email...' ?>"
                                            class="input text-sm pr-10" autocomplete="off">
                                        <div x-show="searchingUsers" class="absolute right-3 top-1/2 -translate-y-1/2">
                                            <div
                                                class="animate-spin rounded-full h-4 w-4 border-2 border-gray-400 border-t-transparent">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- User Search Dropdown -->
                                    <div x-show="showUserDropdown"
                                        class="absolute z-50 w-full mt-1 bg-white rounded-lg shadow-xl border border-gray-200 max-h-60 overflow-y-auto">
                                        <template x-for="user in searchResults" :key="user.user_id">
                                            <div @click="selectUser(user)"
                                                class="px-4 py-3 hover:bg-gray-50 border-b border-gray-50 last:border-0 transition-colors cursor-pointer">
                                                <div class="font-medium text-gray-900 text-sm"
                                                    x-text="user.first_name + ' ' + user.last_name"></div>
                                                <div class="text-xs text-gray-500" x-text="user.email"></div>
                                            </div>
                                        </template>
                                        <div x-show="searchResults.length === 0 && userSearch.length >= 2 && !searchingUsers"
                                            class="px-4 py-3 text-xs text-gray-500 text-center">
                                            <?= T::no_users_found ?? 'No users found' ?>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="user_id" id="user_id_input"
                                    value="<?= $umrah['user_id'] ?? '' ?>">
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 mb-4">
                        <div class="form-control">
                            <label class="required text-sm font-medium block mb-1"><?= T::name ?? 'Umrah name' ?>
                                *</label>
                            <input type="text" name="umrah_name" class="input text-sm"
                                value="<?= htmlspecialchars($umrah['name'] ?? '') ?>"
                                placeholder="<?= T::enter_umrah_name ?? 'Enter Umrah name' ?>">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4 items-end">
                        <div class="form-control">
                            <label
                                class="required text-sm font-medium block mb-1"><?= T::destination ?? 'Destination' ?>
                                *</label>
                            <?php
                            $allowedUmrahDestinations = [
                                'Makkah' => ['lat' => '21.4225', 'lng' => '39.8262'],
                                'Madinah' => ['lat' => '24.4709', 'lng' => '39.6113'],
                                'Jeddah' => ['lat' => '21.4858', 'lng' => '39.1925'],
                            ];
                            $currentUmrahDestination = $umrah['destination'] ?? '';
                            ?>
                            <div class="relative">
                                <span
                                    class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none z-10">location_on</span>
                                <select name="destination" class="select text-sm w-full pl-9" x-model="locationSearch"
                                    @change="applyPresetDestination($event.target.value)" required>
                                    <option value=""><?= T::select_city ?? 'Select City' ?></option>
                                    <?php foreach ($allowedUmrahDestinations as $cityName => $coords): ?>
                                            <option value="<?= htmlspecialchars($cityName) ?>"
                                                <?= $currentUmrahDestination === $cityName ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cityName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" name="latitude" x-model="mainLatitude">
                        <input type="hidden" name="longitude" x-model="mainLongitude">

                        <div class="form-control">
                            <label class="text-sm font-medium block mb-1"><?= T::umrah_type ?? 'Umrah type' ?></label>
                            <select name="umrah_type_id" class="select text-sm w-full">
                                <option value=""><?= T::select_type ?? 'Select Type' ?></option>
                                <?php if (isset($umrah_types) && is_array($umrah_types)):
                                    foreach ($umrah_types as $type): ?>
                                                <option value="<?= $type['id'] ?>" <?= $umrah['umrah_type_id'] == $type['id'] ? 'selected' : '' ?>><?= $type['setting_label'] ?></option>
                                        <?php endforeach; endif; ?>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="text-sm font-medium block mb-1"><?= T::days ?? 'Days' ?></label>
                            <input type="number" name="days" class="input text-sm w-full" min="1"
                                value="<?= $umrah['days'] ?? 1 ?>" placeholder="1">
                        </div>

                        <div class="form-control">
                            <label class="text-sm font-medium block mb-1"><?= T::nights ?? 'Nights' ?></label>
                            <input type="number" name="nights" class="input text-sm w-full" min="0"
                                value="<?= $umrah['nights'] ?? 0 ?>" placeholder="0">
                        </div>
                    </div>

                    <div class="form-control mt-4">
                        <label class="text-sm font-medium block mb-1"><?= T::description ?? 'Description' ?></label>
                        <textarea id="umrah_description" name="description"
                            class="quill-source hidden"><?= htmlspecialchars($umrah['description'] ?? '') ?></textarea>
                        <div id="umrah_description_quill" class="quill-host" data-source="#umrah_description"
                            style="min-height:400px;"></div>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">settings</span>
                        <?= T::umrah_settings ?? 'Umrah Settings' ?>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="flex items-center justify-between">
                                <label for="status"
                                    class="cursor-pointer text-sm font-medium transition-colors hover:text-blue-600"><?= T::active ?? 'Active' ?></label>
                                <div class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2"
                                    role="switch" :aria-checked="status.toString()" @click="status = !status"
                                    :class="status ? 'bg-blue-600' : 'bg-gray-200'">
                                    <input type="checkbox" name="status" :value="status ? 1 : 0" id="status"
                                        class="sr-only" :checked="status">
                                    <span
                                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                        :class="status ? 'translate-x-5' : 'translate-x-0'"></span>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="flex items-center justify-between">
                                <label for="featured"
                                    class="cursor-pointer text-sm font-medium transition-colors hover:text-blue-600"><?= T::featured ?? 'Featured' ?></label>
                                <div class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2"
                                    role="switch" :aria-checked="featured.toString()" @click="featured = !featured"
                                    :class="featured ? 'bg-blue-600' : 'bg-gray-200'">
                                    <input type="checkbox" name="featured" :value="featured ? 1 : 0" id="featured"
                                        class="sr-only" :checked="featured">
                                    <span
                                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                        :class="featured ? 'translate-x-5' : 'translate-x-0'"></span>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <div class="flex items-center justify-between">
                                <label for="refundable"
                                    class="cursor-pointer text-sm font-medium transition-colors hover:text-blue-600"><?= T::refundable ?? 'Refundable' ?></label>
                                <div class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2"
                                    role="switch" :aria-checked="refundable.toString()"
                                    @click="refundable = !refundable"
                                    :class="refundable ? 'bg-blue-600' : 'bg-gray-200'">
                                    <input type="checkbox" name="refundable" :value="refundable ? 1 : 0" id="refundable"
                                        class="sr-only" :checked="refundable">
                                    <span
                                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                        :class="refundable ? 'translate-x-5' : 'translate-x-0'"></span>
                                </div>
                            </div>
                        </div>


                    </div>
                </div>


                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">contact_phone</span>
                        <?= T::contact_information ?? 'Contact Information' ?>
                    </h3>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="form-control">
                            <label class="text-sm font-medium block mb-1"><?= T::email ?? 'Email' ?></label>
                            <input type="email" name="email" class="input text-sm"
                                value="<?= htmlspecialchars($umrah['email'] ?? '') ?>"
                                placeholder="<?= T::email_placeholder ?? 'contact@example.com' ?>">
                        </div>

                        <div class="form-control">
                            <label class="text-sm font-medium block mb-1"><?= T::phone ?? 'Phone' ?></label>
                            <input type="text" name="phone" class="input text-sm"
                                value="<?= htmlspecialchars($umrah['phone'] ?? '') ?>"
                                placeholder="<?= T::phone_placeholder ?? '+1 (555) 123-4567' ?>">
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">policy</span>
                        <?= T::policies ?? 'Policies' ?>
                    </h3>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="form-control">
                            <label class="text-sm"><?= T::cancellation_policy ?? 'Cancellation Policy' ?></label>
                            <textarea name="cancellation_policy" class="textarea text-sm" rows="4"
                                placeholder="<?= T::enter_cancellation_policy ?? 'Enter cancellation policy' ?>"><?= htmlspecialchars($umrah['cancellation_policy'] ?? '') ?></textarea>
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::terms_conditions ?? 'Terms & Conditions' ?></label>
                            <textarea name="terms_conditions" class="textarea text-sm" rows="4"
                                placeholder="<?= T::enter_terms_conditions ?? 'Enter terms and conditions' ?>"><?= htmlspecialchars($umrah['terms_conditions'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Pricing (CONDITIONAL BASED ON ALLOWED PERSONS) -->
            <div x-show="activeTab === 'pricing'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">payments</span>
                        <?= T::pricing_information ?? 'Pricing Information' ?>
                    </h3>

                    <div class="grid grid-cols-1 gap-4 mb-4">
                        <div class="form-control">
                            <label class="required text-sm"><?= T::currency ?? 'Currency' ?> *</label>
                            <select name="currency" class="select text-sm">
                                <option value=""><?= T::select_currency ?? 'Select Currency' ?></option>
                                <?php if (isset($currencies) && is_array($currencies)):
                                    foreach ($currencies as $curr): ?>
                                                <option value="<?= $curr['name'] ?>" <?= $umrah['currency'] == $curr['name'] ? 'selected' : '' ?>><?= $curr['name'] ?></option>
                                        <?php endforeach; endif; ?>
                            </select>
                        </div>
                    </div>


                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                        <div class="form-control">
                            <label class="text-sm"><?= T::adult_price ?? 'Adult Price' ?></label>
                            <input type="number" name="adult_price" class="input text-sm" min="0" step="0.01"
                                value="<?= $umrah['adult_price'] ?? 0 ?>" placeholder="0.00">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::child_price ?? 'Child Price' ?></label>
                            <input type="number" name="child_price" class="input text-sm" min="0" step="0.01"
                                value="<?= $umrah['child_price'] ?? 0 ?>" placeholder="0.00">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::infant_price ?? 'Infant Price' ?></label>
                            <input type="number" name="infant_price" class="input text-sm" min="0" step="0.01"
                                value="<?= $umrah['infant_price'] ?? 0 ?>" placeholder="0.00">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <div class="form-control">
                            <label class="text-sm"><?= T::max_adults ?? 'Max Adults' ?></label>
                            <input type="number" name="max_adults" class="input text-sm" min="1"
                                value="<?= $umrah['max_adults'] ?? 1 ?>" placeholder="1">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::max_children ?? 'Max Children' ?></label>
                            <input type="number" name="max_children" class="input text-sm" min="0"
                                value="<?= $umrah['max_children'] ?? 0 ?>" placeholder="0">
                        </div>

                        <div class="form-control">
                            <label class="text-sm"><?= T::max_infants ?? 'Max Infants' ?></label>
                            <input type="number" name="max_infants" class="input text-sm" min="0"
                                value="<?= $umrah['max_infants'] ?? 0 ?>" placeholder="0">
                        </div>
                    </div>
                </div>
            </div>


            <!-- Tab: Itinerary (WITH GALLERY-STYLE ACTIVITY IMAGES) -->
            <div x-show="activeTab === 'itinerary'" x-transition class="space-y-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="mb-4 pb-2 border-b border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-blue-600 text-lg">calendar_month</span>
                            <?= T::umrah_itinerary ?? 'Umrah Itinerary' ?>
                        </h3>
                    </div>

                    <template x-if="itinerary.length === 0">
                        <div class="text-center py-8">
                            <span class="material-symbols-outlined text-6xl text-gray-300">event_note</span>
                            <p class="text-gray-500 mt-2 mb-4">
                                <?= T::no_itinerary_days_added ?? 'No itinerary days added yet' ?></p>
                            <button type="button" @click="addItineraryDay()" class="btn text-sm">
                                <span class="material-symbols-outlined text-lg">add</span>
                                <?= T::add_day ?? 'Add Day' ?>
                            </button>
                        </div>
                    </template>

                    <div class="space-y-6">
                        <template x-for="(day, dayIndex) in itinerary" :key="dayIndex">
                            <div class="bg-white rounded-lg p-6 border-2 border-gray-200 shadow-sm">
                                <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="bg-blue-100 text-blue-600 rounded-full w-10 h-10 flex items-center justify-center font-bold text-lg">
                                            <span x-text="day.day"></span>
                                        </div>
                                        <h4 class="font-semibold text-gray-900 text-lg"><?= T::day ?? 'Day' ?> <span
                                                x-text="day.day"></span></h4>
                                    </div>
                                    <button type="button" @click="removeItineraryDay(dayIndex)"
                                        class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 rounded transition-colors">
                                        <span class="material-symbols-outlined" style="font-size: 16px;">delete</span>
                                        <span><?= T::remove ?? 'Remove' ?></span>
                                    </button>
                                </div>

                                <div class="space-y-4 mb-5">
                                    <div class="form-control">
                                        <label class="text-sm block mb-1"><?= T::title ?? 'Title' ?></label>
                                        <input type="text" x-model="day.title" class="input text-sm"
                                            placeholder="<?= T::day_title_hint ?? 'e.g., Arrival & City Visit' ?>">
                                    </div>

                                    <div class="form-control">
                                        <label class="text-sm block mb-1"><?= T::description ?? 'Description' ?></label>
                                        <textarea x-model="day.description" class="textarea text-sm" rows="3"
                                            placeholder="<?= T::day_brief_overview ?? 'Brief overview of the day...' ?>"></textarea>
                                    </div>

                                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-xs block mb-1"><?= T::location ?? 'Location' ?></label>
                                            <div class="relative"
                                                x-data="{ showDropdown: false, searching: false, results: [] }"
                                                @click.away="showDropdown = false">
                                                <input type="text" x-model="day.location" @input.debounce.300ms="
                                                           if (day.location.length >= 2) {
                                                               searching = true;
                                                               fetch('<?= root . admin ?>/umrah/search-locations', {
                                                                   method: 'POST',
                                                                   body: (() => {
                                                                       const fd = new FormData();
                                                                       fd.append('search', day.location);
                                                                       return fd;
                                                                   })()
                                                               })
                                                               .then(r => r.json())
                                                               .then(data => {
                                                                   results = data.success ? data.locations : [];
                                                                   showDropdown = results.length > 0;
                                                                   searching = false;
                                                               })
                                                               .catch(() => { searching = false; });
                                                           } else {
                                                               results = [];
                                                               showDropdown = false;
                                                           }
                                                       " @focus="if (results.length > 0) showDropdown = true"
                                                    class="input text-xs"
                                                    placeholder="<?= T::search_location ?? 'Search location...' ?>">

                                                <div x-show="searching"
                                                    class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                                    <svg class="animate-spin h-3 w-3 text-gray-400" fill="none"
                                                        viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                                            stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor"
                                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                        </path>
                                                    </svg>
                                                </div>

                                                <div x-show="showDropdown"
                                                    class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                    <template x-for="location in results" :key="location.id">
                                                        <div @click="
                                                                day.location = location.city + ', ' + location.country;
                                                                day.latitude = location.latitude || '';
                                                                day.longitude = location.longitude || '';
                                                                showDropdown = false;
                                                             "
                                                            class="px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                            <div class="font-medium text-gray-900 text-xs"
                                                                x-text="location.city + ', ' + location.country"></div>
                                                            <div class="text-[10px] text-gray-500"
                                                                x-text="location.country_code"></div>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs block mb-1"><?= T::latitude ?? 'Latitude' ?></label>
                                            <input type="text" x-model="day.latitude" class="input text-xs bg-gray-50"
                                                placeholder="<?= T::auto_filled ?? 'Auto-filled' ?>" readonly>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-xs block mb-1"><?= T::longitude ?? 'Longitude' ?></label>
                                            <input type="text" x-model="day.longitude" class="input text-xs bg-gray-50"
                                                placeholder="<?= T::auto_filled ?? 'Auto-filled' ?>" readonly>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                                    <div class="mb-4">
                                        <h5 class="font-semibold text-gray-900 flex items-center gap-2">
                                            <span class="material-symbols-outlined text-green-600">local_activity</span>
                                            <?= T::activities ?? 'Activities' ?>
                                            <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full"
                                                x-text="(day.activities || []).length"></span>
                                        </h5>
                                    </div>

                                    <template x-if="!day.activities || day.activities.length === 0">
                                        <div class="text-center py-6">
                                            <span
                                                class="material-symbols-outlined text-4xl text-gray-300">explore</span>
                                            <p class="text-gray-500 text-sm mt-2">
                                                <?= T::no_activities_added ?? 'No activities added yet' ?></p>
                                        </div>
                                    </template>

                                    <div class="space-y-4">
                                        <template x-for="(activity, actIndex) in day.activities" :key="actIndex">
                                            <div class="bg-white rounded-lg p-4 border border-gray-300">
                                                <div class="flex items-center justify-between mb-3">
                                                    <span
                                                        class="text-sm font-semibold text-gray-700"><?= T::activity ?? 'Activity' ?>
                                                        <span x-text="actIndex + 1"></span></span>
                                                    <button type="button" @click="removeActivity(dayIndex, actIndex)"
                                                        class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 rounded transition-colors">
                                                        <span class="material-symbols-outlined"
                                                            style="font-size: 16px;">delete</span>
                                                        <span><?= T::remove ?? 'Remove' ?></span>
                                                    </button>
                                                </div>

                                                <div class="space-y-3">
                                                    <div class="form-control">
                                                        <label
                                                            class="text-xs block mb-1"><?= T::title ?? 'Title' ?></label>
                                                        <input type="text" x-model="activity.title"
                                                            class="input text-xs"
                                                            placeholder="<?= T::activity_title_hint ?? 'e.g., Museum Visit' ?>">
                                                    </div>

                                                    <div class="form-control">
                                                        <label
                                                            class="text-xs block mb-1"><?= T::description ?? 'Description' ?></label>
                                                        <textarea x-model="activity.description"
                                                            class="textarea text-xs" rows="2"
                                                            placeholder="<?= T::activity_details ?? 'Activity details...' ?>"></textarea>
                                                    </div>
                                                    <!-- GALLERY-STYLE ACTIVITY IMAGES -->
                                                    <div class="form-control">
                                                        <label
                                                            class="text-xs block mb-2"><?= T::images ?? 'Images' ?></label>

                                                        <!-- Existing Images Grid -->
                                                        <template x-if="activity.images && activity.images.length > 0">
                                                            <div class="activity-image-grid mb-3">
                                                                <template x-for="(img, imgIndex) in activity.images"
                                                                    :key="imgIndex">
                                                                    <div class="activity-image-item" draggable="true"
                                                                        @dragstart="dragActivityImageStart(dayIndex, actIndex, imgIndex)"
                                                                        @dragover.prevent
                                                                        @drop.prevent="dropActivityImage(dayIndex, actIndex, imgIndex)">
                                                                        <img :src="img.preview || ('<?= root ?>' + img.url)"
                                                                            class="w-full h-full object-cover"
                                                                            alt="Activity Image">
                                                                        <div class="activity-image-overlay">
                                                                            <button type="button"
                                                                                @click="openActivityLightbox(img.preview || ('<?= root ?>' + img.url))"
                                                                                class="bg-blue-500 hover:bg-blue-600 text-white w-8 h-8 rounded-full flex items-center justify-center">
                                                                                <span
                                                                                    class="material-symbols-outlined text-sm">visibility</span>
                                                                            </button>
                                                                            <button type="button"
                                                                                @click="removeActivityImage(dayIndex, actIndex, imgIndex)"
                                                                                class="bg-red-500 hover:bg-red-600 text-white w-8 h-8 rounded-full flex items-center justify-center">
                                                                                <span
                                                                                    class="material-symbols-outlined text-sm">delete</span>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>

                                                        <!-- Upload Button -->
                                                        <div class="flex items-center gap-2">
                                                            <input type="file"
                                                                :id="'activity_images_' + day.day + '_' + actIndex"
                                                                @change="handleActivityImages($event, dayIndex, actIndex)"
                                                                accept="image/*" multiple class="hidden">
                                                            <label :for="'activity_images_' + day.day + '_' + actIndex"
                                                                class="btn white text-xs flex-1 cursor-pointer flex items-center justify-center gap-1">
                                                                <span
                                                                    class="material-symbols-outlined text-base">add_photo_alternate</span>
                                                                <span
                                                                    x-text="activity.images && activity.images.length > 0 ? '<?= T::upload_more_images ?? 'Upload More Images' ?>' : '<?= T::upload_images ?? 'Upload Images' ?>'"></span>
                                                            </label>
                                                        </div>
                                                        <p class="text-[10px] text-gray-500 mt-1">
                                                            <?= T::image_upload_hint ?? 'Max 5MB per image. You can upload multiple images and drag to reorder.' ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="mt-4 flex justify-end">
                                        <button type="button" @click="addActivity(dayIndex)" class="btn white text-sm">
                                            <span class="material-symbols-outlined text-base">add</span>
                                            <?= T::add_activity ?? 'Add Activity' ?>
                                        </button>
                                    </div>
                                </div>

                                <div x-show="dayIndex === itinerary.length - 1" class="mt-4 flex justify-end">
                                    <button type="button" @click="addItineraryDay()" class="btn text-sm">
                                        <span class="material-symbols-outlined text-lg">add</span>
                                        <?= T::add_day ?? 'Add Day' ?>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <input type="hidden" name="itinerary" :value="JSON.stringify(itinerary)">
                </div>
            </div>

            <!-- Tab: Services -->
            <div x-show="activeTab === 'services'" x-transition class="space-y-6">
                <?php if (!empty($services)):
                    $grouped_services = [];
                    foreach ($services as $service) {
                        $type = $service['setting_type'] ?? 'service';
                        $grouped_services[$type][] = $service;
                    }
                    $categories = array_intersect(['hotel', 'flight', 'car', 'service'], array_keys($grouped_services));
                    ?>
                        <div class="space-y-6">
                            <!-- Flat Service Selection -->
                            <div class="flex flex-wrap gap-x-6 gap-y-4 bg-white p-4 rounded-lg border border-gray-200">
                                <?php foreach ($categories as $type): ?>
                                        <?php foreach ($grouped_services[$type] as $service): ?>
                                                <label class="flex items-center gap-3 cursor-pointer group select-none">
                                                    <!-- Toggle track -->
                                                    <div class="relative flex-shrink-0" @click="toggleService(<?= $service['id'] ?>)">
                                                        <div class="w-10 h-5 rounded-full transition-colors duration-200"
                                                            :class="selectedServices.map(String).includes(String(<?= $service['id'] ?>)) ? 'bg-blue-600' : 'bg-gray-300'">
                                                        </div>
                                                        <div class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200"
                                                            :class="selectedServices.map(String).includes(String(<?= $service['id'] ?>)) ? 'translate-x-5' : 'translate-x-0'">
                                                        </div>
                                                    </div>
                                                    <span class="text-sm transition-colors"
                                                        :class="selectedServices.map(String).includes(String(<?= $service['id'] ?>)) ? 'text-gray-900 font-medium' : 'text-gray-500 group-hover:text-gray-700'">
                                                        <?= T(strtolower($service['setting_label'])) ?>
                                                    </span>
                                                </label>
                                        <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>

                            <!-- Dynamic Detail Forms (Positioned at bottom) -->
                            <div class="space-y-4">
                                <!-- Flight Information -->
                                <?php include __DIR__ . '/services/flights.php'; ?>

                                <!-- Hotel/Stay Information -->
                                <?php include __DIR__ . '/services/stays.php'; ?>

                                <!-- Transfer Information -->
                                <?php include __DIR__ . '/services/transfers.php'; ?>
                            </div>
                        </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Gallery -->
            <div x-show="activeTab === 'gallery'" x-transition class="space-y-6">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined">photo_library</span>
                        <?= T::umrah_gallery ?? 'Umrah Gallery' ?>
                    </h3>

                    <?php if ($isEdit): ?>
                            <div class="mb-6" x-show="umrahImages.length > 0">
                                <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg">image</span>
                                    <?= T::existing_images ?? 'Existing Images' ?> (<span x-text="umrahImages.length"></span>)
                                    <span
                                        class="text-xs text-gray-500 ml-2"><?= T::drag_to_reorder ?? 'Drag to reorder' ?></span>
                                </h4>

                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                    <template x-for="(img, index) in umrahImages" :key="index">
                                        <div class="relative group bg-white rounded-lg border-2 overflow-hidden transition-all duration-200"
                                            draggable="true" @dragstart="dragStart(index)"
                                            @dragover.prevent="dragOver($event, index)" @drop.prevent="drop(index)"
                                            @dragend="draggedIndex = null" :class="{
                                    'opacity-50': imagesToDelete.includes(img.url) || draggedIndex === index,
                                    'border-green-300 hover:border-green-400': img.url === defaultImage,
                                    'border-gray-200 hover:border-blue-400': img.url !== defaultImage,
                                    'cursor-move': !imagesToDelete.includes(img.url),
                                    'cursor-not-allowed': imagesToDelete.includes(img.url)
                                }" :style="imagesToDelete.includes(img.url) ? 'pointer-events: none;' : ''">
                                            <div class="aspect-video relative">
                                                <img :src="'<?= root ?>' + img.url" :alt="'Umrah Image ' + (index + 1)"
                                                    class="w-full h-full object-cover pointer-events-none" draggable="false">

                                                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2"
                                                    @mousedown.stop @click.stop>
                                                    <button type="button" @click="openLightbox(img.url)"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity bg-blue-500 hover:bg-blue-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                        <span class="material-symbols-outlined text-xl">visibility</span>
                                                    </button>

                                                    <button type="button" @click="setDefaultImage(img.url)"
                                                        x-show="!imagesToDelete.includes(img.url) && img.url !== defaultImage"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity bg-green-500 hover:bg-green-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                        <span class="material-symbols-outlined text-xl">check_circle</span>
                                                    </button>

                                                    <button type="button" @click="toggleDeleteImage(img.url)"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity text-white w-10 h-10 rounded-full flex items-center justify-center"
                                                        :class="imagesToDelete.includes(img.url) ? 'bg-gray-500 hover:bg-gray-600' : 'bg-red-500 hover:bg-red-600'">
                                                        <span class="material-symbols-outlined text-xl"
                                                            x-text="imagesToDelete.includes(img.url) ? 'undo' : 'delete'"></span>
                                                    </button>
                                                </div>

                                                <div x-show="img.url === defaultImage && !imagesToDelete.includes(img.url)"
                                                    class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                                    <span class="material-symbols-outlined text-sm">verified</span>
                                                    <span><?= T::default ?? 'Default' ?></span>
                                                </div>

                                                <div x-show="imagesToDelete.includes(img.url)"
                                                    class="absolute inset-0 bg-red-500 bg-opacity-80 flex items-center justify-center">
                                                    <div class="text-white text-center">
                                                        <span class="material-symbols-outlined text-4xl mb-2">delete</span>
                                                        <p class="text-sm font-semibold">
                                                            <?= T::marked_for_deletion ?? 'Marked for Deletion' ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <input type="hidden" name="reordered_images" :value="JSON.stringify(umrahImages)">
                            </div>
                    <?php endif; ?>

                    <div x-show="previewImages.length > 0" class="mt-6 mb-4" x-transition>
                        <div class="p-4 bg-green-50 rounded-lg border border-green-200 mb-4">
                            <div class="flex items-center gap-2 text-green-700">
                                <span class="material-symbols-outlined">check_circle</span>
                                <span class="font-medium">
                                    <span
                                        x-text="previewImages.length === 1 ? '1 <?= T::new_image ?> <?= T::ready_to_upload ?>' : previewImages.length + ' <?= T::new_images ?> <?= T::ready_to_upload ?>'"></span>
                                </span>
                            </div>
                        </div>

                        <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-lg">new_releases</span>
                            <?= T::image_previews ?? 'Image Previews' ?>
                        </h4>

                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                            <template x-for="(image, index) in previewImages" :key="index">
                                <div
                                    class="relative group bg-white rounded-lg border-2 border-green-300 overflow-hidden hover:border-green-500 transition-all duration-200">
                                    <div class="aspect-video relative">
                                        <img :src="image.preview" :alt="'New Preview ' + (index + 1)"
                                            class="w-full h-full object-cover">

                                        <div
                                            class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-200 flex items-center justify-center gap-2">
                                            <button type="button" @click="removeNewImage(index)"
                                                class="opacity-0 group-hover:opacity-100 transition-opacity bg-red-500 hover:bg-red-600 text-white w-10 h-10 rounded-full flex items-center justify-center">
                                                <span class="material-symbols-outlined text-xl">delete</span>
                                            </button>
                                        </div>

                                        <div
                                            class="absolute top-2 left-2 bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full flex items-center gap-1 shadow-lg">
                                            <span class="material-symbols-outlined text-sm">fiber_new</span>
                                            <span><?= T::new ?? 'New' ?></span>
                                        </div>
                                    </div>

                                    <!-- <div class="p-3 bg-gray-50">
                                        <p class="text-xs text-gray-600 truncate" x-text="image.name"></p>
                                        <p class="text-xs text-gray-500" x-text="formatFileSize(image.size)"></p>
                                    </div> -->
                                </div>
                            </template>
                        </div>
                    </div>

                    <div
                        class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-8">
                        <div class="text-center">
                            <div
                                class="inline-flex items-center justify-center w-20 h-20 bg-blue-100 rounded-full mb-4">
                                <span
                                    class="material-symbols-outlined text-4xl text-blue-600">add_photo_alternate</span>
                            </div>

                            <h4 class="text-lg font-semibold text-gray-900 mb-2">
                                <?= $isEdit ? (T::upload_more_images ?? 'Upload More Images') : (T::upload_images ?? 'Upload Images') ?>
                            </h4>
                            <p class="text-sm text-gray-600 mb-6"><?= T('drag_drop_images_hint') ?></p>

                            <label for="umrah_images_input" class="btn inline-flex items-center gap-2 cursor-pointer">
                                <span class="material-symbols-outlined">upload</span>
                                <span><?= T('choose_images') ?></span>
                            </label>

                            <input type="file" id="umrah_images_input" name="umrah_images[]" multiple accept="image/*"
                                class="hidden" @change="handleImageUpload($event)">

                            <p class="text-xs text-gray-500 mt-4">
                                <?= T::image_upload_requirements ?? 'Supported formats: JPG, PNG, WEBP • Max size: 5MB per image' ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($isEdit): ?>
                            <input type="hidden" name="images_to_delete" :value="JSON.stringify(imagesToDelete)">
                            <input type="hidden" name="default_image" :value="defaultImage">
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab: SEO -->
            <div x-show="activeTab === 'seo'" x-transition class="space-y-6">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined">search</span>
                        <?= T::seo_information ?? 'SEO Information' ?>
                    </h3>

                    <div class="space-y-4">
                        <div class="form-control">
                            <label class="text-sm font-medium block mb-1"><?= T::meta_title ?? 'Meta Title' ?></label>
                            <input type="text" name="meta_title" class="input text-sm"
                                value="<?= htmlspecialchars($umrah['meta_title'] ?? '') ?>"
                                placeholder="<?= T::enter_seo_meta_title ?? 'Enter SEO meta title' ?>">
                        </div>

                        <div class="form-control">
                            <label
                                class="text-sm font-medium block mb-1"><?= T::meta_description ?? 'Meta Description' ?></label>
                            <textarea name="meta_description" class="textarea text-sm" rows="3"
                                placeholder="<?= T::enter_meta_description ?? 'Enter meta description' ?>"><?= htmlspecialchars($umrah['meta_description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-control">
                            <label
                                class="text-sm font-medium block mb-1"><?= T::meta_keywords ?? 'Meta Keywords' ?></label>
                            <textarea name="meta_keywords" class="textarea text-sm" rows="3"
                                placeholder="<?= T::enter_keywords_separated_by_commas ?? 'Enter keywords separated by commas' ?>"><?= htmlspecialchars($umrah['meta_keywords'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Translations -->
            <div x-show="activeTab === 'translations'" x-transition class="space-y-6">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <span class="material-symbols-outlined">translate</span>
                        <?= T::translations ?? 'Translations' ?>
                    </h3>

                    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                        <div class="border-b border-gray-200">
                            <nav class="flex -mb-px overflow-x-auto">
                                <?php
                                foreach ($GLOBALS['languages'] ?? [] as $lang_data):
                                    if ($lang_data['lang_code'] === 'en')
                                        continue;
                                    $lang_name = $lang_data['name'] ?? $lang_data['lang_code'];
                                    $lang_code = $lang_data['lang_code'];
                                    $has_name = !empty($name_translations[$lang_code]);
                                    $has_desc = !empty($desc_translations[$lang_code]);
                                    $has_address = !empty($address_translations[$lang_code]);
                                    $has_policy = !empty($cancellation_policy_translations[$lang_code]);
                                    $has_terms = !empty($terms_conditions_translations[$lang_code]);
                                    $has_any = $has_name || $has_desc || $has_address || $has_policy || $has_terms;
                                    ?>
                                        <button type="button" @click="switchTranslationTab('<?= $lang_code ?>')"
                                            :class="activeTranslationTab === '<?= $lang_code ?>' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                            class="whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm flex items-center gap-2">
                                            <span
                                                class="inline-block w-2 h-2 rounded-full <?= $has_any ? 'bg-green-500' : 'bg-gray-300' ?>"></span>
                                            <?= htmlspecialchars($lang_name) ?>
                                        </button>
                                <?php endforeach; ?>
                            </nav>
                        </div>

                        <div class="p-6">
                            <?php
                            foreach ($GLOBALS['languages'] ?? [] as $lang_data):
                                if ($lang_data['lang_code'] === 'en')
                                    continue;
                                $lang_name = $lang_data['name'] ?? $lang_data['lang_code'];
                                $lang_code = $lang_data['lang_code'];
                                $name_value = $name_translations[$lang_code] ?? '';
                                $desc_value = $desc_translations[$lang_code] ?? '';
                                $address_value = $address_translations[$lang_code] ?? '';
                                $cancellation_policy_value = $cancellation_policy_translations[$lang_code] ?? '';
                                $terms_conditions_value = $terms_conditions_translations[$lang_code] ?? '';
                                ?>
                                    <div x-show="activeTranslationTab === '<?= $lang_code ?>'" x-transition class="space-y-6">
                                        <div class="bg-gray-50 rounded-lg p-4">
                                            <h4
                                                class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                                <span class="material-symbols-outlined text-blue-600 text-lg">translate</span>
                                                <?= T::basic_information ?? 'Basic Information' ?>
                                            </h4>

                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                                                <div class="form-control">
                                                    <label
                                                        class="text-sm font-medium block mb-1"><?= T::umrah_name ?? 'Umrah Name' ?></label>
                                                    <input type="text" name="name_translations[<?= $lang_code ?>]"
                                                        class="input text-sm" value="<?= htmlspecialchars($name_value) ?>"
                                                        placeholder="<?= T::enter_translation ?? 'Enter translation...' ?>">
                                                </div>

                                                <div class="form-control">
                                                    <label
                                                        class="text-sm font-medium block mb-1"><?= T::address ?? 'Address' ?></label>
                                                    <input type="text" name="address_translations[<?= $lang_code ?>]"
                                                        class="input text-sm" value="<?= htmlspecialchars($address_value) ?>"
                                                        placeholder="<?= T::enter_translation ?? 'Enter translation...' ?>">
                                                </div>
                                            </div>

                                            <div class="form-control">
                                                <label
                                                    class="text-sm font-medium block mb-1"><?= T::umrah_description ?? 'Umrah Description' ?></label>
                                                <textarea id="umrah-trans-desc-<?= $lang_code ?>"
                                                    name="desc_translations[<?= $lang_code ?>]"
                                                    class="umrah-trans-desc quill-source hidden"
                                                    data-lang="<?= $lang_code ?>"><?= htmlspecialchars($desc_value) ?></textarea>
                                                <div id="umrah-trans-desc-<?= $lang_code ?>-quill" class="quill-host"
                                                    data-source="#umrah-trans-desc-<?= $lang_code ?>" style="min-height:300px;">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="bg-gray-50 rounded-lg p-4">
                                            <h4
                                                class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                                <span class="material-symbols-outlined text-blue-600 text-lg">policy</span>
                                                <?= T::policies_terms ?? 'Policies & Terms' ?>
                                            </h4>

                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                                <div class="form-control">
                                                    <label
                                                        class="text-sm font-medium"><?= T::cancellation_policy ?? 'Cancellation Policy' ?></label>
                                                    <textarea name="cancellation_policy_translations[<?= $lang_code ?>]"
                                                        class="textarea text-sm" rows="4"
                                                        placeholder="<?= T::enter_translation ?? 'Enter translation...' ?>"><?= htmlspecialchars($cancellation_policy_value) ?></textarea>
                                                </div>

                                                <div class="form-control">
                                                    <label
                                                        class="text-sm font-medium"><?= T::terms_conditions ?? 'Terms & Conditions' ?></label>
                                                    <textarea name="terms_conditions_translations[<?= $lang_code ?>]"
                                                        class="textarea text-sm" rows="4"
                                                        placeholder="<?= T::enter_translation ?? 'Enter translation...' ?>"><?= htmlspecialchars($terms_conditions_value) ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                <a href="<?= root ?>admin/umrah" class="btn white text-sm"><?= T::cancel ?? 'Cancel' ?></a>
                <button type="submit" class="btn text-sm" :disabled="loading">
                    <span :class="loading ? 'hidden' : 'flex'" class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">save</span>
                        <span><?= $isEdit ? (T::update ?? 'Update') : (T::submit ?? 'Submit') ?></span>
                    </span>
                    <span :class="loading ? 'flex' : 'hidden'" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        <span><?= T::processing ?? 'Processing' ?>...</span>
                    </span>
                </button>
            </div>
        </form>

        <!-- Umrah Gallery Lightbox -->
        <?php if ($isEdit): ?>
                <div x-show="showLightbox" @click="closeLightbox()" @keydown.escape.window="closeLightbox()"
                    class="fixed inset-0 z-[60] flex items-center justify-center bg-black bg-opacity-90 p-4"
                    x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;">
                    <button @click="closeLightbox()"
                        class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors">
                        <span class="material-symbols-outlined text-4xl">close</span>
                    </button>
                    <img :src="'<?= root ?>' + lightboxImage" @click.stop
                        class="max-w-full max-h-full object-contain rounded-lg shadow-2xl"
                        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-90"
                        x-transition:enter-end="opacity-100 scale-100">
                </div>
        <?php endif; ?>

        <!-- Stay Gallery Lightbox -->
        <div x-show="showStayLightbox" @click="closeStayLightbox()" @keydown.escape.window="closeStayLightbox()"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black bg-opacity-90 p-4"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;">
            <button @click="closeStayLightbox()"
                class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors">
                <span class="material-symbols-outlined text-4xl">close</span>
            </button>
            <img :src="stayLightboxImage.startsWith('http') ? stayLightboxImage : ('<?= root ?>' + stayLightboxImage)"
                @click.stop class="max-w-full max-h-full object-contain rounded-lg shadow-2xl">
        </div>

        <!-- Room Gallery Lightbox -->
        <div x-show="showRoomLightbox" @click="closeRoomLightbox()" @keydown.escape.window="closeRoomLightbox()"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black bg-opacity-90 p-4"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;">
            <img :src="roomLightboxImage.startsWith('http') ? roomLightboxImage : ('<?= root ?>' + roomLightboxImage)"
                @click.stop class="max-w-full max-h-full object-contain rounded-lg shadow-2xl">
        </div>

        <!-- Traveling Gallery Lightbox -->
        <div x-show="showTransferLightbox" @click="closeTransferLightbox()"
            @keydown.escape.window="closeTransferLightbox()"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black bg-opacity-90 p-4"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;">
            <button @click="closeTravelingLightbox()"
                class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors">
                <span class="material-symbols-outlined text-4xl">close</span>
            </button>
            <img :src="transferLightboxImage && transferLightboxImage.startsWith('http') ? transferLightboxImage : ('<?= root ?>' + transferLightboxImage)"
                @click.stop class="max-w-full max-h-full object-contain rounded-lg shadow-2xl">
        </div>

        <!-- Activity Image Lightbox -->
        <div x-show="showActivityLightbox" @click="closeActivityLightbox()"
            @keydown.escape.window="closeActivityLightbox()"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-90 p-4"
            x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;">
            <button @click="closeActivityLightbox()"
                class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors">
                <span class="material-symbols-outlined text-4xl">close</span>
            </button>
            <img :src="activityImageLightbox" @click.stop
                class="max-w-full max-h-full object-contain rounded-lg shadow-2xl"
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-90"
                x-transition:enter-end="opacity-100 scale-100">
        </div>
    </div>
</div>

<?php if (!$isView): ?>
        <link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
        <style>
            .quill-host {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: .5rem;
            }

            .quill-host .ql-editor {
                min-height: 320px;
            }

            .ql-toolbar.ql-snow {
                border-top-left-radius: .5rem;
                border-top-right-radius: .5rem;
                border-color: #e5e7eb;
            }

            .ql-container.ql-snow {
                border-bottom-left-radius: .5rem;
                border-bottom-right-radius: .5rem;
                border-color: #e5e7eb;
            }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
        <script>
            (function () {
                // Shared registry of Quill instances per source textarea selector
                window.quillInstances = window.quillInstances || {};
                window.editorInstances = window.editorInstances || { main: { description: null }, translations: { desc: {} } };

                var quillToolbar = [
                    [{ header: [1, 2, 3, 4, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ color: [] }, { background: [] }],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    [{ align: [] }],
                    ['link', 'image', 'blockquote', 'code-block'],
                    [{ indent: '-1' }, { indent: '+1' }],
                    ['clean']
                ];

                function mountQuill(hostEl) {
                    if (!hostEl || hostEl.__quillMounted) return null;
                    var sourceSelector = hostEl.getAttribute('data-source');
                    var source = sourceSelector ? document.querySelector(sourceSelector) : null;
                    if (!source) return null;

                    var quill = new Quill(hostEl, {
                        theme: 'snow',
                        modules: { toolbar: quillToolbar }
                    });

                    // Seed initial HTML
                    var initial = source.value || '';
                    if (initial) {
                        try { quill.clipboard.dangerouslyPasteHTML(initial, 'silent'); } catch (e) { hostEl.querySelector('.ql-editor').innerHTML = initial; }
                    }

                    // Live sync HTML back to textarea
                    quill.on('text-change', function () {
                        var html = quill.root.innerHTML;
                        // Treat empty editor as empty string
                        if (html === '<p><br></p>') html = '';
                        source.value = html;
                    });

                    hostEl.__quillMounted = true;
                    window.quillInstances[sourceSelector] = quill;
                    return quill;
                }

                function mountAllQuillEditors() {
                    if (typeof Quill === 'undefined') {
                        setTimeout(mountAllQuillEditors, 100);
                        return;
                    }
                    document.querySelectorAll('.quill-host').forEach(mountQuill);

                    // Expose main editor in editorInstances for save-flow compatibility
                    var main = window.quillInstances['#umrah_description'];
                    if (main) {
                        window.editorInstances.main.description = {
                            getData: function () { var h = main.root.innerHTML; return h === '<p><br></p>' ? '' : h; },
                            setData: function (html) { main.clipboard.dangerouslyPasteHTML(html || '', 'silent'); },
                            _quill: main
                        };
                    }
                }

                window.initMainUmrahEditor = function () { mountAllQuillEditors(); };
                window.initializeTranslationEditor = function (textarea, langCode) {
                    // Re-scan for newly visible hosts (translation tabs)
                    var host = document.getElementById('umrah-trans-desc-' + langCode + '-quill');
                    if (!host) return;
                    var quill = mountQuill(host);
                    if (quill) {
                        window.editorInstances.translations.desc[langCode] = {
                            getData: function () { var h = quill.root.innerHTML; return h === '<p><br></p>' ? '' : h; },
                            setData: function (html) { quill.clipboard.dangerouslyPasteHTML(html || '', 'silent'); },
                            _quill: quill
                        };
                    }
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', mountAllQuillEditors);
                } else {
                    mountAllQuillEditors();
                }
            })();
        </script>
<?php endif; ?>