<?php
// ============================================================================
// PAYSMALLSMALL — plan selector, NOT a money processor.
// ----------------------------------------------------------------------------
// PaySmallSmall is an installment PLAN: selecting it sets up the schedule (done
// at checkout / gateway-select), then the customer pays each part through a REAL
// gateway (wallet / card) on the invoice page — /payment/process charges
// payment_amount_due(), which is installment-aware. So this "gateway" never
// charges anything itself; if it is ever reached it just guides the customer to
// the invoice to pay the first part with a real method.
// ============================================================================

if (!isset($_POST['payload']) && !isset($paymentData)) { return; }

$__inv = '';
if (isset($paymentData['booking']['invoice_id'])) {
    $__inv = (string) $paymentData['booking']['invoice_id'];
} elseif (isset($_POST['payload'])) {
    $__p = json_decode(base64_decode((string) $_POST['payload']));
    $__inv = (string) ($__p->invoice_id ?? $__p->booking_ref_no ?? '');
}

global $db;
$__mt = $__inv !== '' ? strtolower((string) ($db->get('bookings', 'module_type', ['invoice_id' => $__inv]) ?: '')) : '';
$__prefix = 'invoice/';
$__map = ['tours'=>'invoice/tours/','stays'=>'invoice/stays/','flights'=>'invoice/flights/','cars'=>'invoice/cars/','visa'=>'invoice/visa/','bus'=>'invoice/bus/','ferries'=>'invoice/ferries/','rail'=>'invoice/rail/','esim'=>'invoice/esim/','umrah'=>'invoice/umrah/'];
$__url = root . ($__map[$__mt] ?? $__prefix) . rawurlencode($__inv);
?>
<div style="max-width:460px;margin:24px auto;padding:20px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;font-family:-apple-system,Segoe UI,Roboto,sans-serif;">
  <h3 style="margin:0 0 8px;color:#0f172a;">Pay Small Small</h3>
  <p style="color:#475569;font-size:14px;margin:0 0 14px;">Your installment plan is set. Pay the first part now with your preferred method to confirm your booking — you'll pay the rest in scheduled parts.</p>
  <a href="<?= htmlspecialchars($__url, ENT_QUOTES, 'UTF-8') ?>" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;">Continue to pay the first part →</a>
</div>
