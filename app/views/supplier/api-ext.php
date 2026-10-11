<?php @$SECURE or die('Access Denied!'); ?>
<?php
  $events     = (isset($events) && is_array($events)) ? $events : [];
  $endpoints  = (isset($endpoints) && is_array($endpoints)) ? $endpoints : [];
  $deliveries = (isset($deliveries) && is_array($deliveries)) ? $deliveries : [];
  $newSecret  = isset($newSecret) ? (string) $newSecret : '';
  $docsMode   = !empty($docsMode);
  $base       = root . 'supplier/api';
  $stBadge = [
    'pending'   => 'bg-amber-50 text-amber-700',
    'delivered' => 'bg-green-50 text-green-700',
    'failed'    => 'bg-red-50 text-red-700',
  ];
  $stLabel = [
    'pending'   => 'Queued',
    'delivered' => 'Delivered',
    'failed'    => 'Failed',
  ];
  $epById = []; foreach ($endpoints as $e) { $epById[(int) $e['id']] = $e; }
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-start justify-between gap-2">
    <div>
      <h1 class="text-2xl font-bold text-gray-900"><?= $docsMode ? 'Developer docs' : 'API &amp; webhooks' ?></h1>
      <p class="text-sm text-gray-600"><?= $docsMode
        ? 'How to receive real-time events from your GoGlobia account.'
        : 'Register outbound webhooks and watch their delivery log. Third-party channel adapters (Booking.com, Expedia, …) are configured under Integrations.' ?></p>
    </div>
    <a href="<?= $base . ($docsMode ? '' : '/docs') ?>" class="btn secondary text-sm whitespace-nowrap">
      <span class="material-symbols-outlined text-base align-middle"><?= $docsMode ? 'arrow_back' : 'menu_book' ?></span>
      <?= $docsMode ? 'Back to webhooks' : 'Developer docs' ?>
    </a>
  </div>

