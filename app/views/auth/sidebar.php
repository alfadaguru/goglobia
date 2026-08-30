<?php
// ============================================================================
// DYNAMIC SIDEBAR MENU - USER DASHBOARD NAVIGATION
// ============================================================================
// PURPOSE: Generates a dynamic sidebar menu based on enabled modules
// FEATURES:
// - Module-based visibility (only shows enabled modules from database)
// - Accordion-style navigation for bookings and account sections
// - Active state detection for current page highlighting
// - Responsive design with collapsible mobile view
// - Translation support via T:: constants
// ============================================================================
$user = $db->get('users', '*', ['user_id' => $_SESSION['user_id']]);

// ============================================================================
// INITIALIZE DASHBOARD DATA IF NOT SET
// ============================================================================
// This variable is set by dashboard route, but sidebar is used on other pages too
// Initialize with default values if not already set to prevent undefined variable errors
if (!isset($dashboardData)) {
    $totalBookings = $db->count('bookings', ['user_id' => $_SESSION['user_id']]);
    $dashboardData = [
        'total_bookings' => $totalBookings,
        'member_since' => $user['created_at'] ?? 'N/A'
    ];
}

// ============================================================================
// SECTION 1: INITIALIZE CURRENT PAGE CONTEXT
// ============================================================================
// Capture current URL information to determine active menu items
$currentPath  = $_SERVER['REQUEST_URI'];           // Full URI path with query string
$currentFile  = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)); // Current file name only
$currentQuery = $_SERVER['QUERY_STRING'] ?? '';    // Query parameters (e.g., "filter=hotels")

// ============================================================================
// SECTION 2: FETCH ENABLED MODULES FROM DATABASE
// ============================================================================
// Query the 'modules' table to get all module types where status = 1 (enabled)
// This determines which booking options appear in the sidebar
// Example modules: stays, flights, tours, cars, bus, umrah, ferries, esim, visa
$enabledModules = $db->select('modules', 'type', ['status' => 1]);

// Convert to associative array for O(1) lookup performance
// Format: ['stays' => 0, 'flights' => 1, 'tours' => 2, ...]
$enabledModulesMap = array_flip($enabledModules);

// ============================================================================
// SECTION 3: DETERMINE ACTIVE ACCORDION STATES
// ============================================================================
// Check if user is on a bookings-related page
// Opens "My Bookings" accordion if any booking page or filter is active
$isBookingsActive = str_contains($currentPath, 'bookings') ||
                   isset($_GET['filter']) && in_array($_GET['filter'], ['hotels', 'stays', 'flights', 'tours', 'cars', 'visa', 'bus', 'umrah', 'ferries', 'esim']);

// Check if user is on an account-related page
// Opens "Account" accordion if on profile or logout pages
$isAccountActive = str_contains($currentPath, 'profile') ||
                   str_contains($currentPath, 'logout');

// ============================================================================
// SECTION 4: BUILD DYNAMIC BOOKINGS SUBMENU
// ============================================================================
// Create array of booking filter options based on enabled modules
// Each module check ensures only active modules appear in the sidebar
$bookingsChildren = [];

// Add "All Bookings" option if ANY booking module is enabled
// This serves as the parent view showing all booking types together
if (!empty($enabledModules)) {
    $bookingsChildren[] = [
        'label' => T::all .' ' . T::bookings,
        'icon' => 'list_alt',
        'url' => root . 'bookings',
        'active' => ($currentFile == 'bookings' && empty($_GET['filter']))
    ];
}

// MODULE CHECK: Stays (Hotels)
// Only adds this option if 'stays' module is enabled (status = 1 in database)
if (isset($enabledModulesMap['stays'])) {
    $bookingsChildren[] = [
        'label' => T::stays,
        'icon' => 'hotel',
        'url' => root . 'bookings?filter=stays',
        'active' => ($_GET['filter'] ?? '') == 'stays'
    ];
}

// MODULE CHECK: Flights
// Only adds this option if 'flights' module is enabled
if (isset($enabledModulesMap['flights'])) {
    $bookingsChildren[] = [
        'label' => T::flights,
        'icon' => 'flight_takeoff',
        'url' => root . 'bookings?filter=flights',
        'active' => ($_GET['filter'] ?? '') == 'flights'
    ];
}

// MODULE CHECK: Tours
// Only adds this option if 'tours' module is enabled
if (isset($enabledModulesMap['tours'])) {
    $bookingsChildren[] = [
        'label' => T::tours,
        'icon' => 'tour',
        'url' => root . 'bookings?filter=tours',
        'active' => ($_GET['filter'] ?? '') == 'tours'
    ];
}

