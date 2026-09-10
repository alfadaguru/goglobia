<?php
// themes/default/includes/footer.php
@$SECURE or die('Access Denied!');

// Get current language
$currentLang = $_SESSION['app_language'] ?? 'en';
$footer_pages = (cms($db, "footer", "headerfooter"));

// Single loop with deferred parent processing
$footer_menus = [];
$temp = ['parents' => [], 'children' => []];

foreach ($footer_pages as $page) {
    $page['parent_id']
        ? $temp['children'][$page['parent_id']][] = $page
        : $temp['parents'][$page['id']] = $page;
}

// Convert to final structure using array_map (functional approach)
$footer_menus = array_values(array_filter(array_map(
    fn($id, $parent) => isset($temp['children'][$id])
    ? ['parent' => $parent, 'children' => $temp['children'][$id]]
    : null,
    array_keys($temp['parents']),
    $temp['parents']
)));

?>

<?php if (($footer ?? true) !== false) { ?>
    <!-- ============================================================================
     TRAVEL AGENCY FOOTER - PREMIUM MODERN DESIGN
     ============================================================================ -->

    <footer class="w-full border-t" style="background-color: var(--footer-background); border-color: var(--footer-border-color);">

            <!-- ================================================================
         MAIN FOOTER CONTENT SECTION
         ================================================================ -->
            <div class="container mx-auto px-4 py-12 lg:py-16">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-12">

                    <!-- ============================================================
                 COMPANY BRAND & ABOUT SECTION (4 columns)
                 ============================================================ -->
                <div class="lg:col-span-4">
                    <!-- BRAND: FAVICON + BUSINESS NAME (FROM SETTINGS) -->
                    <div class="mb-6">
                        <div class="mb-4">
                            <a href="<?= root ?>" class="flex items-start">
                                <img src="<?= versionedAssetUrl('uploads/global/logo.png') ?>"
                                     alt="<?= isset($GLOBALS['app']['business_name']) ? $GLOBALS['app']['business_name'] : '' ?>"
                                     class="h-8 w-auto" width="160" height="40" decoding="async">
                            </a>
                        </div>
                        <p class="text-sm leading-relaxed" style="color: var(--footer-text-color);">
                            <?= T::footer_trusted_partner ?>
                        </p>
                    </div>


                    <!-- 24/7 Support Card — horizontal row layout -->
                    <div class="border border-l-4 px-3 py-2.5 rounded-lg flex items-center justify-between gap-3" style="background-color: color-mix(in srgb, var(--footer-background) 96%, var(--footer-text-color) 4%); border-color: var(--footer-border-color); border-left-color: var(--footer-link-color);">
                        <!-- Left: icon + text -->
                        <div class="flex items-center gap-2.5 shrink-0">
                            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-white" style="font-size:18px;">headset_mic</span>
                            </div>
                            <div>
                                <p class="font-bold text-sm leading-tight" style="color: var(--footer-heading-color);"><?= T::footer_support_24_7 ?></p>
                                <p class="text-xs" style="color: var(--footer-text-color);"><?= T::footer_always_here ?></p>
                            </div>
                        </div>
                        <!-- Right: contact icon buttons (matches Get in Touch style) -->
                        <div class="flex items-center gap-2">
                            <?php if (isset($GLOBALS['app']['contact_email'])): ?>
                                <a href="mailto:<?= $GLOBALS['app']['contact_email'] ?>"
                                   class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200"
                                   style="background-color: color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%); border: 1px solid var(--footer-border-color); color: var(--footer-text-color);"
                                   onmouseover="this.style.backgroundColor='var(--color-primary, #2563eb)'; this.style.borderColor='var(--color-primary, #2563eb)'; this.style.color='#ffffff';"
                                   onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%)'; this.style.borderColor='var(--footer-border-color)'; this.style.color='var(--footer-text-color)';"
                                   aria-label="Email Support">
                                    <span class="material-symbols-outlined" style="font-size:22px;">mail</span>
                                </a>
                            <?php endif; ?>
                            <a href="https://wa.me/<?= preg_replace('/\D/', '', $GLOBALS['app']['contact_phone'] ?? '') ?>"
                               target="_blank"
                               class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200"
                               style="background-color: color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%); border: 1px solid var(--footer-border-color); color: var(--footer-text-color);"
                               onmouseover="this.style.backgroundColor='var(--color-primary, #2563eb)'; this.style.borderColor='var(--color-primary, #2563eb)'; this.style.color='#ffffff';"
                               onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%)'; this.style.borderColor='var(--footer-border-color)'; this.style.color='var(--footer-text-color)';"
                               aria-label="WhatsApp Support">
                                <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            </a>
                        </div>
                    </div>
                </div>

                    <!-- ============================================================
                 CMS FOOTER MENUS (5 columns)
                 ============================================================ -->
                    <?php if (!empty($footer_menus)): ?>
                            <div class="lg:col-span-5">
                                <div class="grid grid-cols-2 md:grid-cols-<?= min(count($footer_menus), 3) ?> gap-8">
                                    <?php foreach ($footer_menus as $menu): ?>
                                            <div>
                                                <h4 class="text-sm font-bold uppercase tracking-wider mb-4"
                                                    style="color: var(--footer-heading-color);">
                                                    <?= htmlspecialchars(getTranslatedPageName($menu['parent'], $currentLang)) ?>
                                                </h4>
                                                <ul class="space-y-2.5">
                                                    <?php foreach ($menu['children'] as $child): ?>
                                                            <li>
                                                                <a href="<?= root ?>page/<?= htmlspecialchars($child['slug_url']) ?>"
                                                                    class="relative inline-block no-underline pb-[2px] text-sm after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-full after:h-[2px] after:rounded-[1px] after:bg-[var(--footer-link-color,#2563eb)] after:scale-x-0 after:origin-left after:transition-transform after:duration-500 after:ease-in-out hover:after:scale-x-100"
                                                                    style="color: var(--footer-text-color);"
                                                                    onmouseover="this.style.color='var(--footer-link-color)'"
                                                                    onmouseout="this.style.color='var(--footer-text-color)'">
                                                                    <?= htmlspecialchars(getTranslatedPageName($child, $currentLang)) ?>
                                                                </a>
                                                            </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                    <?php endif; ?>

                    <!-- ============================================================
                 CONTACT INFORMATION SECTION (3 columns)
                 ============================================================ -->
                    <div class="lg:col-span-3">
                        <h4 class="text-sm font-bold uppercase tracking-wider mb-4" style="color: var(--footer-heading-color);">
                            <?= T::footer_get_in_touch ?>
                        </h4>

                        <div class="space-y-3">
                            <?php if (isset($GLOBALS['app']['site_offline']) && $GLOBALS['app']['site_offline'] == true): ?>
                                    <div class="bg-red-50 border border-red-200 p-3 rounded-2xl">
                                        <div class="flex items-start gap-2">
                                            <span class="material-symbols-outlined text-red-600 text-base flex-shrink-0">warning</span>
                                            <div>
                                                <p class="text-red-900 text-xs font-semibold"><?= T::footer_currently_offline ?></p>
                                                <p class="text-red-600 text-xs mt-0.5">
                                                    <?= isset($GLOBALS['app']['offline_message']) ? $GLOBALS['app']['offline_message'] : '' ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                            <?php endif; ?>

                            <!-- Address -->
                            <?php if (isset($GLOBALS['app']['address'])): ?>
                                    <div class="flex items-start gap-2">
                                        <span class="material-symbols-outlined text-blue-600 text-lg flex-shrink-0">location_on</span>
                                        <p class="text-sm leading-relaxed" style="color: var(--footer-text-color);">
                                            <?= isset($GLOBALS['app']['address']) ? $GLOBALS['app']['address'] : '' ?>
                                            <?php if (isset($GLOBALS['app']['city'])): ?>,
                                                    <?= isset($GLOBALS['app']['city']) ? $GLOBALS['app']['city'] : '' ?>                <?php endif; ?>
                                            <?php if (isset($GLOBALS['app']['state'])): ?>,
                                                    <?= isset($GLOBALS['app']['state']) ? $GLOBALS['app']['state'] : '' ?>                <?php endif; ?>
                                            <?php if (isset($GLOBALS['app']['post_code'])): ?>
                                                    <?= isset($GLOBALS['app']['post_code']) ? $GLOBALS['app']['post_code'] : '' ?>                <?php endif; ?>
                                            <?php if (isset($GLOBALS['app']['country_name'])): ?><br><?= isset($GLOBALS['app']['country_name']) ? $GLOBALS['app']['country_name'] : '' ?><?php endif; ?>
                                        </p>
                                    </div>
                            <?php endif; ?>

                            <!-- Phone -->
                            <?php if (isset($GLOBALS['app']['contact_phone'])): ?>
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-blue-600 text-lg flex-shrink-0">call</span>
                                        <a href="tel:<?= isset($GLOBALS['app']['contact_phone']) ? $GLOBALS['app']['contact_phone'] : '' ?>"
                                            class="transition-colors duration-200 text-sm font-medium"
                                            style="color: var(--footer-text-color);"
                                            onmouseover="this.style.color='var(--footer-link-color)'"
                                            onmouseout="this.style.color='var(--footer-text-color)'">
                                            <?= isset($GLOBALS['app']['contact_phone']) ? $GLOBALS['app']['contact_phone'] : '' ?>
                                        </a>
                                    </div>
                            <?php endif; ?>

                            <!-- Email -->
                            <?php if (isset($GLOBALS['app']['contact_email'])): ?>
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-blue-600 text-lg flex-shrink-0">mail</span>
                                        <a href="mailto:<?= isset($GLOBALS['app']['contact_email']) ? $GLOBALS['app']['contact_email'] : '' ?>"
                                            class="transition-colors duration-200 text-sm font-medium break-all"
                                            style="color: var(--footer-text-color);"
                                            onmouseover="this.style.color='var(--footer-link-color)'"
                                            onmouseout="this.style.color='var(--footer-text-color)'">
                                            <?= isset($GLOBALS['app']['contact_email']) ? $GLOBALS['app']['contact_email'] : '' ?>
                                        </a>
                                    </div>
                            <?php endif; ?>

                            <!-- Social Media Links -->
                            <?php
                            $socialMediaJson = $GLOBALS['app']['social_media'] ?? '{}';
                            $socialMedia = json_decode($socialMediaJson, true);
                            if (!is_array($socialMedia)) {
                                $socialMedia = [];
                            }
                            ?>
                            <div class="mt-4 pt-4 border-t" style="border-color: var(--footer-border-color);">
                                <div class="flex gap-2">
                                    <a target="_blank" <?php if (isset($socialMedia['facebook'])): ?>href="<?= $socialMedia['facebook'] ?>" <?php endif; ?>
                                        class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200"
                                        style="background-color: color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%); border: 1px solid var(--footer-border-color); color: var(--footer-text-color);"
                                        onmouseover="this.style.backgroundColor='var(--color-primary, #2563eb)'; this.style.borderColor='var(--color-primary, #2563eb)'; this.style.color='#ffffff';"
                                        onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%)'; this.style.borderColor='var(--footer-border-color)'; this.style.color='var(--footer-text-color)';"
                                        aria-label="Facebook">
                                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                            <path
                                                d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
                                        </svg>
                                    </a>
                                    <a target="_blank" <?php if (isset($socialMedia['twitter'])): ?>href="<?= $socialMedia['twitter'] ?>" <?php endif; ?>
                                        class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200"
                                        style="background-color: color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%); border: 1px solid var(--footer-border-color); color: var(--footer-text-color);"
                                        onmouseover="this.style.backgroundColor='var(--color-primary, #2563eb)'; this.style.borderColor='var(--color-primary, #2563eb)'; this.style.color='#ffffff';"
                                        onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%)'; this.style.borderColor='var(--footer-border-color)'; this.style.color='var(--footer-text-color)';"
                                        aria-label="Twitter">
                                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                            <path
                                                d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
                                        </svg>
                                    </a>
                                    <a target="_blank" <?php if (isset($socialMedia['instagram'])): ?>href="<?= $socialMedia['instagram'] ?>" <?php endif; ?>
                                        class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200"
                                        style="background-color: color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%); border: 1px solid var(--footer-border-color); color: var(--footer-text-color);"
                                        onmouseover="this.style.backgroundColor='var(--color-primary, #2563eb)'; this.style.borderColor='var(--color-primary, #2563eb)'; this.style.color='#ffffff';"
                                        onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%)'; this.style.borderColor='var(--footer-border-color)'; this.style.color='var(--footer-text-color)';"
                                        aria-label="Instagram">
                                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                            <path
                                                d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" />
                                        </svg>
                                    </a>
                                    <a target="_blank" <?php if (isset($socialMedia['youtube'])): ?>href="<?= $socialMedia['youtube'] ?>" <?php endif; ?>
                                        class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200"
                                        style="background-color: color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%); border: 1px solid var(--footer-border-color); color: var(--footer-text-color);"
                                        onmouseover="this.style.backgroundColor='var(--color-primary, #2563eb)'; this.style.borderColor='var(--color-primary, #2563eb)'; this.style.color='#ffffff';"
                                        onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%)'; this.style.borderColor='var(--footer-border-color)'; this.style.color='var(--footer-text-color)';"
                                        aria-label="YouTube">
                                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                            <path
                                                d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
                                        </svg>
                                    </a>
                                    <a target="_blank" <?php if (isset($socialMedia['linkedin'])): ?>href="<?= $socialMedia['linkedin'] ?>" <?php endif; ?>
                                        class="w-9 h-9 rounded-full flex items-center justify-center transition-all duration-200"
                                        style="background-color: color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%); border: 1px solid var(--footer-border-color); color: var(--footer-text-color);"
                                        onmouseover="this.style.backgroundColor='var(--color-primary, #2563eb)'; this.style.borderColor='var(--color-primary, #2563eb)'; this.style.color='#ffffff';"
                                        onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%)'; this.style.borderColor='var(--footer-border-color)'; this.style.color='var(--footer-text-color)';"
                                        aria-label="LinkedIn">
                                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                            <path
                                                d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z" />
                                        </svg>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ================================================================
         FOOTER BOTTOM - Copyright, Legal Links & Trust Badges
         ================================================================ -->
            <div class="border-t"
                style="background-color: color-mix(in srgb, var(--footer-background) 94%, var(--footer-text-color) 6%); border-color: var(--footer-border-color);">
                <div class="container mx-auto px-4 py-6">
                    <!-- Responsive 3-Column Grid Layout -->
                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 lg:gap-6 items-center">

                    <!-- Column 1: Copyright & Powered By (Left on desktop, centered on mobile) -->
                    <div class="text-center xl:text-left">
                        <p class="text-sm font-medium" style="color: var(--footer-heading-color);">
                            © <?= date('Y') ?>     <?= isset($GLOBALS['app']['business_name']) ? $GLOBALS['app']['business_name'] : '' ?>. All rights reserved.
                        </p>
                        <p class="text-xs mt-1" style="color: var(--footer-text-color);">
                           Built with <span style="color:#e0245e;" aria-label="love">&hearts;</span> by <a href="https://goglobia.com" class="hover:underline font-semibold transition-colors" style="color: var(--footer-link-color);" target="_blank" rel="noopener">GoGlobia.com</a> &mdash; making travel easy for the world
                        </p>
                    </div>

                        <!-- Column 2: Legal Links (Center on desktop, centered on mobile) -->
                        <div class="flex flex-row flex-wrap sm:flex-nowrap justify-center items-center gap-1.5 text-[11px]">
                            <a href="<?= root ?>page/privacy-policy"
                                class="relative inline-block no-underline pb-[2px] font-medium whitespace-nowrap after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-full after:h-[2px] after:rounded-[1px] after:bg-[var(--footer-link-color,#2563eb)] after:scale-x-0 after:origin-left after:transition-transform after:duration-500 after:ease-in-out hover:after:scale-x-100"
                                style="color: var(--footer-text-color);"
                                onmouseover="this.style.color='var(--footer-link-color)'"
                                onmouseout="this.style.color='var(--footer-text-color)'">
                                Privacy Policy
                            </a>
                            <div class="flex items-center gap-1.5">
                                <span style="color: var(--footer-text-color); opacity: 0.4;">•</span>
                                <a href="<?= root ?>page/terms-of-use"
                                    class="relative inline-block no-underline pb-[2px] font-medium whitespace-nowrap after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-full after:h-[2px] after:rounded-[1px] after:bg-[var(--footer-link-color,#2563eb)] after:scale-x-0 after:origin-left after:transition-transform after:duration-500 after:ease-in-out hover:after:scale-x-100"
                                    style="color: var(--footer-text-color);"
                                    onmouseover="this.style.color='var(--footer-link-color)'"
                                    onmouseout="this.style.color='var(--footer-text-color)'">
                                    Terms of Use
                                </a>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span style="color: var(--footer-text-color); opacity: 0.4;">•</span>
                                <a href="<?= root ?>page/cookies-policy"
                                    class="relative inline-block no-underline pb-[2px] font-medium whitespace-nowrap after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-full after:h-[2px] after:rounded-[1px] after:bg-[var(--footer-link-color,#2563eb)] after:scale-x-0 after:origin-left after:transition-transform after:duration-500 after:ease-in-out hover:after:scale-x-100"
                                    style="color: var(--footer-text-color);"
                                    onmouseover="this.style.color='var(--footer-link-color)'"
                                    onmouseout="this.style.color='var(--footer-text-color)'">
                                    Cookies Policy
                                </a>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span style="color: var(--footer-text-color); opacity: 0.4;">•</span>
                                <a href="<?= root ?>page/refund-policy"
                                    class="relative inline-block no-underline pb-[2px] font-medium whitespace-nowrap after:content-[''] after:absolute after:bottom-0 after:left-0 after:w-full after:h-[2px] after:rounded-[1px] after:bg-[var(--footer-link-color,#2563eb)] after:scale-x-0 after:origin-left after:transition-transform after:duration-500 after:ease-in-out hover:after:scale-x-100"
                                    style="color: var(--footer-text-color);"
                                    onmouseover="this.style.color='var(--footer-link-color)'"
                                    onmouseout="this.style.color='var(--footer-text-color)'">
                                    Refund Policy
                                </a>
                            </div>
                        </div>

                        <!-- Column 3: Trust Badges (Right on desktop, centered on mobile) -->
                        <div class="flex flex-wrap justify-center xl:justify-end items-center gap-2">
                            <div class="flex items-center gap-1.5 border px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-full shadow-sm"
                                style="background-color: var(--footer-background); border-color: var(--footer-border-color);">
                                <span class="material-symbols-outlined text-blue-600 text-sm sm:text-base">verified_user</span>
                                <span class="font-semibold text-[10px] sm:text-xs whitespace-nowrap"
                                    style="color: var(--footer-heading-color);">SSL Secure</span>
                            </div>

                            <a href="https://iata.co" target="_blank"
                                class="flex items-center gap-1.5 border px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-full shadow-sm hover:shadow-md hover:border-blue-600 transition-all duration-200"
                                style="background-color: var(--footer-background); border-color: var(--footer-border-color);">
                                <span class="material-symbols-outlined text-blue-600 text-sm sm:text-base">verified</span>
                                <span class="font-semibold text-[10px] sm:text-xs whitespace-nowrap"
                                    style="color: var(--footer-heading-color);">IATA</span>
                            </a>

                            <div class="flex items-center gap-1.5 border px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-full shadow-sm"
                                style="background-color: var(--footer-background); border-color: var(--footer-border-color);">
                                <span class="material-symbols-outlined text-blue-600 text-sm sm:text-base">credit_card</span>
                                <span class="font-semibold text-[10px] sm:text-xs whitespace-nowrap"
                                    style="color: var(--footer-heading-color);">PCI DSS</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================================================================
         BACK TO TOP BUTTON
         ================================================================ -->
            <button onclick="window.scrollTo({top: 0, behavior: 'smooth'})" id="backToTop"
                class="fixed bottom-6 right-6 w-12 h-12 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-110 z-50 opacity-0 invisible flex items-center justify-center group"
                aria-label="Back to top">
                <span class="material-symbols-outlined text-xl">arrow_upward</span>
            </button>

            <!-- ================================================================
         BACK TO TOP BUTTON VISIBILITY SCRIPT
         ================================================================ -->
            <script>
                // Show/hide back to top button based on scroll position (passive + rAF)
                (function () {
                    const backToTop = document.getElementById('backToTop');
                    if (!backToTop) return;

                    let ticking = false;
                    const update = function () {
                        const show = window.pageYOffset > 300;
                        backToTop.classList.toggle('opacity-100', show);
                        backToTop.classList.toggle('visible', show);
                        backToTop.classList.toggle('opacity-0', !show);
                        backToTop.classList.toggle('invisible', !show);

                        // Adjust position if room booking bar is visible, or if mobile filter button is present and visible
                        const roomBookingBar = document.querySelector('.room-booking-bar');
                        const filterBtn = document.querySelector('.mobile-filter-btn');

                        if (roomBookingBar && window.getComputedStyle(roomBookingBar).display !== 'none') {
                            const barHeight = roomBookingBar.offsetHeight || 80;
                            backToTop.style.bottom = (barHeight + 16) + 'px';
                        } else if (filterBtn && window.getComputedStyle(filterBtn).display !== 'none' && window.innerWidth < 768) {
                            backToTop.style.bottom = '80px';
                        } else {
                            backToTop.style.bottom = '';
                        }
                        ticking = false;
                    };

                    window.addEventListener('scroll', function () {
                        if (!ticking) {
                            window.requestAnimationFrame(update);
                            ticking = true;
                        }
                    }, { passive: true });
                })();
            </script>

        </footer>
