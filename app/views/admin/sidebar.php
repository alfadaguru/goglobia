<?php
// ADMIN SIDEBAR NAVIGATION COMPONENT
// Only render for admin users
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {

// GET CURRENT PAGE PATH FOR ACTIVE STATE DETECTION
$current_route = $_SERVER['REQUEST_URI'] ?? '';
$current_path = parse_url($current_route, PHP_URL_PATH) ?? '';
$current_path = rtrim($current_path, '/'); // Remove trailing slash for consistent matching

/**
 * CHECK IF MENU ITEM SHOULD BE ACTIVE
 * Returns true if current page matches the menu item or any of its children
 *
 * @param string $url Main menu item URL
 * @param array|null $submenu Array of submenu items to check
 * @return bool True if menu should be marked as active
 */
function isMenuActive($url, $submenu = null) {
    global $current_path;

    // VALIDATE INPUT
    if (!$current_path || !$url || $url === '#') return false;

    // ONLY MATCH ADMIN PAGES - Prevent homepage false positives
    if (strpos($current_path, '/admin') === false) return false;

    // GET CLEAN PATH FROM URL
    $menu_path = parse_url($url, PHP_URL_PATH);
    if (!$menu_path || $menu_path === '#') return false;
    $menu_path = rtrim($menu_path, '/');

    // CHECK EXACT MATCH OR HIERARCHICAL MATCH (for sub-pages)
    if ($current_path === $menu_path || strpos($current_path, $menu_path . '/') === 0) {
        return true;
    }

    // CHECK SUBMENU ITEMS RECURSIVELY
    if ($submenu && is_array($submenu)) {
        foreach ($submenu as $sub) {
            // CHECK SUBMENU URL
            if (isset($sub['url']) && $sub['url'] && $sub['url'] !== '#') {
                $sub_path = parse_url($sub['url'], PHP_URL_PATH);
                if ($sub_path) {
                    $sub_path = rtrim($sub_path, '/');
                    if ($current_path === $sub_path || strpos($current_path, $sub_path . '/') === 0) {
                        return true;
                    }
                }
            }

            // CHECK NESTED SUBMENU ITEMS (3rd level)
            if (isset($sub['nested']) && is_array($sub['nested'])) {
                foreach ($sub['nested'] as $nested) {
                    if (isset($nested['url']) && $nested['url'] && $nested['url'] !== '#') {
                        $nested_path = parse_url($nested['url'], PHP_URL_PATH);
                        if ($nested_path) {
                            $nested_path = rtrim($nested_path, '/');
                            if ($current_path === $nested_path || strpos($current_path, $nested_path . '/') === 0) {
                                return true;
                            }
                        }
                    }
                }
            }
        }
    }
    return false;
}

// FETCH USER ROLES FROM DATABASE
global $db;
$userRoles = $db->select('users_roles', ['id', 'type_name'], ['ORDER' => ['id' => 'ASC']]);

// BUILD USERS SUBMENU - Single level with all users, then role types
$usersSubmenu = [
    ['name' => T::all_users, 'icon' => 'people', 'url' => root.admin.'/users'],
];

// ADD EACH USER ROLE DYNAMICALLY
foreach ($userRoles as $role) {
    $roleSlug = strtolower(str_replace(' ', '-', $role['type_name']));
    $usersSubmenu[] = [
        'name' => $role['type_name'],
        'icon' => 'badge',
        'url' => root.admin.'/users?search_col=role&q='.$roleSlug
    ];
}

// CHECK MODULE STATUS - Determine which modules are enabled
$flights = $db->count('modules', ['type' => 'flights', 'status' => 1, 'active' => 1]);
$stays = $db->count('modules', ['type' => 'stays', 'status' => 1, 'active' => 1]);
$tours = $db->count('modules', ['type' => 'tours', 'status' => 1, 'active' => 1]);
$visa_count = $db->count('modules', ['type' => 'visa', 'status' => 1, 'active' => 1]);
$cars = $db->count('modules', ['type' => 'cars', 'status' => 1, 'active' => 1]);
$umrah_count = $db->count('modules', ['type' => 'umrah', 'status' => 1, 'active' => 1]);
$bus_count = $db->count('modules', ['type' => 'bus', 'status' => 1, 'active' => 1]);
$rail_count = $db->count('modules', ['type' => 'rail', 'status' => 1, 'active' => 1]);
$ferries_count = $db->count('modules', ['type' => 'ferries', 'status' => 1, 'active' => 1]);
$esim_count = $db->count('modules', ['type' => 'esim', 'status' => 1, 'active' => 1]);

// ADD DIVIDER AND USER ROLES MANAGEMENT PAGE
$usersSubmenu[] = ['divider' => true];
$usersSubmenu[] = [];

// BUILD BOOKINGS SUBMENU - Dynamically based on enabled modules
$bookingsSubmenu = [
  ['name' => T::all . ' ' . T::bookings, 'icon' => 'library_books', 'url' => root.admin.'/bookings']
];

// ADD FLIGHTS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($flights > 0) {
  $bookingsSubmenu[] = ['name' => T::flights . ' ' . T::bookings, 'icon' => 'flight_takeoff', 'url' => root.admin.'/bookings/flights'];
}

// ADD STAYS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($stays > 0) {
  $bookingsSubmenu[] = ['name' => T::stays . ' ' . T::bookings, 'icon' => 'hotel', 'url' => root.admin.'/bookings/stays'];
}

// ADD TOURS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($tours > 0) {
  $bookingsSubmenu[] = ['name' => T::tours . ' ' . T::bookings, 'icon' => 'tour', 'url' => root.admin.'/bookings/tours'];
}

// ADD VISAS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($visa_count > 0) {
  $bookingsSubmenu[] = ['name' => T::visa . ' ' . T::bookings, 'icon' => 'assignment', 'url' => root.admin.'/bookings/visa'];
}

// ADD CARS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($cars > 0) {
  $bookingsSubmenu[] = ['name' => T::cars . ' ' . T::bookings, 'icon' => 'directions_car', 'url' => root.admin.'/bookings/cars'];
}

// ADD UMRAH BOOKINGS ONLY IF MODULE IS ACTIVE
if ($umrah_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::umrah ?? 'Umrah') . ' ' . T::bookings, 'icon' => 'mosque', 'url' => root.admin.'/bookings/umrah'];
}

