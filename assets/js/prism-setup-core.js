/**
 * Minimal PrismJS setup to ensure critical functionality
 * This provides a fallback if the main setup fails
 */
(function() {
    // Run after the page is fully loaded
    window.addEventListener('load', function() {
        // Make sure Prism is available
        if (typeof Prism === 'undefined') {
            console.warn('PrismJS not found, cannot highlight code blocks');
            return;
        }
        
        // Ensure PHP language is available
        if (!Prism.languages.php) {
            console.warn('PHP language definition not found, using basic fallback');
            // Basic PHP syntax definition as fallback
            Prism.languages.php = Prism.languages.extend('clike', {
                'keyword': /\b(?:and|or|xor|array|as|break|case|cfunction|class|const|continue|declare|default|die|do|else|elseif|enddeclare|endfor|endforeach|endif|endswitch|endwhile|extends|for|foreach|function|include|include_once|global|if|new|return|static|switch|use|require|require_once|var|while|abstract|interface|public|implements|private|protected|parent|throw|null|echo|print|trait|namespace|final|yield|goto|instanceof|finally|try|catch)\b/i,
                'constant': /\b[A-Z0-9_]{2,}\b/,
                'comment': {
                    pattern: /(^|[^\\])(?:\/\*[\s\S]*?\*\/|\/\/.*)/,
                    lookbehind: true
                }
            });
        }
        
        try {
            // Add line-numbers class to all pre elements if needed
            document.querySelectorAll('pre:not(.no-line-numbers)').forEach(function(pre) {
                pre.classList.add('line-numbers');
            });
            
            // Highlight all code blocks
            Prism.highlightAll();
            console.log('Core PrismJS highlighting complete');
        } catch (e) {
            console.error('Error in core PrismJS highlighting:', e);
        }
    });
})();