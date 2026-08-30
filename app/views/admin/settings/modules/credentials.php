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
                <?= ucfirst($module['name']) ?> <?=T::integration?>
            </span>
        </div>
    </div>
    <div class="p-4 space-y-3">
        <?php foreach ($credentialFields as $field => $config): ?>
            <?php
                $label = is_array($config) ? $config['label'] : $config;
                $required = is_array($config) ? ($config['required'] ?? false) : true;
                $placeholder = is_array($config) ? ($config['placeholder'] ?? '') : '';
                $fieldType = is_array($config) ? ($config['type'] ?? 'password') : 'password';
                $options = is_array($config) ? ($config['options'] ?? []) : [];
                $help = is_array($config) ? ($config['help'] ?? '') : '';
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
                <?php if ($fieldType === 'select'): ?>
                    <select name="<?= $field ?>" class="input select w-full credential-input" <?= $required ? 'required' : '' ?>>
                        <?php foreach ($options as $optionValue => $optionLabel): ?>
                            <option value="<?= htmlspecialchars((string) $optionValue) ?>"
                                <?= (($module[$field] ?? '') === $optionValue || (($module[$field] ?? '') === '' && $optionValue === array_key_first($options))) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $optionLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?php $isPlainText = ($fieldType === 'text'); // non-secret fields (e.g. Base URL) stay visible ?>
                    <input type="<?= $isPlainText ? 'text' : $fieldType ?>"
                           name="<?= $field ?>"
                           value="<?= $module[$field] ?? '' ?>"
                           class="input w-full<?= $isPlainText ? '' : ' credential-input' ?>"
                           placeholder="<?= $placeholder ?: 'Enter ' . strtolower($label) ?>"
                           autocomplete="off"
                           <?= $isPlainText ? '' : 'data-protected="true"' ?>
                           <?= $required ? 'required' : '' ?>>
                <?php endif; ?>
                <?php if ($help !== ''): ?>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string) $help) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

                <?php if ($showCurrency && strtolower($module['name'] ?? '') === 'airalo'): ?>
                    <?php $selectedCurrency = (string) ($module['currency'] ?? 'USD'); ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <?=T::currency?>
                            <span class="text-red-500">*</span>
                        </label>
                        <select name="currency" class="select w-full" required>
                            <?php foreach ((array) $currencies as $currency): ?>
                                <?php
                                    $currencyCode = (string) ($currency['currency_code'] ?? $currency['name'] ?? '');
                                    if ($currencyCode === '') {
                                        continue;
                                    }
                                    $countryName = trim((string) ($currency['country_name'] ?? ''));
                                    $label = $currencyCode . ($countryName !== '' ? ' - ' . $countryName : '');
                                ?>
                                <option value="<?= htmlspecialchars($currencyCode) ?>" <?= $selectedCurrency === $currencyCode ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Default module currency used for payment alignment and exchange display.</p>
                    </div>
                <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (strtolower($module['name']) === 'hotelbeds'): ?>
