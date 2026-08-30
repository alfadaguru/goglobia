<?php
// ============================================================================
// DEPOSIT VIEW - AGENT WALLET FUNDING PAGE
// ============================================================================
// PURPOSE: Display deposit history and provide form to submit deposit requests
// FEATURES:
// - CRUD table for deposit history
// - Modal form for new deposit requests
// - Bank transfer details display
// - File upload for payment proof
// - Real-time form submission with AJAX
// ============================================================================
?>

<style>
[x-cloak] { display: none !important; }
</style>

<script>
// Define Alpine.js component functions before they're used
function viewDepositModal() {
    return {
        showViewModal: false,
        imageFullscreen: false,
        depositData: {
            id: '',
            status: '',
            amount: '',
            currency: '',
            payment_method: '',
            transaction_id: '',
            created_at: '',
            details: '',
            attachment: ''
        },

        initView() {
            window.viewDepositModalComponent = this;
        },

        openViewModal(data) {
            this.depositData = {
                id: data.id || '',
                status: (data.status || 'pending').toLowerCase(),
                amount: data.amount || '0.00',
                currency: data.currency || 'USD',
                payment_method: data.payment_method || '--',
                transaction_id: data.transaction_id || '--',
                created_at: data.created_at ? new Date(data.created_at).toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }) : '--',
                details: data.details || '',
                attachment: data.attachment || ''
            };
            this.showViewModal = true;
            this.imageFullscreen = false;
            document.body.style.overflow = 'hidden';
        },

        closeViewModal() {
            this.showViewModal = false;
            this.imageFullscreen = false;
            document.body.style.overflow = 'auto';
        }
    }
}
</script>

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
               <h1 class="text-2xl font-semibold text-gray-900"><?= T::deposit ?? 'Payment Deposits' ?></h1>
               <p class="text-sm text-gray-600 mt-1"><?= T::deposit_subtitle ?? 'Manage your deposit requests and view transaction history' ?></p>
            </div>
            <button class="btn" onclick="openDepositModal()">
               <span class="material-symbols-outlined text-sm">add</span>
               <?= T::request_deposit ?? 'Request Deposit' ?>
            </button>
         </div>

         <!-- ============================================================ -->
         <!-- DEPOSIT HISTORY TABLE (CRUD LIBRARY) -->
         <!-- ============================================================ -->
         <?php
         echo crud()->table('deposit')
            ->where(['user_id' => $userId])
            ->title(T::deposit_history ?? 'Payment Deposits')
            ->col('id,amount,created_at,payment_method,transaction_id,status,details,attachment')
            ->label([
               'id' => T::transaction_id ?? 'ID',
               'created_at' => T::date ?? 'Date',
               'amount' => T::amount ?? 'Amount',
               'currency' => T::currency ?? 'Currency',
               'payment_method' => T::payment_method ?? 'Payment Method',
               'transaction_id' => 'Trx ID',
               'status' => T::status ?? 'Status',
               'details' => T::details ?? 'Details',
               'attachment' => T::attachment ?? 'Attachment'
            ])
            ->order('created_at', 'DESC')
            ->actions([
               'add' => false,
               'view' => true,
               'edit' => false,
               'delete' => false,
               'status' => false,
               'search' => false,
               'bulk_delete' => false,
            ])
            ->action_urls([
               'view' => '#',
            ])
            ->row([
               'id' => '#{{id}}',
               'created_at' => function ($row) {
                  return date('M d, Y g:i A', strtotime($row['created_at']));
               },
               'amount' => '{{currency}} <strong>{{number_format(amount, 2)}}</strong>',
               'status' => function ($row) {
                  $statusClass = 'bg-gray-100 text-gray-800';
                  $status = strtolower($row['status']);
                  if ($status === 'pending') {
                     $statusClass = 'bg-red-100 text-red-800 border-red-300 uppercase text-xs font-semibold border';
                  } elseif ($status === 'approved' || $status === 'success') {
                     $statusClass = 'bg-green-100 text-green-800 border-green-300 uppercase text-xs font-semibold border';
                  } elseif ($status === 'rejected') {
                     $statusClass = 'bg-gray-100 text-gray-800 border-gray-300 uppercase text-xs font-semibold border';
                  }
                  return '<span class="inline-block px-2 py-1 rounded ' . $statusClass . '">' . htmlspecialchars(ucfirst($row['status'])) . '</span>';
               },
               'details' => function ($row) {
                  return $row['details'] ? '<span class="text-sm text-gray-600">' . htmlspecialchars($row['details']) . '</span>' : '<span class="text-gray-500 italic">--</span>';
               },
               'attachment' => function ($row) {
                  if ($row['attachment']) {
                     return '<span class="text-xs text-green-600"><span class="material-symbols-outlined text-sm align-middle">check_circle</span> Uploaded</span>';
                  }
                  return '<span class="text-xs text-gray-400">--</span>';
               }
            ])
            ->col_width('id', '100px')
            ->col_width('created_at', '180px')
            ->col_width('amount', '120px')
            ->col_width('currency', '80px')
            ->col_width('payment_method', '150px')
            ->col_width('transaction_id', '70px')
            ->col_width('status', '120px')
            ->col_width('attachment', '100px')
            ->render();
         ?>

      </div>
   </main>
