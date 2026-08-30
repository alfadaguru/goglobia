<?php
// Gateway credential configurations
$gatewayCredentials = [
    'pay_later' => [
        'pay_later' => [
        ]
    ],
    'digital_wallet' => [
        'paypal' => [
            'c1' => [
                'label' => 'Client ID',
                'required' => true,
                'placeholder' => 'Enter PayPal Client ID'
            ],
            'c2' => [
                'label' => 'Client Secret',
                'required' => true,
                'placeholder' => 'Enter PayPal Client Secret'
            ],
            'c3' => [
                'label' => 'Sandbox Email',
                'required' => false,
                'placeholder' => 'sandbox@business.example.com (optional)'
            ],
            'c4' => [
                'label' => 'Password',
                'required' => false,
                'placeholder' => 'Sandbox password (optional)'
            ]
        ],
        'xmoney' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Your xMoney API key'
            ],
            'c2' => [
                'label' => 'Secret Key',
                'required' => false,
                'placeholder' => 'Your xMoney secret / webhook key'
            ]
        ]
    ],
    'credit_card' => [
        'stripe' => [
            'c1' => [
                'label' => 'Publishable Key',
                'required' => true,
                'placeholder' => 'pk_test_... or pk_live_...'
            ],
            'c2' => [
                'label' => 'Secret Key',
                'required' => true,
                'placeholder' => 'sk_test_... or sk_live_...'
            ],
            'c3' => [
                'label' => 'Test Card',
                'required' => false,
                'placeholder' => '4242 4242 4242 4242'
            ],
            'c4' => [
                'label' => 'Test Expiry & CVV',
                'required' => false,
                'placeholder' => '12/26 123'
            ]
        ],
        'flutterwave' => [
            'c1' => [
                'label' => 'Public Key',
                'required' => true,
                'placeholder' => 'FLWPUBK_TEST-... or FLWPUBK-...'
            ],
            'c2' => [
                'label' => 'Secret Key',
                'required' => true,
                'placeholder' => 'FLWSECK_TEST-... or FLWSECK-...'
            ],
            'c3' => [
                'label' => 'Encryption Key',
                'required' => true,
                'placeholder' => 'FLWSECK_TEST...'
            ]
        ],
        'paystack' => [
            'c1' => [
                'label' => 'Live Secret Key',
                'required' => true,
                'placeholder' => 'sk_live_f388c50...'
            ],
            'c2' => [
                'label' => 'Live Public Key',
                'required' => true,
                'placeholder' => 'pk_live_38e9e9-...'
            ]
        ],
        'cashfree' => [
            'c1' => [
                'label' => 'App ID',
                'required' => true,
                'placeholder' => 'Enter Cashfree App ID from Merchant Dashboard'
            ],
            'c2' => [
                'label' => 'Secret Key',
                'required' => true,
                'placeholder' => 'Enter Cashfree Secret Key'
            ]
        ],
        'fawaterak' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Enter API Key provided by FAWATERAK'
            ],
        ],
        'adyen' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'Checkout API key (Developers > API credentials)'
            ],
            'c2' => [
                'label' => 'Merchant Account',
                'required' => true,
                'placeholder' => 'e.g. YourCompanyECOM'
            ],
            'c3' => [
                'label' => 'HMAC Key (Webhook)',
                'required' => false,
                'placeholder' => 'From Developers > Webhooks > Standard notification'
            ],
            'c4' => [
                'label' => 'Live URL Prefix',
                'required' => false,
                'placeholder' => 'Live only (Developers > API URLs); leave blank for test'
            ],
        ],

    ],
    'bank_transfer' => [
        'wire_transfer' => [
            'c1' => [
                'label' => 'Account Holder',
                'required' => true,
                'placeholder' => 'e.g., John Doe'
            ],
            'c2' => [
                'label' => 'Routing Number',
                'required' => true,
                'placeholder' => 'e.g., 084009519'
            ],
            'c3' => [
                'label' => 'Account Number',
                'required' => true,
                'placeholder' => 'e.g., 9600001474383599'
            ],
            'c4' => [
                'label' => 'Bank Address',
                'required' => true,
                'placeholder' => '26th Street, Sixth Floor New York NY 10010'
            ],
            'c5' => [
                'label' => 'IBAN/SWIFT',
                'required' => false,
                'placeholder' => 'e.g., GBPXXXIP0024456987 (optional)'
            ]
        ]
    ],
    'internal_wallet' => [
        'wallet_balance' => [
            'api_crendential' => false,
            'api_testing' => false,
            'env' => false
        ],
        'credits' => [
            'api_crendential' => false,
            'api_testing' => false,
            'env' => false
        ],
    ],
    'crypto_currency' => [
        'coinsbuy' => [
            'c1' => [
                'label' => 'Merchant ID',
                'required' => true,
                'placeholder' => '239946231881042623'
            ],
            'c2' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'rekzuyRFnpuJiJlzlgzGj2dpP5ZQ03Wgb...'
            ]
        ],
        'xmoney' => [
            'c1' => [
                'label' => 'API Key',
                'required' => true,
                'placeholder' => 'u_test_api_...'
            ],
            'c2' => [
                'label' => 'Webhook Secret',
                'required' => false,
                'placeholder' => 'Enter Webhook Secret (optional)'
            ]
        ]
    ]
];