// ADD BUS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($bus_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::bus ?? 'Bus') . ' ' . T::bookings, 'icon' => 'directions_bus', 'url' => root.admin.'/bookings/bus'];
}

// ADD RAIL BOOKINGS ONLY IF MODULE IS ACTIVE
if ($rail_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::rail ?? 'Rail') . ' ' . T::bookings, 'icon' => 'directions_railway', 'url' => root.admin.'/bookings/rail'];
}

// ADD FERRIES BOOKINGS ONLY IF MODULE IS ACTIVE
if ($ferries_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::ferries ?? 'Ferries') . ' ' . T::bookings, 'icon' => 'directions_boat', 'url' => root.admin.'/bookings/ferries'];
}

// ADD ESIM BOOKINGS ONLY IF MODULE IS ACTIVE
if ($esim_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::esim ?? 'eSIM') . ' ' . T::bookings, 'icon' => 'sim_card', 'url' => root.admin.'/bookings/esim'];
}

// INITIALIZE ADMIN MENU STRUCTURE - Start with core menu items
$adminMenu = [
  [
    'icon' => 'library_books',
    'name' => 'Bookings',
    'url' => root.admin.'/bookings',
    'submenu' => $bookingsSubmenu
  ],
  [
    'icon' => 'group',
    'name' => T::users,
    'url' => root.admin.'/users',
    'submenu' => $usersSubmenu
  ],
];

// ADD STAYS MENU ONLY IF MODULE IS ACTIVE
if ($stays > 0) {
    $adminMenu[] = [
        'icon' => 'hotel',
        'name' => T::stays,
        'url' => root.admin.'/stays',
        'submenu' => [
            ['name' => T::stays, 'icon' => 'hotel', 'url' => root.admin.'/stays'],
            ['name' => T::stays_settings, 'icon' => 'settings', 'url' => root.admin.'/stays/settings']
        ]
    ];
}

// ADD FLIGHTS MENU ONLY IF MODULE IS ACTIVE
if ($flights > 0) {
    $adminMenu[] = [
        'icon' => 'flight_takeoff',
        'name' => T::flights,
        'url' => root.admin.'/flights',
        'submenu' => [
            ['name' => T::flights, 'icon' => 'flight_takeoff', 'url' => root.admin.'/flights'],
            ['name' => T::airlines, 'icon' => 'airlines', 'url' => root.admin.'/flights-airlines'],
            ['name' => T::airports, 'icon' => 'airplane_ticket', 'url' => root.admin.'/flights-airports']
        ]
    ];
}

// ADD TOURS MENU ONLY IF MODULE IS ACTIVE
if ($tours > 0) {
    $adminMenu[] = [
        'icon' => 'tour',
        'name' => T::tours,
        'url' => root.admin.'/tours',
        'submenu' => [
            ['name' => T::tours, 'icon' => 'tour', 'url' => root.admin.'/tours'],
            ['name' => T::tours_settings, 'icon' => 'settings', 'url' => root.admin.'/tours/settings']
        ]
    ];
}

// ADD UMRAH MENU ONLY IF MODULE IS ACTIVE
if ($umrah_count > 0) {
    $adminMenu[] = [
        'icon' => 'mosque',
        'name' => T::umrah ?? 'Umrah',
        'url' => root.admin.'/umrah',
        'submenu' => [
            // Full CRUD console (packages, tiers, plans, departures, images).
            ['name' => 'Umrah Manager', 'icon' => 'tune', 'url' => root.admin.'/umrah-manager'],
            ['name' => 'Bookings', 'icon' => 'list_alt', 'url' => root.admin.'/umrah-manager/bookings'],
            ['name' => 'Agent groups', 'icon' => 'groups', 'url' => root.admin.'/umrah-manager/groups'],
            ['name' => T::umrah_settings ?? 'Umrah Settings', 'icon' => 'settings', 'url' => root.admin.'/umrah/settings'],
            ['name' => (T::umrah ?? 'Umrah') . ' (legacy)', 'icon' => 'mosque', 'url' => root.admin.'/umrah'],
        ]
    ];
}

// ADD VISAS MENU ONLY IF MODULE IS ACTIVE
if ($visa_count > 0) {
    $adminMenu[] = [
        'icon' => 'assignment',
        'name' => T::visa,
        'url' => root.admin.'/visa',
        'submenu' => [
            ['name' => T::visa, 'icon' => 'assignment', 'url' => root.admin.'/visa'],
            ['name' => T::visa_settings ?? 'Visa Settings', 'icon' => 'settings', 'url' => root.admin.'/visa/settings']
        ]
    ];
}

// ADD CARS MENU ONLY IF MODULE IS ACTIVE
if ($cars > 0) {
    $adminMenu[] = [
        'icon' => 'directions_car',
        'name' => T::cars,
        'url' => root.admin.'/cars',
        'submenu' => [
            ['name' => T::cars, 'icon' => 'directions_car', 'url' => root.admin.'/cars'],
            ['name' => T::cars_settings, 'icon' => 'settings', 'url' => root.admin.'/cars/settings']
        ]
    ];
}

// ADD BUS MENU ONLY IF MODULE IS ACTIVE
if ($bus_count > 0) {
    $adminMenu[] = [
        'icon' => 'directions_bus',
        'name' => T::bus ?? 'Bus',
        'url' => root.admin.'/bus',
        'submenu' => [
            ['name' => T::buses ?? 'Buses', 'icon' => 'directions_bus', 'url' => root.admin.'/bus'],
            ['name' => T::operators ?? 'Operators', 'icon' => 'badge', 'url' => root.admin.'/bus/operators']
        ]
    ];
}

