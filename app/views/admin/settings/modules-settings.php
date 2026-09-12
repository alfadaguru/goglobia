<?php
// Module credential configurations
$moduleCredentials = [
    'stays' => [
        'hotels' => [
             'api_crendential' => false,
             'api_testing' => false,
             'env' => false,
             'currency' => false,
        ],
        'stuba' => [
            'c1' => [
                'label' => 'ORG ID',
                'required' => true,
                'placeholder' => 'Enter your Stuba ORG ID'
            ],
            'c2' => [
                'label' => 'Username',
                'required' => true,
                'placeholder' => 'Enter your Stuba username'
            ],
            'c3' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your Stuba password'
            ],
        ],
        'hotelston' => [
            'c1' => [
                'label' => 'Email',
                'required' => true,
                'placeholder' => 'Enter your Hotelston Email'
            ],
            'c2' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your Hotelston Password'
            ],
            'c3' => [
                'label' => 'Profile ID',
                'required' => true,
                'placeholder' => 'Enter your Hotelston Profile ID'
            ],
        ],
        'hotelbeds' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Hotelbeds API Key'
            ],
            'c2' => [
                'label' => 'Secret',
                'required' => true,
                'placeholder' => 'Enter your Hotelbeds Secret'
            ],

        ],
        'ratehawk' => [
            'c1' => [
                'label' => 'Key ID',
                'required' => true,
                'placeholder' => 'Enter your Ratehawk Key ID'
            ],
            'c2' => [
                'label' => 'Key Type',
                'required' => true,
                'placeholder' => 'Enter your Ratehawk Key Type'
            ],
            'c3' => [
                'label' => 'API Keys',
                'required' => true,
                'placeholder' => 'Enter your Ratehawk API Keys'
            ],
            'c4' => [
                'label' => 'Base URL',
                'required' => true,
                'type' => 'text',
                'placeholder' => 'Enter your Ratehawk Base URL (e.g. https://api-sandbox.worldota.net/)'
            ],
        ],
        'agoda' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Agoda API Key'
            ],
            'c2' => [
                'label' => 'API Secret',
                'required' => true,
                'placeholder' => 'Enter your Agoda API Secret'
            ]
        ],
        'booking' => [
            'c1' => [
                'label' => 'RapidAPI Key',
                'required' => true,
                'placeholder' => 'Enter your RapidAPI Key (e.g., 7407e154e7msh...)'
            ],
            'c2' => [
                'label' => 'RapidAPI Host',
                'required' => false,
                'type' => 'text',
                'placeholder' => 'booking-com15.p.rapidapi.com'
            ],
        ],
        'amadeus' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Amadeus API Key'
            ],
            'c2' => [
                'label' => 'API Secret',
                'required' => true,
                'placeholder' => 'Enter your Amadeus API Secret'
            ]
        ],
        'tbo-holidays' => [
            'c1' => [
                'label' => 'Username',
                'required' => true,
                'type' => 'text',
                'placeholder' => 'Enter your TBO Holidays Username'
            ],
            'c2' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your TBO Holidays Password'
            ],
            'c3' => [
                'label' => 'Service URL',
                'required' => true,
                'type' => 'text',
                'placeholder' => 'Test: https://api.tbotechnology.in/HotelAPI | Live: URL from TBO'
            ]
        ],
        'wanderbeds' => [
            'c1' => [
                'label' => 'Username',
                'required' => true,
                'type' => 'text',
                'placeholder' => 'Enter your Wanderbeds Username'
            ],
            'c2' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your Wanderbeds Password'
            ],
            'c3' => [
                'label' => 'Base URL',
                'required' => true,
                'type' => 'text',
                'placeholder' => 'https://api.wanderbeds.com'
            ]
        ],
        'travelport' => [
            'c1' => [
                'label' => 'Username',
                'required' => true,
                'placeholder' => 'e.g., Universal API/uAPI7072905908-cb4b3e26'
            ],
            'c2' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your Travelport Password'
            ],
            'c3' => [
                'label' => 'Branch Code',
                'required' => true,
                'placeholder' => 'e.g., P7096532'
            ],
            'c4' => [
                'label' => 'PCC',
                'required' => false,
                'placeholder' => 'e.g., 6E80 (optional)'
            ]
        ],
    ],
    'flights' => [
        'flights' => [
             'api_crendential' => false,
             'api_testing' => false,
             'env' => false,
             'currency' => false,
        ],
        'duffel' => [
            'c1' => [
                'label' => 'API Token',
                'required' => true,
                'placeholder' => 'Enter your Duffel API Token (starts with duffel_test_ or duffel_live_)'
            ]
        ],
        'pkfare' => [
            'c1' => [
                'label' => 'Partner ID',
                'required' => true,
                'placeholder' => 'Enter your PKFare Partner ID'
            ],
            'c2' => [
                'label' => 'Sign',
                'required' => true,
                'placeholder' => 'Enter your PKFare Sign'
            ],
        ],
        'travelpayouts' => [
            'c1' => [
                'label' => 'API Token',
                'required' => true,
                'placeholder' => 'Enter your Travelpayouts API Token'
            ],
            'c2' => [
                'label' => 'Partner ID',
                'required' => true,
                'placeholder' => 'Enter your Travelpayouts Partner ID'
            ],
        ],
        'travelport' => [
            'c1' => [
                'label' => 'Client ID',
                'required' => true,
                'placeholder' => 'Enter your Travelport+ Client ID'
            ],
            'c2' => [
                'label' => 'Client Secret',
                'required' => true,
                'placeholder' => 'Enter your Travelport+ Client Secret'
            ],
            'c3' => [
                'label' => 'Username',
                'required' => true,
                'placeholder' => 'Enter your Travelport+ Username'
            ],
            'c4' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your Travelport+ Password'
            ],
            'c5' => [
                'label' => 'Branch ID / Access Group',
                'required' => true,
                'placeholder' => 'e.g., BCD4940F-F55D-4B63-B0E9-FA95701C8AE3'
            ],
            'c6' => [
                'label' => 'PCC',
                'required' => true,
                'placeholder' => 'e.g., A990'
            ]
        ],
        'amadeus' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Amadeus API Key'
            ],
            'c2' => [
                'label' => 'API Secret',
                'required' => true,
                'placeholder' => 'Enter your Amadeus API Secret'
            ]
        ],
        'kiwi' => [
            'c1' => [
                'label' => 'AffilID',
                'required' => true,
                'placeholder' => 'Enter your Kiwi AffilID'
            ],
            'c2' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Kiwi API Key'
            ]
        ],
        'kayak' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Kayak API Key'
            ],

        ],
        'googleflights' => [
            'c1' => [
                'label' => 'RapidAPI Key',
                'required' => true,
                'placeholder' => 'Enter your RapidAPI Key for google-flights2.p.rapidapi.com'
            ],
        ],
        'amadeus_enterprise' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Amadeus Enterprise API Key'
            ],
            'c2' => [
                'label' => 'API Secret',
                'required' => true,
                'placeholder' => 'Enter your Amadeus Enterprise API Secret'
            ]
        ],
        'seeru' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Seeru Travel API Key'
            ],
            'c2' => [
                'label' => 'Refresh Key',
                'required' => true,
                'placeholder' => 'Enter your Seeru Travel Refresh Key'
            ]
        ],
        'tbo' => [
            'c1' => [
                'label' => 'Username',
                'required' => true,
                'placeholder' => 'Enter your username'
            ],
            'c2' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your password'
            ],
            'c3' => [
                'label' => 'IP Address',
                'required' => true,
                'placeholder' => 'Enter your IP Address'
            ],
        ],
        'sabre' => [
            'c1' => [
                'label' => 'PCC (Pseudo City Code)',
                'required' => true,
                'placeholder' => 'Enter your sabre PCC'
            ],
            'c2' => [
                'label' => 'EPR (Enterprise Profile Record)',
                'required' => true,
                'placeholder' => 'Enter your sabre EPR'
            ],
            'c3' => [
                'label' => 'Domain',
                'required' => true,
                'placeholder' => 'Enter your sabre Domain'
            ],
            'c4' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your sabre Password'
            ],
        ],
        'mystifly' => [
            'c1' => [
                'label' => 'Account Number (MCN)',
                'required' => true,
                'placeholder' => 'Enter your Mystifly Account Number / MCN'
            ],
            'c2' => [
                'label' => 'Username',
                'required' => true,
                'placeholder' => 'Enter your Mystifly Username'
            ],
            'c3' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your Mystifly Password'
            ],
            'c4' => [
                'label' => 'Session ID (Bearer Token)',
                'required' => false,
                'placeholder' => 'Auto-generated via CreateSession — or enter manually'
            ],
            'c5' => [
                'label' => 'Base URL',
                'required' => false,
                'placeholder' => 'e.g., https://restapidemo.myfarebox.com (leave blank for default)'
            ],
        ],

    ],
    'cars' => [
        'cars' => [
             'api_crendential' => false,
             'api_testing' => false,
             'env' => false,
             'currency' => false,
        ],
        'cartrawler' => [
            'c1' => [
                'label' => 'Client ID',
                'required' => true,
                'placeholder' => 'Enter your Cartrawler Client ID'
            ],
            'c2' => [
                'label' => 'TV',
                'required' => true,
                'placeholder' => 'Enter your Cartrawler TV'
            ]
        ],
        'discover_cars' => [
            'c1' => [
                'label' => 'Username',
                'required' => true,
                'placeholder' => 'Enter your Discover Cars Username'
            ],
            'c2' => [
                'label' => 'Password',
                'required' => true,
                'placeholder' => 'Enter your Discover Cars Password'
            ],
            'c3' => [
                'label' => 'Token',
                'required' => true,
                'placeholder' => 'Enter your Discover Cars Token'
            ],
        ],
        'kiwitaxi' => [
            'c1' => [
                'label' => 'Partner ID',
                'required' => true,
                'placeholder' => 'Enter your KiwiTaxi Partner/Affiliate ID'
            ],
            'c2' => [
                'label' => 'Security Token',
                'required' => true,
                'placeholder' => 'Enter your KiwiTaxi Security Token'
            ],
        ],
        'mozio' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Mozio API Key'
            ],
            'c2' => [
                'label' => 'Payment Mode',
                'required' => true,
                'type' => 'select',
                'options' => [
                    'partner_managed' => 'Partner-Managed (you charge the customer, Mozio invoices you monthly)',
                    'hosted_checkout' => 'Mozio-Hosted Checkout (Mozio charges the customer directly)',
                    'tokenized'       => 'Direct Card Tokenization (not yet available)',
                ],
                'help' => 'Controls how customers pay for Mozio transfer/hourly bookings. Must match what Mozio has actually configured for this API key.'
            ],
        ],

    ],
    'tours' => [
        'tours' => [
             'api_crendential' => false,
             'api_testing' => false,
             'env' => false,
             'currency' => false,
        ],

        'tiqets' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Tiqets API Key'
            ],

        ],
        'viator' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Viator API Key'
            ],

        ],
        'viator_merchant' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Viator Merchant API Key'
            ],
            'c2' => [
                'label' => 'Merchant ID',
                'required' => true,
                'placeholder' => 'Enter your Viator Merchant ID'
            ]
        ],
        'toursbms' => [
            'api_testing' => true,
            'c1' => [
                'label' => 'Merchant ID',
                'required' => true,
                'placeholder' => 'Enter your ToursBMS Merchant ID'
            ],
            'c2' => [
                'label' => 'Secret Key',
                'required' => true,
                'placeholder' => 'Enter your ToursBMS Secret Key'
            ],
            'c3' => [
                'label' => 'Base URL (optional)',
                'required' => false,
                'type' => 'text',
                'placeholder' => 'http://api.toursbms.com (default)'
            ],
        ],

    ],
    'visa' => [
        // Local inventory (no third-party API) — same pattern as umrah/cars/tours
        'visa' => [
            'api_crendential' => false,
            'api_testing' => false,
            'env' => false,
            'currency' => false,
        ],
        // Reserved for a future API supplier row (name=atlys)
        'atlys' => [
            'c1' => 'API Key',
            'c2' => 'Partner ID'
        ]
    ],
    'umrah' => [
        'umrah' => [
            'api_crendential' => false,
            'api_testing' => false,
            'env' => false,
            'currency' => false,
        ],
    ],
    'esim' => [
        'airalo' => [
            'c1' => [
                'label' => 'API Client ID',
                'required' => true,
                'placeholder' => 'Enter your Airalo API Client ID'
            ],
            'c2' => [
                'label' => 'API Client Secret',
                'required' => true,
                'placeholder' => 'Enter your Airalo API Client Secret'
            ]
        ]
    ],
    'rail' => [
        'train' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter your Rail API Key'
            ],
            'c2' => [
                'label' => 'Base URL',
                'required' => true,
                'placeholder' => 'Enter your Rail Base URL'
            ]
        ]
    ],
    'ferries' => [
        'kikoto' => [
            'c1' => [
                'label' => 'Bearer Token',
                'required' => true,
                'placeholder' => 'Enter your Kikoto Bearer Token'
            ],
            'c2' => [
                'label' => 'Base URL',
                'required' => true,
                'placeholder' => 'Enter Kikoto Base URL'
            ]
        ]
    ]
];

