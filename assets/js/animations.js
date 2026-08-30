/**
 * Global Animation Functions for Forms
 * Provides consistent animations across all authentication forms
 */

// Global animation functions for button loading states
function showButtonLoading(selector) {
    const btn = $(selector);
    btn.prop('disabled', true);
    btn.find('.btn-text').hide();
    btn.find('.btn-loading').show();
    btn.addClass('opacity-75 cursor-not-allowed');
}

function hideButtonLoading(selector) {
    const btn = $(selector);
    btn.prop('disabled', false);
    btn.find('.btn-text').show();
    btn.find('.btn-loading').hide();
    btn.removeClass('opacity-75 cursor-not-allowed');
}

// Form entrance animations
function fadeInForm(selector = '.fade-in', duration = 600) {
    $(selector).hide().fadeIn(duration);
}

// Input focus animations
function animateInputFocus() {
    $('.input').on('focus', function() {
        $(this).parent().addClass('focused');
    }).on('blur', function() {
        if (!$(this).val()) {
            $(this).parent().removeClass('focused');
        }
    });
}

// Alert animations
function showAlert(message, type = 'info', duration = 5000) {
    const alertClass = type === 'error' ? 'alert error' : 
                     type === 'success' ? 'alert success' : 
                     type === 'warning' ? 'alert warning' : 'alert info';
    
    const alertHtml = `
        <div class="${alertClass}" style="display: none; margin-bottom: 1rem;">
            ${message}
        </div>
    `;
    
    $('.form-box').prepend(alertHtml);
    $('.alert').first().slideDown(300);
    
    if (duration > 0) {
        setTimeout(() => {
            $('.alert').first().slideUp(300, function() {
                $(this).remove();
            });
        }, duration);
    }
}

// Form validation animations
function animateFieldError(selector) {
    $(selector).addClass('border-red-500').removeClass('border-gray-300');
    $(selector).parent().addClass('shake');
    
    setTimeout(() => {
        $(selector).parent().removeClass('shake');
    }, 500);
}

function clearFieldError(selector) {
    $(selector).removeClass('border-red-500').addClass('border-gray-300');
}

// Password strength indicator animation
function animatePasswordStrength(password) {
    const strength = calculatePasswordStrength(password);
    const indicator = $('.password-strength');
    
    if (indicator.length) {
        indicator.removeClass('weak medium strong').addClass(strength.level);
        indicator.find('.strength-text').text(strength.text);
        indicator.find('.strength-bar').css('width', strength.percentage + '%');
    }
}

function calculatePasswordStrength(password) {
    let score = 0;
    if (password.length >= 6) score++;
    if (password.length >= 8) score++;
    if (/[a-z]/.test(password)) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    
    if (score < 3) return { level: 'weak', text: 'Weak', percentage: 33 };
    if (score < 5) return { level: 'medium', text: 'Medium', percentage: 66 };
    return { level: 'strong', text: 'Strong', percentage: 100 };
}

// Initialize global animations
$(document).ready(function() {
    // Initialize input focus animations
    animateInputFocus();
    
    // Add shake animation CSS if not present
    if (!$('style[data-animations]').length) {
        $('head').append(`
            <style data-animations>
                .shake {
                    animation: shake 0.5s;
                }
                
                @keyframes shake {
                    0%, 100% { transform: translateX(0); }
                    25% { transform: translateX(-5px); }
                    75% { transform: translateX(5px); }
                }
                
                .fade-in {
                    animation: fadeIn 0.6s ease-in;
                }
                
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                
                .password-strength {
                    margin-top: 0.5rem;
                    padding: 0.25rem;
                    border-radius: 0.25rem;
                    font-size: 0.75rem;
                    transition: all 0.3s ease;
                }
                
                .password-strength.weak { color: #ef4444; }
                .password-strength.medium { color: #f59e0b; }
                .password-strength.strong { color: #10b981; }
                
                .strength-bar {
                    height: 3px;
                    background: currentColor;
                    border-radius: 1.5px;
                    transition: width 0.3s ease;
                }
                
                .btn-loading {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 0.5rem;
                }
                
                .focused {
                    transform: scale(1.02);
                    transition: transform 0.2s ease;
                }
            </style>
        `);
    }
});