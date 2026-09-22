<?php
/**
 * AI settings tab — Passport Scanner + Trip Planner feature cards + Provider.
 * Suggestions list is rendered after Save Settings in settings.php.
 * Trip Planner suppliers follow each module's status=1 AND active=1.
 * Expects $db in scope.
 */
ensurePassportAiSchema($db);
$ai = passportAiSettings($db);
$registry = passportAiProvidersRegistry();
$maxFileSizeMb = round(($ai['max_file_size'] ?: 5242880) / 1048576, 1);
$allowedTypes = implode(',', $ai['allowed_file_types']);
$aiActiveProvider = $ai['provider'] !== '' ? $ai['provider'] : (string) array_key_first($registry);

$aiFeatureModules = [
    'passport' => [
        'key' => 'passport',
        'name' => 'Passport Scanner',
        'type' => 'Booking',
        'icon' => 'document_scanner',
        'color' => '#3b82f6',
        'desc' => 'AI scan passport images on flight booking forms (requires API key)',
        'field' => 'passport_ai_enabled',
        'enabled' => !empty($ai['passport_enabled']),
        'needs_key' => true,
        'model' => 'passportEnabled',
    ],
    'passport_local' => [
        'key' => 'passport_local',
        'name' => 'Local Passport Scanner',
        'type' => 'Booking',
        'icon' => 'qr_code_scanner',
        'color' => '#64748b',
        'desc' => 'On-device MRZ/OCR scan without an AI model.',
        'field' => 'passport_local_enabled',
        'enabled' => !empty($ai['passport_local_enabled']),
        'needs_key' => false,
        'model' => 'passportLocalEnabled',
    ],
    'trip' => [
        'key' => 'trip',
        'name' => 'AI Trip Planner',
        'type' => 'Search',
        'icon' => 'auto_awesome',
        'color' => '#0ea5e9',
        'desc' => 'Home AI search tab and trip planner page. Active modules appear automatically.',
        'field' => 'ai_trip_enabled',
        'enabled' => !empty($ai['trip_enabled']),
        'needs_key' => true,
        'model' => 'tripEnabled',
    ],
];
$aiActiveCount = count(array_filter($aiFeatureModules, static fn ($m) => $m['enabled']));
$aiTotalCount = count($aiFeatureModules);

// UI meta for the 3 supported AI providers (colors + icon glyph)
$aiProviderUi = [
    'openai' => [
        'color' => '#10a37f',
        'icon' => 'psychology',
        'subtitle' => 'GPT models',
    ],
    'gemini' => [
        'color' => '#8b5cf6',
        'icon' => 'auto_awesome',
        'subtitle' => 'Google AI',
    ],
    'claude' => [
        'color' => '#d97757',
        'icon' => 'neurology',
        'subtitle' => 'Anthropic',
    ],
];

$aiProviderHasKey = [];
foreach (array_keys($registry) as $providerKey) {
    $savedProvider = $ai['providers_config'][$providerKey] ?? [];
    $aiProviderHasKey[$providerKey] = trim((string) ($savedProvider['api_key'] ?? '')) !== '';
}
$aiActiveProviderHasKey = !empty($aiProviderHasKey[$aiActiveProvider]);

