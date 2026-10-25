<?php
// ============================================================================
// AGENT API — API ACCESS VIEW (Phase 3). See docs/AGENT-API.md.
// Manage own API keys, view enabled services + fees, wallet + usage.
// Layout mirrors deposit.php (sidebar + main content).
// ============================================================================
@$SECURE or die('Access Denied!');

$__flashOk  = $_SESSION['success_message'] ?? null; unset($_SESSION['success_message']);
$__flashErr = $_SESSION['error_message'] ?? null;   unset($_SESSION['error_message']);
$csrf = CSRF::getToken();
$svcLabels = [
    'flights'=>'Flights','stays'=>'Hotels / Stays','cars'=>'Cars','tours'=>'Tours',
    'visa'=>'Visa','umrah'=>'Umrah','esim'=>'eSIM','bus'=>'Bus','ferries'=>'Ferries','rail'=>'Rail',
];
?>
<style>[x-cloak]{display:none!important}</style>

<div class="">
  <div class="flex gap-5 container mb-8">
    <div class="flex-shrink-0">
      <?php include views."auth/sidebar.php"; ?>
    </div>

    <main class="flex-1 min-w-0 pt-8 bg-white rounded-[8px] lg:ml-0 ml-[40px]">
      <div class="mx-auto">

        <!-- HEADER -->
        <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
          <div>
            <h1 class="text-2xl font-semibold text-gray-900">API Access</h1>
            <p class="text-sm text-gray-600 mt-1">Generate keys and integrate Goglobia services into your own systems.</p>
          </div>
          <a href="<?=root?>api-docs" class="btn outline"><span class="material-symbols-outlined">menu_book</span><span>API Docs</span></a>
        </div>

        <?php if ($__flashOk): ?>
          <div class="alert-success mb-4"><span class="material-icon material-symbols-outlined">check_circle</span><p><?= htmlspecialchars($__flashOk) ?></p></div>
        <?php endif; ?>
        <?php if ($__flashErr): ?>
          <div class="alert-error mb-4"><span class="material-icon material-symbols-outlined">error</span><p><?= htmlspecialchars($__flashErr) ?></p></div>
        <?php endif; ?>

        <?php if (!empty($freshKey)): ?>
          <div class="alert-warning mb-6" x-data="{k:'<?= htmlspecialchars($freshKey, ENT_QUOTES) ?>'}">
            <div class="flex-1">
              <p class="font-semibold mb-1">Your new API key (shown once):</p>
              <div class="flex items-center gap-2">
                <code class="bg-white/70 px-3 py-2 rounded-lg text-sm break-all flex-1" x-text="k"></code>
                <button type="button" class="btn btn-sm" @click="navigator.clipboard.writeText(k)">Copy</button>
              </div>
              <p class="text-xs mt-2">Store it securely now. For security we only keep a hash — it cannot be shown again.</p>
            </div>
          </div>
        <?php endif; ?>

        <!-- WALLET + USAGE SUMMARY -->
        <div class="cards-grid-2 mb-8">
          <div class="card border">
            <div class="card-stat-label">Wallet balance (credits)</div>
            <div class="card-stat-value"><?= number_format((float)($walletBalance ?? 0), 2) ?></div>
            <p class="text-xs text-gray-500 mt-1">Bookings made via the API are paid from this balance.</p>
          </div>
          <div class="card border">
            <div class="card-stat-label">API requests logged</div>
            <div class="card-stat-value"><?= (int)($usageCount ?? 0) ?></div>
            <p class="text-xs text-gray-500 mt-1">Total requests recorded for your account.</p>
          </div>
        </div>

        <?php if (empty($agentApiHost)): ?>
          <div class="alert-info mb-6"><span class="material-icon material-symbols-outlined">info</span>
            <p>The API host is not configured yet. Keys can be created, but calls only work once your account manager enables the API host.</p></div>
        <?php else: ?>
          <div class="alert-info mb-6"><span class="material-icon material-symbols-outlined">info</span>
            <p>Base URL: <code>https://<?= htmlspecialchars($agentApiHost) ?>/api</code> — send your key in the <code>X-Agent-Key</code> header.</p></div>
        <?php endif; ?>

        <!-- ENABLED SERVICES + FEES -->
        <div class="section mb-8">
          <div class="section-header"><h2>Enabled services &amp; fees</h2>
            <p>Services your key may call, and the per-booking fee your account manager has set.</p></div>
          <div class="table-container">
            <table class="table">
              <thead><tr><th>Service</th><th>Status</th><th>Fee</th></tr></thead>
              <tbody>
                <?php if (empty($services)): ?>
                  <tr><td colspan="3" class="text-gray-500">No services enabled yet. Contact your account manager.</td></tr>
                <?php else: foreach ($services as $s):
                  $lbl = $svcLabels[$s['service']] ?? ucfirst($s['service']);
                  $on  = (int)$s['enabled'] === 1;
                  $fee = ((string)$s['fee_type'] === 'flat') ? number_format((float)$s['fee_value'],2).' flat' : rtrim(rtrim((string)$s['fee_value'],'0'),'.').'%';
                ?>
                  <tr>
                    <td><?= htmlspecialchars($lbl) ?></td>
                    <td><?php if($on): ?><span class="badge badge-success">Enabled</span><?php else: ?><span class="badge badge-gray">Disabled</span><?php endif; ?></td>
                    <td><?= $on ? htmlspecialchars($fee) : '—' ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php
          // PAID-SERVICE access stage (set by agentApiRoutes.php). Controls what
          // the agent can do here. $apiAccessStatus ∈ none/requested/sandbox/
          // certifying/live/rejected/suspended; $apiCanMint ∈ ''|sandbox|live.
          $apiAccessStatus = $apiAccessStatus ?? 'none';
          $apiCanMint = $apiCanMint ?? '';
        ?>

        <!-- API ACCESS STAGE -->
        <div class="section">
          <div class="section-header"><div><h2>API access</h2><p>The API is a paid, certified integration. Request access, we’ll reach out, then you build in sandbox before going live.</p></div></div>

          <?php if ($apiAccessStatus === 'none' || $apiAccessStatus === 'rejected'): ?>
            <?php if ($apiAccessStatus === 'rejected'): ?><div class="alert-warning mb-4">Your previous request was not approved. You may request again below.</div><?php endif; ?>
            <form method="POST" action="<?=root?>api-access/request" class="mb-2 p-4 rounded-xl border border-gray-200 bg-slate-50">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <div class="form-grid">
                <div class="form-control"><label>Company name</label><input class="input" name="company_name" maxlength="255"></div>
                <div class="form-control"><label>Contact email</label><input class="input" type="email" name="contact_email" value="<?= htmlspecialchars($dashboardData['user_email'] ?? '') ?>" maxlength="255"></div>
                <div class="form-control"><label>Contact phone</label><input class="input" name="contact_phone" maxlength="40"></div>
                <div class="form-control"><label>Expected monthly volume</label><input class="input" name="expected_volume" placeholder="e.g. 500 bookings/mo" maxlength="120"></div>
              </div>
              <div class="form-control mt-3"><label>What will you build?</label><textarea class="input" name="use_case" rows="3" maxlength="2000"></textarea></div>
              <div class="mt-4"><button type="submit" class="btn">Request API access</button></div>
            </form>
          <?php elseif ($apiAccessStatus === 'requested'): ?>
            <div class="alert-warning">Your API access request is <strong>under review</strong>. Our team will reach out to you by email.</div>
          <?php elseif ($apiAccessStatus === 'sandbox'): ?>
            <div class="alert-success">✅ <strong>Sandbox access is active.</strong> Build and test against the sandbox. When your integration is ready, request live certification.</div>
            <form method="POST" action="<?=root?>api-access/request-live" class="mt-3">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <button type="submit" class="btn">Request live certification</button>
            </form>
          <?php elseif ($apiAccessStatus === 'certifying'): ?>
            <div class="alert-warning">Your sandbox integration is <strong>being certified</strong>. We’ll grant live access once it passes review.</div>
          <?php elseif ($apiAccessStatus === 'live'): ?>
            <div class="alert-success">✅ <strong>Live access is active.</strong> You can create sandbox and live keys below.</div>
          <?php elseif ($apiAccessStatus === 'suspended'): ?>
            <div class="alert-error">Your API access is currently <strong>suspended</strong>. Please contact support.</div>
          <?php endif; ?>
        </div>

        <!-- API KEYS (only when cleared to mint) -->
        <?php if ($apiCanMint !== ''): ?>
        <div class="section">
          <div class="section-header flex items-center justify-between">
            <div><h2>API keys</h2><p>Create a key for each integration. Revoke any key instantly.</p></div>
            <button class="btn" x-data @click="document.getElementById('gen-key-form').classList.toggle('hidden')">
              <span class="material-symbols-outlined">add</span><span>New key</span>
            </button>
          </div>

          <form id="gen-key-form" method="POST" action="<?=root?>api-access/generate" class="hidden mb-6 p-4 rounded-xl border border-gray-200 bg-slate-50">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="form-grid">
              <div class="form-control">
                <label>Environment</label>
                <select class="input" name="env">
                  <option value="sandbox">Sandbox</option>
                  <?php if ($apiCanMint === 'live'): ?><option value="live">Live</option><?php endif; ?>
                </select>
              </div>
              <div class="form-control">
                <label>Label</label>
                <input class="input" type="text" name="label" placeholder="e.g. Production server" maxlength="255">
              </div>
              <div class="form-control">
                <label>IP allow-list (optional)</label>
                <input class="input" type="text" name="ip_allowlist" placeholder="Comma-separated IPs, or blank for any">
              </div>
            </div>
            <div class="mt-4"><button type="submit" class="btn">Generate key</button></div>
          </form>

          <div class="table-container">
            <table class="table">
              <thead><tr><th>Key</th><th>Label</th><th>Status</th><th>Last used</th><th>Created</th><th></th></tr></thead>
              <tbody>
                <?php if (empty($keys)): ?>
                  <tr><td colspan="6" class="text-gray-500">No API keys yet. Click “New key” to create one.</td></tr>
                <?php else: foreach ($keys as $k):
                  $active = (string)$k['status'] === 'active';
                ?>
                  <tr>
                    <td><code class="text-xs"><?= htmlspecialchars($k['key_prefix']) ?>…</code></td>
                    <td><?= htmlspecialchars($k['label'] ?? '') ?: '<span class="text-gray-400">—</span>' ?></td>
                    <td><?php if($active): ?><span class="badge badge-success">Active</span><?php else: ?><span class="badge badge-error">Revoked</span><?php endif; ?></td>
                    <td class="text-xs text-gray-500"><?= htmlspecialchars($k['last_used_at'] ?? '') ?: 'Never' ?></td>
                    <td class="text-xs text-gray-500"><?= htmlspecialchars($k['created_at'] ?? '') ?></td>
                    <td>
                      <?php if ($active): ?>
                      <form method="POST" action="<?=root?>api-access/revoke" onsubmit="return confirm('Revoke this key? Applications using it will stop working immediately.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
                        <button type="submit" class="btn btn-sm rose">Revoke</button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; /* $apiCanMint !== '' — keys section */ ?>

      </div>
    </main>
  </div>
</div>