// MODULE CHECK: Cars
// Only adds this option if 'cars' module is enabled
if (isset($enabledModulesMap['cars'])) {
    $bookingsChildren[] = [
        'label' => T::cars,
        'icon' => 'directions_car',
        'url' => root . 'bookings?filter=cars',
        'active' => ($_GET['filter'] ?? '') == 'cars'
    ];
}

// MODULE CHECK: Bus
// Only adds this option if 'bus' module is enabled
if (isset($enabledModulesMap['bus'])) {
    $bookingsChildren[] = [
        'label' => T::bus,
        'icon' => 'directions_bus',
        'url' => root . 'bookings?filter=bus',
        'active' => ($_GET['filter'] ?? '') == 'bus'
    ];
}

// MODULE CHECK: Umrah
if (isset($enabledModulesMap['umrah'])) {
    $bookingsChildren[] = [
        'label' => T::umrah,
        'icon' => 'mosque',
        'url' => root . 'bookings?filter=umrah',
        'active' => ($_GET['filter'] ?? '') == 'umrah'
    ];
}

// MODULE CHECK: Ferries
if (isset($enabledModulesMap['ferries'])) {
    $bookingsChildren[] = [
        'label' => T::ferries,
        'icon' => 'directions_boat',
        'url' => root . 'bookings?filter=ferries',
        'active' => ($_GET['filter'] ?? '') == 'ferries'
    ];
}

// MODULE CHECK: eSIM
if (isset($enabledModulesMap['esim'])) {
    $bookingsChildren[] = [
        'label' => T::esim,
        'icon' => 'sim_card',
        'url' => root . 'bookings?filter=esim',
        'active' => ($_GET['filter'] ?? '') == 'esim'
    ];
}

// MODULE CHECK: Visa
// Only adds this option if 'visa' module is enabled
if (isset($enabledModulesMap['visa'])) {
    $bookingsChildren[] = [
        'label' => T::visa,
        'icon' => 'description',
        'url' => root . 'bookings?filter=visa',
        'active' => ($_GET['filter'] ?? '') == 'visa'
    ];
}

// ============================================================================
// SECTION 5: CONSTRUCT MAIN SIDEBAR MENU ARRAY
// ============================================================================
// This array structure defines all sidebar items
// Each item can be type 'link' (direct link) or 'accordion' (expandable section)
//
// MENU ITEM STRUCTURE:
// - type: 'link' or 'accordion'
// - label: Translation key or direct text
// - icon: Material Symbols icon name
// - url: Link destination
// - active: Boolean condition for highlighting current page
// - children: (accordion only) Array of sub-items
// - class: (optional) Additional CSS classes
// ============================================================================

// Start with Dashboard (always visible)
$sidebarMenu = [
    [
        'type' => 'link',
        'label' => T::dashboard,
        'icon' => 'dashboard',
        'url' => root . 'dashboard',
        'active' => str_contains($currentPath, 'dashboard')
    ]
];

// CONDITIONAL: Add "My Bookings" accordion
// Only appears if at least one booking module is enabled
// If all booking modules are disabled, this entire section is hidden
if (!empty($bookingsChildren)) {
    $sidebarMenu[] = [
        'type' => 'accordion',
        'id' => 'accordion1',
        'label' => T::my_bookings,
        'icon' => 'calendar_month',
        'active' => $isBookingsActive,
        'children' => $bookingsChildren  // Dynamically built array from Section 4
    ];
}