<?php if ($docsMode): ?>
  <!-- ============== DEVELOPER DOCS ============== -->
  <div class="card p-5 space-y-3">
    <h2 class="text-sm font-semibold text-gray-900">Webhook events</h2>
    <p class="text-xs text-gray-500">When one of these happens on your account, GoGlobia queues a delivery to each of your enabled endpoints subscribed to that event.</p>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-100"><th class="py-2 pr-4">Event</th><th class="py-2">Fires when</th></tr></thead>
        <tbody>
          <?php foreach ($events as $key => $desc): ?>
            <tr class="border-b border-gray-50">
              <td class="py-2 pr-4"><code class="text-xs bg-gray-50 border border-gray-200 rounded px-1.5 py-0.5"><?= htmlspecialchars((string) $key) ?></code></td>
              <td class="py-2 text-gray-600"><?= htmlspecialchars((string) $desc) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card p-5 space-y-2">
    <h2 class="text-sm font-semibold text-gray-900">Payload &amp; signature</h2>
    <p class="text-xs text-gray-500">Each delivery is an HTTP <code class="text-xs">POST</code> with a JSON body:</p>
    <pre class="text-xs bg-gray-900 text-gray-100 rounded p-3 overflow-x-auto">{
  "event": "booking.created",
  "key":   "booking.created:84213",
  "data":  { ... }
}</pre>
    <p class="text-xs text-gray-500">Verify authenticity with the <code class="text-xs">X-GoGlobia-Signature</code> header, an HMAC-SHA256 of the raw body keyed by your endpoint secret:</p>
    <pre class="text-xs bg-gray-900 text-gray-100 rounded p-3 overflow-x-auto">X-GoGlobia-Signature: sha256=hex(hmac_sha256(raw_body, endpoint_secret))</pre>
    <p class="text-xs text-gray-500">The <code class="text-xs">key</code> field is a stable per-event identity: we never queue the same <code class="text-xs">key</code> to the same endpoint twice, so you can treat it as an idempotency key on your side too.</p>
  </div>

  <div class="card p-5">
    <div class="flex items-start gap-2">
      <span class="material-symbols-outlined text-amber-500 text-base">info</span>
      <div class="text-xs text-gray-600">
        <p class="font-medium text-gray-800">Delivery status — honest note</p>
        <p>Endpoints and the delivery log are fully recorded, but this environment does not yet run a background delivery worker, so queued events are <strong>not</strong> actually POSTed to your URL — they stay in the <em>Queued</em> state. Inbound REST credentials and OTA channel sync are platform-administered; see <a href="<?= root ?>supplier/integrations" class="text-violet-600 hover:underline">Integrations</a>.</p>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ============== WEBHOOK ENDPOINTS ============== -->
  <?php if ($newSecret !== ''): ?>
    <div class="card p-5 border border-amber-200 bg-amber-50/40">
      <h2 class="text-sm font-semibold text-amber-800 mb-1">Your signing secret (shown once)</h2>
      <p class="text-xs text-amber-700 mb-2">Store this now. Use it to verify the <code class="text-xs">X-GoGlobia-Signature</code> header. You can rotate it later, but it will not be shown again.</p>
      <code class="block text-xs bg-white border border-amber-200 rounded px-2 py-2 break-all"><?= htmlspecialchars($newSecret) ?></code>
    </div>
  <?php endif; ?>

  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Add an endpoint</h2>
    <form action="<?= $base ?>/endpoint" method="POST" class="space-y-3">
      <?= CSRF::tokenField() ?>
      <input type="url" name="url" class="input text-sm w-full" placeholder="https://your-system.example.com/webhooks/goglobia" required>
      <div>
        <p class="text-xs text-gray-500 mb-1">Events (leave all unchecked to receive every event):</p>
        <div class="flex flex-wrap gap-3">
          <?php foreach ($events as $key => $desc): ?>
            <label class="inline-flex items-center gap-1.5 text-xs text-gray-700">
              <input type="checkbox" name="events[]" value="<?= htmlspecialchars((string) $key) ?>" class="checkbox-input">
              <code class="text-xs"><?= htmlspecialchars((string) $key) ?></code>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="submit" class="btn secondary text-sm">Add endpoint</button>
    </form>
    <p class="text-xs text-gray-400 mt-2">HTTPS only — we never send secrets over plaintext HTTP.</p>
  </div>

  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3">Your endpoints</h2>
    <?php if (empty($endpoints)): ?>
      <p class="text-sm text-gray-500">No webhook endpoints registered yet.</p>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($endpoints as $ep): $on = (int) ($ep['status'] ?? 0) === 1; $ev = trim((string) ($ep['events'] ?? '')); ?>
          <div class="border border-gray-100 rounded-lg p-4">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <code class="text-xs text-gray-800 break-all"><?= htmlspecialchars((string) $ep['url']) ?></code>
                <div class="mt-1 text-xs text-gray-500"><?= $ev === '' ? 'All events' : htmlspecialchars($ev) ?></div>
              </div>
              <span class="px-2 py-0.5 rounded text-xs font-medium <?= $on ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $on ? 'Enabled' : 'Disabled' ?></span>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-3">
              <form action="<?= $base ?>/endpoint/toggle" method="POST" class="inline">
                <?= CSRF::tokenField() ?><input type="hidden" name="endpoint_id" value="<?= (int) $ep['id'] ?>">
                <?php if (!$on): ?><input type="hidden" name="enabled" value="1"><?php endif; ?>
                <button type="submit" class="text-xs text-violet-600 hover:underline"><?= $on ? 'Disable' : 'Enable' ?></button>
              </form>
              <span class="text-gray-200">|</span>
              <form action="<?= $base ?>/endpoint/rotate" method="POST" class="inline" onsubmit="return confirm('Rotate the signing secret? The current one stops working immediately.');">
                <?= CSRF::tokenField() ?><input type="hidden" name="endpoint_id" value="<?= (int) $ep['id'] ?>">
                <button type="submit" class="text-xs text-violet-600 hover:underline">Rotate secret</button>
              </form>
              <span class="text-gray-200">|</span>
              <form action="<?= $base ?>/endpoint/delete" method="POST" class="inline" onsubmit="return confirm('Delete this endpoint and its delivery log?');">
                <?= CSRF::tokenField() ?><input type="hidden" name="endpoint_id" value="<?= (int) $ep['id'] ?>">
                <button type="submit" class="text-xs text-red-600 hover:underline">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============== DELIVERY LOG ============== -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Delivery log</h2>
    <p class="text-xs text-gray-500 mb-3">Most recent 50 queued deliveries. No background delivery worker is connected yet, so events stay <em>Queued</em> rather than being POSTed — the log, idempotency and retry accounting are real.</p>
    <?php if (empty($deliveries)): ?>
      <p class="text-sm text-gray-500">No deliveries queued yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-100">
            <th class="py-2 pr-4">Event</th><th class="py-2 pr-4">Endpoint</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Attempts</th><th class="py-2 pr-4">Last attempt</th><th class="py-2"></th>
          </tr></thead>
          <tbody>
            <?php foreach ($deliveries as $d): $st = (string) ($d['status'] ?? 'pending'); $epId = (int) ($d['endpoint_id'] ?? 0); $epUrl = isset($epById[$epId]) ? (string) $epById[$epId]['url'] : ('#' . $epId); ?>
              <tr class="border-b border-gray-50">
                <td class="py-2 pr-4"><code class="text-xs"><?= htmlspecialchars((string) ($d['event_type'] ?? '')) ?></code><div class="text-[11px] text-gray-400"><?= htmlspecialchars((string) ($d['event_key'] ?? '')) ?></div></td>
                <td class="py-2 pr-4"><code class="text-[11px] text-gray-500 break-all"><?= htmlspecialchars($epUrl) ?></code></td>
                <td class="py-2 pr-4"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stBadge[$st] ?? 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars($stLabel[$st] ?? ucfirst($st)) ?></span></td>
                <td class="py-2 pr-4 text-gray-600"><?= (int) ($d['attempts'] ?? 0) ?></td>
                <td class="py-2 pr-4 text-gray-500 text-xs"><?= htmlspecialchars((string) ($d['last_attempt_at'] ?? '—')) ?></td>
                <td class="py-2 text-right">
                  <?php if ($st !== 'delivered'): ?>
                    <form action="<?= $base ?>/delivery/retry" method="POST" class="inline">
                      <?= CSRF::tokenField() ?><input type="hidden" name="delivery_id" value="<?= (int) $d['id'] ?>">
                      <button type="submit" class="text-xs text-violet-600 hover:underline">Retry</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
