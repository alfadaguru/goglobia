<style>
[x-cloak] { display: none !important; }
</style>

<script>
// Alpine.js component for deposit management modal
function depositManagementModal() {
    return {
        showModal: false,
        loading: false,
        depositData: {
            id: '',
            user_id: '',
            amount: '',
            currency: '',
            payment_method: '',
            transaction_id: '',
            status: '',
            details: '',
            attachment: '',
            created_at: ''
        },
        imageFullscreen: false,

        init() {
            window.depositManagementModalComponent = this;
            
            // Auto-open modal if ID is provided in URL
            const urlParams = new URLSearchParams(window.location.search);
            const depositId = urlParams.get('id');
            if (depositId) {
                fetch('<?= root ?>api/admin/deposit/get?id=' + depositId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.deposit) {
                            this.openModal(data.deposit);
                        }
                    }).catch(err => console.error('Auto-open error:', err));
            }
        },

        openModal(data) {
            this.depositData = {
                id: data.id || '',
                user_id: data.user_id || '',
                amount: data.amount || '0.00',
                currency: data.currency || 'USD',
                payment_method: data.payment_method || '--',
                transaction_id: data.transaction_id || '--',
                status: (data.status || 'pending').toLowerCase(),
                details: data.details || '',
                attachment: data.attachment || '',
                created_at: data.created_at ? new Date(data.created_at).toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }) : '--'
            };
            this.showModal = true;
            this.imageFullscreen = false;
            document.body.style.overflow = 'hidden';
        },

        closeModal() {
            this.showModal = false;
            this.imageFullscreen = false;
            document.body.style.overflow = 'auto';
        },

        async updateStatus(newStatus) {
            if (!confirm(`Are you sure you want to ${newStatus} this deposit?`)) {
                return;
            }

            this.loading = true;

            try {
                const response = await fetch('<?= root ?>api/admin/deposit/update-status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        deposit_id: this.depositData.id,
                        status: newStatus
                    })
                });

                const data = await response.json();

                if (data.success) {
                    vt.success(data.message || 'Deposit status updated successfully');
                    setTimeout(() => {
                        this.closeModal();
                        location.reload();
                    }, 1500);
                } else {
                    vt.error(data.message || 'Failed to update deposit status');
                }
            } catch (error) {
                console.error('Error:', error);
                vt.error('Network error. Please try again.');
            } finally {
                this.loading = false;
            }
        }
    }
}
</script>

<div class="container py-5">
    <?php
    echo crud()->table('deposit')
        ->where([])
        ->title(T::deposit_management ?? 'Deposit Management')
        ->col('id,user_id,amount,payment_method,transaction_id,status,details,attachment,created_at')
        ->label([
            'id' => T::transaction_id ?? 'ID',
            'user_id' => T::user_id ?? 'User ID',
            'amount' => T::amount ?? 'Amount',
            'currency' => T::currency ?? 'Currency',
            'payment_method' => T::payment_method ?? 'Payment Method',
            'transaction_id' => 'Trx ID',
            'status' => T::status ?? 'Status',
            'details' => T::details ?? 'Details',
            'attachment' => T::attachment ?? 'Attachment',
            'created_at' => T::date ?? 'Date',
        ])
        ->order('id', 'ASC')
        ->actions([
            'add' => false,
            'view' => false,
            'edit' => true,
            'delete' => false,
            'status' => false,
            'search' => true,
            'bulk_delete' => false,
        ])
        ->action_urls([
            'edit' => '#modal',
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
            'attachment' => function ($row) {
                if ($row['attachment']) {
                    return '<span class="text-xs text-green-600"><span class="material-symbols-outlined text-sm align-middle">check_circle</span> Uploaded</span>';
                }
                return '<span class="text-xs text-gray-400">--</span>';
            }
        ])
        ->col_width('id', '100px')
        ->col_width('user_id', '80px')
        ->col_width('amount', '120px')
        ->col_width('currency', '80px')
        ->col_width('payment_method', '150px')
        ->col_width('transaction_id', '150px')
        ->col_width('status', '120px')
        ->col_width('attachment', '100px')
        ->render();
    ?>
</div>