// ============================================================================
// SECTION 6: ADD REMAINING MENU ITEMS
// ============================================================================
// These items are always visible regardless of module status
// Some items are commented out for future implementation
$sidebarMenu = array_merge($sidebarMenu, [
    // Support - Help and ticket system
    [
        'type' => 'accordion',
        'id' => 'supportAccordion',
        'label' => T::support,
        'icon' => 'support_agent',
        'active' => str_contains($currentPath, 'support'),
        'children' => [
            [
                'label' => T::support.' '.T::tickets,
                'icon' => 'confirmation_number',
                'url' => root . 'support/tickets',
                'active' => str_contains($currentPath, 'support/tickets')
            ]
        ]
    ],

    // Favourites - User's saved items
    [
        'type' => 'link',
        'label' => T::favourites,
        'icon' => 'favorite',
        'url' => root . 'favourites',
        'active' => str_contains($currentPath, 'favourites')
    ],

    // FUTURE FEATURES (currently disabled)
    // Uncomment when implementing these features:
    //
    // [
    //     'type' => 'link',
    //     'label' => 'my_posts',
    //     'icon' => 'article',
    //     'url' => '#',
    //     'active' => str_contains($currentPath, 'my_posts')
    // ],
    // [
    //     'type' => 'link',
    //     'label' => 'price_alerts',
    //     'icon' => 'notifications',
    //     'url' => root . 'price_alerts',
    //     'active' => str_contains($currentPath, 'price_alerts')
    // ],
    // [
    //     'type' => 'link',
    //     'label' => 'my_cards',
    //     'icon' => 'credit_card',
    //     'url' => root . 'my_cards',
    //     'active' => str_contains($currentPath, 'my_cards')
    // ],
    // [
    //     'type' => 'link',
    //     'label' => 'promo_codes',
    //     'icon' => 'local_offer',
    //     'url' => '#',
    //     'active' => str_contains($currentPath, 'promo_codes')
    // ],

    // Account Accordion - Profile & Logout
    [
        'type' => 'accordion',
        'id' => 'accordion2',
        'label' => T::account,
        'icon' => 'account_circle',
        'active' => $isAccountActive,
        'children' => [
            // Profile page
            [
                'label' => T::profile,
                'icon' => 'person',
                'url' => root . 'profile',
                'active' => str_contains($currentPath, 'profile')
            ],
            // Logout link (styled in red)
            [
                'label' => T::logout,
                'icon' => 'logout',
                'url' => root . 'logout',
                'active' => str_contains($currentPath, 'logout'),
                'class' => 'text-red-600 hover:text-red-700 hover:bg-red-50'
            ]
        ]
    ]
]);

// ============================================================================
// SECTION 7: ADD AGENT-ONLY MENU ITEMS
// ============================================================================
// These items only appear for users with role 'agent'
if (($user['role'] ?? '') === 'agent') {
    // Insert agent-specific items before the Account accordion
    $accountAccordionIndex = array_search('accordion2', array_column($sidebarMenu, 'id'));

    $agentMenuItems = [
      //   [
      //       'type' => 'link',
      //       'label' => T::deposit.' '.T::funds,
      //       'icon' => 'account_balance_wallet',
      //       'url' => root . 'deposit',
      //       'active' => str_contains($currentPath, 'deposit')
      //   ],
      //   [
      //       'type' => 'link',
      //       'label' => T::agency.' '.T::details,
      //       'icon' => 'business',
      //       'url' => root . 'agency-details',
      //       'active' => str_contains($currentPath, 'agency-details')
      //   ]
    ];

    // Find position of Account accordion
    $insertPosition = count($sidebarMenu) - 1; // Before last item (Account accordion)
    array_splice($sidebarMenu, $insertPosition, 0, $agentMenuItems);
}
?>

<!-- ============================================================================ -->
<!-- SIDEBAR HTML STRUCTURE -->
<!-- ============================================================================ -->
<!-- RESPONSIVE BEHAVIOR:
     - Desktop (lg+): Relative positioned, rounded corners, 270px width
     - Mobile: Fixed position overlay, slides in/out via JavaScript
     - Uses Tailwind CSS utility classes for responsive design
============================================================================ -->
<!-- Mobile Overlay Backdrop -->
<div x-show="!sidebarCollapsed" 
     @click="sidebarCollapsed = true"
     class="fixed inset-0 bg-black/50 z-[100] lg:hidden"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0">
</div>

<div id="sidebar" 
     class="lg:rounded-[8px] h-full overflow-y-auto top-0 left-0 transform transition-all duration-300 bg-white fixed lg:relative z-[110] lg:z-auto w-[270px] lg:mt-7 mt-0 shadow-xl lg:shadow-none p-2 lg:p-0"
     :class="sidebarCollapsed ? '-translate-x-full lg:translate-x-0' : 'translate-x-0'">

    <!-- Mobile Close Button -->
    <div class="lg:hidden flex items-center justify-between p-4 border-b border-gray-100 bg-slate-50">
        <span class="font-bold text-gray-900"><?= T::menu ?></span>
        <button @click="sidebarCollapsed = true" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-md">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>