</div>
</div>

<!-- ============================================================ -->
<!-- MODALS (Outside main content to prevent layout issues) -->
<!-- ============================================================ -->

<!-- VIEW DEPOSIT MODAL -->
<div x-data="viewDepositModal()" x-init="initView()" x-cloak>
   <div x-show="showViewModal"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.5); z-index: 9999; overflow-y: auto; padding: 1rem;"
        @click.self="closeViewModal()">
      <div x-show="showViewModal"
           x-transition:enter="transition ease-out duration-300 transform"
           x-transition:enter-start="opacity-0 scale-95"
           x-transition:enter-end="opacity-100 scale-100"
           x-transition:leave="transition ease-in duration-200 transform"
           x-transition:leave-start="opacity-100 scale-100"
           x-transition:leave-end="opacity-0 scale-95"
           class="bg-white rounded-lg shadow-lg border border-gray-200 w-full max-w-3xl mx-auto my-8"
           style="position: relative;">
         <div class="modal-header">
            <h3><?= T::deposit_details ?? 'Deposit Details' ?></h3>
            <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" @click="closeViewModal()">
               <span class="material-symbols-outlined">close</span>
            </button>
         </div>

         <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
            <template x-if="showViewModal">
            <div class="grid grid-cols-2 gap-4">
               <!-- Transaction ID -->
               <div class="form-control">
                  <label class="text-sm font-medium text-gray-700"><?= T::transaction_id ?? 'Transaction ID' ?></label>
                  <p class="text-base text-gray-900 font-semibold" x-text="'#' + (depositData?.id || '')"></p>
               </div>

               <!-- Status -->
               <div class="form-control">
                  <label class="text-sm font-medium text-gray-700"><?= T::status ?? 'Status' ?></label>
                  <div>
                     <span
                        class="inline-block px-3 py-1 rounded uppercase text-xs font-semibold border"
                        :class="{
                           'bg-red-100 text-red-800 border-red-300': depositData?.status === 'pending',
                           'bg-green-100 text-green-800 border-green-300': depositData?.status === 'approved' || depositData?.status === 'success',
                           'bg-gray-100 text-gray-800 border-gray-300': depositData?.status === 'rejected'
                        }"
                        x-text="depositData?.status ? depositData.status.charAt(0).toUpperCase() + depositData.status.slice(1) : 'Pending'">
                     </span>
                  </div>
               </div>

               <!-- Amount -->
               <div class="form-control">
                  <label class="text-sm font-medium text-gray-700"><?= T::amount ?? 'Amount' ?></label>
                  <p class="text-base text-gray-900 font-semibold">
                     <span x-text="depositData?.currency || 'USD'"></span> <span x-text="depositData?.amount ? parseFloat(depositData.amount).toFixed(2) : '0.00'"></span>
                  </p>
               </div>

               <!-- Payment Method -->
               <div class="form-control">
                  <label class="text-sm font-medium text-gray-700"><?= T::payment_method ?? 'Payment Method' ?></label>
                  <p class="text-base text-gray-900" x-text="depositData?.payment_method || '--'"></p>
               </div>

               <!-- Transaction Reference -->
               <div class="form-control">
                  <label class="text-sm font-medium text-gray-700"><?= T::transaction_ref ?? 'Transaction Reference' ?></label>
                  <p class="text-base text-gray-900" x-text="depositData?.transaction_id || '--'"></p>
               </div>

               <!-- Created Date -->
               <div class="form-control">
                  <label class="text-sm font-medium text-gray-700"><?= T::date ?? 'Date' ?></label>
                  <p class="text-base text-gray-900" x-text="depositData?.created_at || '--'"></p>
               </div>

               <!-- Details (full width) -->
               <div class="form-control col-span-2">
                  <label class="text-sm font-medium text-gray-700"><?= T::details ?? 'Details' ?></label>
                  <p class="text-base text-gray-900" x-text="depositData?.details || 'No additional details'"></p>
               </div>

               <!-- Attachment (full width) -->
               <div class="form-control col-span-2" x-show="depositData?.attachment">
                  <label class="text-sm font-medium text-gray-700"><?= T::payment_proof ?? 'Payment Proof' ?></label>
                  <div class="mt-2">
                     <div class="relative inline-block">
                        <img :src="depositData?.attachment ? '<?= root ?>' + depositData.attachment : ''"
                             alt="Payment Proof"
                             class="max-w-full h-auto rounded-lg border border-gray-200 shadow-sm cursor-pointer transition-transform hover:scale-105"
                             style="max-height: 400px;"
                             @click="imageFullscreen = true">
                        <div class="mt-2 text-sm text-gray-600">
                           <span class="material-symbols-outlined text-sm align-middle">info</span>
                           Click image to view fullscreen
                        </div>
                     </div>
                  </div>
               </div>
            </div>
            </template>
         </div>

         <div class="modal-footer bg-gray-50 border-t border-gray-200 p-3">
            <button type="button" class="btn light" @click="closeViewModal()">
               <span class="material-symbols-outlined text-sm">close</span>
               <?= T::close ?? 'Close' ?>
            </button>
         </div>
      </div>
   </div>

   <!-- Fullscreen Image Modal -->
   <div x-show="imageFullscreen"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.9); z-index: 10000; padding: 2rem;"
        @click="imageFullscreen = false">
      <button type="button" class="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors z-10" @click="imageFullscreen = false">
         <span class="material-symbols-outlined" style="font-size: 32px;">close</span>
      </button>
      <div class="h-full flex items-center justify-center">
         <img :src="depositData?.attachment ? '<?= root ?>' + depositData.attachment : ''"
              alt="Payment Proof Fullscreen"
              class="max-w-full max-h-full object-contain"
              @click.stop>
      </div>
   </div>
