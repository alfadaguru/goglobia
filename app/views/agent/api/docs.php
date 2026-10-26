<?php
// ============================================================================
// Agent API CONSOLE — dedicated, well-structured documentation page.
// Rendered inside the agent portal shell by /agent/api/docs. Accurate to the
// real contract: the agent API authenticates with X-Agent-Key and reuses the
// existing /api/* REST endpoints; access is HYBRID (agency in once, each
// service certified separately). Expects: $agentApiHost, $services (enabled),
// $owner. Anchored left-nav + copyable examples.
// ============================================================================
@$SECURE or die('Access Denied!');
$host = (isset($agentApiHost) && $agentApiHost !== '') ? $agentApiHost : 'api.goglobia.com';
$base = 'https://' . $host . '/api';
$enabled = array_map(fn($s) => $s['service'], $services ?? []);
$hostReady = (isset($agentApiHost) && $agentApiHost !== '');

// Real endpoint reference (paths are the existing /api/* REST routes the key reuses).
$catalog = [
  'flights' => [['POST','/api/flights/search','Search flights'],['POST','/api/flight/booking/save-draft','Create a draft'],['POST','/api/flight/booking/submit','Confirm + charge wallet'],['POST','/api/flight/booking/request-cancellation','Request cancellation']],
  'stays'   => [['POST','/api/stays/search','Search stays'],['POST','/api/stays/booking/draft','Create a draft'],['POST','/api/stays/booking/submit','Confirm + charge wallet'],['POST','/api/stays/booking/cancel','Cancel']],
  'tours'   => [['POST','/api/tours/search','Search tours'],['POST','/api/tours/booking/draft','Create a draft'],['POST','/api/tours/booking/submit','Confirm + charge wallet']],
  'cars'    => [['POST','/api/cars/search','Search cars'],['POST','/api/cars/booking/draft','Create a draft'],['POST','/api/cars/booking/submit','Confirm + charge wallet']],
  'visa'    => [['POST','/api/visa/search','Search visa products'],['POST','/api/visa/booking/submit','Confirm + charge wallet']],
  'umrah'   => [['POST','/api/umrah/search','Search packages'],['POST','/api/umrah/bookings/submit','Confirm + charge wallet']],
  'esim'    => [['GET','/api/esim/packages/{country}','List packages'],['POST','/api/esim/booking/submit','Buy + charge wallet']],
  'bus'     => [['POST','/api/bus/listing','Search buses'],['POST','/api/bus/booking/app-submit','Confirm + charge wallet']],
  'ferries' => [['POST','/api/ferries/search','Search sailings'],['POST','/api/ferries/booking/submit','Confirm + charge wallet']],
  'rail'    => [['POST','/api/rail/search','Search trains'],['POST','/api/rail/booking/submit','Confirm + charge wallet']],
];
$svcLabels = ['flights'=>'Flights','stays'=>'Hotels / Stays','cars'=>'Cars','tours'=>'Tours','visa'=>'Visa','umrah'=>'Umrah','esim'=>'eSIM','bus'=>'Bus','ferries'=>'Ferries','rail'=>'Rail'];
?>
<div class="px-4 py-6 max-w-6xl mx-auto">
  <h1 class="text-2xl font-semibold text-slate-900 mb-1">API Console</h1>
  <p class="text-sm text-slate-500 mb-5">Everything you need to integrate Goglobia into your own systems.</p>

  <?php require views . 'agent/api/_nav.php'; ?>

  <div class="lg:grid lg:grid-cols-[200px_1fr] lg:gap-8">
    <!-- ANCHOR NAV -->
    <nav class="hidden lg:block sticky top-6 self-start text-sm space-y-1">
      <a href="#getting-started" class="block px-2 py-1.5 rounded text-slate-600 hover:bg-slate-100">Getting started</a>
      <a href="#environments" class="block px-2 py-1.5 rounded text-slate-600 hover:bg-slate-100">Environments</a>
      <a href="#auth" class="block px-2 py-1.5 rounded text-slate-600 hover:bg-slate-100">Authentication</a>
      <a href="#billing" class="block px-2 py-1.5 rounded text-slate-600 hover:bg-slate-100">Billing &amp; wallet</a>
      <a href="#example" class="block px-2 py-1.5 rounded text-slate-600 hover:bg-slate-100">Example: book a flight</a>
      <a href="#webhooks" class="block px-2 py-1.5 rounded text-slate-600 hover:bg-slate-100">Webhooks</a>
      <a href="#errors" class="block px-2 py-1.5 rounded text-slate-600 hover:bg-slate-100">Errors &amp; limits</a>
      <a href="#endpoints" class="block px-2 py-1.5 rounded text-slate-600 hover:bg-slate-100">Endpoint reference</a>
    </nav>

    <div class="min-w-0 space-y-8">

      <!-- GETTING STARTED -->
      <section id="getting-started" class="section">
        <div class="section-header"><h2>Getting started</h2></div>
        <div class="p-4 text-sm text-slate-700 space-y-3">
          <p>Access is <strong>certified per service</strong>. The flow:</p>
          <ol class="list-decimal pl-5 space-y-1.5">
            <li><strong>Request API access</strong> for your agency on the <a class="text-violet-700" href="<?=root?>agent/api">Overview</a> tab — we reach out and grant you in.</li>
            <li><strong>Request each service</strong> you need on the <a class="text-violet-700" href="<?=root?>agent/api/services">Services</a> tab. Each runs its own <em>sandbox → certify → live</em> lifecycle.</li>
            <li><strong>Create a key</strong> on the <a class="text-violet-700" href="<?=root?>agent/api/keys">Keys</a> tab (start in sandbox). Build against sandbox, then request live certification per service.</li>
            <li><strong>Top up your wallet</strong> — bookings are charged from it (plus any per-service fee your account manager set).</li>
            <li>Optionally point a <a class="text-violet-700" href="<?=root?>agent/api/webhooks">Webhook</a> at your system for booking/payment events.</li>
          </ol>
        </div>
      </section>

      <!-- ENVIRONMENTS -->
      <section id="environments" class="section">
        <div class="section-header"><h2>Environments</h2></div>
        <div class="p-4 text-sm text-slate-700 space-y-2">
          <p>Two environments, selected by the <strong>key</strong> you send:</p>
          <ul class="list-disc pl-5 space-y-1">
            <li><strong>Sandbox</strong> — safe to test; no real money moves, no supplier tickets are issued.</li>
            <li><strong>Live</strong> — real bookings; your wallet is charged. A service only answers live once <em>that service</em> is live-certified for your agency.</li>
          </ul>
          <?php if (!$hostReady): ?>
            <div class="alert-info mt-2"><p>Your dedicated API host isn't configured yet. The base URL below is an example; your account manager will confirm the real host before you go live.</p></div>
          <?php endif; ?>
          <div class="code-block mt-2">Base URL:  <?= htmlspecialchars($base) ?></div>
        </div>
      </section>

      <!-- AUTH -->
      <section id="auth" class="section">
        <div class="section-header"><h2>Authentication</h2></div>
        <div class="p-4 text-sm text-slate-700 space-y-2">
          <p>Send your key in the <code>X-Agent-Key</code> header on every request. A key limited to certain services returns <code>403</code> for others; a missing/invalid key returns <code>401</code>.</p>
          <div class="code-block">curl -X POST "<?= htmlspecialchars($base) ?>/flights/search" \
  -H "X-Agent-Key: gk_xxxxxxxx.your-secret-here" \
  -H "Content-Type: application/json" \
  -d '{ "origin": "LOS", "destination": "KAN", "depart_date": "2026-11-01", "adults": 1 }'</div>
        </div>
      </section>

      <!-- BILLING -->
      <section id="billing" class="section">
        <div class="section-header"><h2>Billing &amp; wallet</h2></div>
        <div class="p-4 text-sm text-slate-700">
          <ul class="list-disc pl-5 space-y-1">
            <li>Bookings are charged to your <strong>credits wallet</strong> (top up from the dashboard).</li>
            <li>Each booking debits the <strong>booking total</strong> plus any <strong>service fee</strong> (percentage or flat) your account manager set for that service.</li>
            <li>If your balance (plus any credit line) is insufficient, the booking is rejected with <code>402</code> and nothing is charged.</li>
            <li>Prices are computed server-side at your B2B rate — you cannot set your own price.</li>
          </ul>
        </div>
      </section>

      <!-- EXAMPLE -->
      <section id="example" class="section">
        <div class="section-header"><h2>Example: book a flight end-to-end</h2></div>
        <div class="p-4 text-sm text-slate-700 space-y-3">
          <p><strong>1. Search</strong></p>
          <div class="code-block">POST <?= htmlspecialchars($base) ?>/flights/search
{ "origin": "LOS", "destination": "KAN", "trip_type": "oneway",
  "depart_date": "2026-11-01", "cabin_class": "economy",
  "adults": 1, "children": 0, "infants": 0 }</div>
          <p class="text-xs text-slate-500">Returns offers, each with a <code>booking_hash</code>/offer reference and a server-side price in your base currency.</p>

          <p><strong>2. Create a draft</strong> (locks the offer)</p>
          <div class="code-block">POST <?= htmlspecialchars($base) ?>/flight/booking/save-draft
{ "flight_data": { ...offer... }, "search_params": { ... } }
→ { "success": true, "hash": "e52887ea749373b8" }</div>

          <p><strong>3. Confirm + charge wallet</strong></p>
          <div class="code-block">POST <?= htmlspecialchars($base) ?>/flight/booking/submit
{ "booking_hash": "e52887ea749373b8",
  "guest_details": {
    "primary_guest": { "first_name": "...", "last_name": "...", "email": "...",
                       "country_code": "NG", "phone": "8030000000" },
    "passengers": { "adult_0": { "first_name": "...", "last_name": "...", "dob": "1990-01-01" } }
  } }