// CONTINUE BUILDING MENU - Add remaining static menu items
$adminMenu = array_merge($adminMenu, [
    [
        'icon' => 'folder', 'name' => T::pages, 'url' => root.'#',
        'submenu' => [
            ['name' => T::pages, 'icon' => 'article', 'url' => root.admin.'/cms/pages'],
            ['name' => T::menus, 'icon' => 'dashboard', 'url' => root.admin.'/cms/menus']
        ]
    ],
    [
        'icon' => 'book', 'name' => T::blogs, 'url' => root.'#',
        'submenu' => [
            ['name' => T::blogs, 'icon' => 'newspaper', 'url' => root.admin.'/blogs'],
            ['name' => T::blog_categories, 'icon' => 'category', 'url' => root.admin.'/blogs/categories']
        ]
    ],
    // ['icon' => 'bar_chart', 'name' => T::analytics, 'url' => root.admin.'/analytics'],
    [
        'icon' => 'settings', 'name' => T::settings, 'url' => root.admin.'/settings',
        'submenu' => [
            [
                'name' => 'General Settings',
                'icon' => 'tune',
                'has_submenu' => true,
                'nested' => [
                    ['name' => 'General', 'icon' => 'settings', 'url' => root.admin.'/settings#general'],
                    ['name' => 'SEO', 'icon' => 'search', 'url' => root.admin.'/settings#seo'],
                    ['name' => 'Branding', 'icon' => 'palette', 'url' => root.admin.'/settings#branding'],
                    ['name' => 'Themes', 'icon' => 'format_paint', 'url' => root.admin.'/settings#themes'],
                    ['name' => 'Accounts', 'icon' => 'account_circle', 'url' => root.admin.'/settings#accounts'],
                    ['name' => 'Contact', 'icon' => 'contact_mail', 'url' => root.admin.'/settings#contact'],
                    ['name' => 'Notifications', 'icon' => 'notifications', 'url' => root.admin.'/settings#notifications'],
                    ['name' => 'Social Media', 'icon' => 'share', 'url' => root.admin.'/settings#social'],
                    ['name' => 'Apps', 'icon' => 'apps', 'url' => root.admin.'/settings#apps'],
                    ['name' => 'Tracking', 'icon' => 'analytics', 'url' => root.admin.'/settings#tracking'],
                    ['name' => 'Booking', 'icon' => 'analytics', 'url' => root.admin.'/settings#booking'],
                    ['name' => 'AI', 'icon' => 'smart_toy', 'url' => root.admin.'/settings#ai']
                ]
            ],
            ['name' => 'Mobile App', 'icon' => 'smartphone', 'url' => root.admin.'/settings/app'],
            ['name' => T::modules, 'icon' => 'extension', 'url' => root.admin.'/settings/modules'],
            ['name' => T::payment_gateways, 'icon' => 'payment', 'url' => root.admin.'/settings/gateways'],
            // ['name' => T::users_roles, 'icon' => 'admin_panel_settings', 'url' => root.admin.'/users/roles'],
            ['name' => T::languages, 'icon' => 'translate', 'url' => root.admin.'/settings/languages'],
            ['name' => T::currencies, 'icon' => 'currency_exchange', 'url' => root.admin.'/settings/currencies'],
            ['name' => T::countries, 'icon' => 'map', 'url' => root.admin.'/settings/countries'],
            ['name' => T::locations, 'icon' => 'japanese_flag', 'url' => root.admin.'/settings/locations'],
            ['name' => T::updates, 'icon' => 'refresh', 'url' => root.'updates', 'target' => '_blank'],
            ['name' => 'Database Update', 'icon' => 'database', 'url' => root.admin.'/updates/database'],
            ['name' => T::database, 'icon' => 'database', 'url' => root.admin.'/database'],
            ['name' => T::system_info, 'icon' => 'info', 'url' => root.admin.'/settings/system-info']
        ]
    ],
    [
        'icon' => 'finance', 'name' => T::finance, 'url' => root.admin.'/finance',
        'dot' => ($db->count('deposit', ['status' => 'pending']) > 0),
        'submenu' => [
            ['name' => T::credits, 'icon' => 'tune', 'url' => root.admin.'/finance/credits'],
            ['name' => T::deposits, 'icon' => 'account_balance_wallet', 'url' => root.admin.'/finance/deposit', 'badge' => $db->count('deposit', ['status' => 'pending'])],
            ['name' => T::transactions, 'icon' => 'receipt_long', 'url' => root.admin.'/finance/transactions'],
            ['name' => 'Transaction Journeys', 'icon' => 'timeline', 'url' => root.admin.'/finance/journeys'],
            ['name' => 'Tiers & Loyalty', 'icon' => 'workspace_premium', 'url' => root.admin.'/finance/tiers'],
            ['name' => 'Members', 'icon' => 'groups', 'url' => root.admin.'/finance/members'],
            ['name' => T::promo_codes, 'icon' => 'confirmation_number', 'url' => root.admin.'/promo-codes'],

            // ['name' => T::invoices, 'icon' => 'description', 'url' => root.admin.'/finance/invoices'],
            // ['name' => T::payments, 'icon' => 'payment', 'url' => root.admin.'/finance/payments'],
        ]
    ],
    [
        'icon' => 'analytics', 'name' => 'Reports', 'url' => root.'#',
        'submenu' => [
            ['name' => T::booking.' '.T::logs, 'icon' => 'history', 'url' => root.admin.'/reports/booking-logs'],
            ['name' => T::search. ' '.T::logs, 'icon' => 'search', 'url' => root.admin.'/reports/search-logs'],
            ['name' => T::webhook.' '.T::logs, 'icon' => 'webhook', 'url' => root.admin.'/reports/webhook-logs'],
            ['name' => 'Finance '.T::reports, 'icon' => 'payments', 'url' => root.admin.'/reports/finance'],
            ['name' => T::booking.' '.T::reports, 'icon' => 'assessment', 'url' => root.admin.'/reports/bookings'],
            ['name' => T::users.' '.T::reports, 'icon' => 'people_outline', 'url' => root.admin.'/reports/users'],
            ['name' => T::transactions.' '.T::reports, 'icon' => 'receipt_long', 'url' => root.admin.'/reports/transactions'],
            ['name' => T::deposit.' '.T::reports, 'icon' => 'receipt_long', 'url' => root.admin.'/reports/deposit']
        ]
    ],
    [
        'icon' => 'support_agent', 'name' => 'Support', 'url' => root.'#',
        'submenu' => [
            ['name' => 'Support Tickets', 'icon' => 'confirmation_number', 'url' => root.admin.'/support/tickets']
        ]
    ]
]);

// PRE-CALCULATE WHICH MENUS SHOULD BE EXPANDED BASED ON CURRENT URL
// IMPORTANT: This MUST be after all adminMenu items are added (including conditional modules)
$expandedMenus = [];