// Get credentials for current module
$moduleName = strtolower($module['name']);
$moduleType = strtolower($module['type']);
$credentialFields = [];

// Database-level visibility switch; older schemas remain compatible.
$showApiCredentials = !array_key_exists('credentials', $module) || (string)$module['credentials'] === '1';
$showApiTesting = true;
$showEnvironment = true;
$showCurrency = true;
$showDatabaseTab = !empty($module['import_database']) || in_array(strtolower($module['name'] ?? ''), ['hotelbeds', 'hotelston', 'stuba', 'agoda', 'ratehawk', 'tbo-holidays', 'wanderbeds', 'toursbms']);
$showStationsTab = ($moduleType === 'rail' && $moduleName === 'train');
// Airalo manages markup/currency per-package via airalo_packages, so hide the global Markup & Tax tab.
$hideMarkupTab = (strtolower($module['name'] ?? '') === 'airalo');

// Check if we have specific credentials for this module
if (isset($moduleCredentials[$moduleType]) && isset($moduleCredentials[$moduleType][$moduleName])) {
    $credentialFields = $moduleCredentials[$moduleType][$moduleName];

    // These are UI meta-flags, not credential inputs. Flip the matching $show* flag
    // when they are explicitly false, then always remove them so they never render
    // as a credential field (a `true` value would otherwise print a field labelled "1").
    if (isset($credentialFields['api_crendential'])) {
        if ($credentialFields['api_crendential'] === false) $showApiCredentials = false;
        unset($credentialFields['api_crendential']);
    }
    if (isset($credentialFields['api_testing'])) {
        if ($credentialFields['api_testing'] === false) $showApiTesting = false;
        unset($credentialFields['api_testing']);
    }
    if (isset($credentialFields['env'])) {
        if ($credentialFields['env'] === false) $showEnvironment = false;
        unset($credentialFields['env']);
    }
    if (isset($credentialFields['currency'])) {
        if ($credentialFields['currency'] === false) $showCurrency = false;
        unset($credentialFields['currency']);
    }
} else {
    $credentialFields = [
        'c1' => ['label' => 'Credential 1', 'required' => false, 'placeholder' => ''],
        'c2' => ['label' => 'Credential 2', 'required' => false, 'placeholder' => ''],
        'c3' => ['label' => 'Credential 3', 'required' => false, 'placeholder' => ''],
        'c4' => ['label' => 'Credential 4', 'required' => false, 'placeholder' => ''],
        'c5' => ['label' => 'Credential 5', 'required' => false, 'placeholder' => ''],
        'c6' => ['label' => 'Credential 6', 'required' => false, 'placeholder' => '']
    ];
}

$availableModuleTabs = [];
if ($showApiCredentials) $availableModuleTabs[] = 'credentials';
if (!$hideMarkupTab) $availableModuleTabs[] = 'markup';
if ($showDatabaseTab) $availableModuleTabs[] = 'database';
if ($showStationsTab) $availableModuleTabs[] = 'stations';
if (!empty($module['content_import'])) { $availableModuleTabs[] = 'import'; $availableModuleTabs[] = 'contents'; }
$defaultModuleTab = $availableModuleTabs[0] ?? 'credentials';
?>

<style>
[draggable="true"]:active {
    opacity: 0.5;
    cursor: grabbing !important;
}
[draggable="true"] {
    cursor: grab;
}
</style>

<!-- Page Header -->
<div class="container py-6 pb-0">

<!-- Flash Message -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success mb-5' : 'alert-error mb-5' ?>">
        <span class="material-icon material-symbols-outlined">
            <?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?>
        </span>
        <p><?= isset($_SESSION['message']['key']) ? constant('T::' . $_SESSION['message']['key']) : $_SESSION['message']['text'] ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root.admin ?>/settings/modules#<?= $module['type'] ?>" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">
                    <?= ucwords(str_replace('_', ' ', $module['name'])) ?> <?=T::configuration?>
                </h1>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">category</span>
                        <?= ucfirst($module['type']) ?> <?=T::module?>
                    </span>
                    <span>•</span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">tag</span>
                        <?=T::id?>: <?= $module['id'] ?>
                    </span>
                </div>
                <?php if (strtolower($module['type'] ?? '') === 'umrah'): ?>
                <!-- Umrah has its own dedicated CRUD console (packages, tiers,
                     payment plans, departures, images). Surface it right here so
                     it is reachable from Settings -> Modules -> Umrah. -->
                <div class="mt-3">
                    <a href="<?= root.admin ?>/umrah-manager" class="btn inline-flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">tune</span>
                        Manage Umrah (packages, tiers, departures &amp; images)
                    </a>
                    <p class="text-xs text-slate-500 mt-1">Create/edit/archive packages, tiers, payment plans and departures, and set real Umrah images.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="view-options mt-2 sm:mt-0 flex items-end gap-3 min-w-[365px]">
            <div class="flex flex-col gap-1 flex-1 min-w-0">
                <label for="module_status" class="text-xs font-medium text-slate-600"><?=T::module?> <?=T::status?></label>
                <select id="module_status" class="select input text-sm py-1.5 px-3 w-full">
                    <option value="1" <?= $module['status'] ? 'selected' : '' ?>><?=T::active?></option>
                    <option value="0" <?= !$module['status'] ? 'selected' : '' ?>><?=T::inactive?></option>
                </select>
            </div>
            <?php if ($showEnvironment): ?>
            <div class="flex flex-col gap-1 flex-1 min-w-0">
                <label for="dev_mode_status" class="text-xs font-medium text-slate-600"><?=T::environment?></label>
                <select id="dev_mode_status" name="dev_mode" form="settingsForm" class="select input text-sm py-1.5 px-3 w-full">
                    <option value="0" <?= !$module['dev_mode'] ? 'selected' : '' ?>><?=T::production?></option>
                    <option value="1" <?= $module['dev_mode'] ? 'selected' : '' ?>><?=T::development?></option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        </div>
    </div>
</div>







<!-- Tab Navigation -->
<div class="bg-white border-b border-gray-200">
    <div class="container">
        <nav class="flex overflow-x-auto" id="moduleTabNav">
            <?php if ($showApiCredentials): ?>
            <button type="button" id="tab-btn-credentials" onclick="switchTab('credentials')"
                    class="tab-btn flex items-center gap-2 px-5 py-3.5 text-sm font-medium border-b-2 border-blue-600 text-blue-600 whitespace-nowrap transition-colors">
                <span class="material-symbols-outlined text-[18px]">key</span>
                <?=T::api_credentials?>
            </button>
            <?php endif; ?>
            <?php if (!$hideMarkupTab): ?>
            <button type="button" id="tab-btn-markup" onclick="switchTab('markup')"
                    class="tab-btn flex items-center gap-2 px-5 py-3.5 text-sm font-medium border-b-2 <?= $defaultModuleTab === 'markup' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap transition-colors">
                <span class="material-symbols-outlined text-[18px]">percent</span>
                Markup &amp; Tax
            </button>
            <?php endif; ?>
            <?php if ($showDatabaseTab): ?>
            <button type="button" id="tab-btn-database" onclick="switchTab('database')"
                    class="tab-btn flex items-center gap-2 px-5 py-3.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap transition-colors">
                <span class="material-symbols-outlined text-[18px]">storage</span>
                Database
            </button>
            <?php endif; ?>
            <?php if ($showStationsTab): ?>
            <button type="button" id="tab-btn-stations" onclick="switchTab('stations')"
                    class="tab-btn flex items-center gap-2 px-5 py-3.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap transition-colors">
                <span class="material-symbols-outlined text-[18px]">directions_railway</span>
                Station Import
            </button>
            <?php endif; ?>
            <?php if (!empty($module['content_import'])): ?>
            <button type="button" id="tab-btn-import" onclick="switchTab('import')"
                    class="tab-btn flex items-center gap-2 px-5 py-3.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap transition-colors">
                <span class="material-symbols-outlined text-[18px]">upload_file</span>
                Import
            </button>
            <button type="button" id="tab-btn-contents" onclick="switchTab('contents')"
                    class="tab-btn flex items-center gap-2 px-5 py-3.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap transition-colors">
                <span class="material-symbols-outlined text-[18px]">inventory_2</span>
                Contents
            </button>
            <?php endif; ?>
        </nav>
    </div>
</div>