</div>

<!-- ============================================================ -->
<!-- DEPOSIT REQUEST MODAL -->
<!-- ============================================================ -->
<div x-data="depositModal()" x-init="init()">
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
           class="bg-white rounded-lg shadow-lg border border-gray-200 w-full max-w-2xl mx-auto my-8"
           style="position: relative;">
         <div class="modal-header">
            <h3><?= T::request_deposit ?? 'Request Deposit' ?></h3>
            <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" @click="closeModal()">
               <span class="material-symbols-outlined">close</span>
            </button>
         </div>

         <form @submit.prevent="submitForm" enctype="multipart/form-data">
         <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">

            <!-- ============================================================ -->
            <!-- BANK TRANSFER DETAILS -->
            <!-- ============================================================ -->
            <?php if (!empty($bankTransfer)): ?>
            <div class="alert-info mb-4">
               <span class="material-symbols-outlined text-lg">account_balance</span>
               <div>
                  <h6 class="font-semibold"><?= htmlspecialchars($bankTransfer['name']) ?></h6>
                  <div class="space-y-0">
                     <?php if (!empty($bankTransfer['c1'])): ?>
                     <p style="margin-bottom:0px!important"><?= htmlspecialchars($bankTransfer['c1']) ?></p>
                     <?php endif; ?>

                     <?php if (!empty($bankTransfer['c2'])): ?>
                     <p><?= htmlspecialchars($bankTransfer['c2']) ?></p>
                     <?php endif; ?>

                     <?php if (!empty($bankTransfer['c3'])): ?>
                     <p><?= htmlspecialchars($bankTransfer['c3']) ?></p>
                     <?php endif; ?>

                     <?php if (!empty($bankTransfer['c4'])): ?>
                     <p><?= htmlspecialchars($bankTransfer['c4']) ?></p>
                     <?php endif; ?>

                     <?php if (!empty($bankTransfer['c5'])): ?>
                     <p><?= htmlspecialchars($bankTransfer['c5']) ?></p>
                     <?php endif; ?>
                  </div>
               </div>
            </div>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- FORM FIELDS -->
            <!-- ============================================================ -->
            <div class="form-grid">
               <div class="form-control mb-3">
                  <label><?= T::amount ?? 'Amount' ?> *</label>
                  <input type="number" step="0.01" min="1" class="input" name="amount" required>
               </div>

               <div class="form-control mb-3">
                  <label><?= T::currency ?? 'Currency' ?></label>

                  <?php
                  $currency = $db->get('currencies', 'name', ['default' => 1]);
                  ?>

                  <input type="text" class="input bg-gray-100" name="currency" value="<?= htmlspecialchars($currency) ?>" readonly>
               </div>
            </div>

            <div class="form-grid">
               <div class="form-control mb-3">
                  <label><?= T::transaction_id ?? 'Transaction ID' ?> *</label>
                  <input type="text" class="input" name="transaction_id" placeholder="<?= T::enter_transaction_id ?? 'Enter your transaction reference ID' ?>" required>
               </div>

               <div class="form-control mb-3">
                  <label><?= T::payment_method ?? 'Payment Gateway' ?> *</label>
                  <select class="select" name="payment_method" required>
                     <?php if (!empty($paymentGateways)): ?>
                        <?php foreach ($paymentGateways as $gateway): ?>
                           <?php if ($gateway['type'] === 'bank_transfer'): ?>
                           <option value="<?= htmlspecialchars($gateway['name']) ?>">
                              <?= htmlspecialchars(getGatewayDisplayName($gateway)) ?>
                           </option>
                           <?php endif; ?>
                        <?php endforeach; ?>
                     <?php endif; ?>
                  </select>
               </div>
            </div>

            <div class="form-control mb-3">
               <label><?= T::description ?? 'Description' ?></label>
               <textarea class="textarea" name="details" rows="3" placeholder="<?= T::optional_notes ?? 'Add any additional notes (optional)' ?>"></textarea>
            </div>

            <div class="form-control mb-3">
               <label><?= T::security_verification ?? 'Security Verification' ?> *</label>
               <div class="flex gap-3 items-center">
                  <div class="bg-gray-100 border border-gray-300 rounded-lg px-3 text-lg font-bold select-none flex items-center justify-center" x-text="captcha" style="height: var(--input-height); min-width: 150px;"></div>
                  <input type="text" class="input flex-1" x-model="captchaInput" placeholder="<?= T::enter_code ?? 'Enter the code above' ?>" required autocomplete="off">
                  <button type="button" class="btn light" @click="generateCaptcha()" style="height: var(--input-height);">
                     <span class="material-symbols-outlined text-lg">refresh</span>
                  </button>
               </div>
            </div>

            <div class="form-control mb-3">
               <label><?= T::payment_proof ?? 'Payment Proof' ?> *</label>
               <input type="file" class="input" name="attachment" accept="image/*,.pdf" required>
               <p class="text-xs text-muted-foreground mt-1"><?= T::supported_formats ?? 'Supported formats: JPG, PNG, PDF (Max 5MB)' ?></p>
            </div>

         </div>

         <div class="modal-footer bg-gray-50 border-t border-gray-200 p-3">
            <button type="button" class="btn light" @click="closeModal()" :disabled="loading">
               <span class="material-symbols-outlined text-sm">close</span>
               <?= T::cancel ?? 'Cancel' ?>
            </button>
            <button type="submit" class="btn" :disabled="loading">
               <span class="material-symbols-outlined text-sm" :class="loading && 'animate-spin'">check_circle</span>
               <span x-text="loading ? '<?= T::processing ?? 'Processing...' ?>' : '<?= T::submit_request ?? 'Submit Request' ?>'"></span>
            </button>
         </div>
      </form>

         <!-- Loading Overlay (Inside Modal) -->
         <div x-show="loading"
              x-transition:enter="transition ease-out duration-200"
              x-transition:enter-start="opacity-0"
              x-transition:enter-end="opacity-100"
              x-transition:leave="transition ease-in duration-150"
              x-transition:leave-start="opacity-100"
              x-transition:leave-end="opacity-0"
              style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10000; display: flex; align-items: center; justify-content: center;">
            <div class="text-center bg-white rounded-lg shadow-xl p-6 border border-gray-200">
               <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 border-t-blue-500 mb-3"></div>
               <p class="text-gray-800 text-base font-medium"><?= T::processing_deposit ?? 'Processing your deposit...' ?></p>
               <p class="text-gray-600 text-sm mt-1"><?= T::please_wait ?? 'Please wait' ?></p>
            </div>
         </div>