<!-- DEPOSIT MANAGEMENT MODAL -->
<div x-data="depositManagementModal()" x-init="init()" x-cloak>
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
             class="bg-white rounded-lg shadow-lg border border-gray-200 w-full max-w-4xl mx-auto my-8"
             style="position: relative;">
            <div class="modal-header">
                <h3>Deposit Request Details</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600 transition-colors" @click="closeModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>

            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <template x-if="showModal">
                <div>
                    <!-- Status Update Actions - Moved to Top -->
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6" x-show="depositData?.status === 'pending'">
                        <h4 class="text-sm font-semibold text-gray-900 mb-3">Update Status</h4>
                        <div class="flex gap-3">
                            <button type="button"
                                    class="btn bg-green-600 hover:bg-green-700 text-white flex-1"
                                    @click="updateStatus('approved')"
                                    :disabled="loading">
                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                <span x-text="loading ? 'Processing...' : 'Approve Deposit'"></span>
                            </button>
                            <button type="button"
                                    class="btn bg-red-600 hover:bg-red-700 text-white flex-1"
                                    @click="updateStatus('rejected')"
                                    :disabled="loading">
                                <span class="material-symbols-outlined text-sm">cancel</span>
                                <span x-text="loading ? 'Processing...' : 'Reject Deposit'"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Already Processed Notice - Moved to Top -->
                    <div class="alert-info mb-6" x-show="depositData?.status !== 'pending'">
                        <span class="material-symbols-outlined text-lg">info</span>
                        <div>
                            <p class="font-semibold">This deposit has already been processed</p>
                            <p class="text-sm" x-text="'Status: ' + (depositData?.status ? depositData.status.charAt(0).toUpperCase() + depositData.status.slice(1) : '')"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <!-- Transaction ID -->
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700">Transaction ID</label>
                            <p class="text-base text-gray-900 font-semibold" x-text="'#' + (depositData?.id || '')"></p>
                        </div>

                        <!-- User ID -->
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700">User ID</label>
                            <p class="text-base text-gray-900" x-text="depositData?.user_id || '--'"></p>
                        </div>

                        <!-- Amount -->
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700">Amount</label>
                            <p class="text-base text-gray-900 font-semibold">
                                <span x-text="depositData?.currency || 'USD'"></span>
                                <span x-text="depositData?.amount ? parseFloat(depositData.amount).toFixed(2) : '0.00'"></span>
                            </p>
                        </div>

                        <!-- Current Status -->
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700">Current Status</label>
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

                        <!-- Payment Method -->
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700">Payment Method</label>
                            <p class="text-base text-gray-900" x-text="depositData?.payment_method || '--'"></p>
                        </div>

                        <!-- Transaction Reference -->
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700">Transaction Reference</label>
                            <p class="text-base text-gray-900" x-text="depositData?.transaction_id || '--'"></p>
                        </div>

                        <!-- Created Date -->
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700">Created Date</label>
                            <p class="text-base text-gray-900" x-text="depositData?.created_at || '--'"></p>
                        </div>

                        <!-- Currency -->
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700">Currency</label>
                            <p class="text-base text-gray-900" x-text="depositData?.currency || 'USD'"></p>
                        </div>

                        <!-- Details (full width) -->
                        <div class="form-control col-span-2">
                            <label class="text-sm font-medium text-gray-700">Details</label>
                            <p class="text-base text-gray-900" x-text="depositData?.details || 'No additional details'"></p>
                        </div>

                        <!-- Payment Proof (full width) -->
                        <div class="form-control col-span-2" x-show="depositData?.attachment">
                            <label class="text-sm font-medium text-gray-700">Payment Proof</label>
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
                </div>
                </template>
            </div>

            <div class="modal-footer bg-gray-50 border-t border-gray-200 p-3">
                <button type="button" class="btn light" @click="closeModal()">
                    <span class="material-symbols-outlined text-sm">close</span>
                    Close
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

<script>
// Intercept edit button clicks to open modal
document.addEventListener('DOMContentLoaded', function() {
    console.log('Deposit modal script loaded');

    document.addEventListener('click', function(e) {
        const editBtn = e.target.closest('a[href="#modal"]');

        if (editBtn && editBtn.closest('tr')) {
            e.preventDefault();
            e.stopPropagation();

            const row = editBtn.closest('tr');
            const depositId = row.dataset.id;

            console.log('Edit button clicked, depositId:', depositId);
            console.log('Modal component exists:', !!window.depositManagementModalComponent);

            if (depositId && window.depositManagementModalComponent) {
                // Fetch deposit data
                fetch('<?= root ?>api/admin/deposit/get?id=' + depositId)
                    .then(response => response.json())
                    .then(data => {
                        console.log('API Response:', data);
                        if (data.success && data.deposit) {
                            window.depositManagementModalComponent.openModal(data.deposit);
                        } else {
                            vt.error(data.message || 'Failed to load deposit details');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        vt.error('Failed to load deposit details');
                    });
            } else if (!depositId) {
                console.error('No deposit ID found in row');
            } else if (!window.depositManagementModalComponent) {
                console.error('Modal component not initialized');
            }
        }
    });
});
</script>