<?php
   // Global default currency for base conversion
   $default_currency_data = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
   $base_currency = $default_currency_data['name'] ?? 'USD';
   $base_rate = (float)($default_currency_data['rate'] ?? 1);

   // Selected session currency
   $session_currency = $_SESSION['app_currency'] ?? $base_currency;

   $display_balance = (float)($user['balance'] ?? 0);
   $display_credit_limit = (float)($user['credit_limits'] ?? 0);

   if ($session_currency !== $base_currency) {
       $session_curr_data = $db->get('currencies', ['rate'], ['name' => $session_currency]);
       $session_rate = (float)($session_curr_data['rate'] ?? 1);
       
       // Conversion: (Amount / Base Rate) * Target Rate
       if ($base_rate > 0) {
           $display_balance = ($display_balance / $base_rate) * $session_rate;
           $display_credit_limit = ($display_credit_limit / $base_rate) * $session_rate;
       }
   }
?>
<div class="card p-0 mb-5">
      <div class="card-header">
         <h3 class="font-semibold text-gray-900"><?= T::finance ?? 'Finance' ?></h3>
      </div>
      <div class="card-content p-5">
         <div class="space-y-4">
            <div class="flex justify-between items-center text-[14px]">
               <span class="text-gray-600"><?= T::wallet.  ' ' .T::balance ?? 'Balance' ?></span>
               <span class="font-bold text-gray-900">
                  <?= htmlspecialchars($session_currency) ?>
                  <?= number_format($display_balance, 2) ?>
               </span>
            </div>

            <?php if (($user['role'] ?? '') === 'agent'): ?>
            <div class="flex justify-between items-center text-[14px]">
               <span class="text-gray-600"><?= T::credit_limit ?? 'Credit Limit' ?></span>
               <span class="font-bold text-blue-600">
                  <?= htmlspecialchars($session_currency) ?>
                  <?= number_format($display_credit_limit, 2) ?>
               </span>
            </div>
            <?php endif; ?>
         </div>
      </div>
   </div>

   <!-- =========================================================================
        MENU ITEMS CONTAINER
        Dynamically generated from $sidebarMenu array
        Supports two types: 'link' (direct link) and 'accordion' (expandable)
   ========================================================================== -->
   <div class="py-4 bg-slate-50 space-y-1 card px-2">
      <?php foreach ($sidebarMenu as $item): ?>

         <!-- TYPE: REGULAR LINK -->
         <!-- Direct navigation item (e.g., Dashboard, Favourites) -->
         <?php if ($item['type'] === 'link'): ?>
            <a class="flex items-center gap-3 text-[14px] font-[600] px-4 py-2 transition-all duration-200
               <?= $item['active'] ? 'sidebar-active' : 'text-[#374151]' ?> <?= $item['class'] ?? '' ?>"
               href="<?= $item['url'] ?>">
               <span class="material-symbols-outlined text-lg"><?= $item['icon'] ?></span>
               <!-- Translation fallback: Uses T:: constant or formats label as fallback -->
               <span><?= T::${$item['label']} ?? ucfirst(str_replace('_', ' ', $item['label'])) ?></span>
            </a>

         <!-- TYPE: ACCORDION -->
         <!-- Expandable section with sub-items (e.g., My Bookings, Account) -->
         <?php elseif ($item['type'] === 'accordion'): ?>

            <!-- Accordion Header (clickable to toggle) -->
            <div class="flex items-center px-4 py-2 cursor-pointer transition-colors duration-200 hover:bg-gray-100 rounded-md"
                 onclick="toggleAccordion('<?= $item['id'] ?>')">
               <div class="flex items-center gap-3">
                  <span class="material-symbols-outlined text-lg"><?= $item['icon'] ?></span>
                  <span class="text-[14px] font-[600]"><?= T::${$item['label']} ?? ucfirst(str_replace('_', ' ', $item['label'])) ?></span>
               </div>
               <!-- Expand/collapse icon (rotates 180deg when open) -->
               <span id="<?= $item['id'] ?>-icon" class="material-symbols-outlined text-lg ml-auto transition-transform duration-300
                  <?= $item['active'] ? 'rotate-180' : '' ?>">expand_more</span>
            </div>

            <!-- Accordion Body (collapsible content) -->
            <!-- Uses opacity and max-height for smooth animation -->
            <div id="<?= $item['id'] ?>-body" class="overflow-hidden transition-all duration-300 ease-in-out
               <?= $item['active'] ? 'opacity-100 max-h-96' : 'opacity-0 max-h-0' ?>">
               <div class="transform transition-transform duration-300 ease-in-out
                  <?= $item['active'] ? 'translate-y-0' : '-translate-y-2' ?> pl-4 space-y-1">

                  <!-- Loop through child items (e.g., booking filters, account options) -->
                  <?php foreach ($item['children'] as $child): ?>
                     <a class="flex items-center gap-3 text-[14px] px-4 py-2 transition-all duration-200
                        <?= $child['active'] ? 'sidebar-active' : 'text-[#65707D]' ?> <?= $child['class'] ?? '' ?>"
                        href="<?= $child['url'] ?>">
                        <span class="material-symbols-outlined text-lg align-middle"><?= $child['icon'] ?></span>
                        <!-- Child labels can be direct text or translation keys -->
                        <span><?= T::${$child['label']} ?? $child['label'] ?></span>
                     </a>
                  <?php endforeach; ?>
               </div>
            </div>
         <?php endif; ?>
      <?php endforeach; ?>
   </div>

   <div class="card p-0 mt-5">
                  <div class="card-header">
                     <h3><?= T::quick_actions ?></h3>
                  </div>
                  <div class="card-content p-5">
                     <div class="space-y-3">
                        <?php if (($user['role'] ?? '') === 'agent'): ?>
                        <!-- Deposit Funds Button (Agent Only) -->
                        <a href="<?=root?>deposit" class="btn light w-full justify-start">
                           <span class="material-symbols-outlined">account_balance_wallet</span>
                           <span><?= T::deposit.' '.T::funds ?></span>
                        </a>
                        <!-- Agency Details Button (Agent Only) -->
                        <a href="<?=root?>agency-details" class="btn light w-full justify-start">
                           <span class="material-symbols-outlined">business</span>
                           <span><?= T::agency.' '.T::details ?></span>
                        </a>
                        <?php endif; ?>
                        <!-- Edit Profile Button -->
                        <a href="<?=root?>profile" class="btn light w-full justify-start">
                           <span class="material-symbols-outlined">person</span>
                           <span><?= T::edit_profile ?></span>
                        </a>
                        <!-- Payment Methods Button -->
                        <!-- <a href="<?=root?>my-cards" class="btn light w-full justify-start">
                           <span class="material-symbols-outlined">credit_card</span>
                           <span><?= T::payment_methods ?></span>
                        </a> -->
                     </div>
                  </div>
               </div>

               <!-- ==========================================================
                    CARD 2: Account Summary
                    Displays key account metrics and information
                    Format: Label-value pairs in two columns
               ========================================================== -->
               <div class="card p-0 mt-5">
                  <div class="card-header">
                     <h3><?= T::account_summary ?></h3>
                  </div>
                  <div class="card-content p-5">
                     <div class="space-y-3">
                        <!-- Member Since Date -->
                        <div class="flex justify-between items-center">
                           <span class="text-gray-600"><?= T::member_since ?></span>
                           <span class="font-medium"><?= $dashboardData['member_since'] !== 'N/A' ? date('M Y', strtotime($dashboardData['member_since'])) : T::not_available ?></span>
                        </div>
                        <!-- Total Trips Count -->
                        <div class="flex justify-between items-center">
                           <span class="text-gray-600"><?= T::total_trips ?></span>
                           <span class="font-medium"><?= $dashboardData['total_bookings'] ?></span>
                        </div>
                        <!-- Loyalty Points (Future Feature - currently hardcoded to 0) -->
                        <div class="flex justify-between items-center">
                           <span class="text-gray-600"><?= T::loyalty_points ?></span>
                           <span class="font-medium text-[#3265ff]">0</span>
                        </div>
                     </div>
                  </div>
               </div>

               <!-- ==========================================================
                    CARD 3: Recent Activity
                    Shows timeline of user actions
                    Currently displays welcome message with timestamp
                    Future: Can be extended to show booking actions, updates
               ========================================================== -->
               <!-- <div class="card p-0">
                  <div class="card-header">
                     <h3><?= T::recent_activity ?></h3>
                  </div>
                  <div class="card-content p-5">
                     <div class="space-y-3">
                         <div class="flex items-start gap-3 text-sm">
                            <span class="material-symbols-outlined text-base text-gray-400 mt-0.5">circle</span>
                           <div>
                               <p class="text-gray-700"><?= T::welcome_back ?> <?= $_SESSION['user_name'] ?? 'User' ?>!</p>
                               <p class="text-gray-400 text-xs"><?= date('M d, Y H:i') ?></p>
                           </div>
                        </div>
                     </div>
                  </div>
               </div> -->


