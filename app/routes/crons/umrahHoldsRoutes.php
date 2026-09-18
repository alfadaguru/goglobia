<?php
// app/routes/crons/umrahHoldsRoutes.php
// Umrah redesign (docs/UMRAH-PHASE1-BUILD-PLAN.md §2) — hold-expiry worker.
// Expire past-deadline inventory holds so their seats return to the pool.
// Schedule via cron every few minutes: GET /umrah_expire_holds
@$SECURE or die('Access Denied!');

$router->get('/umrah_expire_holds', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $expired = function_exists('umrah_hold_expire_sweep') ? umrah_hold_expire_sweep($db) : 0;
    // Audit H5: also cancel abandoned unpaid 'held' bookings whose hold lapsed,
    // so they stop permanently consuming departure capacity.
    $cancelled = function_exists('umrah_booking_expire_sweep') ? umrah_booking_expire_sweep($db) : 0;
    // Email customers whose next installment is due soon (once per installment),
    // and flip genuinely past-due installments to 'overdue'. Umrah customers had
    // no payment reminder before this — installments lapsed silently.
    $reminded = function_exists('umrah_installment_reminder_sweep') ? umrah_installment_reminder_sweep($db) : 0;
    // AFTER the two sweeps above free capacity (expired holds + abandoned
    // bookings), notify waitlisted customers on any departure that now has room.
    $waitlisted = function_exists('umrah_waitlist_notify_sweep') ? umrah_waitlist_notify_sweep($db) : 0;
    echo json_encode(['status' => true, 'expired' => $expired, 'cancelled_bookings' => $cancelled, 'installment_reminders' => $reminded, 'waitlist_notified' => $waitlisted, 'ran_at' => date('Y-m-d H:i:s')]);
});
