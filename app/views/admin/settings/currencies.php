<?php
// Make sure the currency_api_key column + currency_updates table exist, and load
// the current key (migrated from the old .env API_LAYER_KEY the first time).
ensureCurrencyUpdateSchema($db);
$currencyApiKey = (string) ($db->get('settings', 'currency_api_key', ['id' => 1]) ?? '');
$cronUrl = root . 'update_currency_rates';
?>
<div class="container my-4" x-data="currencyUpdater()">

<!-- Success/Error Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
        <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
        <div><?= $_SESSION['message']['text'] ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<!-- Exchange Rate API key + Cron -->
<div class="card w-full p-4 mb-4 bg-white">
    <!-- Header: title + documentation on the left, Update Rates action on the right -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-blue-600">key</span>
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Exchange Rate API</h3>
                <p class="text-xs text-gray-500 mt-0.5">
                    <?= T::update_rates_info ?? 'Fetch the latest exchange rates for all currencies.' ?>
                    <a href="https://docs.phptravels.com/startup/setup/settings/currencies" target="_blank" class="underline ml-1 text-primary-600"><?= T::documentation ?? 'Learn More' ?></a>
                </p>
            </div>
        </div>
        <button type="button" @click="updateCurrencies()" :disabled="updating"
            class="btn primary inline-flex items-center gap-2 shrink-0" :class="{ 'opacity-50 cursor-not-allowed': updating }">
            <template x-if="!updating">
                <span class="material-symbols-outlined">refresh</span>
            </template>
            <template x-if="updating">
                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </template>
            <span x-text="updating ? 'Updating Rates...' : '<?= T::update_rates ?? 'Update Exchange Rates' ?>'"></span>
        </button>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- API key -->
        <div>
            <label class="text-xs font-medium text-gray-600">currencylayer API Access Key</label>
            <div class="flex gap-2 mt-1">
                <input type="text" x-model="apiKey" class="input flex-1" placeholder="Enter your currencylayer access key">
                <button type="button" @click="saveApiKey()" :disabled="savingKey"
                    class="btn primary" :class="{ 'opacity-50 cursor-not-allowed': savingKey }">
                    <span x-text="savingKey ? 'Saving...' : 'Update'"></span>
                </button>
            </div>
            <p class="text-xs text-gray-400 mt-1">Stored in the database. Get a free access key from currencylayer.com.</p>
        </div>
        <!-- Cron link -->
        <div>
            <label class="text-xs font-medium text-gray-600">Cron URL — run daily to auto-update all rates</label>
            <div class="flex gap-2 mt-1">
                <input type="text" readonly value="<?= htmlspecialchars($cronUrl) ?>" x-ref="cronUrl"
                    class="input flex-1 bg-gray-50 font-mono text-xs">
                <button type="button" @click="copyCron()" class="btn white" title="Copy">
                    <span class="material-symbols-outlined text-sm" x-text="copied ? 'check' : 'content_copy'"></span>
                </button>
            </div>
            <p class="text-xs text-gray-400 mt-1 font-mono">0 3 * * * curl -s "<?= htmlspecialchars($cronUrl) ?>"</p>
        </div>
    </div>
</div>

<?php
echo crud()->table('currencies')
    ->col('name,country,rate')
    ->title('Currencies')
    ->actions([
        'view' => false,
        'delete' => true,
        'edit' => true,
        'status' => true,
        'default' => true,
    ])
    ->action_urls([
        'add' => admin.'/settings/currencies/add',
        'edit' => root.admin.'/settings/currencies/edit/{id}',
    ])
    ->id_column('id')
    ->relation('country', 'countries', 'nicename', 'iso')
    ->col_width('name', '70px')
    ->col_width('country', '100px')
    ->render();
?>

</div>

