/**
 * PrismJS Initialization and Copy Button Functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - preparing Prism setup');

    // Check if Prism is loaded
    if (typeof Prism === 'undefined') {
        console.error('PrismJS is not loaded. Please include the library.');
        return;
    }

    /**
     * Safe initialization function with comprehensive fallbacks
     */
    function initPrism() {
        console.log('Initializing PrismJS...');

        // Make sure we have PHP language support one way or another
        if (!Prism.languages.php || typeof Prism.languages.php.tokenizePlaceholders === 'undefined') {
            console.log('PHP language definition is missing or incomplete. Adding fallback...');
            
            // Start with C-like as base if available
            if (Prism.languages.clike) {
                console.log('Using clike as base for PHP definition');
                Prism.languages.php = Prism.languages.extend('clike', {
                    'comment': [
                        {
                            pattern: /(^|[^\\])\/\*[\s\S]*?(?:\*\/|$)/,
                            lookbehind: true
                        },
                        {
                            pattern: /(^|[^\\:])\/\/.*/,
                            lookbehind: true
                        }
                    ],
                    'string': {
                        pattern: /(["'])(?:\\[\s\S]|(?!\1)[^\\])*\1/,
                        greedy: true
                    },
                    'keyword': /\b(?:and|or|xor|array|as|break|case|cfunction|class|const|continue|declare|default|die|do|else|elseif|enddeclare|endfor|endforeach|endif|endswitch|endwhile|extends|for|foreach|function|include|include_once|global|if|new|return|static|switch|use|require|require_once|var|while|abstract|interface|public|implements|private|protected|parent|throw|null|echo|print|trait|namespace|final|yield|goto|instanceof|finally|try|catch)\b/i,
                    'boolean': /\b(?:true|false)\b/i,
                    'constant': /\b[A-Z0-9_]{2,}\b/,
                    'number': /\b0b[01]+\b|\b0x[\da-f]+\b|(?:\b\d+(?:\.\d*)?|\B\.\d+)(?:e[+-]?\d+)?/i
                });
                
                // Add tokenizePlaceholders to prevent errors
                Prism.languages.php.tokenizePlaceholders = function() { return []; };
            } else {
                // Basic PHP definition if clike is not available
                console.log('Creating standalone PHP definition');
                Prism.languages.php = {
                    'comment': /\/\*[\s\S]*?(?:\*\/|$)|\/{2}.*/,
                    'string': {
                        pattern: /(["'])(?:\\[\s\S]|(?!\1)[^\\])*\1/,
                        greedy: true
                    },
                    'keyword': /\b(?:and|or|xor|array|as|break|case|class|const|continue|declare|default|die|do|else|elseif|endif|endfor|endforeach|endswitch|endwhile|extends|for|foreach|function|if|include|include_once|global|new|return|static|switch|use|require|require_once|var|while|abstract|interface|public|implements|private|protected|parent|throw|null|echo|print|trait|namespace|final|yield|goto|instanceof|finally|try|catch)\b/i,
                    'boolean': /\b(?:true|false)\b/i,
                    'number': /\b0b[01]+\b|\b0x[\da-f]+\b|(?:\b\d+(?:\.\d*)?|\B\.\d+)(?:e[+-]?\d+)?/i,
                    'operator': /[-+*\/%]|[<>]=?|[!=]=|&&|\|\||\?\:?|:|\b(?:is|as)\b/,
                    'punctuation': /[{}[\];(),.:]/
                };
                
                // Add tokenizePlaceholders to prevent errors
                Prism.languages.php.tokenizePlaceholders = function() { return []; };
            }
        }
        
        // Add line numbers to all code blocks
        try {
            document.querySelectorAll('pre').forEach(function(pre) {
                if (!pre.classList.contains('no-line-numbers')) {
                    pre.classList.add('line-numbers');
                }
            });
            
            console.log('Running Prism.highlightAll()...');
            Prism.highlightAll();
            console.log('PrismJS highlighting complete');
        } catch (e) {
            console.error('Error during PrismJS highlighting:', e);
            
            // Try highlighting each element individually
            try {
                console.log('Attempting element-by-element highlighting...');
                document.querySelectorAll('pre code').forEach(function(element) {
                    try {
                        Prism.highlightElement(element);
                    } catch (innerError) {
                        console.warn('Could not highlight element:', element, innerError);
                    }
                });
            } catch (fallbackError) {
                console.error('Element-by-element highlighting failed:', fallbackError);
            }
        }
    }
    
    // Wait a bit longer to ensure all components are loaded
    console.log('Setting timeout for PrismJS initialization...');
    setTimeout(function() {
        // Initialize Prism with our safe function
        initPrism();

    // Setup line numbers plugin if available
    if (Prism.plugins && Prism.plugins.lineNumbers) {
        document.querySelectorAll('pre:not(.no-line-numbers)').forEach(function(block) {
            block.classList.add('line-numbers');
        });
    }
    
    // Setup manual copy buttons
    const copyButtons = document.querySelectorAll('.copy-btn');
    copyButtons.forEach(button => {
        button.addEventListener('click', () => {
            const targetId = button.dataset.copy;
            const targetElement = document.getElementById(targetId);
            if (targetElement) {
                // Get the text without line numbers
                let textToCopy = '';
                const codeElement = targetElement.querySelector('code');
                if (codeElement) {
                    textToCopy = codeElement.textContent;
                } else {
                    textToCopy = targetElement.textContent;
                }
                
                navigator.clipboard.writeText(textToCopy).then(() => {
                    // Update button text temporarily
                    const originalText = button.innerHTML;
                    button.innerHTML = '<span class="material-symbols-outlined text-sm mr-1">check</span>Copied';
                    button.classList.add('bg-green-100', 'text-green-700');
                    
                    setTimeout(() => {
                        button.innerHTML = originalText;
                        button.classList.remove('bg-green-100', 'text-green-700');
                    }, 2000);
                });
            }
        });
    });
    
    // Add automatic copy buttons to code blocks
    document.querySelectorAll('pre[class*="language-"]:not(.no-copy-button)').forEach(function(pre) {
        // Don't add if it already has a .copy-btn inside
        if (pre.querySelector('.copy-btn')) return;
        
        // Create the copy button
        const copyButton = document.createElement('button');
        copyButton.className = 'copy-code-button';
        copyButton.innerHTML = '<span class="material-symbols-outlined" style="font-size: 16px;">content_copy</span>';
        copyButton.setAttribute('aria-label', 'Copy code to clipboard');
        
        // Style the button
        copyButton.style.position = 'absolute';
        copyButton.style.top = '0.5rem';
        copyButton.style.right = '0.5rem';
        copyButton.style.backgroundColor = 'rgba(59, 130, 246, 0.8)';
        copyButton.style.color = 'white';
        copyButton.style.border = 'none';
        copyButton.style.borderRadius = '0.25rem';
        copyButton.style.padding = '0.25rem';
        copyButton.style.fontSize = '0.75rem';
        copyButton.style.fontWeight = '500';
        copyButton.style.cursor = 'pointer';
        copyButton.style.transition = 'all 0.2s ease';
        copyButton.style.opacity = '0';
        copyButton.style.zIndex = '5';
        
        // Make the pre position relative to position the button
        pre.style.position = 'relative';
        
        // Show button on hover
        pre.addEventListener('mouseenter', function() {
            copyButton.style.opacity = '1';
        });
        
        pre.addEventListener('mouseleave', function() {
            if (!copyButton.classList.contains('copied')) {
                copyButton.style.opacity = '0';
            }
        });
        
        // Copy functionality
        copyButton.addEventListener('click', function() {
            // Get the code text
            const code = pre.querySelector('code');
            const textToCopy = code.innerText;
            
            // Copy to clipboard
            navigator.clipboard.writeText(textToCopy).then(function() {
                // Visual feedback
                copyButton.innerHTML = '<span class="material-symbols-outlined" style="font-size: 16px;">check</span>';
                copyButton.style.backgroundColor = 'rgba(34, 197, 94, 0.8)';
                copyButton.classList.add('copied');
                
                // Reset after 2 seconds
                setTimeout(function() {
                    copyButton.innerHTML = '<span class="material-symbols-outlined" style="font-size: 16px;">content_copy</span>';
                    copyButton.style.backgroundColor = 'rgba(59, 130, 246, 0.8)';
                    copyButton.classList.remove('copied');
                    
                    // Hide if not hovering
                    if (!pre.matches(':hover')) {
                        copyButton.style.opacity = '0';
                    }
                }, 2000);
            }, function(err) {
                console.error('Failed to copy text: ', err);
                copyButton.innerHTML = '<span class="material-symbols-outlined" style="font-size: 16px;">error</span>';
                copyButton.style.backgroundColor = 'rgba(239, 68, 68, 0.8)';
            });
        });
        
        // Add the button to the pre element
        pre.appendChild(copyButton);
    });
    
    }, 600); // Much longer delay to ensure all components are loaded
    
    // Add a window.onload backup in case DOMContentLoaded was too early
    window.addEventListener('load', function() {
        setTimeout(function() {
            console.log('Window load backup initialization');
            if (document.querySelectorAll('pre code:not(.prism-highlighted)').length > 0) {
                initPrism(); // Run again if there are unhighlighted code blocks
            }
        }, 800);
    });
});