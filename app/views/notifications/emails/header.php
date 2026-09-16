<?php
/**
 * EMAIL HEADER - Modern, professional, responsive
 */
// NOTE: previously this hardcoded date_default_timezone_set('Asia/Karachi'), a
// copy-paste leftover. Because this header is included when rendering emails from
// notify.php, the support routes, and the credits-reminder CRON, that call
// globally switched PHP's timezone to Karachi for the REST of the request — so
// any timestamp written after an email send silently used the wrong zone. Removed:
// emails now render in the app's configured timezone (config.php / .env TIMEZONE),
// consistent with the PHP<->MySQL alignment.
$rootUrl = defined('root') ? root : 'http://localhost/v10/';
$logoUrl = $rootUrl . 'uploads/global/logo.png';
$displayBrand = $companyName ?? getBrandName($settings ?? null);
$language = $language ?: 'en';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; line-height: 1.6; }
        .wrapper { width: 100%; border-collapse: collapse; background-color: #f8fafc; padding: 40px 10px; }
        .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .header { background: #ffffff; padding: 24px 32px; border-bottom: 1px solid #f1f5f9; }
        .header-table { width: 100%; border-collapse: collapse; }
        .logo-img { height: 32px; width: auto; display: block; }
        .header-content { text-align: right; }
        .brand-name { font-size: 16px; font-weight: 700; color: #0f172a; }
        .header-date { font-size: 12px; color: #64748b; margin-top: 2px; }
        @media only screen and (max-width: 600px) {
            .email-container { border-radius: 0; }
            .header { padding: 20px !important; }
        }
    </style>
</head>
<body>
    <table class="wrapper">
        <tr>
            <td align="center">
                <div class="email-container">
                    <!-- Global Header -->
                    <div class="header">
                        <table class="header-table">
                            <tr>
                                <td align="left">
                                    <img src="<?= $logoUrl ?>" alt="<?= $displayBrand ?>" class="logo-img">
                                </td>
                                <td class="header-content" align="right">
                                    <div class="brand-name">#<?=$invoiceId ?? 'xxxxxx' ?></div>
                                    <div class="header-date"><?= date('D, M d, Y - h:i A') ?></div>
                                </td>
                            </tr>
                        </table>
                    </div>