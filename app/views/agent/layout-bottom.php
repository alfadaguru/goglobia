<?php
// ============================================================================
// app/views/agent/layout-bottom.php — DEDICATED agent-portal shell (bottom).
// Closes the <main>/<body>/<html> opened by layout-top.php and loads ONLY the
// JS the portal needs: AlpineJS (branding/roles/trip views use x-data) and a
// tiny fetch() wrapper that auto-attaches the CSRF token to same-origin POSTs
// (agent action buttons post JSON). No customer footer / Pusher / GIA.
// ============================================================================
@$SECURE or die('Access Denied!');
?>
    </main>

    <!-- AlpineJS — required by agent/branding, roles, trip-new, trip-builder (x-data). -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Attach the CSRF token (from the <meta> in layout-top) to same-origin POST
         fetches automatically, so the portal's JS actions (e.g. booking cancel/
         refund/resend) carry it without each call wiring it by hand. -->
    <script>
    (function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        if (!token || !window.fetch) return;
        var origin = window.location.origin;
        var _fetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            init = init || {};
            var method = (init.method || (typeof input === 'object' && input.method) || 'GET').toUpperCase();
            var url = (typeof input === 'string') ? input : (input && input.url) || '';
            var sameOrigin = url.indexOf('http') !== 0 || url.indexOf(origin) === 0;
            if (method !== 'GET' && method !== 'HEAD' && sameOrigin) {
                init.headers = init.headers || {};
                if (init.headers instanceof Headers) {
                    if (!init.headers.has('X-CSRF-TOKEN')) init.headers.set('X-CSRF-TOKEN', token);
                } else if (!init.headers['X-CSRF-TOKEN']) {
                    init.headers['X-CSRF-TOKEN'] = token;
                }
            }
            return _fetch(input, init);
        };
    })();
    </script>
</body>
</html>
