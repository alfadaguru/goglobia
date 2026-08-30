<?php
// ============================================================================
// AGENCY DETAILS VIEW - AGENT AGENCY INFORMATION MANAGEMENT
// ============================================================================
// PURPOSE: Display and allow editing of agency information
// FEATURES:
// - Display current agency information in a card
// - Modal form for updating agency details
// - Logo upload functionality
// - Real-time form validation
// ============================================================================
?>

<style>
[x-cloak] { display: none !important; }
</style>

<?php if (!empty($_SESSION['error_message'])): ?>

<div class="container mt-5">
<div class="alert-error">
<span class="material-icon material-symbols-outlined">error</span>
<p> <?= htmlspecialchars($_SESSION['error_message']) ?></p></div>
</div>
</div>
<?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="">
   <div class="flex gap-5 container mb-8">

      <div class="flex-shrink-0">
         <?php include views."auth/sidebar.php"; ?>
      </div>

      <main class="flex-1 min-w-0 pt-8 bg-white rounded-[8px] lg:ml-0 ml-[40px]">
         <div class="mx-auto">

            <!-- ============================================================ -->
            <!-- PAGE HEADER -->
            <!-- ============================================================ -->
            <div class="flex items-center justify-between mb-6">
               <div>
                  <h1 class="text-2xl font-semibold text-gray-900"><?= T::agency_details ?? 'Agency Details' ?></h1>
                  <p class="text-sm text-gray-600 mt-1"><?= T::agency_subtitle ?? 'Manage your agency information and contact details' ?></p>
               </div>
               <button class="btn" onclick="openAgencyModal()">
                  <span class="material-symbols-outlined text-sm">edit</span>
                  <?= T::edit_details ?? 'Edit Details' ?>
               </button>
            </div>

            <!-- ============================================================ -->
            <!-- AGENCY INFORMATION CARD -->
            <!-- ============================================================ -->
            <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
               <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                  <!-- Agency Logo -->
                  <div class="md:col-span-1 flex flex-col items-center justify-center border-r border-gray-200 pr-6">
                     <?php if (!empty($agency['logo'])): ?>
                        <img src="<?= root . htmlspecialchars($agency['logo']) ?>"
                             alt="Agency Logo"
                             class="w-40 h-40 object-contain rounded-lg border border-gray-200 mb-3">
                     <?php else: ?>
                        <div class="w-40 h-40 bg-gray-100 rounded-lg border border-gray-200 flex items-center justify-center mb-3">
                           <span class="material-symbols-outlined text-6xl text-gray-400">business</span>
                        </div>
                     <?php endif; ?>
                     <p class="text-sm text-gray-600 text-center"><?= T::agency_logo ?? 'Agency Logo' ?></p>
                  </div>

                  <!-- Agency Details -->
                  <div class="md:col-span-2 space-y-4">
                     <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <!-- Agency Name -->
                        <div class="form-control">
                           <label class="text-sm font-medium text-gray-700"><?= T::agency_name ?? 'Agency Name' ?></label>
                           <p class="text-base text-gray-900 font-semibold">
                              <?= !empty($agency['agency_name']) ? htmlspecialchars($agency['agency_name']) : '<span class="text-gray-400 italic">Not set</span>' ?>
                           </p>
                        </div>

                        <!-- License Number -->
                        <div class="form-control">
                           <label class="text-sm font-medium text-gray-700"><?= T::license_number ?? 'License Number' ?></label>
                           <p class="text-base text-gray-900">
                              <?= !empty($agency['license_number']) ? htmlspecialchars($agency['license_number']) : '<span class="text-gray-400 italic">Not set</span>' ?>
                           </p>
                        </div>

                        <!-- City -->
                        <div class="form-control">
                           <label class="text-sm font-medium text-gray-700"><?= T::city ?? 'City' ?></label>
                           <p class="text-base text-gray-900">
                              <?= !empty($agency['city']) ? htmlspecialchars($agency['city']) : '<span class="text-gray-400 italic">Not set</span>' ?>
                           </p>
                        </div>

                        <!-- Country -->
                        <div class="form-control">
                           <label class="text-sm font-medium text-gray-700"><?= T::country ?? 'Country' ?></label>
                           <p class="text-base text-gray-900">
                              <?= !empty($agency['country']) ? htmlspecialchars($agency['country']) : '<span class="text-gray-400 italic">Not set</span>' ?>
                           </p>
                        </div>

                        <!-- Address -->
                        <div class="form-control md:col-span-2">
                           <label class="text-sm font-medium text-gray-700"><?= T::address ?? 'Address' ?></label>
                           <p class="text-base text-gray-900">
                              <?= !empty($agency['address']) ? htmlspecialchars($agency['address']) : '<span class="text-gray-400 italic">Not set</span>' ?>
                           </p>
                        </div>

                        <!-- Phone -->
                        <div class="form-control">
                           <label class="text-sm font-medium text-gray-700"><?= T::phone ?? 'Phone' ?></label>
                           <p class="text-base text-gray-900">
                              <?= !empty($agency['phone']) ? htmlspecialchars($agency['phone']) : '<span class="text-gray-400 italic">Not set</span>' ?>
                           </p>
                        </div>

                        <!-- Email -->
                        <div class="form-control">
                           <label class="text-sm font-medium text-gray-700"><?= T::email ?? 'Email' ?></label>
                           <p class="text-base text-gray-900">
                              <?= !empty($agency['email']) ? htmlspecialchars($agency['email']) : '<span class="text-gray-400 italic">Not set</span>' ?>
                           </p>
                        </div>

                     </div>
                  </div>

               </div>
            </div>

         </div>
      </main>
   </div>