<!-- Settings Content -->
<div class="container mt-6 mb-8">
    <div class="">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Main Settings -->
            <div class="lg:col-span-2 space-y-6">
                <form method="POST" action="<?= root.admin ?>/settings/modules/<?= $module['id'] ?>" id="settingsForm">

                    <!-- Hidden fields for header dropdowns -->
                    <input type="hidden" name="hidden_status" id="hidden_status" value="<?= $module['status'] ?>">
                    <input type="hidden" name="hidden_dev_mode" id="hidden_dev_mode" value="<?= $module['dev_mode'] ?>">
                    <input type="hidden" name="active" value="<?= $module['active'] ? '1' : '0' ?>">
                    <input type="hidden" name="payment_mode" value="<?= $module['payment_mode'] ? '1' : '0' ?>">

                    <?php if ($showApiCredentials): ?>
                    <!-- Tab Panel: Credentials -->
                    <div id="panel-credentials" class="<?= $defaultModuleTab === 'credentials' ? '' : 'hidden' ?>">
                        <?php include __DIR__ . '/modules/credentials.php'; ?>
                    </div><!-- /panel-credentials -->
                    <?php endif; ?>

                    <?php if (!$hideMarkupTab): ?>
                    <!-- Tab Panel: Markup & Tax -->
                    <div id="panel-markup" class="<?= $defaultModuleTab === 'markup' ? '' : 'hidden' ?>">
                        <?php include __DIR__ . '/modules/markup.php'; ?>
                    </div><!-- /panel-markup -->
                    <?php endif; ?>
                    <?php if (strtolower($module['name']) === 'airalo'): ?>
                    </form>
                    <?php endif; ?>

                    <!-- Tab Panel: Database (hidden by default) -->
                    <?php if ($showDatabaseTab): ?>
                    <div id="panel-database" class="hidden">
                        <?php include __DIR__ . '/modules/database.php'; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($showStationsTab): ?>
                    <div id="panel-stations" class="hidden">
                        <?php include __DIR__ . '/modules/rail-stations.php'; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($module['content_import'])): ?>
                    <!-- Tab Panel: Import (hidden by default) -->
                    <div id="panel-import" class="hidden">
                        <?php
                        $_im_name = strtolower($module['name'] ?? '');
                        $_im_type = strtolower($module['type'] ?? '');
                        $_im_file = __DIR__ . '/../../../../modules/' . $_im_type . '/' . $_im_name . '/content/' . $_im_name . '-import.php';
                        if (file_exists($_im_file)): ?>
                            <?php include $_im_file; ?>
                        <?php else: ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
                                <span class="material-symbols-outlined text-gray-300 text-5xl block mb-3">upload_file</span>
                                <p class="text-sm text-gray-500">No import interface available for this module.</p>
                                <p class="text-xs text-gray-400 mt-1 font-mono">Expected: modules/<?= $_im_type ?>/<?= $_im_name ?>/content/<?= $_im_name ?>-import.php</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab Panel: Contents (hidden by default) -->
                    <div id="panel-contents" class="hidden">
                        <?php
                        $_ct_name = strtolower($module['name'] ?? '');
                        $_ct_type = strtolower($module['type'] ?? '');
                        $_ct_file = __DIR__ . '/../../../../modules/' . $_ct_type . '/' . $_ct_name . '/content/imported.php';
                        if (file_exists($_ct_file)): ?>
                            <?php include $_ct_file; ?>
                        <?php else: ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-10 text-center">
                                <span class="material-symbols-outlined text-gray-300 text-5xl block mb-3">inventory_2</span>
                                <p class="text-sm text-gray-500">No imported content available for this module.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; /* content_import */ ?>

                    <?php if (false): /* database content moved to modules/database.php */ ?>
                    <?php if (in_array(strtolower($module['name'] ?? ''), ['hotelbeds', 'hotelston', 'stuba', 'agoda','ratehawk'])): ?>
                    <!-- Database Configuration Card (Hotelbeds/Hotelston Only) -->
                    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
                        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-gray-600 text-lg">storage</span>
                                    <h3 class="text-sm font-semibold text-gray-900"><?=T::database_configuration?></h3>
                                </div>
                                <span class="text-xs text-gray-500 bg-blue-100 px-2 py-1 rounded-md">
                                    <?=T::separate_content_database?>
                                </span>
                            </div>
                        </div>
                        <div class="p-4 space-y-4">
                            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 mb-3">
                                <div class="flex items-start gap-3">
                                    <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                                    <div class="text-sm text-blue-900">
                                        <p class="font-bold mb-2 text-base"><?=T::separate_database_setup?></p>
                                        <p class="text-xs mb-3 text-blue-800"><?= ucfirst($module['name']) ?> <?=T::separate_database_description?></p>

                                        <div class="flex items-start gap-2 text-xs">
                                            <span class="text-green-600">✓</span>
                                            <div>
                                                <span class="font-medium"><?=T::benefits?>:</span> <?=T::separate_database_benefits?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        <?=T::host?>
                                    </label>
                                    <input type="text"
                                           name="host"
                                           id="db_host"
                                           value="<?= $module['host'] ?? 'localhost' ?>"
                                           class="input w-full"
                                           placeholder="localhost">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        <?=T::database_name?>
                                    </label>
                                    <input type="text"
                                           name="database"
                                           id="db_database"
                                           value="<?= $module['database'] ?? '' ?>"
                                           class="input w-full"
                                           placeholder="phptravels_hotelbeds">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        <?=T::username?>
                                    </label>
                                    <input type="text"
                                           name="username"
                                           id="db_username"
                                           value="<?= $module['username'] ?? '' ?>"
                                           class="input w-full"
                                           placeholder="root">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        <?=T::password?>
                                    </label>
                                    <input type="password"
                                           name="password"
                                           id="db_password"
                                           value="<?= $module['password'] ?? '' ?>"
                                           class="input w-full"
                                           placeholder="••••••••">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 pt-2">
                                <button type="button"
                                        onclick="testDatabaseConnection()"
                                        class="btn"
                                        id="testDbButton">
                                    <span class="material-symbols-outlined text-sm">cable</span>
                                    <?=T::test_connection?>
                                </button>
                                <button type="button"
                                        onclick="saveDatabaseCredentials()"
                                        class="btn"
                                        id="saveDbButton"
                                        disabled>
                                    <span class="material-symbols-outlined text-sm">save</span>
                                    <?=T::save_credentials?>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php
                    // ============================================
                    // LOGS CONFIGURATION - Auto-detect log files
                    // ============================================
                    $showLogsSection = false;
                    $logFiles = [];
                    $logsPath = '';

                    // Check if logs exist for this module
                    if (in_array(strtolower($module['name']), ['hotelbeds', 'hotelston', 'stuba', 'agoda', 'ratehawk', 'tbo-holidays', 'wanderbeds', 'toursbms'])) {

                        // Try multiple possible paths for logs directory
                        $possibleLogPaths = [
                            __DIR__ . '/../../../../modules/' . $module['type'] . '/' . $module['name'] . '/logs/',
                            $_SERVER['DOCUMENT_ROOT'] . '/v10/modules/' . $module['type'] . '/' . $module['name'] . '/logs/',
                            $_SERVER['DOCUMENT_ROOT'] . '/modules/' . $module['type'] . '/' . $module['name'] . '/logs/',
                            dirname(__FILE__) . '/../../../../modules/' . $module['type'] . '/' . $module['name'] . '/logs/'
                        ];

                        foreach ($possibleLogPaths as $path) {
                            if (file_exists($path) && is_dir($path)) {
                                $logsPath = $path;

                                // Scan for JSON log files
                                $files = scandir($path);
                                foreach ($files as $file) {
                                    if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                                        $filePath = $path . $file;
                                        $logFiles[] = [
                                            'name' => $file,
                                            'size' => filesize($filePath),
                                            'date' => date('Y-m-d H:i:s', filemtime($filePath)),
                                            'timestamp' => filemtime($filePath)
                                        ];
                                    }
                                }

                                // Sort by timestamp (newest first)
                                usort($logFiles, function($a, $b) {
                                    return $b['timestamp'] - $a['timestamp'];
                                });

                                // Show logs section if files exist OR if logging is enabled
                                if (!empty($logFiles) || (isset($module['logging_enabled']) && $module['logging_enabled'] == 1)) {
                                    $showLogsSection = true;
                                }

                                break;
                            }
                        }
                    }
                    ?>

                    <?php if (in_array(strtolower($module['name'] ?? ''), ['hotelbeds', 'toursbms'])): ?>
                        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
                            <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-gray-600 text-lg">description</span>
                                        <h3 class="text-sm font-semibold text-gray-900"><?=T::logs_configuration?></h3>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <?php if (!empty($logFiles)): ?>
                                        <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-md font-medium">
                                            <?= count($logFiles) ?> <?=T::log_files?>
                                        </span>
                                        <?php endif; ?>
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <span class="text-xs text-gray-600"><?=T::enable?> <?=T::logging?></span>
                                            <input type="checkbox"
                                                name="logging_enabled"
                                                id="logging_enabled_toggle"
                                                value="1"
                                                <?= isset($module['logging_enabled']) && $module['logging_enabled'] == 1 ? 'checked' : '' ?>
                                                class="w-10 h-5 appearance-none bg-gray-300 rounded-full relative cursor-pointer transition-colors checked:bg-blue-600
                                                        before:content-[''] before:absolute before:w-4 before:h-4 before:rounded-full before:bg-white before:top-0.5 before:left-0.5
                                                        before:transition-transform checked:before:translate-x-5">
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Logs Content Section (Always visible) -->
                            <div class="p-4 space-y-4">
                                <!-- Info Banner -->
                                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
                                    <div class="flex items-start gap-3">
                                        <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                                        <div class="text-sm text-blue-900">
                                            <p class="font-bold mb-2 text-base"><?=T::api_request_logging?></p>
                                            <p class="text-xs mb-3 text-blue-800">
                                                <?=T::logs_description?>: <?= ucfirst($module['name']) ?> API requests and responses are automatically saved as JSON files for debugging and monitoring.
                                            </p>

                                            <div class="space-y-1">
                                                <div class="flex items-start gap-2 text-xs">
                                                    <span class="text-green-600">✓</span>
                                                    <div>
                                                        <span class="font-medium"><?=T::benefits?>:</span> Track API performance, debug issues, monitor request/response data
                                                    </div>
                                                </div>
                                                <div class="flex items-start gap-2 text-xs">
                                                    <span class="text-orange-600">⚠</span>
                                                    <div>
                                                        <span class="font-medium"><?=T::warning?>:</span> Disabling logging will permanently delete ALL existing log files
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Log Files List Section (Show/Hide based on toggle) -->
                                <div id="logs_list_section" style="display: <?= isset($module['logging_enabled']) && $module['logging_enabled'] == 1 && !empty($logFiles) ? 'block' : 'none' ?>;">
                                    <?php if (!empty($logFiles)): ?>
                                    <!-- Log Files List -->
                                    <div class="border border-gray-200 rounded-lg overflow-hidden">
                                        <div class="bg-gray-50 px-4 py-2 border-b border-gray-200">
                                            <h4 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-lg text-blue-600">folder_open</span>
                                                <?=T::available_log_files?>
                                            </h4>
                                        </div>

                                        <div class="max-h-64 overflow-y-auto">
                                            <table class="w-full text-sm">
                                                <thead class="bg-gray-50 sticky top-0">
                                                    <tr class="border-b border-gray-200">
                                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-600"><?=T::file_name?></th>
                                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-600"><?=T::size?></th>
                                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-600"><?=T::date?></th>
                                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-600"><?=T::actions?></th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200">
                                                    <?php foreach ($logFiles as $index => $logFile): ?>
                                                    <tr class="hover:bg-gray-50 transition-colors">
                                                        <td class="px-4 py-3">
                                                            <div class="flex items-center gap-2">
                                                                <span class="material-symbols-outlined text-gray-400 text-base">description</span>
                                                                <span class="font-mono text-xs text-gray-900"><?= htmlspecialchars($logFile['name']) ?></span>
                                                            </div>
                                                        </td>
                                                        <td class="px-4 py-3 text-xs text-gray-600">
                                                            <?= formatBytes($logFile['size']) ?>
                                                        </td>
                                                        <td class="px-4 py-3 text-xs text-gray-600">
                                                            <?= $logFile['date'] ?>
                                                        </td>
                                                        <td class="px-4 py-3 text-right">
                                                            <div class="flex items-center justify-end gap-2">
                                                                <!-- View Icon -->
                                                                <button type="button"
                                                                        onclick="viewLogFile('<?= htmlspecialchars($logFile['name']) ?>')"
                                                                        class="text-blue-600 hover:text-blue-800">
                                                                    <span class="material-symbols-outlined text-base">visibility</span>
                                                                </button>

                                                                <!-- Download Icon -->
                                                                <button type="button"
                                                                        onclick="downloadLogFile('<?= htmlspecialchars($logFile['name']) ?>', event)"
                                                                        class="text-green-600 hover:text-green-800">
                                                                    <span class="material-symbols-outlined text-base">download</span>
                                                                </button>

                                                                <!-- Delete Icon -->
                                                                <button type="button"
                                                                        onclick="deleteLogFile('<?= htmlspecialchars($logFile['name']) ?>')"
                                                                        class="text-red-600 hover:text-red-800">
                                                                    <span class="material-symbols-outlined text-base">delete</span>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Bulk Actions -->
                                    <div class="flex items-center justify-between pt-2">
                                        <span class="text-xs text-gray-500">
                                            <?=T::total?>: <?= count($logFiles) ?> <?=T::files?> (<?= formatBytes(array_sum(array_column($logFiles, 'size'))) ?>)
                                        </span>
                                        <div class="flex gap-2">
                                            <button type="button"
                                                    onclick="downloadAllLogs(event)"
                                                    class="btn light text-xs">
                                                <span class="material-symbols-outlined text-sm">folder_zip</span>
                                                <?=T::download_all?>
                                            </button>
                                            <button type="button"
                                                    onclick="deleteAllLogs()"
                                                    class="btn light text-xs">
                                                <span class="material-symbols-outlined text-sm">delete_sweep</span>
                                                <?=T::delete_all_logs?>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- No Logs Available (Show when logging is enabled but no files exist) -->
                                <div id="no_logs_message" style="display: <?= isset($module['logging_enabled']) && $module['logging_enabled'] == 1 && empty($logFiles) ? 'block' : 'none' ?>;" class="text-center py-8">
                                    <span class="material-symbols-outlined text-gray-300 text-5xl mb-3">description</span>
                                    <p class="text-sm text-gray-600"><?=T::no_log_files_available?></p>
                                    <p class="text-xs text-gray-500 mt-1"><?=T::logs_will_appear_here_when_created?></p>
                                </div>
                            </div>
                        </div>
                    <?php
                    endif;
                    ?>



                    <?php endif; /* end if(false) legacy database */ ?>

                    <!-- Action Buttons -->
                    <div class="flex items-center justify-between" id="panel-actions">
                        <a href="<?= root.admin ?>/settings/modules#<?= $module['type'] ?>" class="btn light" onclick="showBackLoading(this)">
                            <span class="material-symbols-outlined text-sm">arrow_back</span>
                            <?=T::back_to_modules?>
                        </a>
                        <button type="button" name="save_settings" class="btn" onclick="submitModuleSettings(this)">
                            <span class="material-symbols-outlined text-sm">save</span>
                            <?=T::save_configuration?>
                        </button>
                    </div>

                <?php if (strtolower($module['name']) !== 'airalo'): ?>
                </form>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">

                <!-- Module Info Card -->
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-gray-600 text-lg">info</span>
                            <h3 class="text-sm font-semibold text-gray-900"><?=T::module?> <?=T::information?></h3>
                        </div>
                    </div>
                    <div class="p-4 space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::module?> <?=T::id?>:</span>
                            <span class="font-medium"><?= $module['id'] ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::type?>:</span>
                            <span class="font-medium capitalize"><?= $module['type'] ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::provider?>:</span>
                            <span class="font-medium capitalize"><?= $module['name'] ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::status?>:</span>
                            <span class="font-medium <?= $module['status'] ? 'text-green-600' : 'text-red-600' ?>">
                                <?= $module['status'] ? T::enabled : T::disabled ?>
                            </span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::environment?>:</span>
                            <span class="font-medium <?= $module['dev_mode'] ? 'text-orange-600' : 'text-blue-600' ?>">
                                <?= $module['dev_mode'] ? T::development : T::production ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Documentation Card -->
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-gray-600 text-lg">help</span>
                            <h3 class="text-sm font-semibold text-gray-900"><?=T::help?> & <?=T::documentation?></h3>
                        </div>
                    </div>
                    <div class="p-4 space-y-2">
                        <a href="https://docs.phptravels.com/modules/<?= strtolower($module['type']) ?>/<?= strtolower($module['name']) ?>"
                           target="_blank"
                           class="flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800">
                            <span class="material-symbols-outlined text-sm">description</span>
                            <?=T::setup_documentation?>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
