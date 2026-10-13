<?php
// FILE: app/views/supplier/stays/bookings-management.php
// Supplier dashboard for viewing and managing guest bookings

if (!isset($stay) || !is_array($stay)) {
    echo '<div class="alert alert-danger">Invalid stay data</div>';
    exit;
}

$stayId = (int) $stay['id'];
$stayName = htmlspecialchars($stay['name'] ?? 'Hotel');
?>

<div class="container mt-5 mb-5">
    <div class="page-header mb-4">
        <h1>Guest Bookings</h1>
        <p class="text-muted">Manage all guest bookings for <?php echo $stayName; ?></p>
    </div>

    <div class="card">
        <div class="card-body">
            <p class="text-muted">Booking management dashboard</p>
            <p>Total Bookings: Coming Soon</p>
            <p>Confirmed: Coming Soon</p>
            <p>Pending: Coming Soon</p>
            <p>Cancelled: Coming Soon</p>
        </div>
    </div>
</div>
