<?php
// ============================================================================
// AGENT API — DOCUMENTATION VIEW (Phase 3). See docs/AGENT-API.md.
// ============================================================================
@$SECURE or die('Access Denied!');
$host = $agentApiHost ?? 'api.goglobia.com';
$base = 'https://' . $host . '/api';
$enabled = array_map(fn($s) => $s['service'], $services ?? []);
?>
<div class="">
  <div class="flex gap-5 container mb-8">
    <div class="flex-shrink-0">
      <?php include views."auth/sidebar.php"; ?>
    </div>

    <main class="flex-1 min-w-0 pt-8 bg-white rounded-[8px] lg:ml-0 ml-[40px]">
      <div class="mx-auto max-w-4xl">

        <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
          <div>
            <h1 class="text-2xl font-semibold text-gray-900">API Documentation</h1>
            <p class="text-sm text-gray-600 mt-1">Integrate Goglobia travel services into your platform.</p>
          </div>
          <a href="<?=root?>api-access" class="btn outline"><span class="material-symbols-outlined">key</span><span>My Keys</span></a>
        </div>

        <!-- AUTH -->
        <div class="section mb-6">
          <div class="section-header"><h2>Authentication</h2></div>
          <p class="text-sm text-gray-700 mb-3">All requests go to your dedicated API host and must include your API key in the <code>X-Agent-Key</code> header. Generate a key on the <a class="text-primary" href="<?=root?>api-access">API Access</a> page.</p>
          <div class="code-block">Base URL:  <?= htmlspecialchars($base) ?>

curl -X POST "<?= htmlspecialchars($base) ?>/flights/search" \
  -H "X-Agent-Key: gk_xxxxxxxx.your-secret-here" \
  -H "Content-Type: application/json" \
  -d '{ ... }'</div>
          <p class="text-xs text-gray-500 mt-2">Requests without a valid key return <code>401</code>. A key limited to certain services returns <code>403</code> for others.</p>
        </div>

        <!-- WALLET / FEE MODEL -->
        <div class="section mb-6">
          <div class="section-header"><h2>Billing &amp; wallet</h2></div>
          <ul class="text-sm text-gray-700 list-disc pl-5 space-y-1">
            <li>Bookings are charged to your <strong>credits wallet</strong> (top up from your dashboard).</li>
            <li>Each booking debits the <strong>booking total</strong> plus any <strong>service fee</strong> your account manager set for that service (percentage or flat).</li>
            <li>If your balance (plus any credit limit) is insufficient, the booking is rejected with <code>402</code> and nothing is charged.</li>
            <li>Prices are calculated server-side at your B2B rate — you cannot set your own price.</li>
          </ul>
        </div>

        <!-- ENDPOINTS -->
        <div class="section mb-6">
          <div class="section-header"><h2>Endpoints by service</h2>
            <p>Your key is currently enabled for: <?= empty($enabled) ? '<em>none yet — contact your account manager</em>' : '<strong>'.htmlspecialchars(implode(', ', $enabled)).'</strong>' ?></p>
          </div>

          <div class="space-y-4 text-sm">
            <?php
            $catalog = [
              'flights' => [['POST','/flights/search','Search flights'],['POST','/flights/booking/draft','Create a draft'],['POST','/flights/booking/submit','Confirm + charge wallet'],['POST','/flights/booking/request-cancellation','Request cancellation']],
              'stays'   => [['POST','/stays/booking/draft','Create a draft'],['POST','/stays/booking/submit','Confirm + charge wallet'],['POST','/stays/booking/cancel','Cancel']],
              'cars'    => [['POST','/cars/booking/draft','Create a draft'],['POST','/cars/booking/submit','Confirm + charge wallet']],
              'tours'   => [['POST','/tours/booking/draft','Create a draft'],['POST','/tours/booking/submit','Confirm + charge wallet']],
              'visa'    => [['POST','/visas/search','Search visa products'],['POST','/visas/booking/draft','Create a draft'],['POST','/visas/booking/submit','Confirm + charge wallet']],
              'umrah'   => [['POST','/umrah/search','Search packages'],['POST','/umrah/booking/draft','Create a draft'],['POST','/umrah/bookings/submit','Confirm + charge wallet']],
              'esim'    => [['GET','/esim/packages/{country}','List packages'],['POST','/esim/booking/submit','Buy + charge wallet']],
              'bus'     => [['POST','/bus/listing','Search buses'],['POST','/bus/booking/draft','Create a draft'],['POST','/bus/booking/app-submit','Confirm + charge wallet']],
              'ferries' => [['POST','/ferries/search','Search sailings'],['POST','/ferries/booking/draft','Create a draft'],['POST','/ferries/booking/submit','Confirm + charge wallet']],
              'rail'    => [['POST','/rail/search','Search trains'],['POST','/rail/booking/submit','Confirm + charge wallet']],
            ];
            foreach ($catalog as $svc => $eps):
              $isOn = in_array($svc, $enabled, true);
            ?>
              <div class="border border-gray-200 rounded-xl p-4 <?= $isOn ? '' : 'opacity-60' ?>">
                <div class="flex items-center gap-2 mb-2">
                  <h3 class="font-semibold text-gray-900 capitalize"><?= htmlspecialchars($svc) ?></h3>
                  <?php if($isOn): ?><span class="badge badge-success badge-sm">enabled</span><?php else: ?><span class="badge badge-gray badge-sm">not enabled</span><?php endif; ?>
                </div>
                <table class="table">
                  <tbody>
                    <?php foreach ($eps as $e): ?>
                      <tr>
                        <td class="w-16"><span class="badge badge-info badge-sm"><?= $e[0] ?></span></td>
                        <td><code class="text-xs"><?= htmlspecialchars($e[1]) ?></code></td>
                        <td class="text-gray-600"><?= htmlspecialchars($e[2]) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- ERRORS -->
        <div class="section">
          <div class="section-header"><h2>Response codes</h2></div>
          <table class="table">
            <tbody>
              <tr><td><span class="badge badge-success badge-sm">200</span></td><td>Success</td></tr>
              <tr><td><span class="badge badge-warning badge-sm">401</span></td><td>Missing or invalid API key</td></tr>
              <tr><td><span class="badge badge-warning badge-sm">402</span></td><td>Insufficient wallet balance (nothing charged)</td></tr>
              <tr><td><span class="badge badge-warning badge-sm">403</span></td><td>Service not enabled for your key</td></tr>
              <tr><td><span class="badge badge-warning badge-sm">429</span></td><td>Rate limit exceeded — slow down</td></tr>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>
</div>
