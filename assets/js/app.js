(function () {
    function getLoader() { return document.getElementById('page-loader'); }

    function hide() {
        const el = getLoader();
        if (!el || el.dataset.hidden === '1') return;
        el.dataset.hidden = '1';
        el.classList.add('hide');
        const done = () => { el.style.display = 'none'; };
        // Remove from layout after the (quick) fade completes.
        el.addEventListener('transitionend', done, { once: true });
        setTimeout(done, 260); // safety, just past the .18s fade
    }

    function show() {
        const el = getLoader();
        if (!el) return;
        el.dataset.hidden = '0';
        el.style.display = 'flex';
        // Start from opacity 0 and fade in quickly (reflow so the transition runs).
        el.classList.add('hide');
        void el.offsetHeight;
        el.classList.remove('hide');
    }

    // Reveal the page as soon as it's loaded and painted.
    function hideWhenReady() {
        requestAnimationFrame(function () { requestAnimationFrame(hide); });
    }
    if (document.readyState === 'complete') {
        hideWhenReady();
    } else {
        window.addEventListener('load', hideWhenReady, { once: true });
    }

    // Safety net: never trap the user behind the loader if `load` never fires.
    setTimeout(hide, 8000);

    // bfcache: back/forward restores a frozen page — hide the loader again.
    window.addEventListener('pageshow', function (e) { if (e.persisted) hide(); });

    // Same-origin left-clicks: fade the loader in and let the browser navigate
    // IMMEDIATELY (no artificial delay). The old code waited up to 400ms for the
    // fade-in before navigating, which made every click feel slow.
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href ||
            href[0] === '#' ||
            href.startsWith('javascript:') ||
            link.target === '_blank' ||
            link.hasAttribute('download') ||
            (href.includes('://') && !href.startsWith(location.origin))) return;
        // Show as feedback; the browser's default navigation proceeds right away.
        show();
    }, true);
})();

// ============================================================================
// GLOBAL SEARCH DROPDOWN PANEL HELPERS (REUSED ACROSS ALL SEARCH WIDGET MODULES)
// ============================================================================
window.flIsDesktop = window.flIsDesktop || (() => window.matchMedia('(min-width:1024px)').matches);

window.flAnchorPanel = window.flAnchorPanel || function (triggerId, panelId) {
    const trigger = document.getElementById(triggerId);
    const panel   = document.getElementById(panelId);
    if (!trigger || !panel) return;
    if (window.flIsDesktop()) {
        const r          = trigger.getBoundingClientRect();
        const viewportH  = window.innerHeight;
        const spaceBelow = viewportH - r.bottom - 12;
        const maxH       = Math.min(320, Math.max(160, spaceBelow));

        panel.style.position = 'fixed';
        panel.style.top      = r.bottom + 'px';
        if (triggerId === 'vfc_t' || triggerId === 'vtc_t') {
            const parent = trigger.closest('.field-box-split');
            if (parent) {
                const pr = parent.getBoundingClientRect();
                panel.style.left  = pr.left + 'px';
                panel.style.width = pr.width + 'px';
            } else {
                panel.style.left  = r.left + 'px';
                panel.style.width = Math.max(r.width, 320) + 'px';
            }
        } else {
            panel.style.left  = r.left + 'px';
            panel.style.width = r.width + 'px';
        }
        panel.style.maxHeight       = maxH + 'px';
        panel.style.overflow        = 'hidden';
        panel.style.zIndex          = '9999';
        panel.style.backgroundColor = '#ffffff';
        panel.style.boxShadow       = '0 4px 24px 0 rgba(16,24,40,0.12)';
    } else {
        ['position','top','left','width','maxHeight','overflow','zIndex','backgroundColor','boxShadow'].forEach(k => panel.style[k] = '');
    }
};

window.railIsDesktop  = window.railIsDesktop || window.flIsDesktop;
window.railAnchorPanel = window.railAnchorPanel || window.flAnchorPanel;

window.flPanel = window.flPanel || function (triggerId, panelId, focusId) {
    return {
        open: false,
        isDesktop: window.flIsDesktop(),
        _t: triggerId,
        _p: panelId,
        _f: focusId || null,
        initPanel() {
            const sync = () => {
                this.isDesktop = window.flIsDesktop();
                if (this.open) window.flAnchorPanel(this._t, this._p);
            };
            window.addEventListener('resize', sync);
            window.addEventListener('scroll', sync, true);
            document.addEventListener('mousedown', (e) => {
                if (!this.open) return;
                const a = document.getElementById(this._t);
                const b = document.getElementById(this._p);
                if (a && b && !a.contains(e.target) && !b.contains(e.target)) this.open = false;
            });
        },
        togglePanel() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => {
                    window.flAnchorPanel(this._t, this._p);
                    if (this._f) setTimeout(() => document.getElementById(this._f)?.focus(), 50);
                });
            }
        }
    };
};