// ─── Tab Switching ──────────────────────────────────────────────────────────
function switchTab(name) {
    const validTabs = <?= json_encode($availableModuleTabs) ?>;
    if (!validTabs.includes(name)) name = '<?= $defaultModuleTab ?>';

    // Show/hide all panels
    ['credentials', 'markup', 'database', 'stations', 'contents', 'import'].forEach(function(tab) {
        const el = document.getElementById('panel-' + tab);
        if (el) el.classList.toggle('hidden', tab !== name);
    });

    // Save/back buttons hidden on non-form tabs
    const actions = document.getElementById('panel-actions');
    if (actions) actions.style.display = (name === 'database' || name === 'stations' || name === 'contents' || name === 'import') ? 'none' : '';

    // Update tab button styles
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('border-blue-600', 'text-blue-600');
        btn.classList.add('border-transparent', 'text-gray-500');
    });
    const active = document.getElementById('tab-btn-' + name);
    if (active) {
        active.classList.remove('border-transparent', 'text-gray-500');
        active.classList.add('border-blue-600', 'text-blue-600');
    }

    // Sync URL hash without scrolling
    history.replaceState(null, '', '#' + name);
    sessionStorage.setItem('msTab_<?= $module['id'] ?>', name);
}

document.addEventListener('DOMContentLoaded', function () {
    const validTabs = <?= json_encode($availableModuleTabs) ?>;
    const hash = location.hash.replace('#', '');
    const saved = sessionStorage.getItem('msTab_<?= $module['id'] ?>');
    switchTab(validTabs.includes(hash) ? hash : (validTabs.includes(saved) ? saved : '<?= $defaultModuleTab ?>'));
});

// ============================================
// SECURITY: CREDENTIAL PROTECTION & DEV TOOLS BLOCKING
// ============================================

// DEV MODE: Set to true to disable all security restrictions for debugging
const DEV_MODE = true;  // Change to false for production