// Get credentials for current gateway
$gatewayName = str_replace([' ', '_'], '_', strtolower($gateway['name']));
$gatewayType = str_replace([' ', '_'], '_', strtolower($gateway['type']));
$credentialFields = [];

// Debug: Log the values
// error_log("Gateway Name: " . $gateway['name'] . " -> " . $gatewayName);
// error_log("Gateway Type: " . $gateway['type'] . " -> " . $gatewayType);

// Check if API credentials and testing should be shown
$showApiCredentials = true;
$showApiTesting = false; // API testing disabled
$showEnvironment = true;
$showCurrency = true;

// Check if we have specific credentials for this gateway
if (isset($gatewayCredentials[$gatewayType]) && isset($gatewayCredentials[$gatewayType][$gatewayName])) {
    $credentialFields = $gatewayCredentials[$gatewayType][$gatewayName];

    if (isset($credentialFields['api_crendential']) && $credentialFields['api_crendential'] === false) {
        $showApiCredentials = false;
        unset($credentialFields['api_crendential']);
    }
    if (isset($credentialFields['api_testing']) && $credentialFields['api_testing'] === false) {
        $showApiTesting = false;
        unset($credentialFields['api_testing']);
    }
    if (isset($credentialFields['env']) && $credentialFields['env'] === false) {
        $showEnvironment = false;
        unset($credentialFields['env']);
    }

    // Check if there are any actual credential fields (c1, c2, etc.)
    $hasCredentialFields = false;
    foreach ($credentialFields as $key => $value) {
        if (preg_match('/^c\d+$/', $key)) {
            $hasCredentialFields = true;
            break;
        }
    }
    if (!$hasCredentialFields) {
        $showApiCredentials = false;
    }
} else {
    // Default fields
    $credentialFields = [
        'c1' => ['label' => 'Credential 1', 'required' => false, 'placeholder' => ''],
        'c2' => ['label' => 'Credential 2', 'required' => false, 'placeholder' => ''],
        'c3' => ['label' => 'Credential 3', 'required' => false, 'placeholder' => ''],
        'c4' => ['label' => 'Credential 4', 'required' => false, 'placeholder' => ''],
        'c5' => ['label' => 'Credential 5', 'required' => false, 'placeholder' => '']
    ];
}

