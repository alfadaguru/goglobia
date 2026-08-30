<?php
// app/routes/users/agencySignup.php
@$SECURE or die('Access Denied!');

// ============================================================================
// AGENCY SIGNUP PAGE - REGISTER A NEW AGENCY
// ============================================================================
// PURPOSE: Allow agents to sign up and create their agency profile
// ============================================================================

$router->get('agent-signup', function () use ($SECURE, $db) {

    // ============================================================================
    // META DETAILS FOR PAGE
    // ============================================================================
    $title = "Agent Registration - Join Our Travel Network | Wholesale Rates & B2B Platform";
    $description = "Register as a travel agent and access exclusive wholesale rates on 500,000+ properties worldwide. Free registration, 24/7 support, and instant confirmation. Join our B2B travel platform today.";
    $keywords = "travel agent registration, B2B travel platform, wholesale hotel rates, agent signup, travel agency registration, B2B booking platform, wholesale travel rates";

    // Get business name and contact info
    $business_name = $_SESSION['app']->business_name ?? 'Our Platform';
    $contact_email = $_SESSION['app']->contact_email ?? '';

    // ============================================================================
    // RENDER VIEW
    // ============================================================================
    require_once views."includes/header.php";
    require_once views."auth/agency-signup.php";
    require_once views."includes/footer.php";

});