<?php } ?>

<?php if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])): ?>
        <script defer src="https://js.pusher.com/8.0.1/pusher.min.js"></script>
        <script defer>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof Pusher === 'undefined') { console.warn('Pusher script not loaded, skipping init.'); return; }
                if (!window.__appPusherInit) {
                    window.__appPusherInit = true;
                    let notificationCounter = 1;
                    let processedNotifications = new Set();

                    const generateSessionId = () => {
                        let sessionId = localStorage.getItem('pusher_session_id');
                        if (!sessionId) {
                            sessionId = 'user-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
                            localStorage.setItem('pusher_session_id', sessionId);
                        }
                        return sessionId;
                    };

                    const sessionId = generateSessionId();

                    const pusher = new Pusher("47c534ae27bce2c4a8b7", {
                        cluster: "us2"
                    });

                    const generalChannel = pusher.subscribe("notify-channel");

                    generalChannel.bind("new-notification", function (data) {
                        console.log('General channel notification:', data);

                        if (!data.is_test && Notification.permission === "granted") {
                            showNotification(data.title, data.message, data);
                        }
                    });

                    function showNotification(title, message, data = {}) {
                        if (Notification.permission === "granted") {
                            const notificationId = title + '_' + message + '_' + Date.now();

                            if (processedNotifications.has(notificationId)) {
                                console.log('⚠️ Duplicate notification skipped:', notificationId);
                                return false;
                            }

                            processedNotifications.add(notificationId);

                            setTimeout(() => {
                                processedNotifications.delete(notificationId);
                            }, 5000);

                            const uniqueTag = 'notification-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);

                            const notification = new Notification(title, {
                                body: message,
                                // icon: data.icon || '/uploads/global/logo.png',
                                // badge: data.badge || '/uploads/global/logo.png',
                                tag: uniqueTag,
                                requireInteraction: false,
                                silent: false,
                                timestamp: Date.now()
                            });

                            notification.onclick = function () {
                                console.log('Notification clicked:', uniqueTag);

                                const redirectUrl = data.url || window.location.href;
                                window.open(redirectUrl, "_blank");
                                window.focus();
                                notification.close();
                            };

                            notification.onclose = function () {
                                console.log('Notification closed:', uniqueTag);
                            };

                            notification.onshow = function () {
                                console.log('✅ Notification displayed:', uniqueTag);
                            };

                            return true;
                        }
                        return false;
                    }

                    async function requestNotificationPermission() {
                        if (!("Notification" in window)) {
                            return "unsupported";
                        }

                        if (Notification.permission === "granted") {
                            return "granted";
                        }

                        if (Notification.permission === "denied") {
                            return "denied";
                        }

                        try {
                            const permission = await Notification.requestPermission();
                            return permission;
                        } catch (error) {
                            console.error("Error requesting notification permission:", error);
                            return "denied";
                        }
                    }

                    async function ensureNotificationPermission() {
                        const permission = await requestNotificationPermission();

                        switch (permission) {
                            case "granted":
                                return { success: true, message: "Notifications are enabled" };

                            case "denied":
                                return {
                                    success: false,
                                    message: "Notifications are blocked. Please enable them in your browser settings."
                                };

                            case "unsupported":
                                return {
                                    success: false,
                                    message: "Your browser doesn't support notifications"
                                };

                            default:
                                return {
                                    success: false,
                                    message: "Notification permission not granted"
                                };
                        }
                    }

                    function getNotificationIcon() {
                        // Try multiple possible paths
                        const possiblePaths = [
                            '/uploads/global/logo.png',
                            '/assets/images/logo.png',
                            '/img/logo.png',
                            '/favicon.ico'
                        ];
                        return '';
                    }
                } // end window.__appPusherInit guard
            });
        </script>
<?php endif; ?>

<script defer src="<?= root ?>assets/js/toast.js"></script>
<script defer
    src="<?= root ?>assets/js/datepicker.js?v=<?= file_exists(__DIR__ . '/../../../assets/js/datepicker.js') ? filemtime(__DIR__ . '/../../../assets/js/datepicker.js') : time() ?>"></script>
<?php if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])): ?>
        <script defer src="https://unpkg.com/vt-notifications@latest/dist/vt-notifications.umd.js"></script>
<?php endif; ?>

<!-- Demo Warning Modal - For phptravels.net and localhost only -->
<?php
try {
    require_once views . "includes/demo-warning-modal.php";
} catch (\Exception $e) {
    // Log error but don't break the page
    error_log("Demo Warning Modal Error: " . $e->getMessage());
    if (in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', '::1', 'phptravels.net', 'www.phptravels.net'])) {
        echo "<!-- Demo Modal Error: " . htmlspecialchars($e->getMessage()) . " -->";
    }
}
?>

</body>

</html>