$aiModelsList = [
    'openai' => [
        'gpt-5', 'gpt-5-mini', 'gpt-5-nano',
        'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano',
        'gpt-4o', 'gpt-4o-mini',
        'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo',
        'o3', 'o3-pro', 'o4-mini',
    ],
    'gemini' => [
        'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite',
        'gemini-2.0-flash', 'gemini-2.0-flash-lite',
        'gemini-1.5-pro', 'gemini-1.5-flash',
    ],
    'claude' => [
        'claude-opus-4', 'claude-sonnet-4',
        'claude-3-7-sonnet',
        'claude-3-5-sonnet', 'claude-3-5-haiku',
        'claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku',
    ],
];
?>
<div x-data="aiSettingsTab({
    provider: '<?= htmlspecialchars($aiActiveProvider, ENT_QUOTES) ?>',
    passport: <?= ($aiFeatureModules['passport']['enabled'] && $aiActiveProviderHasKey) ? 'true' : 'false' ?>,
    passportLocal: <?= !empty($aiFeatureModules['passport_local']['enabled']) ? 'true' : 'false' ?>,
    trip: <?= ($aiFeatureModules['trip']['enabled'] && $aiActiveProviderHasKey) ? 'true' : 'false' ?>,
    providerKeys: <?= htmlspecialchars(
        (string) json_encode($aiProviderHasKey, JSON_UNESCAPED_SLASHES),
        ENT_QUOTES,
        'UTF-8'
    ) ?>
})">
    <input type="hidden" name="ai_provider" :value="activeProvider">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">AI Modules</h3>
        </div>
        <!-- <div class="flex items-center gap-4">
            <div class="text-center">
                <div class="text-2xl font-bold text-green-600" x-text="activeCount"><?= $aiActiveCount ?></div>
                <div class="text-xs text-gray-500">Active</div>
            </div>
            <div class="text-center">
                <div class="text-2xl font-bold text-gray-900"><?= $aiTotalCount ?></div>
                <div class="text-xs text-gray-500">Total</div>
            </div>
        </div> -->
    </div>

    <!-- Feature cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <?php foreach ($aiFeatureModules as $mod):
            $color = $mod['color'];
            $isPassport = $mod['key'] === 'passport';
            $isPassportLocal = $mod['key'] === 'passport_local';
            $needsKey = !empty($mod['needs_key']);
            $model = $mod['model'];
            ?>
            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
                <div class="p-3" style="background: linear-gradient(135deg, <?= $color ?>15 0%, <?= $color ?>05 100%);">
                    <div class="flex justify-between items-center gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center shrink-0"
                                 style="background-color: <?= $color ?>20;">
                                <span class="material-symbols-outlined text-2xl" style="color: <?= $color ?>;"><?= $mod['icon'] ?></span>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($mod['name']) ?></h3>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($mod['type']) ?></p>
                            </div>
                        </div>
                        <?php if ($needsKey): ?>
                        <label class="switch-container switch-md switch-blue shrink-0"
                               :class="!activeProviderHasKey ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'"
                               :title="!activeProviderHasKey ? 'First add an API key for the selected provider' : ''"
                               @click="guardFeatureToggle($event)">
                            <input type="checkbox"
                                   name="<?= htmlspecialchars($mod['field']) ?>"
                                   id="<?= htmlspecialchars($mod['field']) ?>"
                                   value="1"
                                   class="switch-input"
                                   :disabled="!activeProviderHasKey"
                                   @change="onPassportModeChange('<?= $isPassport ? 'ai' : 'trip' ?>')"
                                   x-model="<?= $model ?>">
                            <div class="switch-track">
                                <div class="switch-thumb"></div>
                            </div>
                        </label>
                        <?php else: ?>
                        <label class="switch-container switch-md switch-blue shrink-0 cursor-pointer"
                               title="Does not require an AI API key">
                            <input type="checkbox"
                                   name="<?= htmlspecialchars($mod['field']) ?>"
                                   id="<?= htmlspecialchars($mod['field']) ?>"
                                   value="1"
                                   class="switch-input"
                                   @change="onPassportModeChange('local')"
                                   x-model="<?= $model ?>">
                            <div class="switch-track">
                                <div class="switch-thumb"></div>
                            </div>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="p-4 space-y-3">
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($mod['desc']) ?></p>
                    <?php if ($needsKey): ?>
                    <div class="default-warning" x-show="!activeProviderHasKey" x-cloak>
                        <p class="text-xs text-yellow-600 mt-1">First add the API key</p>
                    </div>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium"
                          x-show="activeProviderHasKey"
                          x-cloak
                          :class="<?= $model ?> ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
                        <span class="w-1.5 h-1.5 rounded-full"
                              :class="<?= $model ?> ? 'bg-green-500' : 'bg-gray-400'"></span>
                        <span x-text="<?= $model ?> ? 'Active' : 'Inactive'"></span>
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium"
                          :class="<?= $model ?> ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
                        <span class="w-1.5 h-1.5 rounded-full"
                              :class="<?= $model ?> ? 'bg-green-500' : 'bg-gray-400'"></span>
                        <span x-text="<?= $model ?> ? 'Active' : 'Inactive'"></span>
                    </span>
                    <?php endif; ?>

                    <?php if ($isPassport): ?>
                        <!-- <div class="pt-3 border-t border-gray-100 space-y-3" x-show="passportEnabled" x-cloak>
                            <div class="form-control">
                                <label for="ai_max_file_size_mb" class="text-xs text-gray-500">Max file size (MB)</label>
                                <input type="number" min="1" max="20" step="0.5" name="ai_max_file_size_mb" id="ai_max_file_size_mb"
                                    value="<?= htmlspecialchars((string) $maxFileSizeMb) ?>" class="input" placeholder="5">
                            </div>
                            <div class="form-control">
                                <label for="ai_allowed_file_types" class="text-xs text-gray-500">Allowed file types</label>
                                <input type="text" name="ai_allowed_file_types" id="ai_allowed_file_types"
                                    value="<?= htmlspecialchars($allowedTypes) ?>" class="input" placeholder="jpg,jpeg,png,webp">
                            </div>
                        </div> -->
                    <?php elseif ($isPassportLocal): ?>
                        
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Shared provider -->
    <div>
        <div class="section">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5">
                <div class="flex items-start gap-2 min-w-0">
                    <div class="w-6 h-6 bg-indigo-50 rounded flex items-center justify-center shrink-0 mt-0.5">
                        <span class="material-symbols-outlined text-blue-600 text-sm">hub</span>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-base font-medium text-gray-900">Provider</h3>
                        <p class="text-xs text-gray-500">Shared by Passport Scanner and Trip Planner. Select a tab and save to activate it.</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 mb-5 border-b border-gray-100 pb-4">
                <?php foreach ($registry as $key => $meta):
                    $ui = $aiProviderUi[$key] ?? ['icon' => 'smart_toy', 'subtitle' => ''];
                    ?>
                    <button type="button"
                        class="btn shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2 px-6 py-3 rounded-lg"
                        :class="activeProvider === '<?= $key ?>'
                            ? 'bg-blue-600 hover:bg-blue-700 text-white'
                            : 'white'"
                        @click="selectProvider('<?= $key ?>')">
                        <span class="material-symbols-outlined text-sm"><?= htmlspecialchars($ui['icon']) ?></span>
                        <span class="font-medium"><?= htmlspecialchars($meta['label']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($registry as $key => $meta):
                $saved = $ai['providers_config'][$key] ?? ['api_key' => '', 'api_secret' => '', 'endpoint' => '', 'model' => ''];
                $defaultEndpoint = (string) $meta['default_endpoint'];
                $defaultModel = (string) $meta['default_model'];
                ?>
                <div x-show="activeProvider === '<?= $key ?>'" x-cloak class="space-y-4">
                    <div class="flex flex-col lg:flex-row lg:items-end gap-3">
                        <div class="form-control flex-1 min-w-0">
                            <label class="flex justify-between items-center">
                                <span>API Key</span>
                            </label>
                            <input type="password" name="providers[<?= $key ?>][api_key]" class="input"
                                autocomplete="new-password"
                                @input="setProviderKey('<?= $key ?>', $event.target.value)"
                                placeholder="Enter API key"
                                value="<?= htmlspecialchars((string) $saved['api_key']) ?>">
                        </div>

                        <?php if (!empty($meta['needs_model']) || $defaultModel !== '' || (string) $saved['model'] !== ''): ?>
                        <div class="form-control w-full lg:w-48 shrink-0">
                            <label>Model</label>
                            <?php if (isset($aiModelsList[$key])): ?>
                            <select name="providers[<?= $key ?>][model]" class="select">
                                <?php foreach ($aiModelsList[$key] as $m): ?>
                                    <option value="<?= $m ?>" <?= ((string) $saved['model'] === $m || (empty($saved['model']) && $defaultModel === $m)) ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="text" name="providers[<?= $key ?>][model]" class="input"
                                value="<?= htmlspecialchars((string) $saved['model']) ?>"
                                placeholder="<?= $defaultModel !== '' ? htmlspecialchars($defaultModel) : 'Optional' ?>">
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="providers[<?= $key ?>][model]" value="">
                        <?php endif; ?>

                        <div class="form-control shrink-0">
                            <label class="invisible select-none hidden lg:block">&nbsp;</label>
                            <button type="button" @click="testApi('<?= $key ?>')"
                                class="btn shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center space-x-2 px-5 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white whitespace-nowrap w-full lg:w-auto"
                                :disabled="isTesting">
                                <span class="material-symbols-outlined text-sm" :class="isTesting ? 'animate-spin' : ''">science</span>
                                <span class="font-medium" x-text="isTesting ? 'Testing…' : 'Test API Connection'">Test API Connection</span>
                            </button>
                        </div>
                    </div>

                    <?php if (!empty($meta['needs_secret'])): ?>
                    <div class="form-control max-w-xl">
                        <label>API Secret <span class="text-red-500">*</span></label>
                        <input type="password" name="providers[<?= $key ?>][api_secret]" class="input"
                            autocomplete="new-password"
                            placeholder="Enter API secret"
                            value="<?= htmlspecialchars((string) $saved['api_secret']) ?>">
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="providers[<?= $key ?>][api_secret]" value="">
                    <?php endif; ?>

                    <?php if (empty($meta['needs_endpoint']) && $defaultEndpoint === ''): ?>
                        <input type="hidden" name="providers[<?= $key ?>][endpoint]" value="<?= htmlspecialchars((string) $saved['endpoint']) ?>">
                    <?php else: ?>
                        <input type="hidden" name="providers[<?= $key ?>][endpoint]" value="<?= htmlspecialchars((string) $saved['endpoint']) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="text-center py-10 rounded-lg border border-dashed border-gray-200 bg-gray-50" x-show="!anyEnabled" x-cloak>
        <span class="material-symbols-outlined text-gray-400 text-5xl mb-2">apps</span>
        <h3 class="text-lg font-medium text-gray-900 mb-1">No modules active</h3>
        <p class="text-sm text-gray-500"
           x-text="activeProviderHasKey
             ? 'Turn on Passport Scanner, Local Passport Scanner, or AI Trip Planner above, then save settings.'
             : 'Add an API key for AI features, or enable Local Passport Scanner (no key required).'"></p>
    </div>
</div>

<script>
function aiSettingsTab(opts) {
    opts = opts || {};
    return {
        activeProvider: opts.provider || 'openai',
        passportEnabled: !!opts.passport,
        passportLocalEnabled: !!opts.passportLocal,
        tripEnabled: !!opts.trip,
        savedProviderKeys: Object.assign({}, opts.providerKeys || {}),
        providerKeyDrafts: {},
        apiKeyNotice: false,
        isTesting: false,
        get activeProviderHasKey() {
            return !!this.savedProviderKeys[this.activeProvider]
                || String(this.providerKeyDrafts[this.activeProvider] || '').trim() !== '';
        },
        get providerLabel() {
            const labels = {
                openai: 'OpenAI',
                gemini: 'Gemini',
                claude: 'Claude',
            };
            return labels[this.activeProvider]
                || String(this.activeProvider || 'selected provider').replace(/^\w/, (c) => c.toUpperCase());
        },
        get anyEnabled() {
            return this.passportEnabled || this.passportLocalEnabled || this.tripEnabled;
        },
        get activeCount() {
            return (this.passportEnabled ? 1 : 0)
                + (this.passportLocalEnabled ? 1 : 0)
                + (this.tripEnabled ? 1 : 0);
        },
        onPassportModeChange(mode) {
            if (mode === 'ai' && this.passportEnabled) {
                this.passportLocalEnabled = false;
            } else if (mode === 'local' && this.passportLocalEnabled) {
                this.passportEnabled = false;
            }
        },
        setProviderKey(provider, value) {
            this.providerKeyDrafts[provider] = String(value || '');
            if (provider === this.activeProvider && this.activeProviderHasKey) {
                this.apiKeyNotice = false;
            }
        },
        selectProvider(provider) {
            this.activeProvider = provider;
            if (!this.activeProviderHasKey) {
                this.passportEnabled = false;
                this.tripEnabled = false;
                this.apiKeyNotice = true;
            } else {
                this.apiKeyNotice = false;
            }
        },
        guardFeatureToggle(event) {
            if (this.activeProviderHasKey) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            this.passportEnabled = false;
            this.tripEnabled = false;
            this.apiKeyNotice = true;
            this.$nextTick(() => {
                const input = document.querySelector(
                    `input[name="providers[${this.activeProvider}][api_key]"]`
                );
                if (input) {
                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    window.setTimeout(() => input.focus({ preventScroll: true }), 350);
                }
            });
        },
        async testApi(provider) {
            if (this.isTesting) {
                return;
            }
            this.isTesting = true;

            const apiKeyInput = document.querySelector(`input[name="providers[${provider}][api_key]"]`);
            const apiSecretInput = document.querySelector(`input[name="providers[${provider}][api_secret]"]`);
            const apiKey = apiKeyInput ? apiKeyInput.value.trim() : '';
            const apiSecret = apiSecretInput ? apiSecretInput.value.trim() : '';

            const notify = (type, message) => {
                if (typeof vt !== 'undefined' && typeof vt[type] === 'function') {
                    vt[type](message);
                    return;
                }
                window.alert(message);
            };

            if (!apiKey && (!apiKeyInput || String(apiKeyInput.placeholder || '').includes('Enter'))) {
                notify('error', 'API Key is missing.');
                this.isTesting = false;
                return;
            }

            const formData = new FormData();
            formData.append('provider', provider);
            formData.append('api_key', apiKey || 'saved_key');
            formData.append('api_secret', apiSecret);

            try {
                const response = await fetch('<?=root.admin?>/settings/ai/test', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();
                if (data.success) {
                    notify('success', data.statusText || 'API key is valid and ready to use.');
                } else {
                    notify('error', data.statusText || 'Connection failed.');
                }
            } catch (err) {
                notify('error', err.message || 'Network error while testing API.');
            } finally {
                this.isTesting = false;
            }
        }
    };
}
</script>
