<?php
/**
 * Passport scan UI block for one passenger.
 * Expects: $passportScanPassengerKey (e.g. adult_0)
 * Optional: $passportAiEnabled, $passportLocalEnabled — either enables the block.
 */
@$SECURE or die('Access Denied!');

$passportScanEnabled = !empty($passportScanEnabled)
    || !empty($passportAiEnabled)
    || !empty($passportLocalEnabled);
if (!$passportScanEnabled) {
    return;
}
$key = $passportScanPassengerKey ?? '';
if ($key === '') {
    return;
}
$keyEsc = htmlspecialchars($key, ENT_QUOTES);
$isLocalMode = empty($passportAiEnabled) && !empty($passportLocalEnabled);
?>
<div class="mb-4 pb-4 ">
    <div class="flex flex-col gap-3">
        <div class="flex items-start gap-2">
            <span class="material-symbols-outlined text-gray-600 dark:text-gray-300 text-xl shrink-0">document_scanner</span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Scan Passport to Auto-Fill Details</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <?php if ($isLocalMode): ?>
                        Upload the passport info page. Processing runs on this device (MRZ preferred). Always review autofilled fields.
                    <?php else: ?>
                        Upload the passport info page. Keep the full page visible, avoid glare, and include the MRZ.
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 w-full">
            <button type="button"
                class="btn light text-xs py-2 px-3 w-full"
                :disabled="passportAiBusy('<?= $keyEsc ?>')"
                @click="openPassportUpload('<?= $keyEsc ?>')">
                <span class="material-symbols-outlined text-sm">upload</span>
                <span>Upload Passport Image</span>
            </button>
            <button type="button"
                class="btn light text-xs py-2 px-3 w-full"
                :disabled="passportAiBusy('<?= $keyEsc ?>')"
                @click="openPassportCamera('<?= $keyEsc ?>')">
                <span class="material-symbols-outlined text-sm">photo_camera</span>
                <span>Use Camera</span>
            </button>
        </div>
    </div>

    <!-- Gallery / file picker (no capture) -->
    <input type="file"
        class="hidden"
        accept="image/jpeg,image/png,image/webp,image/jpg,.jpg,.jpeg,.png,.webp"
        x-ref="passportUpload_<?= $keyEsc ?>"
        @change="onPassportFileSelected('<?= $keyEsc ?>', $event)">

    <!-- Native camera capture fallback (mobile) -->
    <input type="file"
        class="hidden"
        accept="image/*"
        capture="environment"
        x-ref="passportCamera_<?= $keyEsc ?>"
        @change="onPassportFileSelected('<?= $keyEsc ?>', $event)">

    <div x-show="passportAi['<?= $keyEsc ?>']?.preview || passportAiBusy('<?= $keyEsc ?>')"
         class="mt-3 relative w-full rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-900"
         style="min-height: 320px;"
         x-cloak>
        <img x-show="passportAi['<?= $keyEsc ?>']?.preview"
             :src="passportAi['<?= $keyEsc ?>']?.preview"
             alt="Passport preview"
             class="absolute inset-0 w-full h-full object-cover">

        <!-- Loading overlay -->
        <div x-show="passportAiBusy('<?= $keyEsc ?>')"
             class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-white/80 dark:bg-gray-900/80"
             x-cloak>
            <span class="material-symbols-outlined text-3xl text-gray-600 dark:text-gray-300 animate-spin">progress_activity</span>
            <p class="text-xs font-medium text-gray-700 dark:text-gray-200">Reading passport details…</p>
        </div>
    </div>

    <button type="button"
        class="btn text-xs py-1 px-2 h-auto mt-2"
        @click="clearPassportScan('<?= $keyEsc ?>')"
        x-show="passportAi['<?= $keyEsc ?>']?.preview && !passportAiBusy('<?= $keyEsc ?>')"
        x-cloak>
        Remove / Retake
    </button>

    <p class="text-xs text-green-700 dark:text-green-400 mt-2" x-show="passportAi['<?= $keyEsc ?>']?.status === 'success'" x-cloak
       x-text="passportAi['<?= $keyEsc ?>']?.message"></p>
    <p class="text-xs text-amber-700 dark:text-amber-400 mt-2" x-show="passportAi['<?= $keyEsc ?>']?.warning" x-cloak
       x-text="passportAi['<?= $keyEsc ?>']?.warning"></p>
    <p class="text-xs text-red-600 dark:text-red-400 mt-2" x-show="passportAi['<?= $keyEsc ?>']?.error" x-cloak
       x-text="passportAi['<?= $keyEsc ?>']?.error"></p>
</div>