// Get available currencies
$currencies = [];
try {
    $currenciesResult = $db->select('currencies', '*', ['LIMIT' => 1]);
    if (!empty($currenciesResult)) {
        $sampleCurrency = $currenciesResult[0];
        $hasStatusColumn = isset($sampleCurrency['status']);
        $hasCountryColumn = isset($sampleCurrency['country']);

        $condition = [];
        if ($hasStatusColumn) {
            $condition['currencies.status'] = 1;
        }

        if ($hasCountryColumn) {
            $currencies = $db->select('currencies', [
                '[>]countries' => ['country' => 'iso']
            ], [
                'currencies.id',
                'currencies.name(currency_code)',
                'currencies.country',
                'currencies.rate',
                'countries.nicename(country_name)'
            ], array_merge($condition, ['ORDER' => ['currencies.id' => 'ASC']]));
        } else {
            $currencies = $db->select('currencies', '*', array_merge(['status' => 1], ['ORDER' => ['id' => 'ASC']]));
        }
    }
} catch (Exception $e) {
    $currencies = [];
}
?>

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
            <a href="<?= root.admin ?>/settings/gateways" class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <!-- Gateway logo (image when available, else initials badge) -->
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-lg bg-white border border-slate-200 overflow-hidden shrink-0">
                <?= gateway_icon_html($gateway, 40) ?>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-slate-800">
                    <?= ucwords(str_replace('_', ' ', $gateway['name'])) ?> <?=T::configuration?>
                </h1>
                <div class="flex flex-wrap items-center gap-2 mt-1 text-sm text-slate-600">
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">payments</span>
                        <?= ucfirst($gateway['type']) ?> <?=T::gateway?>
                    </span>
                    <span>•</span>
                    <span class="flex items-center gap-1">
                        <span class="material-symbols-outlined text-base">tag</span>
                        <?=T::id?>: <?= $gateway['id'] ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="view-options space-x-4 mt-2 sm:mt-0 min-w-[31.9%] flex justify-between">
            <div class="flex flex-col gap-1 w-full">
                <label for="gateway_status" class="text-xs font-medium text-slate-600"><?=T::gateway?> <?=T::status?></label>
                <select id="gateway_status" class="select input text-sm py-1.5 px-3">
                    <option value="1" <?= $gateway['status'] ? 'selected' : '' ?>><?=T::active?></option>
                    <option value="0" <?= !$gateway['status'] ? 'selected' : '' ?>><?=T::inactive?></option>
                </select>
            </div>
            <?php if ($showEnvironment): ?>
            <div class="flex flex-col gap-1 w-full">
                <label for="dev_mode_status" class="text-xs font-medium text-slate-600"><?=T::environment?></label>
                <select id="dev_mode_status" class="select input text-sm py-1.5 px-3">
                    <option value="0" <?= !$gateway['dev_mode'] ? 'selected' : '' ?>><?=T::production?></option>
                    <option value="1" <?= $gateway['dev_mode'] ? 'selected' : '' ?>><?=T::development?></option>
                </select>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Settings Content -->