</div>

<!-- ============================================================================ -->
<!-- SIDEBAR CUSTOM STYLES -->
<!-- ============================================================================ -->
<style>
/* =============================================================================
   ACTIVE MENU ITEM STYLING
   ============================================================================
   Design Decision: Minimal active state using ONLY color change
   - No background pill/highlight
   - No border or shadow
   - Only blue (#3B82F6) text and icon color
   - Font weight increased to 600 for emphasis

   This provides a clean, modern look without visual clutter
============================================================================= */
.sidebar-active {
  color: #3B82F6 !important; /* Tailwind Blue-500 */
  background-color: transparent !important;
  border-radius: 0 !important;
  box-shadow: none !important;
  font-weight: 600 !important;
}

/* Apply active color to Material Icons within active items */
.sidebar-active .material-symbols-outlined {
  color: #3B82F6 !important;
  vertical-align: middle !important;
}

/* =============================================================================
   ICON ALIGNMENT FIX
   ============================================================================
   Ensures all Material Symbols icons align properly with text
   Using inline-flex centers icons vertically within their container
============================================================================= */
.material-symbols-outlined {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  vertical-align: middle;
}
</style>

<!-- ============================================================================ -->
<!-- SIDEBAR JAVASCRIPT - ACCORDION FUNCTIONALITY -->
<!-- ============================================================================ -->
<script>
// =============================================================================
// FUNCTION: Initialize Accordion States on Page Load
// =============================================================================
// PURPOSE: Opens accordion sections that contain the current active page
// EXECUTION: Runs when DOM is fully loaded
// LOGIC: PHP determines which accordions should be open via $item['active']
//        JavaScript applies the necessary classes to show/hide content
// =============================================================================
document.addEventListener('DOMContentLoaded', function() {
    // Loop through all accordion items from PHP
    <?php foreach ($sidebarMenu as $item): ?>
        <?php if ($item['type'] === 'accordion'): ?>
            // Get DOM elements for this accordion
            const <?= $item['id'] ?>Body = document.getElementById('<?= $item['id'] ?>-body');
            const <?= $item['id'] ?>Icon = document.getElementById('<?= $item['id'] ?>-icon');
            const <?= $item['id'] ?>Content = <?= $item['id'] ?>Body.querySelector('.transform');

            // If this accordion should be open (contains active page)
            <?php if ($item['active']): ?>
                // Show accordion body
                <?= $item['id'] ?>Body.classList.remove('opacity-0', 'max-h-0');
                <?= $item['id'] ?>Body.classList.add('opacity-100', 'max-h-96');

                // Slide content down
                <?= $item['id'] ?>Content.classList.remove('-translate-y-2');
                <?= $item['id'] ?>Content.classList.add('translate-y-0');

                // Rotate expand icon 180 degrees
                <?= $item['id'] ?>Icon.classList.add('rotate-180');
            <?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>
});

