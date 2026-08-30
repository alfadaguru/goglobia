<?php
// ============================================================================
// SECURE PAYMENT PAGE - Unified Payment Gateway Interface
// ============================================================================
@$SECURE or die('Access Denied!');

$gatewayName = $gateway['name'] ?? 'Payment Gateway';
// Customer-visible label only — $gatewayName above stays the technical key used
// below for dev-mode test-card branching and must not be replaced by this.
$gatewayDisplayName = !empty($gateway) ? getGatewayDisplayName($gateway) : $gatewayName;
$gatewayLogo = $gateway['logo'] ?? null;
$amount = number_format((float)$booking['price_markup'], 2, '.', '');
$currency = strtoupper($booking['currency_markup'] ?? 'USD');
$invoiceId = $booking['invoice_id'];
$moduleType = $booking['module_type'] ?? 'stays';

// Get gateway config for dev mode check
if (!function_exists('get_gateway_config')) {
    require_once __DIR__ . '/../../lib/payment-gateway.php';
}
$gatewayConfig = get_gateway_config($gateway);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proceed to Payment - <?= $invoiceId ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .payment-container {
            max-width: 460px;
            width: 100%;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .payment-header {
            background: #4F46E5;
            color: white;
            padding: 24px;
            text-align: center;
        }
        
        .payment-header h1 {
            margin: 0 0 6px 0;
            font-size: 20px;
            font-weight: 600;
        }
        
        .payment-header p {
            margin: 0;
            font-size: 13px;
            opacity: 0.92;
        }
        
        .payment-body {
            padding: 26px;
        }
        
        .payment-summary {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 18px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 9px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .summary-row:last-child {
            border-bottom: none;
            padding-top: 11px;
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .summary-label {
            color: #64748b;
            font-size: 13px;
        }
        
        .summary-value {
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
        }
        
        .gateway-info {
            text-align: center;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 18px;
        }
        
        .gateway-logo {
            max-width: 90px;
            height: 32px;
            object-fit: contain;
            margin-bottom: 6px;
        }
        
        .gateway-name {
            font-size: 14px;
            font-weight: 600;
            color: #334155;
        }
        
        .test-credentials {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 18px;
        }
        
        .test-credentials h4 {
            margin: 0 0 10px 0;
            font-size: 13px;
            color: #92400e;
            font-weight: 600;
        }
        
        .test-credentials p {
            margin: 4px 0;
            font-size: 12px;
            color: #78350f;
        }
        
        .payment-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .btn-payment {
            width: 100%;
            padding: 13px 20px;
            border: none;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }
        
        .btn-primary {
            background: #4F46E5;
            color: white;
        }
        
        .btn-primary:hover:not(:disabled) {
            background: #4338CA;
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background: #ffffff;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        
        .btn-secondary:hover {
            background: #f8fafc;
            color: #475569;
        }
        
        .security-badge {
            text-align: center;
            padding: 14px 0;
            color: #94a3b8;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .security-icon {
            width: 13px;
            height: 13px;
        }
        
        #gateway-container {
            margin-top: 14px;
            min-height: 100px;
        }
        
        @media (max-width: 640px) {
            body {
                padding: 12px;
            }
            
            .payment-header h1 {
                font-size: 18px;
            }
            
            .payment-body {
                padding: 20px 16px;
            }
        }
        
        .spinner {
            display: inline-block;
            width: 15px;
            height: 15px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="payment-container">
    <!-- Header -->
    <div class="payment-header">
        <h1>Proceed to Payment</h1>
        <p>Invoice #<?= htmlspecialchars($invoiceId) ?></p>
    </div>
    
    <!-- Body -->
    <div class="payment-body">
        <!-- Payment Summary -->
        <div class="payment-summary">
            <div class="summary-row">
                <span class="summary-label">Invoice ID</span>
                <span class="summary-value">#<?= htmlspecialchars($invoiceId) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Customer</span>
                <span class="summary-value"><?= htmlspecialchars($booking['first_name'] ?? '') ?> <?= htmlspecialchars($booking['last_name'] ?? '') ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Email</span>
                <span class="summary-value"><?= htmlspecialchars($booking['email'] ?? '') ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Total Amount</span>
                <span class="summary-value"><?= $currency ?> <?= $amount ?></span>
            </div>
        </div>
        
        <!-- Gateway Info -->
        <div class="gateway-info">
            <?php if ($gatewayLogo): ?>
                <img src="<?= htmlspecialchars($gatewayLogo) ?>" alt="<?= htmlspecialchars($gatewayDisplayName) ?>" class="gateway-logo">
            <?php endif; ?>
            <div class="gateway-name">Pay with <?= htmlspecialchars($gatewayDisplayName) ?></div>
        </div>
        
        <!-- Test Credentials (if dev mode) -->
        <?php if (!empty($gatewayConfig['dev_mode'])): ?>
        <div class="test-credentials">
            <h4>🧪 Test Mode - Use These Credentials:</h4>
            <?php if (!empty($gatewayConfig['username'])): ?>
                <p><strong>Email:</strong> <?= htmlspecialchars($gatewayConfig['username']) ?></p>
            <?php endif; ?>
            <?php if (!empty($gatewayConfig['password'])): ?>
                <p><strong>Password:</strong> <?= htmlspecialchars($gatewayConfig['password']) ?></p>
            <?php endif; ?>
            <?php if (strtolower($gatewayName) === 'paystack'): ?>
                <p><strong>Card:</strong> 4084084084084081</p>
                <p><strong>CVV:</strong> 408 | <strong>PIN:</strong> 0000 | <strong>OTP:</strong> 123456</p>
            <?php elseif (strtolower($gatewayName) === 'stripe'): ?>
                <p><strong>Card:</strong> 4242 4242 4242 4242</p>
                <p><strong>Expiry:</strong> Any future date | <strong>CVV:</strong> Any 3 digits</p>
            <?php elseif (strtolower($gatewayName) === 'flutterwave'): ?>
                <p><strong>Card:</strong> 4187427415564246</p>
                <p><strong>CVV:</strong> 828 | <strong>Expiry:</strong> 09/32</p>
                <p><strong>PIN:</strong> 3310 | <strong>OTP:</strong> 12345</p>
            <?php elseif (strtolower($gatewayName) === 'cashfree'): ?>
                <p><strong>Card:</strong> 4111111111111111</p>
                <p><strong>CVV:</strong> 123 | <strong>Expiry:</strong> 12/25</p>
                <p><strong>OTP:</strong> 123456</p>
                <p><strong>UPI:</strong> testsuccess@gocash</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Payment Actions -->
        <div class="payment-actions">
            <button type="button" id="proceedPaymentBtn" class="btn-payment btn-primary" onclick="proceedToPayment()">
                <span id="btnText">Proceed to Payment</span>
                <span id="btnSpinner" class="spinner" style="display:none;"></span>
            </button>
            
            <a href="<?= root ?>invoice/<?= $moduleType ?>/<?= $invoiceId ?>" class="btn-payment btn-secondary">
                ← Go Back to Invoice
            </a>
        </div>
        
        <!-- Gateway Container -->
        <div id="gateway-container" style="display:none;"></div>
        
        <!-- Security Badge -->
        <div class="security-badge">
            <svg class="security-icon" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            Secured Payment Processing
        </div>
    </div>
</div>

<script>
function proceedToPayment() {
    const btn = document.getElementById('proceedPaymentBtn');
    const btnText = document.getElementById('btnText');
    const btnSpinner = document.getElementById('btnSpinner');
    const gatewayContainer = document.getElementById('gateway-container');
    
    // Disable button and show loading
    btn.disabled = true;
    btnText.textContent = 'Loading Gateway';
    btnSpinner.style.display = 'inline-block';
    
    // Show gateway container
    gatewayContainer.style.display = 'block';
    gatewayContainer.innerHTML = '<div style="text-align:center;padding:18px;color:#64748b;"><p>Loading payment gateway...</p></div>';
    
    // Load gateway file via AJAX
    fetch('<?= root ?>payment/gateway/<?= $hash ?>')
        .then(response => response.text())
        .then(html => {
            gatewayContainer.innerHTML = html;
            
            // Execute any scripts in the loaded HTML
            const scripts = gatewayContainer.querySelectorAll('script');
            scripts.forEach(script => {
                const newScript = document.createElement('script');
                if (script.src) {
                    newScript.src = script.src;
                    newScript.async = false;
                } else {
                    newScript.textContent = script.textContent;
                }
                document.body.appendChild(newScript);
            });
            
            // Scroll to gateway smoothly
            setTimeout(() => {
                gatewayContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
            
            // Hide button after gateway loads
            setTimeout(() => {
                btn.style.display = 'none';
            }, 400);
        })
        .catch(error => {
            console.error('Error:', error);
            gatewayContainer.innerHTML = '<div style="text-align:center;padding:18px;color:#ef4444;"><p>Error loading gateway. Please try again.</p></div>';
            btn.disabled = false;
            btnText.textContent = 'Proceed to Payment';
            btnSpinner.style.display = 'none';
        });
}
</script>

</body>
</html>