<!-- mTLS Configuration Card -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-gray-600 text-lg">shield</span>
                <h3 class="text-sm font-semibold text-gray-900"><?=T::mtls_configuration?></h3>
            </div>
            <label class="flex items-center gap-2 cursor-pointer">
                <span class="text-xs text-gray-600"><?=T::enable?> <?=T::mtls?></span>
                <input type="checkbox"
                    name="use_mtls"
                    id="use_mtls_toggle"
                    value="1"
                    <?= isset($module['use_mtls']) && $module['use_mtls'] == 1 ? 'checked' : '' ?>
                    class="w-10 h-5 appearance-none bg-gray-300 rounded-full relative cursor-pointer transition-colors checked:bg-blue-600
                            before:content-[''] before:absolute before:w-4 before:h-4 before:rounded-full before:bg-white before:top-0.5 before:left-0.5
                            before:transition-transform checked:before:translate-x-5">
            </label>
        </div>
    </div>

    <div id="mtls_cert_section" class="p-4 space-y-4" style="display: <?= isset($module['use_mtls']) && $module['use_mtls'] == 1 ? 'block' : 'none' ?>;">
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 mb-3">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                <div class="text-sm text-blue-900">
                    <p class="font-bold mb-2 text-base"><?=T::mutual_tls_authentication?></p>
                    <p class="text-xs mb-3 text-blue-800"><?=T::mtls_description?></p>
                    <div class="flex items-start gap-2 text-xs">
                        <span class="text-green-600">✓</span>
                        <div>
                            <span class="font-medium"><?=T::required_files?>:</span> <?=T::client_certificate?> (.pem), <?=T::private_key?> (.key), <?=T::ca_bundle?> (.crt)
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        $possibleCertPaths = [
            __DIR__ . '/../../../../modules/stays/hotelbeds/certs/',
            $_SERVER['DOCUMENT_ROOT'] . '/modules/stays/hotelbeds/certs/',
            $_SERVER['DOCUMENT_ROOT'] . '/v10/modules/stays/hotelbeds/certs/',
            dirname(__FILE__) . '/../../../../modules/stays/hotelbeds/certs/',
        ];
        $certPath = __DIR__ . '/../../../../modules/stays/hotelbeds/certs/';
        foreach ($possibleCertPaths as $path) {
            if (file_exists($path) && is_dir($path)) { $certPath = $path; break; }
        }
        $clientCertExists = file_exists($certPath . 'client.pem');
        $clientKeyExists  = file_exists($certPath . 'client.key');
        $caBundleExists   = file_exists($certPath . 'ca_bundle.crt');
        $hasExistingCerts = $clientCertExists || $clientKeyExists || $caBundleExists;
        ?>

        <div id="certificates_grid" class="mb-6" style="display: <?= $hasExistingCerts ? 'block' : 'none' ?>;">
            <h4 class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                <span class="material-symbols-outlined text-lg text-green-600">verified_user</span>
                Current Certificates
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="certs_display_grid">
                <div id="cert_client_cert" class="<?= $clientCertExists ? '' : 'hidden' ?> bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="material-symbols-outlined text-green-600 text-xl">description</span>
                            <button type="button" onclick="document.getElementById('client_cert').click()" class="text-xs text-blue-600 hover:text-blue-800 font-medium">Change</button>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-green-800" id="cert_client_cert_name">client.pem</p>
                            <p class="text-xs text-green-600"><?=T::client_certificate?></p>
                        </div>
                    </div>
                </div>
                <div id="cert_client_key" class="<?= $clientKeyExists ? '' : 'hidden' ?> bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="material-symbols-outlined text-green-600 text-xl">vpn_key</span>
                            <button type="button" onclick="document.getElementById('client_key').click()" class="text-xs text-blue-600 hover:text-blue-800 font-medium">Change</button>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-green-800" id="cert_client_key_name">client.key</p>
                            <p class="text-xs text-green-600"><?=T::private_key?></p>
                        </div>
                    </div>
                </div>
                <div id="cert_ca_bundle" class="<?= $caBundleExists ? '' : 'hidden' ?> bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="material-symbols-outlined text-green-600 text-xl">folder_zip</span>
                            <button type="button" onclick="document.getElementById('ca_bundle').click()" class="text-xs text-blue-600 hover:text-blue-800 font-medium">Change</button>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-green-800" id="cert_ca_bundle_name">ca_bundle.crt</p>
                            <p class="text-xs text-green-600"><?=T::ca_bundle?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border-2 border-dashed border-blue-300 rounded-xl p-4">
            <div class="text-center">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-100 rounded-full mb-2">
                    <span class="material-symbols-outlined text-2xl text-blue-600">cloud_upload</span>
                </div>
                <h4 class="text-base font-semibold text-gray-900 mb-1"><?=T::upload_certificates?></h4>
                <p class="text-xs text-gray-600 mb-4"><?=T::drag_drop_certificates_hint?></p>
                <div class="flex flex-col sm:flex-row gap-2 justify-center items-center">
                    <label for="client_cert" class="btn btn-sm inline-flex items-center gap-2 cursor-pointer text-xs">
                        <span class="material-symbols-outlined text-sm">description</span><span>.pem</span>
                    </label>
                    <label for="client_key" class="btn btn-sm inline-flex items-center gap-2 cursor-pointer text-xs">
                        <span class="material-symbols-outlined text-sm">vpn_key</span><span>.key</span>
                    </label>
                    <label for="ca_bundle" class="btn btn-sm inline-flex items-center gap-2 cursor-pointer text-xs">
                        <span class="material-symbols-outlined text-sm">folder_zip</span><span>.crt</span>
                    </label>
                </div>
                <input type="file" id="client_cert" name="client_cert" accept=".pem" class="hidden" onchange="handleCertFileSelect(this, 'client_cert')">
                <input type="file" id="client_key" name="client_key" accept=".key" class="hidden" onchange="handleCertFileSelect(this, 'client_key')">
                <input type="file" id="ca_bundle" name="ca_bundle" accept=".crt" class="hidden" onchange="handleCertFileSelect(this, 'ca_bundle')">
                <p class="text-xs text-gray-500 mt-3"><?=T::certificate_upload_requirements?></p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 pt-2">
            <button type="button" onclick="uploadCertificates()" class="btn" id="uploadCertsButton">
                <span class="material-symbols-outlined text-sm">cloud_upload</span>
                <?=T::upload_certificates?>
            </button>
            <button type="button" onclick="testMtlsCertificates()" class="btn" id="testMtlsButton">
                <span class="material-symbols-outlined text-sm">verified_user</span>
                <?=T::test_certificates?>
            </button>
        </div>

        <div id="mtls_test_results" class="hidden mt-3 p-3 rounded-lg border"></div>
    </div>