// =============================================================================
// FUNCTION: Toggle Accordion (Expand/Collapse)
// =============================================================================
// PURPOSE: Handles user clicks on accordion headers to show/hide content
// PARAMETERS: accordionId - The ID of the accordion to toggle
// ANIMATION: Uses CSS transitions for smooth expand/collapse
// BEHAVIOR: Opens if closed, closes if open (toggle behavior)
// =============================================================================
function toggleAccordion(accordionId) {
    // Get accordion elements
    const body = document.getElementById(accordionId + '-body');
    const icon = document.getElementById(accordionId + '-icon');
    const content = body.querySelector('.transform');

    // Check current state (closed if opacity-0)
    if (body.classList.contains('opacity-0')) {
        // EXPAND ACCORDION
        body.classList.remove('opacity-0', 'max-h-0');    // Make visible and allow height
        body.classList.add('opacity-100', 'max-h-96');    // Full opacity and max height
        content.classList.remove('-translate-y-2');       // Remove upward offset
        content.classList.add('translate-y-0');           // Set to normal position
        icon.classList.add('rotate-180');                 // Flip arrow icon
    } else {
        // COLLAPSE ACCORDION
        body.classList.remove('opacity-100', 'max-h-96'); // Hide and collapse
        body.classList.add('opacity-0', 'max-h-0');       // Zero opacity and height
        content.classList.remove('translate-y-0');        // Remove normal position
        content.classList.add('-translate-y-2');          // Slide up slightly
        icon.classList.remove('rotate-180');              // Return arrow to normal
    }
}
</script>