foreach ($adminMenu as $index => $item) {
    $shouldExpand = false;

    // CHECK IF CURRENT PAGE MATCHES ANY SUBMENU ITEM
    if (isset($item['submenu']) && is_array($item['submenu'])) {
        foreach ($item['submenu'] as $sub) {
            // CHECK DIRECT SUBMENU URL
            if (isset($sub['url']) && $sub['url'] && $sub['url'] !== '#') {
                $sub_path = parse_url($sub['url'], PHP_URL_PATH);
                if ($sub_path) {
                    $sub_path = rtrim($sub_path, '/');
                    // EXACT MATCH or HIERARCHICAL MATCH (starts with path)
                    if ($current_path === $sub_path || strpos($current_path, $sub_path . '/') === 0) {
                        $shouldExpand = true;
                        break;
                    }
                }
            }

            // CHECK NESTED ITEMS (3rd level menus)
            if (isset($sub['nested']) && is_array($sub['nested'])) {
                foreach ($sub['nested'] as $nested) {
                    if (isset($nested['url']) && $nested['url'] && $nested['url'] !== '#') {
                        $nested_path = parse_url($nested['url'], PHP_URL_PATH);
                        if ($nested_path) {
                            $nested_path = rtrim($nested_path, '/');
                            // EXACT MATCH or HIERARCHICAL MATCH
                            if ($current_path === $nested_path || strpos($current_path, $nested_path . '/') === 0) {
                                $shouldExpand = true;
                                break 2; // Break out of both nested loops
                            }
                        }
                    }
                }
            }
        }
    }

    // ADD TO EXPANDED MENUS ARRAY IF MATCH FOUND
    if ($shouldExpand) {
        $expandedMenus[$index] = true;
    }
}