<!-- Currency Default Warning Modal -->
<div id="currencyDefaultModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" style="display: none;">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-lg bg-white">
        <div class="mt-3">
            <!-- Icon -->
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100">
                <svg class="h-6 w-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            
            <!-- Title -->
            <h3 class="text-lg font-semibold text-gray-900 text-center mt-4">
                Set Default Currency
            </h3>
            
            <!-- Message -->
            <div class="mt-4 px-3">
                <p class="text-sm text-gray-600 mb-3">
                    <strong class="text-gray-900">Important:</strong> Setting a new default currency will:
                </p>
                <ul class="list-disc list-inside text-sm text-gray-600 space-y-2 ml-2">
                    <li>Set the exchange rate of the selected currency to <strong class="text-gray-900">1.00</strong></li>
                    <li>This currency will become the <strong class="text-gray-900">base currency</strong> for the entire system</li>
                    <li>All previous invoices, billing, and transactions will <strong class="text-red-600">use this new currency</strong> as default going forward</li>
                    <li>Existing transactions keep their original currency values</li>
                </ul>
                <p class="text-sm text-yellow-700 mt-4 bg-yellow-50 p-3 rounded border border-yellow-200">
                    ⚠️ This is a system-wide change. Please confirm you want to proceed.
                </p>
            </div>

            <!-- Buttons -->
            <div class="flex gap-3 mt-6 px-3 pb-3">
                <button id="cancelDefaultChange" class="flex-1 px-4 py-2 bg-gray-200 text-gray-800 text-sm font-medium rounded-lg hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-400">
                    Cancel
                </button>
                <button id="confirmDefaultChange" class="flex-1 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    Confirm & Set Default
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function currencyUpdater() {
    return {
        updating: false,
        apiKey: <?= json_encode($currencyApiKey) ?>,
        savingKey: false,
        copied: false,
        saveApiKey() {
            if (this.savingKey) return;
            this.savingKey = true;
            fetch('<?= root ?>admin/settings/currencies/save-api-key', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'currency_api_key=' + encodeURIComponent(this.apiKey)
            })
            .then(r => r.json())
            .then(res => {
                this.savingKey = false;
                alert(res.message || (res.success ? 'Saved' : 'Failed to save'));
            })
            .catch(e => { this.savingKey = false; alert('Error: ' + e.message); });
        },
        copyCron() {
            navigator.clipboard.writeText(this.$refs.cronUrl.value).then(() => {
                this.copied = true;
                setTimeout(() => this.copied = false, 1500);
            });
        },
        updateCurrencies() {
            if (this.updating) return;
            if (!confirm('⚠️ Warning: Update Exchange Rates\n\nThe default currency price will be used to calculate rates for all other currencies based on current exchange rates.\n\nThis action will overwrite existing currency rates.\n\nDo you want to continue?')) return;
            
            this.updating = true;
            
            // Set 25 second timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 25000);
            
            fetch('<?= root ?>/admin/settings/currencies/update-rates', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'update_rates=true',
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error(text || `HTTP error! status: ${response.status}`);
                    });
                }
                return response.text();
            })
            .then(data => {
                // Try to parse as JSON to check for error messages
                try {
                    const json = JSON.parse(data);
                    if (json.error || json.status === 'error' || json.success === false) {
                        throw new Error(json.message || json.error || 'Update failed');
                    }
                    // Success - reload page
                    location.reload();
                } catch (parseError) {
                    // If JSON parsing fails, check if it contains error indicators
                    if (data.toLowerCase().includes('error') || data.toLowerCase().includes('fail')) {
                        throw new Error('Server error: ' + data.substring(0, 200));
                    }
                    // Otherwise assume success and reload
                    location.reload();
                }
            })
            .catch(err => {
                clearTimeout(timeoutId);
                this.updating = false;
                
                let errorMsg = 'Error updating rates. ';
                
                if (err.name === 'AbortError') {
                    errorMsg = '⏱️ Request timeout (25s exceeded). The exchange rate API is not responding. Please check:\n\n' +
                              '• Your internet connection\n' +
                              '• API service status\n' +
                              '• Try again later';
                } else if (err.message.includes('API key') || err.message.includes('credential') || err.message.includes('authentication')) {
                    errorMsg = '🔑 Authentication Error:\n\n' + err.message + '\n\nPlease check your API credentials in settings.';
                } else if (err.message.includes('expired') || err.message.includes('invalid key')) {
                    errorMsg = '⚠️ API Key Issue:\n\n' + err.message + '\n\nYour API key may be expired or invalid. Please update it.';
                } else if (err.message.includes('limit') || err.message.includes('quota')) {
                    errorMsg = '📊 API Limit Reached:\n\n' + err.message + '\n\nYou may have exceeded your API quota.';
                } else if (err.message.includes('NetworkError') || err.message.includes('Failed to fetch')) {
                    errorMsg = '🌐 Network Error:\n\nCannot connect to exchange rate service. Please check your internet connection.';
                } else {
                    errorMsg += err.message || 'Unknown error occurred.';
                }
                
                alert(errorMsg);
                console.error('Currency update error:', err);
            });
        }
    }
}

