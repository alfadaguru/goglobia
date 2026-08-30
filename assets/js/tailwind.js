// Suppress Tailwind CDN production warning (idempotent)
(function(){
    if (window.__tailwindWarnSuppressed) return;
    window.__tailwindWarnSuppressed = true;
    const originalWarn = console.warn;
    console.warn = (...args) => {
        const message = args[0];
        if (typeof message === 'string' && message.includes('cdn.tailwindcss.com')) {
            return; // Suppress this specific warning
        }
        originalWarn.apply(console, args);
    };
})();
