<?php @$SECURE or die('Access Denied!'); ?>
<?php
// G1 — WHITE-LABEL BOOKING CHROME (foot). Closes the tenant booking shell: a slim hotel footer
// (its own contact + copyright) + the platform scripts the booking view needs (Alpine etc. are
// loaded by the view itself / app.js). No platform footer menus, no Pusher, no "powered by".
$__wlT   = function_exists('wl_tenant') ? wl_tenant() : [];
$__stay  = is_array($__wlT['stay'] ?? null) ? $__wlT['stay'] : [];
$__name  = (string) ($__wlT['name'] ?? ($__stay['name'] ?? 'Hotel'));
$__phone = trim((string) ($__stay['phone'] ?? ''));
$__email = trim((string) ($__stay['email'] ?? ''));
?>
<footer class="wl-bk-foot">
  <div>
    <?php if ($__phone !== ''): ?><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $__phone)) ?>"><?= htmlspecialchars($__phone) ?></a><?php endif; ?>
    <?php if ($__phone !== '' && $__email !== ''): ?> · <?php endif; ?>
    <?php if ($__email !== ''): ?><a href="mailto:<?= htmlspecialchars($__email) ?>"><?= htmlspecialchars($__email) ?></a><?php endif; ?>
  </div>
  <div style="margin-top:8px;opacity:.7">&copy; <?= date('Y') ?> <?= htmlspecialchars($__name) ?> · Secure booking</div>
</footer>
<?php // Core JS is loaded in the chrome HEAD (app.js, Alpine, jQuery, datepicker) — nothing more here. ?>
</body>
</html>