<div class="container mb-8">
    <div class="">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Main Settings -->
            <div class="lg:col-span-2 space-y-6">
                <form method="POST" action="<?= root.admin ?>/settings/gateway/update/<?= $gateway['id'] ?>" id="settingsForm">

                    <input type="hidden" name="csrf_token" value="<?= CSRF::getToken() ?>">

                    <!-- Hidden fields for header dropdowns -->
                    <input type="hidden" name="hidden_status" id="hidden_status" value="<?= $gateway['status'] ?>">
                    <input type="hidden" name="hidden_dev_mode" id="hidden_dev_mode" value="<?= $gateway['dev_mode'] ?>">

                    <!-- Customer-Facing Label Card -->
                    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
                        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-gray-600 text-lg">badge</span>
                                <h3 class="text-sm font-semibold text-gray-900"><?= T::display_name ?? 'Display Name' ?></h3>
                            </div>
                        </div>
                        <div class="p-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <?= T::customer_facing_label ?? 'Customer-Facing Label' ?>
                                <span class="text-gray-400 text-xs">(<?=T::optional?>)</span>
                            </label>
                            <input type="text"
                                   name="display_name"
                                   value="<?= htmlspecialchars($gateway['display_name'] ?? '') ?>"
                                   class="input w-full"
                                   placeholder="<?= htmlspecialchars($gateway['name']) ?>">
                            <p class="text-xs text-gray-500 mt-1">
                                <?= T::display_name_hint ?? 'Shown to customers at checkout, on invoices and in emails instead of the provider name below. Leave blank to show the provider name.' ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($showApiCredentials): ?>
                    <!-- API Credentials Card -->
                    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
                        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-gray-600 text-lg">key</span>
                                    <h3 class="text-sm font-semibold text-gray-900"><?=T::api_credentials?></h3>
                                </div>
                                <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded-md">
                                    <?= ucfirst($gateway['name']) ?> <?=T::integration?>
                                </span>
                            </div>
                        </div>
                        <div class="p-4 space-y-3">
                            <?php foreach ($credentialFields as $field => $config): ?>
                                <?php
                                    $label = is_array($config) ? $config['label'] : $config;
                                    $required = is_array($config) ? ($config['required'] ?? false) : true;
                                    $placeholder = is_array($config) ? ($config['placeholder'] ?? '') : '';
                                ?>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        <?= $label ?>
                                        <?php if ($required): ?>
                                            <span class="text-red-500">*</span>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-xs">(<?=T::optional?>)</span>
                                        <?php endif; ?>
                                    </label>
                                    <input type="text"
                                           name="<?= $field ?>"
                                           value="<?= htmlspecialchars($gateway[$field] ?? '') ?>"
                                           class="input w-full"
                                           placeholder="<?= $placeholder ?: 'Enter ' . strtolower($label) ?>"
                                           <?= $required ? 'required' : '' ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Currency Configuration Card -->
                    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
                        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-gray-600 text-lg">attach_money</span>
                                <h3 class="text-sm font-semibold text-gray-900"><?=T::currency_configuration?></h3>
                            </div>
                        </div>
                        <div class="p-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::supported_currency?></label>
                                <select name="currency" class="input select w-full">
                                    <?php if (isset($currencies) && !empty($currencies)): ?>
                                        <?php foreach ($currencies as $currency): ?>
                                            <?php
                                            $currencyCode = $currency['currency_code'] ?? $currency['name'] ?? 'USD';
                                            $displayName = '';
                                            if (!empty($currency['country_name'])) {
                                                $displayName = $currency['country_name'];
                                            } else {
                                                $displayName = $currencyCode;
                                            }
                                            ?>
                                            <option value="<?= $currencyCode ?>" <?= $gateway['currency'] === $currencyCode ? 'selected' : '' ?>>
                                                <?= $currencyCode ?> - <?= $displayName ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="USD" <?= $gateway['currency'] === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                                        <option value="EUR" <?= $gateway['currency'] === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                                        <option value="GBP" <?= $gateway['currency'] === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                                        <option value="PKR" <?= $gateway['currency'] === 'PKR' ? 'selected' : '' ?>>PKR - Pakistani Rupee</option>
                                        <option value="INR" <?= $gateway['currency'] === 'INR' ? 'selected' : '' ?>>INR - Indian Rupee</option>
                                        <option value="AED" <?= $gateway['currency'] === 'AED' ? 'selected' : '' ?>>AED - UAE Dirham</option>
                                        <option value="SAR" <?= $gateway['currency'] === 'SAR' ? 'selected' : '' ?>>SAR - Saudi Riyal</option>
                                    <?php endif; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-2">
                                    <?=T::currency_support_hint?>
                                </p>
                            </div>
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1"><?= T::order ?? 'Display Order' ?></label>
                                <input type="number" name="order" min="0" value="<?= (int) ($gateway['order'] ?? 0) ?>" class="input w-full">
                                <p class="text-xs text-gray-500 mt-2">Lower numbers appear first in the gateways list.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Notes Card -->
                    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
                        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-gray-600 text-lg">notes</span>
                                <h3 class="text-sm font-semibold text-gray-900"><?=T::additional_notes?></h3>
                            </div>
                        </div>
                        <div class="p-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1"><?=T::notes?></label>
                                <textarea name="note"
                                          class="input w-full h-32"
                                          placeholder="<?=T::enter_any_additional_notes_here?>"><?= htmlspecialchars($gateway['note'] ?? '') ?></textarea>
                                <p class="text-xs text-gray-500 mt-2">
                                    <?=T::notes_hint?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between w-full">
                        <a href="<?= root.admin ?>/settings/gateways" class="btn light inline-flex items-center justify-center w-full sm:w-auto" onclick="showBackLoading(this)">
                            <span class="material-symbols-outlined text-sm">arrow_back</span>
                            <?=T::back_to_gateways?>
                        </a>
                        <?php
                        // Only credential-based gateways get a live "Test Credentials" terminal.
                        // Internal wallet, pay later and bank/wire transfer have nothing to verify.
                        $gwNoTestTypes = ['internal_wallet', 'pay_later', 'bank_transfer', 'cash', 'manual_payment', 'voucher', 'module_gateway'];
                        $gwTestable = !in_array(strtolower((string) ($gateway['type'] ?? '')), $gwNoTestTypes, true);
                        ?>
                        <div class="flex flex-col sm:flex-row gap-3 sm:items-center w-full sm:w-auto">
                            <?php if ($gwTestable): ?>
                            <button type="button" onclick="testGatewayCredentials()" class="btn light inline-flex items-center justify-center w-full sm:w-auto">
                                <span class="material-symbols-outlined text-sm">terminal</span>
                                Test Credentials
                            </button>
                            <?php endif; ?>
                            <button type="submit" name="save_settings" class="btn inline-flex items-center justify-center w-full sm:w-auto" onclick="return validateAndShowSaveLoading(this)">
                                <span class="material-symbols-outlined text-sm">save</span>
                                <?=T::save_configuration?>
                            </button>
                        </div>
                    </div>

                </form>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">

                <!-- Gateway Info Card -->
                <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-gray-600 text-lg">info</span>
                            <h3 class="text-sm font-semibold text-gray-900"><?=T::gateway?> <?=T::information?></h3>
                        </div>
                    </div>
                    <div class="p-4 space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::gateway?> <?=T::id?>:</span>
                            <span class="font-medium"><?= $gateway['id'] ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::type?>:</span>
                            <span class="font-medium capitalize"><?= $gateway['type'] ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::name?>:</span>
                            <span class="font-medium capitalize"><?= ucwords(str_replace('_', ' ', $gateway['name'])) ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::status?>:</span>
                            <span class="font-medium <?= $gateway['status'] ? 'text-green-600' : 'text-red-600' ?>">
                                <?= $gateway['status'] ? T::active : T::inactive ?>
                            </span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::environment?>:</span>
                            <span class="font-medium <?= $gateway['dev_mode'] ? 'text-orange-600' : 'text-blue-600' ?>">
                                <?= $gateway['dev_mode'] ? T::development : T::production ?>
                            </span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::currency?>:</span>
                            <span class="font-medium text-gray-900"><?= $gateway['currency'] ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500"><?=T::order?>:</span>
                            <span class="font-medium text-gray-900"><?= $gateway['order'] ?? 0 ?></span>
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

                            <a href="https://docs.phptravels.com/payments/<?= str_replace(' ', '-', strtolower($gateway['name'])) ?>"
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
document.getElementById('gateway_status').addEventListener('change', function() {
    const newStatus = this.value;
    document.getElementById('hidden_status').value = newStatus;
    saveGatewayField('status', newStatus);
});