// Ensure proper JSON encoding for JavaScript
$expandedMenusJson = json_encode($expandedMenus, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
$adminMenuJson = json_encode($adminMenu, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

// FETCH RECENT BOOKINGS FOR NOTIFICATIONS (exclude AI trip child invoices only:
// AITC + exactly 8 chars — parents are AIT + 10 hex and can start with AITC)
$recentNotifBookings = $db->select("bookings", [
    "id", "invoice_id", "module_type", "first_name", "last_name", "booking_date", "created_at", "booking_status"
], [
    "invoice_id[!~]" => "AITC________",
    "ORDER" => ["created_at" => "DESC"],
    "LIMIT" => 5
]) ?: [];
$notifCount = count($recentNotifBookings);
?>

<!-- ADMIN SIDEBAR WRAPPER
     LOADING MANAGEMENT: STATE LIVES IN THE sidebarOpen COOKIE SO THE SERVER
     RENDERS THE CORRECT BODY OFFSET AND RAIL WIDTH ON FIRST PAINT. THE RAIL
     SHOWS A CENTERED SPINNER, AND THE MENU CONTENT FADES IN AS SOON AS THE
     ICON FONT IS READY — NOT AT FULL PAGE LOAD. -->
<?php $sidebarExpanded = (($_COOKIE['pt_sidebar'] ?? '') === 'expanded'); ?>
<div x-data='{
    "sidebarOpen": <?= $sidebarExpanded ? "true" : "false" ?>,
    "hoverOpen": false,
    "mobileOpen": false,
    "notifOpen": false,
    "userMenuOpen": false,
    "ready": false,
    "switching": false,
    "openMenus": <?= $expandedMenusJson ?>,
    "menuItems": <?= $adminMenuJson ?>,
    "searchQuery": "",
    "currentHash": window.location.hash || "#general",
    "highlightText": function(text, query) {
        if (!query) return text;
        const escapedQuery = query.replace(/[-\/\\^$*+?.()|[\]{}]/g, "\\$&");
        const regex = new RegExp("(" + escapedQuery + ")", "gi");
        return text.replace(regex, `<span class="text-blue-400 font-semibold">$1</span>`);
    },
    "isMobile": function() { return window.innerWidth < 1024 },
    "isExpanded": function() { return this.isMobile() || (this.sidebarOpen || this.hoverOpen) },
    "init": function() {
        this.$nextTick(() => { if (this.$refs.panel) this.$refs.panel.removeAttribute("style") });
        const go = () => { this.ready = true };
        if (document.fonts && document.fonts.load) {
            Promise.race([
                document.fonts.load(`20px "Material Symbols Outlined"`),
                new Promise(res => setTimeout(res, 2500))
            ]).then(go, go);
        } else {
            setTimeout(go, 800);
        }
        if (window.innerWidth < 1024) {
            this.sidebarOpen = false;
            document.body.classList.remove("sidebar-open", "sidebar-closed");
        }
    },
    "clipPulse": function() {
        this.switching = true;
        clearTimeout(this._swT);
        this._swT = setTimeout(() => { this.switching = false }, 260);
    },
    "toggleSidebar": function() {
        const isDesktop = window.innerWidth >= 1024;
        if (isDesktop) {
            this.clipPulse();
            this.sidebarOpen = !this.sidebarOpen;
            document.cookie = "pt_sidebar=" + (this.sidebarOpen ? "expanded" : "collapsed") + "; path=/; max-age=31536000; SameSite=Lax";
            document.body.classList.toggle("sidebar-open", this.sidebarOpen);
            document.body.classList.toggle("sidebar-closed", !this.sidebarOpen);
            this.hoverOpen = false;
        } else {
            this.mobileOpen = !this.mobileOpen;
        }
    },
    "toggleMenu": function(index) {
        this.openMenus[index] = !this.openMenus[index];
    },
    "isMenuOpen": function(index) {
        if (this.searchQuery) {
            const item = this.menuItems[index];
            if (item && item.submenu && Array.isArray(item.submenu)) {
                const query = this.searchQuery.toLowerCase();
                for (const sub of item.submenu) {
                    if (sub.name && sub.name.toLowerCase().includes(query)) return true;
                    if (sub.nested && Array.isArray(sub.nested)) {
                        for (const nested of sub.nested) {
                            if (nested.name && nested.name.toLowerCase().includes(query)) return true;
                        }
                    }
                }
            }
        }
        return this.openMenus[index] === true;
    },
    "itemMatches": function(item) {
        if (!this.searchQuery) return true;
        if (!item) return false;
        const query = this.searchQuery.toLowerCase();
        if (item.name && item.name.toLowerCase().includes(query)) return true;
        if (item.submenu && Array.isArray(item.submenu)) {
            for (const sub of item.submenu) {
                if (sub.name && sub.name.toLowerCase().includes(query)) return true;
                if (sub.nested && Array.isArray(sub.nested)) {
                    for (const nested of sub.nested) {
                        if (nested.name && nested.name.toLowerCase().includes(query)) return true;
                    }
                }
            }
        }
        return false;
    },
    "subMatches": function(sub, parentItem) {
        if (!this.searchQuery) return true;
        if (!sub) return false;
        const query = this.searchQuery.toLowerCase();
        if (parentItem && parentItem.name && parentItem.name.toLowerCase().includes(query)) return true;
        if (sub.name && sub.name.toLowerCase().includes(query)) return true;
        if (sub.nested && Array.isArray(sub.nested)) {
            for (const nested of sub.nested) {
                if (nested.name && nested.name.toLowerCase().includes(query)) return true;
            }
        }
        return false;
    },
    "nestedMatches": function(nested, subItem, parentItem) {
        if (!this.searchQuery) return true;
        if (!nested) return false;
        const query = this.searchQuery.toLowerCase();
        if (parentItem && parentItem.name && parentItem.name.toLowerCase().includes(query)) return true;
        if (subItem && subItem.name && subItem.name.toLowerCase().includes(query)) return true;
        return !!(nested.name && nested.name.toLowerCase().includes(query));
    }
}'
     @keydown.escape.window="mobileOpen = false"
     @hashchange.window="currentHash = window.location.hash || '#general'">

    <!-- Mobile Overlay Backdrop -->
    <div x-show="mobileOpen"
         x-transition:enter="transition-opacity ease-linear duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="mobileOpen = false"
         class="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-40"></div>

    <!-- Mobile Burger Button (Fixed Bottom Left) -->
    <button @click="toggleSidebar()"
            class="lg:hidden fixed bottom-6 left-6 z-50 w-14 h-14 flex items-center justify-center bg-black text-white rounded-full shadow-lg hover:bg-gray-800 transition-colors border border-gray-700">
        <span class="material-symbols-outlined text-2xl">menu</span>
    </button>

    <!-- SIDEBAR NAVIGATION — INLINE STYLE PAINTS THE RAIL AT ITS EXACT SERVER-KNOWN
         WIDTH BEFORE TAILWIND/ALPINE LOAD; ALPINE REMOVES IT IN init() -->
    <div @mouseenter="if(!sidebarOpen && !isMobile()) { hoverOpen = true; clipPulse() }"
         @mouseleave="if(!sidebarOpen) clipPulse(); hoverOpen = false"
         x-ref="panel"
         style="position:fixed;top:0;left:0;bottom:0;z-index:100;width:<?= $sidebarExpanded ? 240 : 64 ?>px;background:#0a0a0a;overflow:hidden"
         class="fixed top-0 left-0 bottom-0 z-[100] bg-[#0a0a0a] border-r border-[#1a1a1a] transition-all duration-200 ease-linear flex flex-col"
         :class="[isExpanded() ? 'w-[240px]' : 'w-[64px]', mobileOpen ? 'flex' : 'hidden lg:flex', switching ? 'overflow-hidden' : '']">

    <!-- LOADING SPINNER — CENTERED IN THE RAIL FROM FIRST PAINT, FADES OUT WHEN READY -->
    <div x-show="!ready" x-transition.opacity style="position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center">
        <svg class="animate-spin" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#2a2a2a" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="#fff" stroke-width="1.5" stroke-linecap="round"></path></svg>
    </div>

    <!-- SIDEBAR HEADER -->
    <div class="h-[64px] w-full flex items-center px-4 border-b border-[#1a1a1a] shrink-0 pe-5"
         style="opacity:0;transition:opacity .4s" :style="ready && {opacity:1}"
         :class="!isExpanded() ? 'justify-center' : 'justify-between'">

        <a href="<?=root.admin?>/dashboard"
           x-show="isExpanded()"
           x-transition:enter="transition-opacity duration-200 delay-100"
           x-transition:enter-start="opacity-0"
           x-transition:enter-end="opacity-100"
           class="text-white hover:text-gray-300 flex items-center gap-2.5">
           <span class="material-symbols-outlined text-[19px]">dashboard</span>
           <span class="whitespace-nowrap text-[15px] font-medium"><?=T::dashboard?></span>
        </a>

        <button @click="toggleSidebar()"
                class="text-gray-400 hover:text-white rounded p-1">
            <svg x-show="isExpanded()" x-transition:enter="transition-opacity duration-200 delay-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"></rect><path d="M15 3v18"></path><path d="m10 15-3-3 3-3"></path></svg>
            <svg x-show="!isExpanded()" x-transition:enter="transition-opacity duration-200 delay-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"></rect><path d="M15 3v18"></path><path d="m8 9 3 3-3 3"></path></svg>
        </button>
    </div>

    <!-- SEARCH BAR -->
    <div x-show="ready" class="px-4 py-3 border-b border-[#1a1a1a] shrink-0" style="opacity:0;transition:opacity .4s" :style="ready && {opacity:1}">
        <!-- Expanded Search Input -->
        <div x-show="isExpanded()" class="relative flex items-center">
            <span class="material-symbols-outlined text-[17px] text-gray-300 absolute left-2.5 pointer-events-none">search</span>
            <input type="text" 
                   x-model="searchQuery" 
                   x-ref="searchInput"
                   placeholder="Search menu..." 
                   class="w-full bg-[#161616] border border-white/20 rounded-md text-[12px] text-white pl-8 pr-7 py-1.5 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition-colors placeholder:text-gray-300"
            />
            <button x-show="searchQuery" @click="searchQuery = ''" class="absolute right-2.5 text-gray-400 hover:text-white flex items-center">
                <span class="material-symbols-outlined text-[15px]">close</span>
            </button>
        </div>
        <!-- Collapsed Search Button -->
        <div x-show="!isExpanded()" class="flex justify-center">
            <button @click="toggleSidebar(); $nextTick(() => $refs.searchInput.focus())" 
                    class="w-8 h-8 rounded-md flex items-center justify-center text-gray-400 hover:bg-white/5 hover:text-white transition-colors">
                <span class="material-symbols-outlined text-[19px]">search</span>
            </button>
        </div>
    </div>

    <!-- SIDEBAR MENU NAVIGATION -->
    <nav class="flex-1 min-h-0 relative" style="overflow-y: scroll !important; overflow-x: hidden; display: flex; flex-direction: column; gap: 2px; padding: 8px 0; opacity:0; transition:opacity .4s" :style="ready && {opacity:1}">
         <style>
            /* Always visible scrollbar with dark gray color */
            nav {
                scrollbar-width: thin !important;
                scrollbar-color: #4a4a4a #1a1a1a !important;
            }
            nav::-webkit-scrollbar {
                width: 8px !important;
                display: block !important;
            }
            nav::-webkit-scrollbar-track {
                background: #1a1a1a !important;
            }
            nav::-webkit-scrollbar-thumb {
                background-color: #4a4a4a !important;
                border-radius: 4px;
                border: 1px solid #2a2a2a;
            }
            nav::-webkit-scrollbar-thumb:hover {
                background-color: #5a5a5a !important;
            }
            nav::-webkit-scrollbar-thumb:active {
                background-color: #6a6a6a !important;
            }
            /* Force scrollbar to always appear */
            nav::-webkit-scrollbar-button {
                display: none;
            }
         </style>
        <?php foreach ($adminMenu as $index => $item): ?>
            <?php
                // CHECK IF THIS SPECIFIC MENU ITEM IS ACTIVE (not its children)
                $menu_path = parse_url($item['url'] ?? '#', PHP_URL_PATH);
                $menu_path = $menu_path ? rtrim($menu_path, '/') : '';
                $isDirectActive = $menu_path && strpos($current_path, '/admin') !== false && ($current_path === $menu_path);
                $isActive = isMenuActive($item['url'] ?? '#', $item['submenu'] ?? null);
            ?>

            <?php if (isset($item['submenu']) && !empty($item['submenu'])): ?>
                <!-- MENU ITEM WITH SUBMENU -->
                <div class="relative" x-show="itemMatches(menuItems[<?= $index ?>])">
                    <button @click="toggleMenu(<?= $index ?>)"
                            class="w-full flex items-center gap-2.5 overflow-hidden px-4 py-1.5 transition-colors duration-150 <?= $isActive ? 'text-white' : 'text-gray-300 hover:bg-white/5 hover:text-gray-200' ?>"
                            :class="!isExpanded() ? 'justify-center' : 'justify-between'">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="relative">
                                <span class="material-symbols-outlined text-[19px] flex-shrink-0"><?= $item['icon'] ?></span>
                                <?php if (isset($item['dot']) && $item['dot']): ?>
                                    <span class="absolute -top-1 -right-1 flex h-2 w-2">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span x-show="isExpanded()" x-transition:enter="transition-opacity duration-200 delay-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="text-[13px] font-medium whitespace-nowrap truncate" x-html="highlightText(menuItems[<?= $index ?>].name, searchQuery)"><?= $item['name'] ?></span>
                        </div>
                        <span x-show="isExpanded()"
                              x-transition:enter="transition-opacity duration-200 delay-100"
                              x-transition:enter-start="opacity-0"
                              x-transition:enter-end="opacity-100"
                              class="material-symbols-outlined text-[16px] flex-shrink-0 transition-transform duration-150 font-light"
                              :class="isMenuOpen(<?= $index ?>) ? 'rotate-180' : ''"
                              style="font-variation-settings: 'FILL' 0, 'wght' 200, 'GRAD' 0, 'opsz' 20;">
                            expand_more
                        </span>
                    </button>

                    <!-- SUBMENU DROPDOWN -->
                    <div x-show="isMenuOpen(<?= $index ?>) && isExpanded()"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="flex flex-col gap-1 mt-1">
                        <?php foreach ($item['submenu'] as $subindex => $subitem): ?>
                            <?php if (isset($subitem['divider']) && $subitem['divider']): ?>
                                <!-- DIVIDER -->
                                <div class="border-t border-gray-700 my-1" x-show="!searchQuery"></div>
                            <?php elseif (isset($subitem['has_submenu']) && $subitem['has_submenu']): ?>
                                <!-- NESTED SUBMENU (3rd level) -->
                                <div x-data="{ 
                                    localNestedOpen: <?= isMenuActive('#', $subitem['nested'] ?? []) ? 'true' : 'false' ?>,
                                    isNestedOpen() {
                                        if (searchQuery) {
                                            const query = searchQuery.toLowerCase();
                                            const nestedItems = menuItems[<?= $index ?>].submenu[<?= $subindex ?>].nested || [];
                                            for (const nested of nestedItems) {
                                                if (nested.name && nested.name.toLowerCase().includes(query)) return true;
                                            }
                                        }
                                        return this.localNestedOpen;
                                    }
                                }" x-show="subMatches(menuItems[<?= $index ?>].submenu[<?= $subindex ?>], menuItems[<?= $index ?>])">
                                    <?php
                                        // CHECK IF ANY NESTED ITEM IS ACTIVE
                                        $isNestedParentActive = false;
                                        if (isset($subitem['nested'])) {
                                            foreach ($subitem['nested'] as $nested) {
                                                if (isset($nested['url']) && $nested['url']) {
                                                    $nested_check_path = parse_url($nested['url'], PHP_URL_PATH);
                                                    if ($nested_check_path) {
                                                        $nested_check_path = rtrim($nested_check_path, '/');
                                                        // EXACT MATCH ONLY - Admin pages only
                                                        if (strpos($current_path, '/admin') !== false && $current_path === $nested_check_path) {
                                                            $isNestedParentActive = true;
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    ?>
                                    <button @click="localNestedOpen = !localNestedOpen"
                                            class="w-full flex items-center gap-2.5 overflow-hidden py-1 pl-8 pr-4 transition-colors duration-150 <?= $isNestedParentActive ? 'bg-blue-500/10 text-blue-400' : 'text-gray-500 hover:bg-white/5 hover:text-gray-300' ?>">
                                        <span class="material-symbols-outlined text-[17px] flex-shrink-0"><?= $subitem['icon'] ?></span>
                                        <span class="text-[12.5px] font-medium truncate flex-1 text-left" x-html="highlightText(menuItems[<?= $index ?>].submenu[<?= $subindex ?>].name, searchQuery)"><?= $subitem['name'] ?></span>
                                        <span class="material-symbols-outlined text-[16px] flex-shrink-0 transition-transform"
                                              :class="localNestedOpen || isNestedOpen() ? 'rotate-180' : ''">
                                            expand_more
                                        </span>
                                    </button>

                                    <!-- NESTED ITEMS -->
                                    <div x-show="localNestedOpen || isNestedOpen()" x-transition class="mt-1 flex flex-col gap-0.5">
                                         <?php if (isset($subitem['nested'])): ?>
                                             <?php foreach ($subitem['nested'] as $nestedIndex => $nestedItem): ?>
                                                 <?php
                                                     $nested_path = parse_url($nestedItem['url'], PHP_URL_PATH);
                                                     $fragment = parse_url($nestedItem['url'], PHP_URL_FRAGMENT) ?? '';
                                                     if ($nested_path) {
                                                         $nested_path = rtrim($nested_path, '/');
                                                         // Only exact match - Admin pages only
                                                         $isNestedActive = (strpos($current_path, '/admin') !== false && $current_path === $nested_path);
                                                     } else {
                                                         $isNestedActive = false;
                                                     }
                                                 ?>
                                                 <a href="<?= $nestedItem['url'] ?>"
                                                    class="flex items-center gap-2.5 overflow-hidden py-1 pl-12 pr-4 transition-colors duration-150"
                                                    :class="(<?= $isNestedActive ? 'true' : 'false' ?> && currentHash === '#<?= $fragment ?>') ? 'bg-blue-500/10 text-blue-400' : 'text-gray-500 hover:bg-white/10 hover:text-gray-300'"
                                                    x-show="nestedMatches(menuItems[<?= $index ?>].submenu[<?= $subindex ?>].nested[<?= $nestedIndex ?>], menuItems[<?= $index ?>].submenu[<?= $subindex ?>], menuItems[<?= $index ?>])">
                                                     <span class="material-symbols-outlined text-[17px] flex-shrink-0"><?= $nestedItem['icon'] ?></span>
                                                     <span class="text-[12.5px] font-medium truncate" x-html="highlightText(menuItems[<?= $index ?>].submenu[<?= $subindex ?>].nested[<?= $nestedIndex ?>].name, searchQuery)"><?= $nestedItem['name'] ?></span>
                                                 </a>
                                             <?php endforeach; ?>
                                         <?php endif; ?>
                                     </div>
                                </div>
                            <?php else: ?>
                                <!-- Regular Submenu Item -->
                                <?php if(isset($subitem['url']) && $subitem['url']): ?>
                                <?php
                                    $subitem_path = parse_url($subitem['url'], PHP_URL_PATH);
                                    $subitem_query = parse_url($subitem['url'], PHP_URL_QUERY);
                                    if ($subitem_path) {
                                        $subitem_path = rtrim($subitem_path, '/');
                                        if (strpos($current_path, '/admin') !== false && $current_path === $subitem_path) {
                                            $current_params = [];
                                            parse_str($_SERVER['QUERY_STRING'] ?? '', $current_params);
                                            unset($current_params['url']);

                                            $subitem_params = [];
                                            if ($subitem_query) {
                                                parse_str($subitem_query, $subitem_params);
                                            }

                                            if (!empty($subitem_params)) {
                                                $isSubitemActive = true;
                                                foreach ($subitem_params as $key => $val) {
                                                    if (!isset($current_params[$key]) || $current_params[$key] !== $val) {
                                                        $isSubitemActive = false;
                                                        break;
                                                    }
                                                }
                                            } else {
                                                $isSubitemActive = !isset($current_params['search_col']) || $current_params['search_col'] !== 'role';
                                            }
                                        } else {
                                            $isSubitemActive = false;
                                        }
                                    } else {
                                        $isSubitemActive = false;
                                    }
                                ?>
                                <a href="<?= $subitem['url'] ?>"<?= !empty($subitem['target']) ? ' target="' . $subitem['target'] . '" rel="noopener"' : '' ?>
                                   class="flex items-center gap-2.5 overflow-hidden py-1 pl-8 pr-4 transition-colors duration-150 <?= $isSubitemActive ? 'bg-blue-500/10 text-blue-400' : 'text-gray-500 hover:bg-white/5 hover:text-gray-300' ?>"
                                   x-show="subMatches(menuItems[<?= $index ?>].submenu[<?= $subindex ?>], menuItems[<?= $index ?>])">
                                    <span class="material-symbols-outlined text-[17px] flex-shrink-0"><?= $subitem['icon'] ?></span>
                                    <span class="text-[12.5px] font-medium truncate flex-1" x-html="highlightText(menuItems[<?= $index ?>].submenu[<?= $subindex ?>].name, searchQuery)"><?= $subitem['name'] ?></span>
                                    <?php if (isset($subitem['badge']) && $subitem['badge'] > 0): ?>
                                        <span class="bg-red-600 text-white text-[10px] px-1.5 py-0.5 rounded-full font-bold ml-auto"><?= $subitem['badge'] ?></span>
                                    <?php endif; ?>
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Simple Menu Item -->
                <a href="<?= $item['url'] ?>"
                   class="flex items-center gap-2.5 overflow-hidden px-4 py-1.5 transition-colors duration-150 <?= $isDirectActive ? 'text-white' : 'text-gray-300 hover:bg-white/5 hover:text-gray-200' ?>"
                   :class="!isExpanded() ? 'justify-center' : ''"
                   x-show="itemMatches(menuItems[<?= $index ?>])">

                    <span class="material-symbols-outlined text-[19px] flex-shrink-0"><?= $item['icon'] ?></span>
                    <span x-show="isExpanded()" x-transition:enter="transition-opacity duration-200 delay-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="text-[13px] font-medium whitespace-nowrap truncate" x-html="highlightText(menuItems[<?= $index ?>].name, searchQuery)"><?= $item['name'] ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <!-- SIDEBAR FOOTER -->
    <div class="flex-shrink-0 flex flex-col gap-0.5 py-2 border-t border-[#1a1a1a]"
         style="opacity:0;transition:opacity .4s" :style="ready && {opacity:1}">
        <!-- Notifications -->
        <div class="relative" style="display:none">
            <button @click.stop="notifOpen = !notifOpen"
                    class="w-full flex items-center gap-2.5 overflow-hidden px-4 py-2 text-gray-400 hover:bg-white/5 hover:text-gray-200 transition-colors duration-150 relative"
                    :class="!isExpanded() ? 'justify-center' : 'justify-between'">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="material-symbols-outlined text-[19px] flex-shrink-0">notifications</span>
                    <span x-show="isExpanded()" x-transition:enter="transition-opacity duration-200 delay-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="text-[13px] font-medium whitespace-nowrap truncate">Notifications</span>
                    <!-- Badge on icon when collapsed -->
                    <span x-show="!isExpanded() && <?= $notifCount ?> > 0" class="absolute top-1 left-7 w-4 h-4 bg-red-600 text-white text-[10px] rounded-full flex items-center justify-center font-medium"><?= $notifCount ?></span>
                </div>
                <!-- Badge on right when expanded -->
                <span x-show="isExpanded() && <?= $notifCount ?> > 0" class="px-2 py-0.5 text-[10px] font-medium bg-red-600 rounded-full flex-shrink-0"><?= $notifCount ?></span>
            </button>
 
            <!-- Notification Dropdown Menu -->
            <div x-show="notifOpen"
                 x-transition
                 @click.away="notifOpen = false"
                 class="absolute bottom-full left-0 right-0 mb-2 mx-2 bg-gray-900 border border-gray-700 rounded-lg shadow-xl overflow-hidden z-[110]"
                 style="min-width: 200px;">
                <div class="px-3 py-2 border-b border-gray-700 bg-gray-800/50 flex items-center justify-between">
                    <span class="text-xs font-bold text-gray-300 uppercase tracking-wider">Recent Bookings</span>
                    <span class="bg-blue-600 text-white text-[10px] px-1.5 py-0.5 rounded-full"><?= $notifCount ?></span>
                </div>
                <div class="max-h-[300px] overflow-y-auto">
                    <?php if (!empty($recentNotifBookings)): ?>
                        <?php foreach ($recentNotifBookings as $notif): ?>
                            <a href="<?= root . admin ?>/bookings" class="flex items-start gap-3 px-3 py-3 hover:bg-white/5 transition-colors border-b border-gray-800/50 last:border-0">
                                <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0 mt-0.5">
                                    <span class="material-symbols-outlined text-blue-400 text-lg">
                                        <?php
                                        switch($notif['module_type']) {
                                            case 'flights': echo 'flight_takeoff'; break;
                                            case 'hotels': echo 'hotel'; break;
                                            case 'tours': echo 'explore'; break;
                                            case 'cars': echo 'directions_car'; break;
                                            case 'visa': echo 'card_membership'; break;
                                            default: echo 'book'; break;
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[12.5px] font-medium text-gray-200 truncate"><?= $notif['first_name'] . ' ' . $notif['last_name'] ?></p>
                                    <p class="text-[11px] text-gray-500 truncate"><?= ucfirst($notif['module_type']) ?> - <?= $notif['invoice_id'] ?></p>
                                    <p class="text-[10px] text-gray-600 mt-0.5"><?= date('M d, H:i', strtotime($notif['created_at'])) ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="px-4 py-8 text-center">
                            <span class="material-symbols-outlined text-gray-700 text-3xl mb-1">notifications_off</span>
                            <p class="text-xs text-gray-500">No new notifications</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="p-2 border-t border-gray-700 bg-gray-800/30">
                    <a href="<?= root . admin ?>/bookings" class="block text-center text-xs font-medium text-blue-400 hover:text-blue-300 transition-colors">
                        View All Bookings
                    </a>
                </div>
            </div>
        </div>

        <!-- User Menu -->
        <div class="relative">
            <button @click.stop="userMenuOpen = !userMenuOpen"
                    class="w-full flex items-center gap-2.5 overflow-hidden px-4 py-1.5 text-gray-400 hover:bg-white/5 hover:text-gray-200 transition-colors duration-150"
                    :class="!isExpanded() ? 'justify-center' : 'justify-between'">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="material-symbols-outlined text-[19px] flex-shrink-0">account_circle</span>
                    <span x-show="isExpanded()" x-transition:enter="transition-opacity duration-200 delay-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="text-[13px] font-medium whitespace-nowrap truncate"><?= $_SESSION['user_name'] ?? 'User' ?></span>
                </div>
                <span x-show="isExpanded()" x-transition:enter="transition-opacity duration-200 delay-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="material-symbols-outlined text-[18px] flex-shrink-0 transition-transform duration-150" :class="userMenuOpen ? 'rotate-180' : ''">expand_more</span>
            </button>
 
            <!-- User Dropdown Menu -->
            <div x-show="userMenuOpen"
                 x-transition
                 @click.away="userMenuOpen = false"
                 class="absolute bottom-full left-0 right-0 mb-2 bg-gray-900 border border-gray-700 rounded-lg shadow-lg overflow-hidden"
                 style="min-width: 200px;">
                <a href="<?=root?>profile" class="flex items-center gap-3 px-4 py-2.5 text-white hover:bg-gray-800 transition-colors">
                    <span class="material-symbols-outlined text-base">person</span>
                    <span class="text-sm">Profile</span>
                </a>
                <a href="<?=root.admin?>/settings" class="flex items-center gap-3 px-4 py-2.5 text-white hover:bg-gray-800 transition-colors">
                    <span class="material-symbols-outlined text-base">settings</span>
                    <span class="text-sm">Settings</span>
                </a>
                <div class="border-t border-gray-700"></div>
                <a href="<?=root?>logout" class="flex items-center gap-3 px-4 py-2.5 text-red-400 hover:bg-gray-800 transition-colors">
                    <span class="material-symbols-outlined text-base">logout</span>
                    <span class="text-sm">Logout</span>
                </a>
            </div>
        </div>
    </div>
</div>
<!-- Close wrapper div -->
</div>

<!-- Add CSS for sidebar layout -->
<style>
/* Body offset rules live in the header critical CSS (must apply before first paint) */

/* Premium dark sidebar styling */
.material-symbols-outlined {
    font-variation-settings: 'FILL' 0, 'wght' 350, 'GRAD' 0, 'opsz' 20;
}

[x-cloak] { display: none !important; }

/* Premium subtle shadows for depth */
.bg-blue-500\/15 {
    box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.1);
}

.bg-blue-500\/10 {
    box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.08);
}
</style>

<?php
    // Show setup progress alert only on admin pages that contain 'admin' in URL.
    // HIDDEN ON localhost AND phptravels.net (DEV/DEMO ENVIRONMENTS).
    $current_uri  = $_SERVER['REQUEST_URI'] ?? '';
    $alertHost    = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    $alertHost    = preg_replace('/^www\./', '', $alertHost);
    $hiddenHosts  = ['localhost', '127.0.0.1', '::1', 'phptravels.net'];
    if (!in_array($alertHost, $hiddenHosts, true)
        && strpos($current_uri, admin) !== false
        && strpos($current_uri, '/get-started') === false) {
        require_once __DIR__ . '/setup-progress-alert.php';
    }
?>

<?php } ?>
