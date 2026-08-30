/**
 * Simple PrismJS Copy Button Functionality
 * (Highlighting is handled inline in header.php to avoid conflicts)
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('Setting up copy buttons for code blocks');

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
    setTimeout(function() {
        document.querySelectorAll('pre[class*="language-"]:not(.no-copy-button)').forEach(function(pre) {
            // Don't add if it already has a copy button
            if (pre.querySelector('.copy-code-button')) return;
            
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
    }, 200); // Wait for highlighting to complete
});