document.getElementById('dev_mode_status').addEventListener('change', function() {
    const newDevMode = this.value;
    document.getElementById('hidden_dev_mode').value = newDevMode;
    saveGatewayField('dev_mode', newDevMode);
});

function saveGatewayField(field, value) {
    const dropdown = field === 'status' ? document.getElementById('gateway_status') : document.getElementById('dev_mode_status');
    dropdown.disabled = true;
    dropdown.style.opacity = '0.6';

    const formData = new FormData();
    formData.append('ajax_update', '1');
    formData.append('field', field);
    formData.append('value', value);
    formData.append('gateway_id', '<?= $gateway['id'] ?>');

    fetch('<?= root.admin ?>/settings/gateway/ajax-update', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            vt.success('<?=T::operation_completed_successfully?>');
        } else {
            vt.error(data.message || '<?=T::failed_to_update_setting?>');
            if (data.previous_value !== undefined) {
                dropdown.value = data.previous_value;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        vt.error('<?=T::error_updating_setting?>');
    })
    .finally(() => {
        dropdown.disabled = false;
        dropdown.style.opacity = '1';
    });
}

function showBackLoading(element) {
    element.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> <?=T::loading?>...';
    element.classList.add('opacity-75', 'pointer-events-none');
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

        alert(errorMessage);

        if (emptyFields[0]) {
            emptyFields[0].focus();
        }

        const credentialsCard = document.querySelector('.bg-white.rounded-lg.border.border-gray-200');
        if (credentialsCard) {
            credentialsCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        return false;
    }

    // If validation passes, show loading
    element.innerHTML = '<div class="inline-flex items-center gap-2"><div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div><?=T::saving?>...</div>';
    element.classList.add('opacity-75');
    return true;
}
</script>