</div>

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-start gap-2 min-w-0">
                <span class="material-symbols-outlined text-gray-600 text-lg shrink-0">inventory_2</span>
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-gray-900"><?= T::allow_packaging_rates ?? 'Allow packaging rates' ?></h3>
                    <p class="text-xs text-gray-600 mt-1"><?= T::allow_packaging_rates_hint ?? 'Show Hotelbeds rates marked for flight+hotel packaging on search and hotel details. Keep off for hotel-only B2C.' ?></p>
                </div>
            </div>
            <label class="flex items-center gap-2 cursor-pointer shrink-0">
                <span class="text-xs text-gray-600"><?= T::enable ?? 'Enable' ?></span>
                <input type="checkbox"
                    name="allow_packaging_rates"
                    id="allow_packaging_rates_toggle"
                    value="1"
                    <?= !empty($module['allow_packaging_rates']) ? 'checked' : '' ?>
                    class="w-10 h-5 appearance-none bg-gray-300 rounded-full relative cursor-pointer transition-colors checked:bg-blue-600
                            before:content-[''] before:absolute before:w-4 before:h-4 before:rounded-full before:bg-white before:top-0.5 before:left-0.5
                            before:transition-transform checked:before:translate-x-5">
            </label>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($showApiTesting): ?>
<!-- API Testing Card -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-5">
    <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-gray-600 text-lg">science</span>
            <h3 class="text-sm font-semibold text-gray-900"><?=T::api_testing?></h3>
        </div>
    </div>
    <div class="p-4">
        <p class="text-sm text-gray-600 mb-3"><?=T::test_api_credentials_connectivity?></p>
        <button type="button" onclick="testAPI()" class="w-full btn mb-3" id="testButton">
            <span class="material-symbols-outlined text-sm">bug_report</span>
            <?=T::test_api_connection?>
        </button>

        <div id="testResults" class="hidden">
            <div class="border rounded-lg overflow-hidden bg-white shadow-lg">
                <div class="bg-gray-200 px-3 py-2 flex items-center justify-between border-b">
                    <div class="flex items-center space-x-2">
                        <div class="flex space-x-1.5">
                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        </div>
                        <div class="flex items-center space-x-1 ml-3">
                            <button class="text-gray-500 hover:text-gray-700 p-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                            </button>
                            <button class="text-gray-500 hover:text-gray-700 p-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                            <button class="text-gray-500 hover:text-gray-700 p-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 mx-4">
                        <div class="bg-white rounded-full px-4 py-1 text-sm text-gray-600 border flex items-center">
                            <svg class="w-4 h-4 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            <span id="browserUrl">localhost:8000/api-test-terminal</span>
                        </div>
                    </div>
                    <div class="flex items-center space-x-1">
                        <button class="text-gray-500 hover:text-gray-700 p-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path></svg>
                        </button>
                    </div>
                </div>
                <div class="bg-gray-900 min-h-[400px] relative">
                    <div class="bg-gray-800 px-4 py-2 flex items-center justify-between border-b border-gray-700">
                        <div class="flex items-center space-x-2">
                            <span class="text-green-400 text-sm font-mono">●</span>
                            <span class="text-gray-300 text-sm font-mono"><?=T::api_test_terminal?></span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <button type="button" onclick="copyTerminalLogs(event)" id="copyLogsBtn"
                                    class="hidden bg-blue-600 hover:bg-blue-700 text-white text-xs px-3 py-1 rounded transition-colors flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                <?=T::copy_logs?>
                            </button>
                            <div class="flex items-center space-x-2 text-xs text-gray-400">
                                <span id="terminalStatus"><?=T::ready?></span>
                                <span>|</span>
                                <span id="terminalTime"></span>
                            </div>
                        </div>
                    </div>
                    <div id="terminalContent" class="p-4 font-mono text-sm text-green-400 leading-relaxed max-h-96 overflow-y-auto">
                        <div class="flex items-center">
                            <span class="text-blue-400">user@v10-api-test</span>
                            <span class="text-gray-400">:</span>
                            <span class="text-yellow-400">~</span>
                            <span class="text-gray-400">$</span>
                            <span class="ml-2 text-white"><?=T::click_test_button_to_start?></span>
                            <span id="cursor" class="ml-1 text-white animate-pulse">_</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