</div>

<!-- ============================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================ -->
<script>
// Global function to open modal
function openDepositModal() {
    if (window.depositModalComponent) {
        window.depositModalComponent.openModal();
    }
}

// Override view button click to open our custom modal
document.addEventListener('DOMContentLoaded', function() {
    // Intercept all view button clicks
    document.addEventListener('click', function(e) {
        // Check if clicked element is a view button (has visibility icon)
        const viewBtn = e.target.closest('a[href="#/"]');
        if (viewBtn && viewBtn.closest('tr')) {
            e.preventDefault();
            e.stopPropagation();
            const row = viewBtn.closest('tr');
            const depositId = row.dataset.id;

            console.log('View button clicked for deposit ID:', depositId);

            if (depositId && window.viewDepositModalComponent) {
                // Fetch deposit data
                fetch('<?= root ?>api/deposit/get?id=' + depositId)
                    .then(response => response.json())
                    .then(data => {
                        console.log('Deposit data received:', data);
                        if (data.status === 'success' && data.deposit) {
                            window.viewDepositModalComponent.openViewModal(data.deposit);
                        } else {
                            vt.error(data.message || 'Failed to load deposit details');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        vt.error('Failed to load deposit details');
                    });
            } else {
                console.error('Deposit ID or modal component not found');
            }
        }
    });
});

function depositModal() {
    return {
        showModal: false,
        loading: false,
        captcha: '',
        captchaInput: '',

        init() {
            this.generateCaptcha();
            // Store reference globally for easy access
            window.depositModalComponent = this;
        },

        generateCaptcha() {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            const length = 6;
            this.captcha = '';
            for (let i = 0; i < length; i++) {
                this.captcha += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            this.captchaInput = '';
        },

        validateCaptcha() {
            return this.captchaInput.toUpperCase() === this.captcha;
        },

        openModal() {
            console.log('Opening modal');
            this.showModal = true;
            this.generateCaptcha();
            document.body.style.overflow = 'hidden';
            // Force display
            setTimeout(() => {
                const modal = this.$el.querySelector('.modal-overlay');
                if (modal) modal.style.display = 'flex';
            }, 0);
        },

        closeModal() {
            this.showModal = false;
            document.body.style.overflow = 'auto';
            const modal = this.$el.querySelector('div[x-show="showModal"]');
            if (modal) modal.style.display = 'none';
            const form = document.querySelector('form[enctype="multipart/form-data"]');
            if (form) form.reset();
            this.captchaInput = '';
        },

        async submitForm(event) {
            // Validate captcha
            if (!this.validateCaptcha()) {
                vt.error('<?= T::invalid_captcha ?? "Invalid security code. Please try again." ?>');
                this.generateCaptcha();
                return;
            }

            this.loading = true;
            const startTime = Date.now();

            // Prepare form data
            const formData = new FormData(event.target);
            formData.append('user_id', '<?= $userId ?>');

            try {
                const response = await fetch('<?= root ?>api/deposit/add', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                // Ensure minimum 3 seconds loading
                const elapsed = Date.now() - startTime;
                const remainingTime = Math.max(3000 - elapsed, 0);

                await new Promise(resolve => setTimeout(resolve, remainingTime));

                this.loading = false;

                if (data.status === 'success') {
                    vt.success(data.message || '<?= T::deposit_submitted ?? "Your deposit request has been submitted and is waiting for approval." ?>');
                    setTimeout(() => {
                        this.closeModal();
                        location.reload();
                    }, 2000);
                } else {
                    vt.error(data.message || '<?= T::deposit_failed ?? "Failed to submit deposit request. Please try again." ?>');
                    this.generateCaptcha();
                }
            } catch (error) {
                const elapsed = Date.now() - startTime;
                const remainingTime = Math.max(3000 - elapsed, 0);

                await new Promise(resolve => setTimeout(resolve, remainingTime));

                this.loading = false;

                vt.error('<?= T::network_error ?? "Network error. Please check your connection and try again." ?>');
                this.generateCaptcha();
                console.error('Error:', error);
            }
        }
    }
}
</script>
