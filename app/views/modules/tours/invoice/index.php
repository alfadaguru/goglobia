<?php
@$SECURE or die('Access Denied!');

$session_keys = [
    'tour_destination',
    'tour_destination_code',
    'tour_start_date',
    'tour_duration',
    'tour_travelers',
    'tour_type',
    'tour_adults',
    'tour_children',
    'tour_travelers_data',
    'tour_detail'
];

foreach ($session_keys as $key) {
    if (isset($_SESSION[$key])) {
        unset($_SESSION[$key]);
    }
}
?>

<div class="bg-gray-200 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>tours" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?= T::tours ?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                <path fill="currentColor" d="m13.292 12l-4.6-4.6l.708-.708L14.708 12L9.4 17.308l-.708-.708z"/>
            </svg>
            <span class="text-gray-700 text-[13px] font-medium"><?= T::invoice ?> #<?= $invoiceId ?></span>
        </div>

        <?php if (!empty($_SESSION['payment_notice'])): ?>
            <?php
            $notice = $_SESSION['payment_notice'];
            $noticeType = $notice['type'] ?? 'success';
            $alertClass = 'alert-success';
            $icon = 'check_circle';

            if ($noticeType === 'warning') {
                $alertClass = 'alert-warning';
                $icon = 'warning';
            } elseif ($noticeType === 'error') {
                $alertClass = 'alert-error';
                $icon = 'error';
            }
            ?>
            <div class="alert <?= $alertClass ?> mb-6">
                <span class="material-symbols-outlined"><?= $icon ?></span>
                <div>
                    <p class="font-semibold"><?= htmlspecialchars($notice['title'] ?? T::payment_update) ?></p>
                    <p class="text-sm"><?= htmlspecialchars($notice['message'] ?? '') ?></p>
                </div>
            </div>
            <?php unset($_SESSION['payment_notice']); ?>
        <?php endif; ?>

        <div class="alert alert-success mb-6" id="successMessage" x-data="{ show: true }" x-show="show" x-transition>
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold"><?= T::tour_booking_confirmed_successfully ?></p>
                <p class="text-sm"><?= T::your_tour_booking_has_been_confirmed ?> <strong><?= $invoiceId ?></strong></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

            <div class="lg:col-span-2 space-y-3">

                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">receipt_long</span>
                            <h3><?= T::invoice_details ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::invoice_information ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::invoice_id ?>:</span>
                                        <span class="font-medium">#<?= $invoiceId ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::booking_date ?>:</span>
                                        <span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::booking_type ?>:</span>
                                        <span class="font-medium"><?= T::tour_package ?></span>
                                    </div>

                                    <?php if ($booking['pnr']): ?>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::pnr ?>:</span>
                                        <span class="font-medium"><?= $booking['pnr'] ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::customer_information ?></h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::name ?>:</span>
                                        <span class="font-medium"><?= $booking['first_name'] . ' ' . $booking['last_name'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::email ?>:</span>
                                        <span class="font-medium"><?= $booking['email'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::phone ?>:</span>
                                        <span class="font-medium">+<?= $booking['phone_country_code'] ?> <?= $booking['phone'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400"><?= T::nationality ?>:</span>
                                        <span class="font-medium"><?= $booking['nationality'] ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">tour</span>
                            <h3><?= T::tour_information ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="flex items-start gap-4 pb-4 mb-4 border-b border-gray-200 dark:border-gray-700">
                            <div class="w-20 h-20 flex-shrink-0">
                                <img src="<?= !empty($bookingData['tour_image']) ? $bookingData['tour_image'] : root . 'uploads/no_img.jpg' ?>"
                                     class="w-full h-full object-cover rounded-xl border border-gray-200 dark:border-gray-700"
                                     alt="<?= $bookingData['tour_name'] ?>"
                                     onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            </div>

                            <div class="flex-1">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">
                                    <?= $bookingData['tour_name'] ?>
                                </h4>

                                <div class="space-y-2 text-sm">
                                    <div class="flex items-center gap-4">
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">calendar_today</span>
                                            <span><?= T::start_date ?>: <?= date('d M Y', strtotime($bookingData['start_date'])) ?></span>
                                        </div>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">schedule</span>
                                            <span><?= T::duration ?>: <?= $bookingData['duration'] ?></span>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-4">
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">location_on</span>
                                            <span><?= $bookingData['tour_location'] ?></span>
                                        </div>
                                        <div class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                                            <span class="material-symbols-outlined text-sm">group</span>
                                            <span><?= $booking['adults'] ?> <?= $booking['adults'] > 1 ? T::adults : T::adult ?><?= $booking['childs'] > 0 ? ', ' . $booking['childs'] . ' ' . ($booking['childs'] > 1 ? T::children : T::child) : '' ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-1 gap-4 mt-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 tracking-wide flex items-center gap-1">
                                    <span class="material-symbols-outlined text-base">payments</span>
                                    <?= T::pricing_details ?>
                                </h4>
                                <div class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                    <div class="flex justify-between">
                                        <span><?= T::tour_price ?>:</span>
                                        <span><?= $bookingData['currency'] ?> <?= number_format($bookingData['markup_total_tour_price'], 2) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span><?= T::adult_price ?> (x<?= $booking['adults'] ?>):</span>
                                        <span><?= $bookingData['currency'] ?> <?= number_format($bookingData['markup_total_price_persons'], 2) ?></span>
                                    </div>
                                    <?php if ($booking['childs'] > 0): ?>
                                    <div class="flex justify-between">
                                        <span><?= T::child_price ?> (x<?= $booking['childs'] ?>):</span>
                                        <span><?= $bookingData['currency'] ?> <?= number_format($bookingData['markup_total_price_childrens'], 2) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex justify-between font-semibold mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                        <span><?= T::subtotal ?>:</span>
                                        <span><?= $bookingData['currency'] ?> <?= number_format($bookingData['markup_total_tour_price'], 2) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card p-0">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">group</span>
                            <h3><?= T::travellers_information ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="space-y-4">
                            <div class="p-3 bg-blue-50 dark:bg-green-900/20 rounded-lg border border-blue-200">
                                <h4 class="font-medium text-blue-700 dark:text-blue-300 mb-2 rounded"><?= T::primary_guest ?></h4>
                                <div class="text-sm">
                                    <?php if (isset($travellersData['primary_guest'])): ?>
                                        <p><strong><?= $travellersData['primary_guest']['title'] ?? '' ?> <?= $travellersData['primary_guest']['first_name'] ?? '' ?> <?= $travellersData['primary_guest']['last_name'] ?? '' ?></strong></p>
                                        <p class="text-gray-600"><?= $travellersData['primary_guest']['email'] ?? '' ?></p>
                                        <p class="text-gray-600">+<?= $travellersData['primary_guest']['country_code'] ?? '' ?> <?= $travellersData['primary_guest']['phone'] ?? '' ?></p>
                                    <?php else: ?>
                                        <p><strong><?= $booking['first_name'] ?> <?= $booking['last_name'] ?></strong></p>
                                        <p class="text-gray-600"><?= $booking['email'] ?></p>
                                        <p class="text-gray-600">+<?= $booking['phone_country_code'] ?> <?= $booking['phone'] ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php
                            if (!empty($travellersData['travelers'])):
                                $travelersArray = $travellersData['travelers'];

                                if (!is_array($travelersArray)) {
                                    $travelersArray = json_decode($travelersArray, true);
                                }

                                if (is_array($travelersArray) && count($travelersArray) > 0):
                            ?>
                                <div>
                                    <div class="flex items-center gap-2 mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 tracking-wide flex items-center gap-1">
                                        <span class="material-symbols-outlined text-base">group_add</span>
                                        <h4 class="font-semibold text-gray-800 dark:text-gray-200"><?= T::additional_travellers ?></h4>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <?php
                                        $allTravelers = [];

                                        if (isset($travelersArray[0]) && is_array($travelersArray[0])) {
                                            $allTravelers = $travelersArray;
                                        } else {
                                            foreach ($travelersArray as $value) {
                                                if (is_array($value)) {
                                                    if (isset($value[0]) && is_array($value[0])) {
                                                        $allTravelers = array_merge($allTravelers, $value);
                                                    } else {
                                                        $allTravelers[] = $value;
                                                    }
                                                }
                                            }
                                        }

                                        foreach ($allTravelers as $traveler):
                                            if (!is_array($traveler)) {
                                                continue;
                                            }

                                            $firstInitial = isset($traveler['first_name']) ? strtoupper(substr($traveler['first_name'], 0, 1)) : '';
                                            $lastInitial = isset($traveler['last_name']) ? strtoupper(substr($traveler['last_name'], 0, 1)) : '';
                                            $initials = $firstInitial . $lastInitial;

                                            $title = $traveler['title'] ?? '';
                                            $firstName = $traveler['first_name'] ?? '';
                                            $lastName = $traveler['last_name'] ?? '';
                                            $fullName = trim($title . ' ' . $firstName . ' ' . $lastName);

                                            if (empty($firstName) && empty($lastName)) {
                                                continue;
                                            }
                                        ?>
                                            <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition-shadow">
                                                <div class="flex-shrink-0">
                                                    <div class="w-12 h-12 rounded-full bg-blue-500 flex items-center justify-center">
                                                        <span class="text-white font-semibold text-base"><?= !empty($initials) ? $initials : 'NA' ?></span>
                                                    </div>
                                                </div>

                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                                        <?= !empty($fullName) ? htmlspecialchars($fullName) : T::not_available ?>
                                                    </p>
                                                    <?php if (isset($traveler['age'])): ?>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                                            <span class="material-symbols-outlined !text-xs align-middle">cake</span>
                                                            <?= T::age ?>: <?= $traveler['age'] ?> <?= T::years ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <?php if (empty($allTravelers)): ?>
                                            <div class="col-span-3 text-center py-4 text-gray-500">
                                                <span class="material-symbols-outlined text-3xl mb-2">group_remove</span>
                                                <p><?= T::no_additional_travelers_available ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php
                                else: // No valid travelers data
                            ?>
                                <div class="text-center py-4 text-gray-500">
                                    <span class="material-symbols-outlined text-3xl mb-2">group_remove</span>
                                    <p><?= T::no_additional_travelers_available ?></p>
                                </div>
                            <?php
                                endif; // End of is_array($travelersArray) && count($travelersArray) > 0
                            else: // No travelers data at all
                            ?>
                                <div class="text-center py-4 text-gray-500">
                                    <span class="material-symbols-outlined text-3xl mb-2">group_remove</span>
                                    <p><?= T::no_additional_travelers_available ?></p>
                                </div>
                            <?php
                            endif; // End of !empty($travellersData['travelers'])
                            ?>
                        </div>

                        <?php if ($booking['special_requests']): ?>
                            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2"><?= T::special_requests ?></h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded">
                                    <?= nl2br(htmlspecialchars($booking['special_requests'])) ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <div class="lg:col-span-1">

                <?php
                $moduleType = 'tours';
                include __DIR__ . '/../../../includes/invoice/summary.php';
                ?>

            </div>
        </div>
    </div>
</div>

<script>
function downloadInvoice() {
    window.location.href = '<?= root ?>api/tour/booking/download-invoice/<?= $invoiceId ?>';
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway').value;
    if (!gateway) {
        alert('<?= T::please_select_a_payment_gateway ?>');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= root ?>payment/process';

    const invoiceInput = document.createElement('input');
    invoiceInput.type = 'hidden';
    invoiceInput.name = 'invoice_id';
    invoiceInput.value = '<?= $invoiceId ?>';

    const gatewayInput = document.createElement('input');
    gatewayInput.type = 'hidden';
    gatewayInput.name = 'gateway_id';
    gatewayInput.value = gateway;

    form.appendChild(invoiceInput);
    form.appendChild(gatewayInput);
    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    const successMessage = document.getElementById('successMessage');
    const invoiceId = '<?= $invoiceId ?>';
    const dismissedKey = `success_dismissed_${invoiceId}`;

    if (localStorage.getItem(dismissedKey)) {
        successMessage.style.display = 'none';
        return;
    }

    setTimeout(function() {
        successMessage.style.display = 'none';
        localStorage.setItem(dismissedKey, 'true');
    }, 5000);
});

const printStyles = `
@media print {
    .btn, .card-header, nav, footer { display: none !important; }
    .card { border: 1px solid #ccc !important; margin-bottom: 20px !important; }
    body { background: white !important; }
}`;

const style = document.createElement('style');
style.textContent = printStyles;
document.head.appendChild(style);


function requestCancellation() {
    const apiUrl = '<?= root ?>api/tour/booking/request-cancellation';

    if (confirm('<?= T::cancellation_confirmation_message ?>')) {
        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                invoice_id: '<?= $invoiceId ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('<?= T::cancellation_request_submitted_successfully ?>');
                location.reload();
            } else {
                alert('<?= T::error ?>: ' + (data.message || '<?= T::failed_to_submit_cancellation_request ?>'));
            }
        })
        .catch(error => {
            alert('<?= T::network_error_try_again ?>');
            console.error('<?= T::error_colon ?>', error);
        });
    }
}
</script>