(function() {
    'use strict';

    // Skip all security checks if DEV_MODE is enabled
    if (DEV_MODE) {
        console.log('%c🔓 DEV MODE ENABLED - Security checks disabled', 'color: #00ff00; font-size: 14px; font-weight: bold');
        return;
    }

    // Disable right-click (except on credential inputs for paste functionality)
    document.addEventListener('contextmenu', function(e) {
        // Allow right-click on credential inputs for paste
        const target = e.target;
        if (target && target.tagName === 'INPUT' &&
            (target.classList.contains('credential-input') ||
             target.getAttribute('data-protected') === 'true' ||
             target.type === 'password')) {
            // Allow context menu on credential fields
            e.stopPropagation();
            return true;
        }
        e.preventDefault();
        e.stopPropagation();
        return false;
    }, true);

    // Disable F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+U, Ctrl+Shift+C, Ctrl+V (paste)
    document.addEventListener('keydown', function(e) {
        // F12
        if (e.key === 'F12' || e.keyCode === 123) {
            e.preventDefault();
            return false;
        }
        // Ctrl+Shift+I (Inspect)
        if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.keyCode === 73)) {
            e.preventDefault();
            return false;
        }
        // Ctrl+Shift+J (Console)
        if (e.ctrlKey && e.shiftKey && (e.key === 'J' || e.keyCode === 74)) {
            e.preventDefault();
            return false;
        }
        // Ctrl+U (View Source)
        if (e.ctrlKey && (e.key === 'U' || e.keyCode === 85)) {
            e.preventDefault();
            return false;
        }
        // Ctrl+Shift+C (Inspect Element)
        if (e.ctrlKey && e.shiftKey && (e.key === 'C' || e.keyCode === 67)) {
            e.preventDefault();
            return false;
        }
        // Ctrl+V (Paste) - block keyboard paste
        if (e.ctrlKey && (e.key === 'v' || e.key === 'V' || e.keyCode === 86)) {
            e.preventDefault();
            return false;
        }
    });

    // Detect DevTools opening - Multiple detection methods
    let devToolsOpen = false;
    let blockerShown = false;

    // Create blocker overlay
    function showDevToolsBlocker() {
        if (blockerShown) return;
        blockerShown = true;

        // Completely remove credential inputs from DOM when DevTools are open
        const credInputs = document.querySelectorAll('input.credential-input[data-protected="true"]');
        credInputs.forEach(function(input) {
            // Store all necessary data
            const inputData = {
                name: input.name,
                value: input.value,
                placeholder: input.placeholder,
                className: input.className,
                required: input.required,
                autocomplete: input.getAttribute('autocomplete'),
                parentNode: input.parentNode
            };

            // Store data in parent element
            input.parentNode.setAttribute('data-input-backup', JSON.stringify(inputData));

            // Replace with empty div to maintain layout
            const placeholder = document.createElement('div');
            placeholder.className = 'credential-placeholder';
            placeholder.style.cssText = 'height: 38px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px;';

            // Remove the actual input from DOM
            input.parentNode.replaceChild(placeholder, input);
        });

        const blocker = document.createElement('div');
        blocker.id = 'devtools-blocker';
        blocker.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
        `;

        blocker.innerHTML = `
            <div style="text-align: center; color: white; padding: 40px;">
                <div style="font-size: 60px; margin-bottom: 20px;">🔒</div>
                <h1 style="font-size: 32px; margin-bottom: 20px;">Security Protection Active</h1>
                <p style="font-size: 18px; margin-bottom: 30px; max-width: 500px;">
                    Developer Tools are not allowed on this page for security reasons.<br><br>
                    <strong>Please close Developer Tools to continue.</strong>
                </p>
                <div style="margin-top: 30px; padding: 20px; background: rgba(255, 255, 255, 0.1); border-radius: 8px; max-width: 400px; margin: 30px auto;">
                    <p style="font-size: 14px; opacity: 0.8;">How to close Developer Tools:</p>
                    <p style="font-size: 14px; margin-top: 10px;">• Press F12<br>• Or press Ctrl+Shift+I<br>• Or close the DevTools panel</p>
                </div>
                <div style="margin-top: 30px;">
                    <div class="spinner" style="border: 4px solid rgba(255,255,255,0.3); border-top: 4px solid white; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                    <p style="font-size: 14px; margin-top: 15px; opacity: 0.7;">Checking status...</p>
                </div>
            </div>
            <style>
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        `;

        document.body.appendChild(blocker);
    }

    function hideDevToolsBlocker() {
        const blocker = document.getElementById('devtools-blocker');
        if (blocker) {
            blocker.remove();
            blockerShown = false;
        }

        // Restore credential inputs from backup when DevTools are closed
        const containers = document.querySelectorAll('[data-input-backup]');
        containers.forEach(function(container) {
            const backupData = container.getAttribute('data-input-backup');
            if (backupData) {
                try {
                    const inputData = JSON.parse(backupData);

                    // Recreate the input element
                    const input = document.createElement('input');
                    input.type = 'password';
                    input.name = inputData.name;
                    input.value = inputData.value;
                    input.placeholder = inputData.placeholder;
                    input.className = inputData.className;
                    input.required = inputData.required;
                    input.setAttribute('autocomplete', inputData.autocomplete);
                    input.setAttribute('data-protected', 'true');

                    // Remove placeholder and add back the input
                    const placeholder = container.querySelector('.credential-placeholder');
                    if (placeholder) {
                        container.replaceChild(input, placeholder);
                    }

                    // Remove backup data
                    container.removeAttribute('data-input-backup');

                    // Re-apply protection
                    protectSingleInput(input);
                } catch(e) {
                    console.error('Failed to restore input:', e);
                }
            }
        });
    }

    function protectSingleInput(input) {
        // Prevent type attribute changes
        Object.defineProperty(input, 'type', {
            get: function() {
                return 'password';
            },
            set: function(value) {
                return 'password';
            },
            configurable: false
        });

        // Monitor for attribute changes
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'type') {
                    input.setAttribute('type', 'password');
                }
            });
        });

        observer.observe(input, {
            attributes: true,
            attributeFilter: ['type']
        });
    }

    // Method 1: Console detection
    const devtools = /./;
    devtools.toString = function() {
        devToolsOpen = true;
    }

    // Method 2: Window size detection
    const threshold = 160;

    // Method 3: Console.log detection
    function checkConsole() {
        const element = new Image();
        Object.defineProperty(element, 'id', {
            get: function() {
                devToolsOpen = true;
                throw new Error('DevTools detected');
            }
        });
        console.log(element);
    }

    function detectDevTools() {
        devToolsOpen = false;

        // Run console detection
        console.log('%c', devtools);

        // Check window dimensions
        const widthThreshold = window.outerWidth - window.innerWidth > threshold;
        const heightThreshold = window.outerHeight - window.innerHeight > threshold;

        if (widthThreshold || heightThreshold) {
            devToolsOpen = true;
        }

        // Try console check
        try {
            checkConsole();
        } catch(e) {}

        return devToolsOpen;
    }

    // Check immediately on page load
    if (detectDevTools()) {
        showDevToolsBlocker();
    }

    // Run continuous detection
    setInterval(function() {
        if (detectDevTools()) {
            showDevToolsBlocker();
        } else {
            hideDevToolsBlocker();
        }
    }, 500);

    // Protect credential inputs from type changes
    function protectCredentialInputs() {
        const credInputs = document.querySelectorAll('input.credential-input[data-protected="true"]');

        credInputs.forEach(function(input) {
            protectSingleInput(input);
        });
    }

    // Run on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', protectCredentialInputs);
    } else {
        protectCredentialInputs();
    }

    // Disable console methods
    if (typeof console !== 'undefined') {
        const noop = function() {};
        Object.keys(console).forEach(function(key) {
            if (typeof console[key] === 'function') {
                console[key] = noop;
            }
        });
    }
})();

let terminalLogs = [];

function handleCertFileSelect(input, type) {
    const file = input.files[0];
    if (!file) return;

    console.log(`Selected ${type}:`, file.name);

    // Show the certificates grid
    document.getElementById('certificates_grid').style.display = 'block';

    // Show and update the specific certificate card
    const certCard = document.getElementById(`cert_${type}`);
    const certName = document.getElementById(`cert_${type}_name`);

    // Update the card styling to show it's newly selected (pending upload)
    certCard.classList.remove('hidden', 'bg-green-50', 'border-green-200');
    certCard.classList.add('bg-blue-50', 'border-blue-200');

    // Update icon color
    const icon = certCard.querySelector('.material-symbols-outlined');
    icon.classList.remove('text-green-600');
    icon.classList.add('text-blue-600');

    // Update text
    certName.textContent = file.name;
    certName.classList.remove('text-green-800');
    certName.classList.add('text-blue-800');

    // Update label
    const labels = certCard.querySelectorAll('.text-xs');
    const statusLabel = labels[labels.length - 1];
    statusLabel.classList.remove('text-green-600');
    statusLabel.classList.add('text-blue-600');
    statusLabel.textContent = 'Ready to upload';

    vt.success(`${file.name} selected. Click "Upload Certificates" to save.`);
}

function testAPI() {
    const button = document.getElementById('testButton');
    const results = document.getElementById('testResults');
    const terminalContent = document.getElementById('terminalContent');
    const terminalStatus = document.getElementById('terminalStatus');
    const browserUrl = document.getElementById('browserUrl');
    const cursor = document.getElementById('cursor');

    results.classList.remove('hidden');
    terminalStatus.textContent = '<?=T::connecting?>';
    browserUrl.textContent = 'localhost:8000/api-test-terminal';

    function updateTime() {
        const now = new Date();
        document.getElementById('terminalTime').textContent = now.toLocaleTimeString();
    }
    updateTime();
    setInterval(updateTime, 1000);

    terminalContent.innerHTML = '';
    terminalLogs = [];

    document.getElementById('copyLogsBtn').classList.remove('hidden');

    function typeText(text, color = 'text-green-400', delay = 0) {
        return new Promise(resolve => {
            const span = document.createElement('div');
            span.className = color;
            span.textContent = text;
            terminalContent.appendChild(span);
            terminalContent.scrollTop = terminalContent.scrollHeight;

            resolve();
        });
    }

    function addPrompt(command) {
        const promptDiv = document.createElement('div');
        promptDiv.className = 'flex items-center mt-2';
        promptDiv.innerHTML = `
            <span class="text-blue-400">user@v10-api-test</span>
            <span class="text-gray-400">:</span>
            <span class="text-yellow-400">~</span>
            <span class="text-gray-400">$</span>
            <span class="ml-2 text-white">${command}</span>
        `;
        terminalContent.appendChild(promptDiv);
        terminalContent.scrollTop = terminalContent.scrollHeight;
    }

    button.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::testing?>...';
    button.disabled = true;

    async function runTerminalSequence() {
        terminalLogs = [];
        document.getElementById('copyLogsBtn').classList.remove('hidden');

        function logAndType(text, color = 'text-green-400', delay = 0) {
            terminalLogs.push(text);
            return typeText(text, color, delay);
        }

        function isSensitiveKey(key) {
            const k = String(key || '').toLowerCase();
            const sensitiveParts = [
                'c1', 'c2', 'c3', 'c4', 'c5', 'c6',
                'password', 'secret', 'token', 'authorization',
                'api_key', 'apikey', 'client_secret', 'client_id', 'access_token'
            ];
            return sensitiveParts.some(part => k.includes(part));
        }

        function sanitizeObject(value) {
            if (Array.isArray(value)) {
                return value.map(sanitizeObject);
            }
            if (!value || typeof value !== 'object') {
                return value;
            }

            const cleaned = {};
            for (const [key, val] of Object.entries(value)) {
                const lk = key.toLowerCase();

                // Remove request-side debug payloads from terminal output.
                if (['api_request', 'request', 'raw_request', 'request_headers', 'headers_sent', 'formdata'].includes(lk)) {
                    continue;
                }

                if (isSensitiveKey(lk)) {
                    cleaned[key] = '[REDACTED]';
                } else {
                    cleaned[key] = sanitizeObject(val);
                }
            }
            return cleaned;
        }

        function isSensitiveStep(step) {
            const s = String(step || '').toLowerCase();
            return s.includes('[key]')
                || s.includes('credential')
                || s.includes('formdata')
                || s.includes('(full):')
                || s.includes('client secret')
                || s.includes('api key')
                || s.includes('password');
        }

        const moduleName = '<?= $module['name'] ?>';
        const moduleType = '<?= $module['type'] ?>';

        await logAndType(`[START] Testing ${moduleName.toUpperCase()} API connection...`, 'text-cyan-400');

        const form = document.getElementById('settingsForm');
        const formData = new FormData(form);

        const devMode = document.getElementById('dev_mode_status').value;

        await logAndType(`[ENV] ${devMode === '1' ? 'DEVELOPMENT' : 'PRODUCTION'}`, 'text-purple-400');

        const credentials = {};
        const missingRequired = [];
        <?php foreach ($credentialFields as $field => $config): ?>
            <?php
                $label = is_array($config) ? $config['label'] : $config;
                $required = is_array($config) ? ($config['required'] ?? false) : true;
            ?>
            {
                const <?= $field ?>El = document.querySelector('[name="<?= $field ?>"]');
                const <?= $field ?>Value = <?= $field ?>El ? String(<?= $field ?>El.value || '').trim() : '';
                if (<?= $field ?>Value !== '') {
                    credentials['<?= $field ?>'] = <?= $field ?>Value;
                }
                <?php if ($required): ?>
                else {
                    missingRequired.push('<?= $label ?>');
                    if (<?= $field ?>El) {
                        <?= $field ?>El.classList.add('border-red-500', 'bg-red-50');
                    }
                }
                <?php endif; ?>
            }
        <?php endforeach; ?>

        credentials['env'] = devMode === '1' ? 'test' : 'production';

        if (missingRequired.length > 0) {
            await logAndType('[ERROR] Missing required fields!', 'text-red-400');
            for (const fieldLabel of missingRequired) {
                await logAndType(`[REQUIRED] ${fieldLabel} is required`, 'text-yellow-400');
            }
            await logAndType('[INFO] Please fill in all required fields before testing.', 'text-yellow-400');

            terminalStatus.textContent = 'Error - Missing required fields';
            button.innerHTML = '<span class="material-symbols-outlined text-sm">bug_report</span> <?=T::test_api_connection?>';
            button.disabled = false;
            return;
        }

        if (Object.keys(credentials).length <= 1) {
            await logAndType('[ERROR] No credentials provided!', 'text-red-400');
            await logAndType('[INFO] Please enter at least one credential field before testing.', 'text-yellow-400');

            terminalStatus.textContent = 'Error - No credentials';
            button.innerHTML = '<span class="material-symbols-outlined text-sm">bug_report</span> <?=T::test_api_connection?>';
            button.disabled = false;
            return;
        }

        await logAndType('[CONNECT] Establishing API connection...', 'text-cyan-400');

        const rootUrl = '<?=root?>';
        let moduleEndpoint;

        moduleEndpoint = `${rootUrl}modules/${moduleType.toLowerCase()}/${moduleName.toLowerCase()}/creds`;

        terminalStatus.textContent = 'Testing API...';

        const moduleFormData = new FormData();
        Object.keys(credentials).forEach(key => {
            moduleFormData.append(key, credentials[key]);
        });

        try {
            await logAndType('[REQUEST] Sending secure test request...', 'text-yellow-400');
            terminalStatus.textContent = 'Testing API...';

            const response = await new Promise((resolve, reject) => {
                $.ajax({
                    url: moduleEndpoint,
                    method: 'POST',
                    timeout: 30000,
                    processData: false,
                    contentType: false,
                    data: moduleFormData,
                    success: function(apiResponse) {
                        resolve({
                            ok: true,
                            json: async () => typeof apiResponse === 'string' ? JSON.parse(apiResponse) : apiResponse
                        });
                    },
                    error: function(xhr, status, error) {
                        const responseText = xhr.responseText || '';
                        resolve({
                            ok: false,
                            status: xhr.status,
                            statusText: error || xhr.statusText || 'Unknown Error',
                            responseText: responseText,
                            text: async () => responseText
                        });
                    }
                });
            });

            if (response.ok) {
                const data = await response.json();
                const safeData = sanitizeObject(data);

                await logAndType('[RESPONSE] Response received', 'text-green-400');

                if (data.success) {
                    await logAndType('[SUCCESS] API CONNECTION ESTABLISHED', 'text-green-400');
                    await logAndType('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'text-green-600', 1);

                    await logAndType('[AUTH] Authenticated', 'text-green-400');

                    if (data.data && data.data.api_details) {
                        await logAndType('', 'text-gray-400');
                        await logAndType('[API] API DETAILS:', 'text-cyan-400');
                        if (data.data.api_details.provider) {
                            await logAndType(`  [PROVIDER] ${data.data.api_details.provider}`, 'text-green-400');
                        }
                        if (data.data.api_details.api_version) {
                            await logAndType(`  [VERSION] ${data.data.api_details.api_version}`, 'text-green-400');
                        }
                        if (data.data.api_details.response_time) {
                            await logAndType(`  [TIME] Response Time: ${data.data.api_details.response_time}`, 'text-yellow-400');
                        }
                        if (data.data.api_details.supported_services && Array.isArray(data.data.api_details.supported_services)) {
                            await logAndType(`  [SERVICES] Supported Services:`, 'text-cyan-400');
                            for (const service of data.data.api_details.supported_services) {
                                await logAndType(`     - ${service}`, 'text-green-300');
                            }
                        }
                    }

                    // Keep terminal output concise and non-technical.

                    if (data.metadata && data.metadata.response_time_ms) {
                        await logAndType('', 'text-gray-400');
                        await logAndType(`[TIME] Total Processing Time: ${data.metadata.response_time_ms}ms`, 'text-gray-400');
                    }

                    await logAndType('', 'text-gray-400');
                    await logAndType('[AUTO-SAVE] Saving credentials to database...', 'text-cyan-400');

                    const form = document.getElementById('settingsForm');
                    const formData = new FormData(form);

                    // Use correct parameter name for backend handler
                    formData.delete('save_module_creds');
                    formData.append('save_settings', '1');

                    try {
                        const saveResp = await fetch(window.location.href, {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        if (saveResp.ok) {
                            await logAndType('[SUCCESS] Credentials saved to database', 'text-green-400');
                            await logAndType('[READY] Search will now use these credentials', 'text-green-400');
                            await logAndType('', 'text-gray-400');
                            await logAndType('[TIP] Go back to the module and try your search again', 'text-cyan-400');
                            if (typeof vt !== 'undefined' && typeof vt.success === 'function') {
                                vt.success('Credentials saved successfully.');
                            }
                        } else {
                            await logAndType('[WARNING] Could not auto-save. Please click Save Configuration manually.', 'text-yellow-400');
                        }
                    } catch (err) {
                        await logAndType('[WARNING] Could not auto-save: ' + err.message, 'text-yellow-400');
                    }

                    terminalStatus.textContent = '<?=T::connected?>';
                    browserUrl.textContent = 'modules-api.sandbox/connection-success';

                } else {
                    await logAndType('[ERROR] CONNECTION FAILED!', 'text-red-400', 3);
                    await logAndType('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'text-gray-600', 1);
                    await logAndType(`[MESSAGE] ${safeData.message || 'Unable to validate credentials.'}`, 'text-yellow-400');

                    // Show the raw provider response body
                    if (data.debug && data.debug.api_response) {
                        await logAndType('', 'text-gray-400');
                        await logAndType(`[PROVIDER RESPONSE] ${data.debug.api_response}`, 'text-orange-300');
                    }

                    // Show provider-specific issue and solutions
                    if (data.data && data.data.issue) {
                        await logAndType('', 'text-gray-400');
                        await logAndType(`[ISSUE] ${data.data.issue}`, 'text-red-300');
                    }
                    if (data.data && Array.isArray(data.data.solutions)) {
                        await logAndType('[HOW TO FIX]', 'text-cyan-400');
                        for (const sol of data.data.solutions) {
                            await logAndType(`  → ${sol}`, 'text-yellow-300');
                        }
                    } else {
                        await logAndType('[TIP] Verify your API credentials and environment, then try again.', 'text-cyan-400');
                    }

                    terminalStatus.textContent = '<?=T::failed?>';
                    browserUrl.textContent = `${moduleName}-api.example.com/status/error`;
                }

            } else {
                const errorText = await response.text();

                let errorData = null;
                try { errorData = JSON.parse(errorText); } catch (e) {}

                await logAndType('[ERROR] CONNECTION FAILED!', 'text-red-400');
                await logAndType('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'text-gray-600', 1);

                if (errorData) {
                    const safeErr = sanitizeObject(errorData);
                    const msg = safeErr.message || safeErr.meta?.message || `HTTP ${response.status} ${response.statusText}`;
                    await logAndType(`[MESSAGE] ${msg}`, 'text-yellow-400');

                    if (errorData.debug && errorData.debug.api_response) {
                        await logAndType('', 'text-gray-400');
                        await logAndType(`[PROVIDER RESPONSE] ${errorData.debug.api_response}`, 'text-orange-300');
                    }
                    if (errorData.data && errorData.data.issue) {
                        await logAndType('', 'text-gray-400');
                        await logAndType(`[ISSUE] ${errorData.data.issue}`, 'text-red-300');
                    }
                    if (errorData.data && Array.isArray(errorData.data.solutions)) {
                        await logAndType('[HOW TO FIX]', 'text-cyan-400');
                        for (const sol of errorData.data.solutions) {
                            await logAndType(`  → ${sol}`, 'text-yellow-300');
                        }
                    }
                } else {
                    await logAndType(`[MESSAGE] HTTP ${response.status}: ${response.statusText}`, 'text-yellow-400');
                    await logAndType('[TIP] Check that the module endpoint exists and the server is reachable.', 'text-cyan-400');
                }

                terminalStatus.textContent = '<?=T::failed?>';
            }

        } catch (error) {
            await logAndType('[ERROR] NETWORK ERROR!', 'text-red-400', 3);
            await logAndType('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'text-gray-600', 1);
            await logAndType(`[ERROR] ${error.message}`, 'text-red-400');
            await logAndType('[CAUSES] POSSIBLE CAUSES:', 'text-yellow-400');
            await logAndType('  - Network connectivity issues', 'text-yellow-300');
            await logAndType('  - Server configuration problems', 'text-yellow-300');
            await logAndType('  - Module returned an unexpected response', 'text-yellow-300');

            terminalStatus.textContent = '<?=T::network_error?>';
            browserUrl.textContent = 'localhost:8000/api-test/connection-failed';
        }

        await logAndType('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'text-gray-600', 1);

        button.innerHTML = '<span class="material-symbols-outlined text-sm">bug_report</span> <?=T::test_api_connection?>';
        button.disabled = false;
    }

    runTerminalSequence();
}

function copyTerminalLogs(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const copyBtn = document.getElementById('copyLogsBtn');
    const originalText = copyBtn.innerHTML;

    if (!terminalLogs || terminalLogs.length === 0) {
        copyBtn.innerHTML = `
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L5.232 15.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
            <?=T::no_logs?>
        `;
        copyBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
        copyBtn.classList.add('bg-orange-600', 'hover:bg-orange-700');

        setTimeout(() => {
            copyBtn.innerHTML = originalText;
            copyBtn.classList.remove('bg-orange-600', 'hover:bg-orange-700');
            copyBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
        }, 2000);
        return;
    }

    const timestamp = new Date().toISOString();
    const moduleName = '<?= $module['name'] ?>';
    const moduleType = '<?= $module['type'] ?>';
    const terminalStatus = document.getElementById('terminalStatus').textContent;

    let logText = `API Test Terminal Logs\n`;
    logText += `======================\n`;
    logText += `Module: ${moduleName.toUpperCase()} (${moduleType})\n`;
    logText += `Status: ${terminalStatus}\n`;
    logText += `Timestamp: ${timestamp}\n`;
    logText += `URL: ${window.location.href}\n`;
    logText += `Total Lines: ${terminalLogs.length}\n`;
    logText += `\n`;
    logText += `Terminal Output:\n`;
    logText += `----------------\n`;

    terminalLogs.forEach((log, index) => {
        logText += `${String(index + 1).padStart(3, '0')}: ${log}\n`;
    });

    logText += `\n`;
    logText += `End of Terminal Output\n`;
    logText += `======================\n`;

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(logText).then(() => {
            copyBtn.innerHTML = `
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <?=T::logs_copied?>!
            `;
            copyBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
            copyBtn.classList.add('bg-green-600', 'hover:bg-green-700');

            if (typeof vt !== 'undefined') {
                vt.success(`📋 ${terminalLogs.length} <?=T::terminal_logs_copied?>`);
            }

            setTimeout(() => {
                copyBtn.innerHTML = originalText;
                copyBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
                copyBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
            }, 3000);
        }).catch(err => {
            console.error('Failed to copy: ', err);
            fallbackCopy(logText, copyBtn, originalText);
        });
    } else {
        fallbackCopy(logText, copyBtn, originalText);
    }
}

function fallbackCopy(text, copyBtn, originalText) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        document.execCommand('copy');
        copyBtn.innerHTML = `
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <?=T::logs_copied?>!
        `;
        copyBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
        copyBtn.classList.add('bg-green-600', 'hover:bg-green-700');

        if (typeof vt !== 'undefined') {
            vt.success(`📋 ${terminalLogs.length} <?=T::terminal_logs_copied?>`);
        }

        setTimeout(() => {
            copyBtn.innerHTML = originalText;
            copyBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
            copyBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
        }, 3000);
    } catch (err) {
        console.error('Fallback copy failed: ', err);
        copyBtn.innerHTML = `
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            <?=T::copy_failed?>
        `;
        copyBtn.classList.remove('bg-blue-600', 'hover:bg-blue-700');
        copyBtn.classList.add('bg-red-600', 'hover:bg-red-700');

        if (typeof vt !== 'undefined') {
            vt.error('❌ <?=T::failed_to_copy_logs?>');
        }

        setTimeout(() => {
            copyBtn.innerHTML = originalText;
            copyBtn.classList.remove('bg-red-600', 'hover:bg-red-700');
            copyBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
        }, 3000);
    }

    document.body.removeChild(textArea);
}

function showBackLoading(element) {
    element.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::loading?>...';
    element.classList.add('opacity-75', 'pointer-events-none');
}

function showSaveLoading(element) {
    element.innerHTML = '<div class="inline-flex items-center gap-2"><div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div><?=T::saving?>...</div>';
    element.classList.add('opacity-75');

    return true;
}

async function submitModuleSettings(button) {
    if (!validateAndShowSaveLoading(button)) {
        return;
    }

    const form = document.getElementById('settingsForm');
    const formData = new FormData(form);
    formData.append('save_settings', '1');

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (response.redirected) {
            window.location.href = response.url;
            return;
        }

        if (!response.ok) {
            throw new Error('Save request failed');
        }

        window.location.reload();
    } catch (error) {
        console.error('Save error:', error);
        vt.error('<?=T::failed_to_save_credentials?>');
        button.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> <?=T::save_configuration?>';
        button.classList.remove('opacity-75');
    }
}

function validateAndShowSaveLoading(element) {
    const requiredFields = [];
    const requiredFieldLabels = [];

    <?php foreach ($credentialFields as $field => $config): ?>
        <?php
            $label = is_array($config) ? $config['label'] : $config;
            $required = is_array($config) ? ($config['required'] ?? false) : true;
        ?>
        <?php if ($required): ?>
            requiredFields.push('<?= $field ?>');
            requiredFieldLabels.push('<?= $label ?>');
        <?php endif; ?>
    <?php endforeach; ?>

    let emptyFields = [];
    let emptyFieldLabels = [];

    for (let i = 0; i < requiredFields.length; i++) {
        const field = requiredFields[i];
        const input = document.querySelector(`input[name="${field}"]`);
        if (input) {
            const value = input.value.trim();
            if (value === '') {
                emptyFields.push(input);
                emptyFieldLabels.push(requiredFieldLabels[i]);
            } else {
                input.classList.remove('border-red-500', 'bg-red-50');
            }
        }
    }

    if (emptyFields.length > 0) {
        emptyFields.forEach(input => {
            input.classList.add('border-red-500', 'bg-red-50');
        });

        let errorMessage = '<?=T::required_fields_missing?>';
        if (emptyFieldLabels.length > 0) {
            errorMessage += '\n\n<?=T::missing_fields?>:\n• ' + emptyFieldLabels.join('\n• ');
        }

        showTerminalModal(false, '💥 <?=T::connection_test_failed?>', errorMessage);

        if (emptyFields[0]) {
            emptyFields[0].focus();
        }

        const credentialsCard = document.querySelector('.bg-white.rounded-lg.border.border-gray-200');
        if (credentialsCard) {
            credentialsCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        return false;
    }

    requiredFields.forEach(field => {
        const input = document.querySelector(`input[name="${field}"]`);
        if (input) {
            input.classList.remove('border-red-500', 'bg-red-50');
        }
    });

    return showSaveLoading(element);
}

document.getElementById('module_status').addEventListener('change', function() {
    const newStatus = this.value;
    document.getElementById('hidden_status').value = newStatus;

    saveModuleField('status', newStatus);
});

document.getElementById('dev_mode_status')?.addEventListener('change', function() {
    const newDevMode = this.value;
    document.getElementById('hidden_dev_mode').value = newDevMode;

    saveModuleField('dev_mode', newDevMode);
});

document.querySelector('select[name="currency"]')?.addEventListener('change', function() {
    saveModuleField('currency', this.value);
});

function saveModuleField(field, value) {
    const control = field === 'status'
        ? document.getElementById('module_status')
        : field === 'dev_mode'
            ? document.getElementById('dev_mode_status')
            : document.querySelector(`select[name="${field}"]`);

    if (control) {
        control.disabled = true;
        control.style.opacity = '0.6';
    }

    const formData = new FormData();
    formData.append('ajax_update', '1');
    formData.append('field', field);
    formData.append('value', value);
    formData.append('module_id', '<?= $module['id'] ?>');

    fetch('<?= root.admin ?>/settings/modules/ajax-update', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            vt.success('<?=T::operation_completed_successfully?>');
            if (field === 'currency' && control) {
                control.value = data.value || value;
            }
        } else {
            vt.error(data.message || '<?=T::failed_to_update_setting?>');
            if (data.previous_value !== undefined) {
                if (control) {
                    control.value = data.previous_value;
                }
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        vt.error('<?=T::error_updating_setting?>');
    })
    .finally(() => {
        if (control) {
            control.disabled = false;
            control.style.opacity = '1';
        }
    });
}

function showTerminalModal(isSuccess, title, content) {
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50';
    modalOverlay.style.backdropFilter = 'blur(4px)';

    const terminalContainer = document.createElement('div');
    terminalContainer.className = 'bg-gray-900 rounded-lg shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden border border-gray-700';

    const terminalHeader = document.createElement('div');
    terminalHeader.className = 'bg-gray-800 px-4 py-2 flex items-center justify-between border-b border-gray-700';
    terminalHeader.innerHTML = `
        <div class="flex items-center space-x-2">
            <div class="flex space-x-2">
                <div class="w-3 h-3 rounded-full bg-red-500"></div>
                <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                <div class="w-3 h-3 rounded-full bg-green-500"></div>
            </div>
            <span class="text-gray-300 text-sm font-mono ml-4"><?=T::api_test_terminal?></span>
        </div>
        <button onclick="closeTerminalModal()" class="text-gray-400 hover:text-white text-lg">&times;</button>
    `;

    const terminalBody = document.createElement('div');
    terminalBody.className = 'bg-gray-900 p-6 font-mono text-sm leading-relaxed overflow-y-auto max-h-[70vh]';
    terminalBody.style.color = isSuccess ? '#10B981' : '#EF4444';

    const formattedContent = content
        .replace(/━+/g, '<span class="text-gray-600">$&</span>')
        .replace(/^\d+\./gm, '<span class="text-blue-400">$&</span>')
        .replace(/✅|❌|🚨|🔧|💡|📞|⚡|🔍|📋/g, '<span class="text-yellow-400">$&</span>')
        .replace(/ERROR|FAILED|SUCCESS/g, `<span class="font-bold ${isSuccess ? 'text-green-400' : 'text-red-400'}">$&</span>`)
        .replace(/https?:\/\/[^\s]+/g, '<span class="text-cyan-400 underline">$&</span>')
        .replace(/\n/g, '<br>');

    terminalBody.innerHTML = `
        <div class="mb-4">
            <span class="text-gray-400">user@v10-api-test:~$</span>
            <span class="text-white ml-2">test-module-credentials</span>
        </div>
        <div class="mb-4">
            <span class="${isSuccess ? 'text-green-400' : 'text-red-400'} font-bold">${title}</span>
        </div>
        <div class="whitespace-pre-wrap">${formattedContent}</div>
        <div class="mt-4 pt-4 border-t border-gray-700">
            <span class="text-gray-400">user@v10-api-test:~$</span>
            <span class="text-white ml-2 animate-pulse">_</span>
        </div>
    `;

    terminalContainer.appendChild(terminalHeader);
    terminalContainer.appendChild(terminalBody);
    modalOverlay.appendChild(terminalContainer);

    document.body.appendChild(modalOverlay);

    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) {
            closeTerminalModal();
        }
    });

    document.addEventListener('keydown', handleEscapeKey);

    window.currentTerminalModal = modalOverlay;
}

function closeTerminalModal() {
    if (window.currentTerminalModal) {
        document.body.removeChild(window.currentTerminalModal);
        window.currentTerminalModal = null;
        document.removeEventListener('keydown', handleEscapeKey);
    }
}

function handleEscapeKey(e) {
    if (e.key === 'Escape') {
        closeTerminalModal();
    }
}

// Database Connection Test Function
function testDatabaseConnection() {
    const button = document.getElementById('testDbButton');

    // Get database credentials
    const host = document.getElementById('db_host').value;
    const database = document.getElementById('db_database').value;
    const username = document.getElementById('db_username').value;
    const password = document.getElementById('db_password').value;

    // Validate inputs
    if (!host || !database || !username) {
        vt.error('<?=T::fill_all_required_fields?>');
        return;
    }

    // Show loading state
    button.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::testing?>...';
    button.disabled = true;

    // Prepare form data
    const formData = new FormData();
    formData.append('test_db', '1');
    formData.append('host', host);
    formData.append('database', database);
    formData.append('username', username);
    formData.append('password', password);
    formData.append('module_id', '<?= $module['id'] ?>');

    // Send test request
    fetch('<?= root.admin ?>/settings/modules/test-db', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('<?=T::network_response_error?>');
        }
        return response.json();
    })
    .then(data => {
        console.log('Test response:', data);
        if (data.success) {
            // Show success notification
            vt.success(data.message);

            // Enable save button after successful test
            document.getElementById('saveDbButton').disabled = false;
        } else {
            // Show error notification
            vt.error(data.message || '<?=T::connection_test_failed?>');

            // Keep save button disabled
            document.getElementById('saveDbButton').disabled = true;
        }
    })
    .catch(error => {
        console.error('Test error:', error);
        vt.error('<?=T::failed_to_test_database?>: ' + error.message);
        document.getElementById('saveDbButton').disabled = true;
    })
    .finally(() => {
        button.innerHTML = '<span class="material-symbols-outlined text-sm">cable</span> <?=T::test_connection?>';
        button.disabled = false;
    });
}

// Save Database Credentials Function
function saveDatabaseCredentials() {
    const button = document.getElementById('saveDbButton');
    const host = document.getElementById('db_host').value;
    const database = document.getElementById('db_database').value;
    const username = document.getElementById('db_username').value;
    const password = document.getElementById('db_password').value;

    // Show loading state
    button.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::saving?>...';
    button.disabled = true;

    // Prepare form data
    const formData = new FormData();
    formData.append('save_settings', '1');
    formData.append('host', host);
    formData.append('database', database);
    formData.append('username', username);
    formData.append('password', password);

    // Get all other form fields
    const form = document.getElementById('settingsForm');
    const formElements = form.querySelectorAll('input, select, textarea');
    formElements.forEach(element => {
        if (element.name && element.name !== 'host' && element.name !== 'database' && element.name !== 'username' && element.name !== 'password') {
            if (element.type === 'checkbox') {
                formData.append(element.name, element.checked ? '1' : '0');
            } else {
                formData.append(element.name, element.value);
            }
        }
    });

    // Send save request
    fetch('<?= root.admin ?>/settings/modules/<?= $module['id'] ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.redirected) {
            vt.success('<?=T::database_credentials_saved?>');
            setTimeout(() => {
                window.location.href = response.url;
            }, 1000);
        } else {
            return response.text();
        }
    })
    .catch(error => {
        vt.error('<?=T::failed_to_save_credentials?>: ' + error.message);
        button.innerHTML = '<span class="material-symbols-outlined text-sm">save</span> <?=T::save_credentials?>';
        button.disabled = false;
    });
}

document.getElementById('use_mtls_toggle')?.addEventListener('change', function() {
    const mtlsSection = document.getElementById('mtls_cert_section');
    const newValue = this.checked ? '1' : '0';

    // Show/hide section
    if (this.checked) {
        mtlsSection.style.display = 'block';
    } else {
        mtlsSection.style.display = 'none';
    }

    // Auto-save the toggle state
    saveHotelbedsSettingToggle('use_mtls', newValue, this, {
        successMessage: '<?= T::mtls_setting_updated ?>',
        failMessage: '<?= T::failed_to_update_mtls ?>',
        errorMessage: '<?= T::error_updating_mtls ?>',
        onRevert(originalState) {
            document.getElementById('mtls_cert_section').style.display = originalState ? 'block' : 'none';
        }
    });
});

document.getElementById('allow_packaging_rates_toggle')?.addEventListener('change', function() {
    const newValue = this.checked ? '1' : '0';
    saveHotelbedsSettingToggle('allow_packaging_rates', newValue, this, {
        successMessage: '<?= T::packaging_rates_setting_updated ?? "Packaging rates setting updated successfully!" ?>',
        failMessage: '<?= T::failed_to_update_packaging_rates ?? "Failed to update packaging rates setting" ?>',
        errorMessage: '<?= T::error_updating_packaging_rates ?? "An error occurred while updating packaging rates setting" ?>'
    });
});

function saveHotelbedsSettingToggle(settingKey, value, toggle, messages = {}) {
    if (!toggle) {
        return;
    }

    const enabled = value === '1' || value === 1 || value === true;
    const previousState = !enabled;
    toggle.disabled = true;
    toggle.style.opacity = '0.6';

    const formData = new FormData();
    formData.append('module_id', '<?= $module['id'] ?>');
    formData.append('setting_key', settingKey);
    formData.append('setting_value', enabled ? '1' : '0');

    fetch('<?= root.admin ?>/settings/modules/hotelbeds/update-setting', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            vt.success(messages.successMessage || 'Setting updated successfully');
        } else {
            vt.error(data.message || messages.failMessage || 'Failed to update setting');
            toggle.checked = previousState;
            if (typeof messages.onRevert === 'function') {
                messages.onRevert(previousState);
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        vt.error(messages.errorMessage || 'An error occurred while updating setting');
        toggle.checked = previousState;
        if (typeof messages.onRevert === 'function') {
            messages.onRevert(previousState);
        }
    })
    .finally(() => {
        toggle.disabled = false;
        toggle.style.opacity = '1';
    });
}

// Backward-compatible alias used by older inline handlers
function saveMtlsToggle(value) {
    const toggle = document.getElementById('use_mtls_toggle');
    saveHotelbedsSettingToggle('use_mtls', value, toggle, {
        successMessage: '<?= T::mtls_setting_updated ?>',
        failMessage: '<?= T::failed_to_update_mtls ?>',
        errorMessage: '<?= T::error_updating_mtls ?>',
        onRevert(previousState) {
            document.getElementById('mtls_cert_section').style.display = previousState ? 'block' : 'none';
        }
    });
}

// Upload Certificates Function
function uploadCertificates() {
    const button = document.getElementById('uploadCertsButton');
    const clientCert = document.getElementById('client_cert').files[0];
    const clientKey = document.getElementById('client_key').files[0];
    const caBundle = document.getElementById('ca_bundle').files[0];

    // Check if at least ONE file is selected
    if (!clientCert && !clientKey && !caBundle) {
        vt.error('Please select at least one certificate file to upload');
        return;
    }

    // Prepare FormData - only add selected files
    const formData = new FormData();
    formData.append('module_id', '<?= $module['id'] ?>');

    let uploadingFiles = [];

    // Validate and add client certificate if selected
    if (clientCert) {
        if (!clientCert.name.endsWith('.pem')) {
            vt.error('<?=T::client_cert_must_be_pem?>');
            return;
        }
        formData.append('client_cert', clientCert);
        uploadingFiles.push('client.pem');
    }

    // Validate and add private key if selected
    if (clientKey) {
        if (!clientKey.name.endsWith('.key')) {
            vt.error('<?=T::private_key_must_be_key?>');
            return;
        }
        formData.append('client_key', clientKey);
        uploadingFiles.push('client.key');
    }

    // Validate and add CA bundle if selected
    if (caBundle) {
        if (!caBundle.name.endsWith('.crt')) {
            vt.error('<?=T::ca_bundle_must_be_crt?>');
            return;
        }
        formData.append('ca_bundle', caBundle);
        uploadingFiles.push('ca_bundle.crt');
    }

    // Show loading
    button.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::uploading?>...';
    button.disabled = true;

    // Upload
    fetch('<?= root.admin ?>/settings/modules/upload-certs', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            vt.success(data.message);

            // Clear file inputs and update cards for uploaded files only
            if (clientCert) {
                document.getElementById('client_cert').value = '';
                updateCertCard('client_cert', 'client.pem', '<?=T::client_certificate?>');
            }

            if (clientKey) {
                document.getElementById('client_key').value = '';
                updateCertCard('client_key', 'client.key', '<?=T::private_key?>');
            }

            if (caBundle) {
                document.getElementById('ca_bundle').value = '';
                updateCertCard('ca_bundle', 'ca_bundle.crt', '<?=T::ca_bundle?>');
            }
        } else {
            vt.error(data.message || '<?=T::failed_to_upload_certificates?>');
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        vt.error('<?=T::error_uploading_certificates?>');
    })
    .finally(() => {
        button.innerHTML = '<span class="material-symbols-outlined text-sm">cloud_upload</span> <?=T::upload_certificates?>';
        button.disabled = false;
    });
}

// Helper function to update certificate card after upload
function updateCertCard(type, filename, label) {
    const certCard = document.getElementById(`cert_${type}`);
    if (!certCard) return;

    // Show card if hidden
    certCard.classList.remove('hidden');

    // Update to green (uploaded state)
    certCard.classList.remove('bg-blue-50', 'border-blue-200');
    certCard.classList.add('bg-green-50', 'border-green-200');

    // Update icon color
    const icon = certCard.querySelector('.material-symbols-outlined');
    icon.classList.remove('text-blue-600');
    icon.classList.add('text-green-600');

    // Update filename
    const certName = document.getElementById(`cert_${type}_name`);
    certName.textContent = filename;
    certName.classList.remove('text-blue-800');
    certName.classList.add('text-green-800');

    // Update label
    const labels = certCard.querySelectorAll('.text-xs');
    const statusLabel = labels[labels.length - 1];
    statusLabel.classList.remove('text-blue-600');
    statusLabel.classList.add('text-green-600');
    statusLabel.textContent = label;
}

// Test mTLS Certificates Function
function testMtlsCertificates() {
    const button = document.getElementById('testMtlsButton');
    const resultsDiv = document.getElementById('mtls_test_results');

    // Show loading
    button.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::testing?>...';
    button.disabled = true;
    resultsDiv.className = 'mt-3 p-3 rounded-lg border border-blue-200 bg-blue-50';
    resultsDiv.innerHTML = '<p class="text-sm text-blue-800"><?=T::testing_mtls_certificates?>...</p>';
    resultsDiv.classList.remove('hidden');

    // Prepare request
    const formData = new FormData();
    formData.append('module_id', '<?= $module['id'] ?>');
    formData.append('c1', document.querySelector('input[name="c1"]').value);
    formData.append('c2', document.querySelector('input[name="c2"]').value);
    formData.append('env', document.getElementById('dev_mode_status').value === '1' ? 'test' : 'live');

    // Test
    fetch('<?= root.admin ?>/settings/modules/test-mtls', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status || data.success) {
            resultsDiv.className = 'mt-3 p-3 rounded-lg border border-green-200 bg-green-50';
            resultsDiv.innerHTML = `
                <div class="flex items-start gap-2">
                    <span class="material-symbols-outlined text-green-600">check_circle</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-green-800 mb-1">✓ <?=T::certificates_valid?>!</p>
                        <p class="text-xs text-green-700">${data.message}</p>
                        ${data.response ? `<pre class="text-xs text-green-600 mt-2 overflow-auto max-h-32">${JSON.stringify(data.response, null, 2)}</pre>` : ''}
                    </div>
                </div>
            `;
            vt.success('<?=T::mtls_certificates_valid?>');
        } else {
            resultsDiv.className = 'mt-3 p-3 rounded-lg border border-red-200 bg-red-50';
            resultsDiv.innerHTML = `
                <div class="flex items-start gap-2">
                    <span class="material-symbols-outlined text-red-600">error</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-red-800 mb-1">✗ <?=T::test_failed?></p>
                        <p class="text-xs text-red-700">${data.message || data.error}</p>
                        ${data.error_details ? `<p class="text-xs text-red-600 mt-1">${data.error_details}</p>` : ''}
                    </div>
                </div>
            `;
            vt.error('<?=T::certificate_test_failed?>');
        }
    })
    .catch(error => {
        console.error('Test error:', error);
        resultsDiv.className = 'mt-3 p-3 rounded-lg border border-red-200 bg-red-50';
        resultsDiv.innerHTML = `
            <div class="flex items-start gap-2">
                <span class="material-symbols-outlined text-red-600">error</span>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-red-800 mb-1">✗ <?=T::connection_error?></p>
                    <p class="text-xs text-red-700"><?=T::failed_to_connect_test?></p>
                </div>
            </div>
        `;
        vt.error('<?=T::error_testing_certificates?>');
    })
    .finally(() => {
        button.innerHTML = '<span class="material-symbols-outlined text-sm">verified_user</span> <?=T::test_certificates?>';
        button.disabled = false;
    });
}

// ============================================
// LOGS MANAGEMENT FUNCTIONS
// ============================================

// Toggle Logging On/Off
document.getElementById('logging_enabled_toggle')?.addEventListener('change', function() {
    const isEnabled = this.checked;
    const toggle = this;
    const logsListSection = document.getElementById('logs_list_section');
    const noLogsMessage = document.getElementById('no_logs_message');

    if (!isEnabled) {
        // Show confirmation dialog before disabling and deleting logs
        if (!confirm('⚠️ WARNING: Disabling logging will permanently delete ALL log files. This action cannot be undone.\n\nAre you sure you want to continue?')) {
            toggle.checked = true; // Revert if cancelled
            return;
        }
    }

    // Show/hide sections immediately (like mTLS)
    if (isEnabled) {
        // Check if there are log files
        const hasLogFiles = logsListSection && logsListSection.querySelector('table');
        if (hasLogFiles) {
            logsListSection.style.display = 'block';
            if (noLogsMessage) noLogsMessage.style.display = 'none';
        } else {
            logsListSection.style.display = 'none';
            if (noLogsMessage) noLogsMessage.style.display = 'block';
        }
    } else {
        logsListSection.style.display = 'none';
        if (noLogsMessage) noLogsMessage.style.display = 'none';
    }

    // Disable toggle during save
    toggle.disabled = true;
    toggle.style.opacity = '0.6';

    const formData = new FormData();
    formData.append('module_id', '<?= $module['id'] ?>');
    formData.append('module_name', '<?= $module['name'] ?>');
    formData.append('module_type', '<?= $module['type'] ?>');
    formData.append('setting_key', 'logging_enabled');
    formData.append('setting_value', isEnabled ? '1' : '0');

    fetch('<?= root.admin ?>/settings/modules/toggle-logging', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            vt.success(data.message);

            // If disabled and logs were deleted, hide the sections without reload
            if (!isEnabled && data.logs_deleted) {
                logsListSection.style.display = 'none';
                if (noLogsMessage) noLogsMessage.style.display = 'none';

                // Optionally update the log count badge if it exists
                const logCountBadge = document.querySelector('.bg-blue-100.text-blue-800');
                if (logCountBadge) {
                    logCountBadge.textContent = '0 <?=T::log_files?>';
                }
            }
        } else {
            vt.error(data.message || '<?=T::failed_to_update_logging?>');
            toggle.checked = !isEnabled; // Revert on failure

            // Revert display state
            if (!isEnabled) {
                const hasLogFiles = logsListSection && logsListSection.querySelector('table');
                if (hasLogFiles) {
                    logsListSection.style.display = 'block';
                    if (noLogsMessage) noLogsMessage.style.display = 'none';
                } else {
                    logsListSection.style.display = 'none';
                    if (noLogsMessage) noLogsMessage.style.display = 'block';
                }
            } else {
                logsListSection.style.display = 'none';
                if (noLogsMessage) noLogsMessage.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        vt.error('<?=T::error_updating_logging?>');
        toggle.checked = !isEnabled; // Revert on error

        // Revert display state
        if (!isEnabled) {
            const hasLogFiles = logsListSection && logsListSection.querySelector('table');
            if (hasLogFiles) {
                logsListSection.style.display = 'block';
                if (noLogsMessage) noLogsMessage.style.display = 'none';
            } else {
                logsListSection.style.display = 'none';
                if (noLogsMessage) noLogsMessage.style.display = 'block';
            }
        } else {
            logsListSection.style.display = 'none';
            if (noLogsMessage) noLogsMessage.style.display = 'none';
        }
    })
    .finally(() => {
        toggle.disabled = false;
        toggle.style.opacity = '1';
    });
});

// View Log File
function viewLogFile(filename) {
    const formData = new FormData();
    formData.append('module_id', '<?= $module['id'] ?>');
    formData.append('module_name', '<?= $module['name'] ?>');
    formData.append('module_type', '<?= $module['type'] ?>');
    formData.append('filename', filename);
    formData.append('action', 'view');

    fetch('<?= root.admin ?>/settings/modules/log-action', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Create modal to display log content
            showLogViewer(filename, data.content);
        } else {
            vt.error(data.message || '<?=T::failed_to_load_log?>');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        vt.error('<?=T::error_loading_log?>');
    });
}

// Download Log File
function downloadLogFile(filename) {
    // Add loading indicator
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;

    btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span>';
    btn.disabled = true;

    const url = `<?= root.admin ?>/settings/modules/log-action?module_id=<?= $module['id'] ?>&module_name=<?= $module['name'] ?>&module_type=<?= $module['type'] ?>&filename=${encodeURIComponent(filename)}&action=download`;

    // Use fetch instead of anchor tag to avoid page navigation
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to download log file');
            }
            return response.blob();
        })
        .then(blob => {
            // Create blob URL and download
            const blobUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = blobUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();

            // Cleanup
            setTimeout(() => {
                document.body.removeChild(a);
                window.URL.revokeObjectURL(blobUrl);
            }, 100);

            vt.success('<?=T::downloading?>: ' + filename);
        })
        .catch(error => {
            console.error('Download error:', error);
            vt.error('<?=T::error_downloading_log?>');
        })
        .finally(() => {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        });
}

// Delete Single Log File
function deleteLogFile(filename) {
    if (!confirm(`<?=T::confirm_delete_log?>: ${filename}?\n\n<?=T::this_action_cannot_be_undone?>`)) {
        return;
    }

    const formData = new FormData();
    formData.append('module_id', '<?= $module['id'] ?>');
    formData.append('module_name', '<?= $module['name'] ?>');
    formData.append('module_type', '<?= $module['type'] ?>');
    formData.append('filename', filename);
    formData.append('action', 'delete');

    fetch('<?= root.admin ?>/settings/modules/log-action', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            vt.success(data.message);
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            vt.error(data.message || '<?=T::failed_to_delete_log?>');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        vt.error('<?=T::error_deleting_log?>');
    });
}

// Delete All Logs
function deleteAllLogs() {
    if (!confirm('⚠️ <?=T::warning?>: <?=T::delete_all_logs_confirmation?>\n\n<?=T::this_action_cannot_be_undone?>')) {
        return;
    }

    const formData = new FormData();
    formData.append('module_id', '<?= $module['id'] ?>');
    formData.append('module_name', '<?= $module['name'] ?>');
    formData.append('module_type', '<?= $module['type'] ?>');
    formData.append('action', 'delete_all');

    fetch('<?= root.admin ?>/settings/modules/log-action', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            vt.success(data.message);
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            vt.error(data.message || '<?=T::failed_to_delete_logs?>');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        vt.error('<?=T::error_deleting_logs?>');
    });
}

// Show Log Viewer Modal
function showLogViewer(filename, content) {
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50';
    modalOverlay.style.backdropFilter = 'blur(4px)';

    const modalContainer = document.createElement('div');
    modalContainer.className = 'bg-white rounded-lg shadow-2xl w-full max-w-6xl max-h-[90vh] overflow-hidden';

    // Try to parse JSON for pretty display
    let displayContent = content;
    let isJSON = false;
    try {
        const parsed = JSON.parse(content);
        displayContent = JSON.stringify(parsed, null, 2);
        isJSON = true;
    } catch (e) {
        // Not JSON, display as-is
    }

    modalContainer.innerHTML = `
        <div class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-blue-600 text-2xl">description</span>
                <div>
                    <h3 class="text-lg font-bold text-gray-900"><?=T::log_file_viewer?></h3>
                    <p class="text-sm text-gray-600">${filename}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="downloadLogContent('${filename}')" class="btn light text-sm">
                    <span class="material-symbols-outlined text-sm">download</span>
                    <?=T::download?>
                </button>
                <button onclick="closeLogViewer()" class="btn light text-sm">
                    <span class="material-symbols-outlined text-sm">close</span>
                    <?=T::close?>
                </button>
            </div>
        </div>
        <div class="p-6 overflow-auto max-h-[calc(90vh-120px)]">
            <pre id="logContent" class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs font-mono leading-relaxed overflow-x-auto">${displayContent}</pre>
        </div>
    `;

    modalOverlay.appendChild(modalContainer);
    document.body.appendChild(modalOverlay);

    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) {
            closeLogViewer();
        }
    });

    window.currentLogModal = modalOverlay;
    window.currentLogContent = content;
    window.currentLogFilename = filename;
}

function closeLogViewer() {
    if (window.currentLogModal) {
        document.body.removeChild(window.currentLogModal);
        window.currentLogModal = null;
        window.currentLogContent = null;
        window.currentLogFilename = null;
    }
}

function downloadLogContent(filename) {
    const content = window.currentLogContent;
    if (!content) return;
    const blob = new Blob([content], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    vt.success('<?=T::downloading?>: ' + filename);
}

function downloadAllLogs(event) {
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::preparing_downloads?>...';
    btn.disabled = true;

    // Get list of all files first
    const formData = new FormData();
    formData.append('module_id', '<?= $module['id'] ?>');
    formData.append('module_name', '<?= $module['name'] ?>');
    formData.append('module_type', '<?= $module['type'] ?>');
    formData.append('action', 'download_all');

    fetch('<?= root.admin ?>/settings/modules/log-action', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Failed to get file list');
            }

            if (!data.files || data.files.length === 0) {
                throw new Error('No log files found');
            }

            // Update button text with count
            btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::downloading?> 0/' + data.files.length + '...';

            // Download files one by one with delay
            let downloadedCount = 0;
            const delay = 500; // 500ms delay between downloads

            function downloadNext(index) {
                if (index >= data.files.length) {
                    vt.success('<?=T::all_files_downloaded?>: ' + data.files.length + ' files');
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                    return;
                }

                const filename = data.files[index];

                // Create download link
                const downloadFormData = new FormData();
                downloadFormData.append('module_id', '<?= $module['id'] ?>');
                downloadFormData.append('module_name', '<?= $module['name'] ?>');
                downloadFormData.append('module_type', '<?= $module['type'] ?>');
                downloadFormData.append('action', 'download');
                downloadFormData.append('filename', filename);

                fetch('<?= root.admin ?>/settings/modules/log-action', {
                    method: 'POST',
                    body: downloadFormData
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Failed to download: ' + filename);
                        }
                        return response.blob();
                    })
                    .then(blob => {
                        const blobUrl = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = blobUrl;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();

                        setTimeout(() => {
                            document.body.removeChild(a);
                            window.URL.revokeObjectURL(blobUrl);
                        }, 100);

                        downloadedCount++;
                        btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::downloading?> ' + downloadedCount + '/' + data.files.length + '...';

                        // Download next file after delay
                        setTimeout(() => downloadNext(index + 1), delay);
                    })
                    .catch(error => {
                        console.error('Download error for ' + filename + ':', error);
                        // Continue with next file even if one fails
                        setTimeout(() => downloadNext(index + 1), delay);
                    });
            }

            // Start downloading
            downloadNext(0);
        })
        .catch(error => {
            console.error('Error:', error);
            vt.error('<?=T::error_downloading?>: ' + error.message);
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        });
}
</script>
