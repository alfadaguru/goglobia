<?php
/**
 * EMAIL FOOTER - Modern, informative
 */
global $db;
// Dynamically load support contact info from settings if not already available
if (empty($supportEmail) || empty($companyName)) {
    $footerSettings = $db->get('settings', ['contact_email', 'contact_phone', 'business_name'], ['id' => 1]);
    if (empty($supportEmail))  $supportEmail  = $footerSettings['contact_email']  ?? '';
    if (empty($supportPhone))  $supportPhone  = $footerSettings['contact_phone']  ?? '';
    if (empty($companyName))   $companyName   = getBrandName($footerSettings ?? null);
}
$language = $language ?: 'en';
?>
                    <!-- Global Footer -->
                    <div class="footer">
                        <div style="margin-bottom: 20px;">
                            <div style="font-weight: 700; color: #ffffff; margin-bottom: 8px;"><?= htmlspecialchars(translateTo('get_in_touch', $language)) ?></div>
                            <div>
                                <?php if (!empty($supportEmail)): ?>
                                <?= htmlspecialchars(translateTo('email', $language)) ?>: <a href="mailto:<?= htmlspecialchars($supportEmail) ?>"><?= htmlspecialchars($supportEmail) ?></a>
                                <?php endif; ?>
                                <?php if (!empty($supportPhone)): ?> | <?= htmlspecialchars(translateTo('phone', $language)) ?>: <?= htmlspecialchars($supportPhone) ?><?php endif; ?>
                            </div>
                        </div>
                        <div style="border-top: 1px solid #334155; padding-top: 24px; color: #64748b;">
                            <p>© <?= date('Y') ?> <?= $companyName ?? 'PHPTRAVELS' ?>. All rights reserved.</p>
                            <p style="margin-top: 8px;"><?= htmlspecialchars(translateTo('automated_message_disclaimer', $language)) ?></p>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>