→ { "success": true, "invoice_id": "AB12CD34", "status": "confirmed" }</div>
          <p class="text-xs text-slate-500">For a <strong>domestic</strong> itinerary (same-country origin &amp; destination) passport/nationality are not required. International trips must include passport details per passenger.</p>
        </div>
      </section>

      <!-- WEBHOOKS -->
      <section id="webhooks" class="section">
        <div class="section-header"><h2>Webhooks</h2></div>
        <div class="p-4 text-sm text-slate-700 space-y-3">
          <p>Register an HTTPS endpoint on the <a class="text-violet-700" href="<?=root?>agent/api/webhooks">Webhooks</a> tab. We <code>POST</code> a JSON body for each subscribed event:</p>
          <div class="code-block">POST https://your-system.example.com/webhook
X-Goglobia-Event: booking.confirmed
X-Goglobia-Signature: sha256=&lt;hex hmac of the raw body&gt;

{ "event": "booking.confirmed", "data": { "invoice_id": "AB12CD34", ... }, "ts": 1730000000 }</div>
          <p><strong>Verify the signature</strong> with the signing secret shown once when you create the webhook:</p>
          <div class="code-block"># PHP
$payload = file_get_contents('php://input');
$sig = hash_hmac('sha256', $payload, $YOUR_WEBHOOK_SECRET);
if (!hash_equals('sha256=' . $sig, $_SERVER['HTTP_X_GOGLOBIA_SIGNATURE'] ?? '')) {
    http_response_code(400); exit; // reject — not from Goglobia
}</div>
          <p class="text-xs text-slate-500">Events: <?= htmlspecialchars(implode(', ', agent_api_webhook_events())) ?>. Respond <code>2xx</code> to acknowledge; non-2xx is retried.</p>
        </div>
      </section>

      <!-- ERRORS -->
      <section id="errors" class="section">
        <div class="section-header"><h2>Errors &amp; rate limits</h2></div>
        <div class="p-4">
          <table class="table">
            <tbody>
              <tr><td><span class="badge badge-success badge-sm">200</span></td><td>Success</td></tr>
              <tr><td><span class="badge badge-warning badge-sm">401</span></td><td>Missing or invalid API key</td></tr>
              <tr><td><span class="badge badge-warning badge-sm">402</span></td><td>Insufficient wallet balance (nothing charged)</td></tr>
              <tr><td><span class="badge badge-warning badge-sm">403</span></td><td>Service not enabled / not live-certified for your key</td></tr>
              <tr><td><span class="badge badge-warning badge-sm">429</span></td><td>Rate limit exceeded — slow down</td></tr>
            </tbody>
          </table>
          <p class="text-xs text-slate-500 mt-2 px-1">Each key is rate-limited per rolling 60 seconds (your account manager sets the limit).</p>
        </div>
      </section>

      <!-- ENDPOINT REFERENCE -->
      <section id="endpoints" class="section">
        <div class="section-header"><h2>Endpoint reference by service</h2>
          <p>Your key is enabled for: <?= empty($enabled) ? '<em>none yet — request services on the Services tab</em>' : '<strong>'.htmlspecialchars(implode(', ', array_map(fn($s)=>$svcLabels[$s]??$s, $enabled))).'</strong>' ?></p>
        </div>
        <div class="p-4 space-y-4">
          <?php foreach ($catalog as $svc => $eps): $isOn = in_array($svc, $enabled, true); ?>
            <div class="border border-slate-200 rounded-xl p-4 <?= $isOn ? '' : 'opacity-60' ?>">
              <div class="flex items-center gap-2 mb-2">
                <h3 class="font-semibold text-slate-900"><?= htmlspecialchars($svcLabels[$svc] ?? ucfirst($svc)) ?></h3>
                <?php if($isOn): ?><span class="badge badge-success badge-sm">enabled</span><?php else: ?><span class="badge badge-gray badge-sm">not enabled</span><?php endif; ?>
              </div>
              <table class="table">
                <tbody>
                  <?php foreach ($eps as $e): ?>
                    <tr>
                      <td class="w-16"><span class="badge badge-info badge-sm"><?= $e[0] ?></span></td>
                      <td><code class="text-xs"><?= htmlspecialchars($e[1]) ?></code></td>
                      <td class="text-slate-600"><?= htmlspecialchars($e[2]) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

    </div>
  </div>
</div>