</div>

<!-- ============================================================ -->
<!-- EDIT AGENCY MODAL -->
<!-- ============================================================ -->
<div x-data="agencyModal()" x-init="init()">
   <div x-show="showModal"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.5); z-index: 9999; overflow-y: auto; padding: 1rem;"
        @click.self="closeModal()">
      <div x-show="showModal"
           x-transition:enter="transition ease-out duration-300 transform"
           x-transition:enter-start="opacity-0 scale-95"
           x-transition:enter-end="opacity-100 scale-100"
           x-transition:leave="transition ease-in duration-200 transform"
           x-transition:leave-start="opacity-100 scale-100"
           x-transition:leave-end="opacity-0 scale-95"
           class="bg-white rounded-lg shadow-lg border border-gray-200 w-full max-w-3xl mx-auto my-8"
           style="position: relative;">
         <div class="modal-header">
            <h3><?= T::edit_agency_details ?? 'Edit Agency Details' ?></h3>
            <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" @click="closeModal()">
               <span class="material-symbols-outlined">close</span>
            </button>
         </div>

         <form @submit.prevent="submitForm" enctype="multipart/form-data">
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">

               <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                  <!-- Agency Name -->
                  <div class="form-control mb-3 md:col-span-2">
                     <label><?= T::agency_name ?? 'Agency Name' ?> *</label>
                     <input type="text" class="input" name="agency_name" value="<?= htmlspecialchars($agency['agency_name'] ?? '') ?>" required>
                  </div>

                  <!-- License Number -->
                  <div class="form-control mb-3">
                     <label><?= T::license_number ?? 'License Number' ?> *</label>
                     <input type="text" class="input" name="license_number" value="<?= htmlspecialchars($agency['license_number'] ?? '') ?>" required>
                  </div>

                  <!-- City -->
                  <div class="form-control mb-3">
                     <label><?= T::city ?? 'City' ?> *</label>
                     <input type="text" class="input" name="city" value="<?= htmlspecialchars($agency['city'] ?? '') ?>" required>
                  </div>

                  <!-- Country -->
                  <div class="form-control mb-3">
                     <label><?= T::country ?? 'Country' ?> *</label>
                     <input type="text" class="input" name="country" value="<?= htmlspecialchars($agency['country'] ?? '') ?>" required>
                  </div>

                  <!-- Phone -->
                  <div class="form-control mb-3">
                     <label><?= T::phone ?? 'Phone' ?> *</label>
                     <input type="tel" class="input" name="phone" value="<?= htmlspecialchars($agency['phone'] ?? '') ?>" required>
                  </div>

                  <!-- Address -->
                  <div class="form-control mb-3 md:col-span-2">
                     <label><?= T::address ?? 'Address' ?> *</label>
                     <textarea class="textarea" name="address" rows="3" required><?= htmlspecialchars($agency['address'] ?? '') ?></textarea>
                  </div>

                  <!-- Email -->
                  <div class="form-control mb-3">
                     <label><?= T::email ?? 'Email' ?> *</label>
                     <input type="email" class="input bg-gray-100" name="email" value="<?= htmlspecialchars($agency['email'] ?? '') ?>" required readonly>
                  </div>

                  <!-- Logo Upload -->
                  <div class="form-control mb-3">
                     <label><?= T::agency_logo ?? 'Agency Logo' ?></label>
                     <input type="file" class="input" name="logo" accept="image/*">
                     <p class="text-xs text-muted-foreground mt-1"><?= T::supported_formats ?? 'Supported formats: JPG, PNG (Max 2MB)' ?></p>
                  </div>

                  <?php if (!empty($agency['logo'])): ?>
                  <!-- Current Logo Preview -->
                  <div class="form-control mb-3 md:col-span-2">
                     <label><?= T::current_logo ?? 'Current Logo' ?></label>
                     <img src="<?= root . htmlspecialchars($agency['logo']) ?>"
                          alt="Current Logo"
                          class="w-32 h-32 object-contain border border-gray-200 rounded-lg">
                  </div>
                  <?php endif; ?>

               </div>

            </div>

            <div class="modal-footer bg-gray-50 border-t border-gray-200 p-3">
               <button type="button" class="btn light" @click="closeModal()" :disabled="loading">
                  <span class="material-symbols-outlined text-sm">close</span>
                  <?= T::cancel ?? 'Cancel' ?>
               </button>
               <button type="submit" class="btn" :disabled="loading">
                  <span class="material-symbols-outlined text-sm" :class="loading && 'animate-spin'">check_circle</span>
                  <span x-text="loading ? '<?= T::saving ?? 'Saving...' ?>' : '<?= T::save_changes ?? 'Save Changes' ?>'"></span>
               </button>
            </div>
         </form>

         <!-- Loading Overlay -->
         <div x-show="loading"
              x-transition:enter="transition ease-out duration-200"
              x-transition:enter-start="opacity-0"
              x-transition:enter-end="opacity-100"
              style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10000;">
            <div class="text-center bg-white rounded-lg shadow-xl p-6 border border-gray-200">
               <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 border-t-blue-500 mb-3"></div>
               <p class="text-gray-800 text-base font-medium"><?= T::processing ?? 'Processing...' ?></p>
            </div>
         </div>
      </div>
   </div>
</div>

<!-- ============================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================ -->
<script>
// Global function to open modal
function openAgencyModal() {
    if (window.agencyModalComponent) {
        window.agencyModalComponent.openModal();
    }
}

function agencyModal() {
    return {
        showModal: false,
        loading: false,

        init() {
            window.agencyModalComponent = this;
        },

        openModal() {
            this.showModal = true;
            document.body.style.overflow = 'hidden';
        },

        closeModal() {
            this.showModal = false;
            document.body.style.overflow = 'auto';
        },

        async submitForm(event) {
            this.loading = true;

            const formData = new FormData(event.target);

            try {
                const response = await fetch('<?= root ?>api/agency/update', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                this.loading = false;

                if (data.success) {
                    vt.success(data.message || '<?= T::agency_updated ?? "Agency details updated successfully." ?>');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    vt.error(data.message || '<?= T::update_failed ?? "Failed to update agency details." ?>');
                }
            } catch (error) {
                this.loading = false;
                vt.error('<?= T::network_error ?? "Network error. Please try again." ?>');
                console.error('Error:', error);
            }
        }
    }
}
</script>