// Currency Default Toggle - Override CRUD.php behavior
$(document).ready(function() {
    let pending = null;
    let processing = false;
    const $modal = $('#currencyDefaultModal');
    
    // Store initial checked state for each switch
    $('.toggle-default-switch[data-table="currencies"]').each(function() {
        $(this).data('was-checked', $(this).prop('checked'));
    });
    
    // Intercept ALL events on currency default switches
    $(document).on('click change mousedown', '.toggle-default-switch[data-table="currencies"]', function(e) {
        if (processing) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
        
        e.preventDefault();
        e.stopImmediatePropagation();
        
        const $switch = $(this);
        const wasChecked = $switch.data('was-checked') || false;
        
        // If clicking an already-default currency (trying to turn it off), prevent it
        if (wasChecked) {
            $switch.prop('checked', true);
            vt.error('Cannot disable the current default currency');
            return false;
        }
        
        // Store pending change and show modal
        pending = { id: $switch.data('id'), $switch: $switch };
        $switch.prop('checked', false); // Keep it unchecked until confirmed
        $modal.show().removeClass('hidden');
        
        return false;
    });
    
    // Cancel
    $('#cancelDefaultChange').click(() => {
        if (pending) pending.$switch.prop('checked', false);
        pending = null;
        $modal.hide().addClass('hidden');
    });
    
    // Confirm
    $('#confirmDefaultChange').click(function() {
        if (!pending || processing) return;
        
        processing = true;
        const {id, $switch} = pending;
        const $all = $('.toggle-default-switch[data-table="currencies"]');
        
        $(this).prop('disabled', true).text('Processing...');
        $modal.hide().addClass('hidden');
        
        $.post({
            url: '<?= root ?>ajax',
            contentType: 'application/json',
            data: JSON.stringify({action: 'set_default', table: 'currencies', id: id}),
            success: (data) => {
                if (data.status === 'success') {
                    // Update UI immediately
                    $all.each(function() {
                        $(this).prop('checked', false);
                        $(this).data('was-checked', false);
                    });
                    $switch.prop('checked', true);
                    $switch.data('was-checked', true);
                    
                    vt.success('Default currency updated! Rate set to 1.00');
                    processing = false;
                    $(this).prop('disabled', false).text('Confirm & Set Default');
                    
                    // Reload after a delay to show updated rate
                    setTimeout(() => location.reload(), 1500);
                } else {
                    vt.error(data.message || 'Update failed');
                    $switch.prop('checked', false);
                    $(this).prop('disabled', false).text('Confirm & Set Default');
                    processing = false;
                }
            },
            error: () => {
                vt.error('Connection error');
                $switch.prop('checked', false);
                $(this).prop('disabled', false).text('Confirm & Set Default');
                processing = false;
            },
            complete: () => { pending = null; }
        });
    });
    
    // Close on outside click
    $modal.click((e) => { if (e.target === $modal[0]) $('#cancelDefaultChange').click(); });
});
</script>