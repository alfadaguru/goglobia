<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // Expected: $cfg, $benefits (tier=>['rate_pct','perks']), $analytics, $occasions,
  // $extCfg (referral/birthday/anniversary), $isOwner. All defaulted defensively.
  $cfg       = (isset($cfg) && is_array($cfg)) ? $cfg : ['enabled' => false, 'points_per_currency' => 1.0, 'redeem_value_per_point' => 0.0];
  $benefits  = (isset($benefits) && is_array($benefits)) ? $benefits : [];
  $analytics = (isset($analytics) && is_array($analytics)) ? $analytics : [];
  $occasions = (isset($occasions) && is_array($occasions)) ? $occasions : [];
  $extCfg    = (isset($extCfg) && is_array($extCfg)) ? $extCfg : ['referral' => 250, 'birthday' => 200, 'anniversary' => 300];
  $isOwner   = !empty($isOwner);
  $base      = root . 'supplier/loyalty';
  $enabled   = !empty($cfg['enabled']);
  $num = function ($v) { return number_format((float) $v, 0); };
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Loyalty &amp; membership</h1>
      <p class="text-sm text-gray-600">Member rates &amp; benefits, referrals, birthday/anniversary offers, and program analytics. Points span every property in your organisation.</p>
    </div>
    <span class="px-2 py-1 rounded text-xs font-medium <?= $enabled ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $enabled ? 'Loyalty on' : 'Loyalty off' ?></span>
  </div>

  <?php if (!$enabled): ?>
    <div class="card p-4 bg-amber-50 border border-amber-100"><p class="text-sm text-amber-800">Loyalty is currently disabled. Turn it on and set the earn / redeem rate under <a href="<?= root ?>supplier/guests" class="underline">Guests → Loyalty settings</a>. The benefits and bonuses below take effect once it is on.</p></div>
  <?php endif; ?>

  <!-- ANALYTICS (#22 loyalty analytics) -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="card p-4"><div class="text-xs text-gray-500">Members</div><div class="text-xl font-bold text-gray-900"><?= $num($analytics['members'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Points outstanding</div><div class="text-xl font-bold text-gray-900"><?= $num($analytics['points_outstanding'] ?? 0) ?></div><?php if (!empty($analytics['cash_liability'])): ?><div class="text-xs text-gray-400 mt-0.5">≈ <?= number_format((float) $analytics['cash_liability'], 2) ?> liability</div><?php endif; ?></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Earned (30d)</div><div class="text-xl font-bold text-green-700"><?= $num($analytics['earned_30d'] ?? 0) ?></div><div class="text-xs text-gray-400 mt-0.5"><?= $num($analytics['earned'] ?? 0) ?> lifetime</div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Redeemed (30d)</div><div class="text-xl font-bold text-violet-700"><?= $num($analytics['redeemed_30d'] ?? 0) ?></div><div class="text-xs text-gray-400 mt-0.5"><?= $num($analytics['redeemed'] ?? 0) ?> lifetime</div></div>
  </div>

  <div class="grid md:grid-cols-2 gap-6">
    <!-- TIER DISTRIBUTION -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Tier distribution</h2>
      <?php $tc = $analytics['tier_counts'] ?? []; if (!empty($tc)): ?>
        <div class="space-y-2">
          <?php foreach ($tc as $tier => $count): ?>
            <div class="flex items-center justify-between text-sm">
              <span class="text-gray-700"><?= htmlspecialchars((string) $tier) ?></span>
              <span class="font-medium text-gray-900"><?= $num($count) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-400">No members yet.</p>
      <?php endif; ?>
    </div>

    <!-- TOP MEMBERS -->
    <div class="card p-5">
      <h2 class="text-sm font-semibold text-gray-900 mb-3">Top members by balance</h2>
      <?php $top = $analytics['top'] ?? []; if (!empty($top)): ?>
        <div class="space-y-2">
          <?php foreach ($top as $m): ?>
            <div class="flex items-center justify-between text-sm">
              <span class="text-gray-700 truncate mr-2"><?= htmlspecialchars((string) ($m['email'] ?? '')) ?></span>
              <span class="flex items-center gap-2 shrink-0">
                <span class="px-2 py-0.5 rounded bg-violet-50 text-violet-700 text-xs"><?= htmlspecialchars((string) ($m['tier'] ?? 'Member')) ?></span>
                <span class="font-medium text-gray-900"><?= $num($m['balance'] ?? 0) ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-sm text-gray-400">No members yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- MEMBER RATES & BENEFITS (#22 member rates / member benefits) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Member rates &amp; benefits</h2>
    <p class="text-xs text-gray-500 mb-3">Each tier can earn a percentage member rate (applied as a discount on a guest's folio from the reservation screen) plus free-text perks shown to your team.</p>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="py-2 pr-3">Tier</th><th class="py-2 pr-3">Member rate %</th><th class="py-2 pr-3">Perks</th><?php if ($isOwner): ?><th class="py-2"></th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($benefits as $tier => $bn): ?>
            <?php if ($isOwner): ?>
            <tr class="border-b border-gray-50">
              <form action="<?= $base ?>/benefit" method="POST" class="contents">
                <?= CSRF::tokenField() ?><input type="hidden" name="tier" value="<?= htmlspecialchars((string) $tier) ?>">
                <td class="py-2 pr-3 font-medium text-gray-900"><?= htmlspecialchars((string) $tier) ?></td>
                <td class="py-2 pr-3"><input type="number" step="0.01" min="0" max="100" name="rate_pct" value="<?= htmlspecialchars((string) ($bn['rate_pct'] ?? 0)) ?>" class="input text-xs w-24"></td>
                <td class="py-2 pr-3"><input type="text" name="perks" value="<?= htmlspecialchars((string) ($bn['perks'] ?? '')) ?>" class="input text-xs w-full" maxlength="500"></td>
                <td class="py-2"><button type="submit" class="text-xs text-violet-600 hover:underline">Save</button></td>
              </form>
            </tr>
            <?php else: ?>
            <tr class="border-b border-gray-50">
              <td class="py-2 pr-3 font-medium text-gray-900"><?= htmlspecialchars((string) $tier) ?></td>
              <td class="py-2 pr-3 text-gray-700"><?= htmlspecialchars((string) ($bn['rate_pct'] ?? 0)) ?>%</td>
              <td class="py-2 pr-3 text-gray-600"><?= htmlspecialchars((string) ($bn['perks'] ?? '')) ?></td>
            </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- APPLY MEMBER RATE TO A FOLIO (money, server-derived) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Apply a member rate to a folio</h2>
    <p class="text-xs text-gray-500 mb-3">Posts the guest's current-tier member rate as a discount on an open folio. The amount is computed from the folio balance — not entered here — and can only be applied once per folio.</p>
    <form action="<?= $base ?>/member-rate" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <input type="text" name="invoice_id" class="input text-sm" placeholder="Booking invoice ID *" required>
      <button type="submit" class="btn secondary text-sm">Apply member rate</button>
    </form>
  </div>

  <!-- BONUS SETTINGS (#22 referral / birthday / anniversary sizes) — OWNER -->
  <?php if ($isOwner): ?>
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Bonus points</h2>
    <p class="text-xs text-gray-500 mb-3">Point bonuses for referrals and guest occasions. Set any to 0 to disable it.</p>
    <form action="<?= $base ?>/bonuses" method="POST" class="flex flex-wrap items-end gap-3">
      <?= CSRF::tokenField() ?>
      <label class="text-xs text-gray-700">Referral<br><input type="number" min="0" name="referral_bonus_points" value="<?= (int) ($extCfg['referral'] ?? 0) ?>" class="input text-sm w-28"></label>
      <label class="text-xs text-gray-700">Birthday<br><input type="number" min="0" name="birthday_bonus_points" value="<?= (int) ($extCfg['birthday'] ?? 0) ?>" class="input text-sm w-28"></label>
      <label class="text-xs text-gray-700">Anniversary<br><input type="number" min="0" name="anniversary_bonus_points" value="<?= (int) ($extCfg['anniversary'] ?? 0) ?>" class="input text-sm w-28"></label>
      <button type="submit" class="btn secondary text-sm">Save bonuses</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- REFERRAL PROGRAM (#22 referral) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Referral program</h2>
    <p class="text-xs text-gray-500 mb-3">When a new guest cites an existing member's referral code on a paid stay, credit the referrer their referral bonus. A booking can credit a referral once.</p>
    <form action="<?= $base ?>/referral" method="POST" class="flex flex-wrap items-end gap-2">
      <?= CSRF::tokenField() ?>
      <input type="text" name="invoice_id" class="input text-sm" placeholder="Referred booking invoice ID *" required>
      <input type="text" name="code" class="input text-sm" placeholder="Referral code (e.g. R1A2B3C4) *" required>
      <button type="submit" class="btn secondary text-sm">Credit referrer</button>
    </form>
    <p class="text-xs text-gray-400 mt-2">A member's referral code is shown on their guest profile (Guests → open a guest).</p>
  </div>

  <!-- BIRTHDAY / ANNIVERSARY OFFERS (#22) -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Birthday &amp; anniversary offers</h2>
    <p class="text-xs text-gray-500 mb-3">Guests with an occasion this month. Grant each the configured bonus once per year.</p>

    <form action="<?= $base ?>/occasion/save" method="POST" class="flex flex-wrap items-end gap-2 mb-4 pb-4 border-b border-gray-100">
      <?= CSRF::tokenField() ?>
      <input type="email" name="email" class="input text-sm" placeholder="Guest email *" required>
      <label class="text-xs text-gray-700">Birthday<br><input type="date" name="birth_date" class="input text-sm"></label>
      <label class="text-xs text-gray-700">Anniversary<br><input type="date" name="anniversary_date" class="input text-sm"></label>
      <button type="submit" class="btn secondary text-sm">Save dates</button>
    </form>

    <?php if (!empty($occasions)): ?>
      <div class="space-y-2">
        <?php foreach ($occasions as $o): ?>
          <div class="flex items-center justify-between gap-2 text-sm">
            <span class="flex items-center gap-2">
              <span class="material-symbols-outlined text-base text-pink-500"><?= ($o['kind'] ?? '') === 'birthday' ? 'cake' : 'favorite' ?></span>
              <span class="text-gray-800 truncate"><?= htmlspecialchars((string) ($o['email'] ?? '')) ?></span>
              <span class="text-xs text-gray-400"><?= htmlspecialchars(ucfirst((string) ($o['kind'] ?? ''))) ?> · <?= htmlspecialchars((string) ($o['date'] ?? '')) ?></span>
            </span>
            <?php if (!empty($o['granted'])): ?>
              <span class="px-2 py-0.5 rounded bg-green-50 text-green-700 text-xs">Granted</span>
            <?php else: ?>
              <form action="<?= $base ?>/occasion/grant" method="POST">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="email" value="<?= htmlspecialchars((string) ($o['email'] ?? '')) ?>">
                <input type="hidden" name="kind" value="<?= htmlspecialchars((string) ($o['kind'] ?? '')) ?>">
                <button type="submit" class="text-xs text-violet-600 hover:underline">Grant bonus</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="text-sm text-gray-400">No guest occasions this month. Add dates above to build the list.</p>
    <?php endif; ?>
  </div>

</div>