<?php if ($gwTestable): // terminal + JS only for gateways that have a test route ?>
<!-- ============================================================ -->
<!-- TEST CREDENTIALS — TERMINAL MODAL                             -->
<!-- ============================================================ -->
<div id="gwTermOverlay" class="fixed inset-0 bg-black/50 items-center justify-center p-4 z-[60] hidden" style="backdrop-filter:blur(4px);" onclick="if(event.target===this)gwTermClose()">
    <div class="bg-gray-900 rounded-lg shadow-2xl w-full max-w-3xl overflow-hidden border border-gray-700">
        <div class="bg-gray-800 px-4 py-2 flex items-center justify-between border-b border-gray-700">
            <div class="flex items-center gap-3">
                <div class="flex gap-2">
                    <div class="w-3 h-3 rounded-full bg-red-500"></div>
                    <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                    <div class="w-3 h-3 rounded-full bg-green-500"></div>
                </div>
                <span class="text-gray-300 text-sm font-mono">gateway credential test</span>
            </div>
            <div class="flex items-center gap-3">
                <span id="gwTermStatus" class="text-xs font-mono text-gray-400">idle</span>
                <button onclick="gwTermClose()" class="text-gray-400 hover:text-white text-lg leading-none">&times;</button>
            </div>
        </div>
        <div id="gwTermBody" class="bg-gray-900 p-5 font-mono text-[13px] leading-relaxed overflow-y-auto max-h-[65vh] whitespace-pre-wrap"></div>
    </div>
</div>

<script>
function gwTermClose() {
    document.getElementById('gwTermOverlay').classList.add('hidden');
    document.getElementById('gwTermOverlay').classList.remove('flex');
}
function gwTermStatus(t, cls) {
    const el = document.getElementById('gwTermStatus');
    el.textContent = t;
    el.className = 'text-xs font-mono ' + (cls || 'text-gray-400');
}
function gwTermLine(text) {
    const body = document.getElementById('gwTermBody');
    let color = 'text-gray-300';
    if (/^\[(OK|SUCCESS)\]/.test(text)) color = 'text-green-400';
    else if (/^\[(ERROR|FAILED)\]/.test(text)) color = 'text-red-400';
    else if (/^\[HINT\]/.test(text)) color = 'text-yellow-400';
    else if (/^\[(START|CALL)\]/.test(text)) color = 'text-blue-400';
    else if (/^\[(ENV|URL|HTTP|INFO|KEY|WARNING)\]/.test(text)) color = 'text-cyan-400';
    const div = document.createElement('div');
    div.className = color;
    // make URLs subtly highlighted
    div.textContent = text;
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
}
function testGatewayCredentials() {
    const form = document.getElementById('settingsForm');
    const fd = new FormData();
    ['c1', 'c2', 'c3', 'c4', 'c5', 'currency'].forEach(function (n) {
        const el = form ? form.querySelector('[name="' + n + '"]') : null;
        if (el) fd.append(n, el.value);
    });
    const dm = document.getElementById('hidden_dev_mode');
    if (dm) fd.append('dev_mode', dm.value);

    const overlay = document.getElementById('gwTermOverlay');
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');
    const body = document.getElementById('gwTermBody');
    body.innerHTML = '';
    gwTermStatus('testing…', 'text-yellow-400');
    gwTermLine('$ test-gateway-credentials');

    fetch('<?= root.admin ?>/settings/gateway/test/<?= strtolower(str_replace(' ', '_', $gateway['name'])) ?>', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            const steps = Array.isArray(d.steps) ? d.steps : [];
            let i = 0;
            (function tick() {
                if (i < steps.length) {
                    gwTermLine(steps[i]);
                    i++;
                    setTimeout(tick, 90);
                } else {
                    gwTermStatus(d.success ? 'connected ✓' : 'failed ✗', d.success ? 'text-green-400' : 'text-red-400');
                }
            })();
        })
        .catch(function (e) {
            gwTermLine('[ERROR] Network error: ' + e);
            gwTermStatus('failed ✗', 'text-red-400');
        });
}
</script>
<?php endif; ?>