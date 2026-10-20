<?php
// app/views/admin/campaigns/campaigns-manage.php — create/edit a campaign.
// HTML body via a styled textarea + live preview (full WYSIWYG designer = Phase 3).
@$SECURE or die('Access Denied!');

$cid = (int) ($_GET['id'] ?? 0);
$c = $cid > 0 ? $db->get('email_campaigns', '*', ['id' => $cid]) : null;
if ($cid > 0 && !$c) {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Campaign not found.'];
    redirect(root . admin . '/campaigns');
    return;
}

$isSent    = $c && in_array($c['status'], ['sent','sending'], true);
$subTotal  = (int) $db->count('newsletter_subscribers', ['status' => 'subscribed']);

// Templates for the "Start from template" picker (Phase 3). Ensure the library
// exists + is seeded so the picker is never empty on a fresh install.
if (function_exists('ensureCampaignTemplateSchema')) { ensureCampaignTemplateSchema($db); }
if (function_exists('campaignSeedStarters'))         { campaignSeedStarters($db); }
$templateChoices = (function_exists('dbTableExists') && dbTableExists($db, 'email_templates_library'))
    ? ($db->select('email_templates_library', ['id', 'name'], ['ORDER' => ['is_system' => 'DESC', 'name' => 'ASC']]) ?: [])
    : [];

// Live recipient count for the SAVED audience (de-duplicated across all selected
// groups), so the admin sees true reach before sending.
$audienceCount = 0;
if ($c && function_exists('campaign_resolve_recipients')) {
    try { $audienceCount = count(campaign_resolve_recipients($db, (array) $c)); }
    catch (\Throwable $e) { $audienceCount = 0; }
}
// Which groups are currently selected (for the checkbox state).
$selectedGroups = ($c && function_exists('campaign_selected_groups'))
    ? campaign_selected_groups((array) $c)
    : ['subscribers'];
$selectedRole = strtolower((string) ($c['audience_role'] ?? ''));

$v = function ($k, $d = '') use ($c) { return htmlspecialchars((string) ($c[$k] ?? $d), ENT_QUOTES); };
?>

<div class="container my-4" style="max-width:1000px">
  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/campaigns" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900"><?= $cid > 0 ? 'Edit campaign' : 'New campaign' ?></h1>
    <?php if ($c): ?>
      <span class="ml-2 text-xs font-bold uppercase tracking-wide px-2 py-0.5 rounded-full"
            style="<?= $c['status'] === 'sent' ? 'background:#dcfce7;color:#166534' : ($c['status'] === 'sending' ? 'background:#dbeafe;color:#1e40af' : 'background:#f1f5f9;color:#475569') ?>"><?= htmlspecialchars($c['status']) ?></span>
    <?php endif; ?>
  </div>

  <form method="POST" action="<?= root.admin ?>/campaigns/save" x-data="{
      body: <?= json_encode((string)($c['body_html'] ?? '<p>Hi {$subscriber.first_name},</p>\n<p>Write your message here…</p>')) ?>,
      tplId: '',
      tplLoading: false,
      async loadTemplate() {
        if (!this.tplId) return;
        if (this.body && this.body.trim() && !confirm('Replace the current body with this template?')) { this.tplId=''; return; }
        this.tplLoading = true;
        try {
          const r = await fetch('<?= root.admin ?>/campaign-templates/html/' + encodeURIComponent(this.tplId), { headers: { 'X-Requested-With': 'fetch' } });
          const d = await r.json().catch(() => ({}));
          if (d && d.success && d.body_html) { this.body = d.body_html; }
        } catch (e) { /* ignore — leave body as-is */ }
        finally { this.tplLoading = false; this.tplId = ''; }
      }
    }">
    <?= CSRF::tokenField() ?>
    <input type="hidden" name="id" value="<?= $cid ?>">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Left: content -->
      <div class="lg:col-span-2 space-y-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Campaign name <span class="text-gray-400 font-normal">(internal)</span></label>
          <input type="text" name="name" value="<?= $v('name') ?>" required <?= $isSent ? 'readonly' : '' ?>
                 class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Subject line</label>
          <input type="text" name="subject" value="<?= $v('subject') ?>" required <?= $isSent ? 'readonly' : '' ?>
                 class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="block text-sm font-semibold text-gray-700">Email body (HTML)</label>
            <?php if (!$isSent && !empty($templateChoices)): ?>
              <div class="flex items-center gap-2">
                <select x-model="tplId" @change="loadTemplate()" :disabled="tplLoading"
                        class="rounded-lg border border-gray-300 px-2.5 py-1.5 text-xs text-gray-700">
                  <option value="">Start from template…</option>
                  <?php foreach ($templateChoices as $tc): ?>
                    <option value="<?= (int) $tc['id'] ?>"><?= htmlspecialchars($tc['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <a href="<?= root.admin ?>/campaign-templates" target="_blank" class="text-xs text-blue-600 hover:underline whitespace-nowrap">Manage</a>
              </div>
            <?php endif; ?>
          </div>
          <textarea name="body_html" x-model="body" rows="14" <?= $isSent ? 'readonly' : '' ?>
                    class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm font-mono focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
          <p class="text-xs text-gray-500 mt-1">
            Placeholders: <code>{$subscriber.first_name}</code>, <code>{$settings.business_name}</code>, <code>{{unsubscribe_url}}</code>.
            The logo, outer frame and an unsubscribe link are added automatically.
          </p>
        </div>
      </div>

      <!-- Right: audience + actions -->
      <div class="space-y-4">
        <div class="rounded-xl border border-gray-100 p-4 bg-gray-50">
          <h3 class="text-sm font-bold text-gray-900 mb-1">Audience</h3>
          <p class="text-xs text-gray-500 mb-3">Send to one group or several — recipients are merged and de-duplicated by email.</p>

          <?php
          // group key => [label, is marketing?, note]
          $groupDefs = [
            'subscribers' => ['Newsletter subscribers', true,  'opted in via the site'],
            'customers'   => ['Customers',              true,  'users.role = customer'],
            'agents'      => ['Agents',                 false, 'operational — no unsubscribe'],
            'suppliers'   => ['Suppliers',              false, 'operational — no unsubscribe'],
            'staff'       => ['Internal staff',         false, 'admin/employee — internal'],
          ];
          foreach ($groupDefs as $gk => [$glabel, $gmkt, $gnote]):
            $checked = in_array($gk, $selectedGroups, true);
          ?>
            <label class="flex items-start gap-2 mb-2 cursor-pointer">
              <input type="checkbox" name="audience_groups[]" value="<?= $gk ?>" <?= $checked ? 'checked' : '' ?> <?= $isSent ? 'disabled' : '' ?> class="mt-0.5">
              <span class="text-sm text-gray-800"><?= htmlspecialchars($glabel) ?>
                <span class="block text-[11px] text-gray-400"><?= htmlspecialchars($gnote) ?><?= $gmkt ? '' : '' ?></span>
              </span>
            </label>
          <?php endforeach; ?>

          <!-- Staff role narrows the "Internal staff" group (optional). -->
          <label class="block text-xs font-semibold text-gray-600 mt-2 mb-1">Staff role <span class="text-gray-400 font-normal">(only for Internal staff)</span></label>
          <select name="audience_role" <?= $isSent ? 'disabled' : '' ?> class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3">
            <option value="">All staff (admin + employee)</option>
            <?php foreach (['admin' => 'Admins only', 'employee' => 'Employees only'] as $rk => $rlbl): ?>
              <option value="<?= $rk ?>" <?= $selectedRole === $rk ? 'selected' : '' ?>><?= $rlbl ?></option>
            <?php endforeach; ?>
          </select>

          <hr class="my-3 border-gray-200">

          <label class="block text-xs font-semibold text-gray-600 mb-1">Subscriber status <span class="text-gray-400 font-normal">(subscribers group)</span></label>
          <select name="audience_status" <?= $isSent ? 'disabled' : '' ?> class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3">
            <?php foreach (['subscribed' => 'Confirmed subscribers', 'pending' => 'Pending (not yet confirmed)'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= ($c['audience_status'] ?? 'subscribed') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Country <span class="text-gray-400 font-normal">(ISO-2, optional)</span></label>
          <input type="text" name="audience_country" maxlength="2" value="<?= $v('audience_country') ?>" <?= $isSent ? 'readonly' : '' ?>
                 placeholder="e.g. NG" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3 uppercase">
          <label class="block text-xs font-semibold text-gray-600 mb-1">Language <span class="text-gray-400 font-normal">(optional)</span></label>
          <input type="text" name="audience_language" maxlength="8" value="<?= $v('audience_language') ?>" <?= $isSent ? 'readonly' : '' ?>
                 placeholder="e.g. en" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <?php if ($c): ?>
            <p class="text-xs text-gray-500 mt-3">Current reach: <strong class="text-gray-900"><?= number_format($audienceCount) ?></strong> recipient<?= $audienceCount === 1 ? '' : 's' ?> (de-duplicated). Save to recalculate.</p>
          <?php endif; ?>
        </div>

        <div class="rounded-xl border border-gray-100 p-4 bg-gray-50">
          <h3 class="text-sm font-bold text-gray-900 mb-3">Sender <span class="text-gray-400 font-normal text-xs">(optional override)</span></h3>
          <input type="text" name="from_name" value="<?= $v('from_name') ?>" <?= $isSent ? 'readonly' : '' ?> placeholder="From name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-2">
          <input type="email" name="from_email" value="<?= $v('from_email') ?>" <?= $isSent ? 'readonly' : '' ?> placeholder="From email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <p class="text-xs text-gray-500 mt-2">Leave blank to use the platform default sender.</p>
        </div>

        <?php if (!$isSent): ?>
          <?php
          // Preformat any existing schedule for the datetime-local input (YYYY-MM-DDTHH:MM).
          $schedVal = '';
          if (!empty($c['scheduled_at'])) {
              $sts = strtotime((string) $c['scheduled_at']);
              if ($sts !== false) { $schedVal = date('Y-m-d\TH:i', $sts); }
          }
          ?>
          <div class="rounded-xl border border-gray-100 p-4 bg-gray-50">
            <h3 class="text-sm font-bold text-gray-900 mb-1">Schedule <span class="text-gray-400 font-normal text-xs">(optional)</span></h3>
            <p class="text-xs text-gray-500 mb-2">Pick a future date/time to send automatically, or leave blank and use “Send” below. Requires the dispatch cron to be running on the server.</p>
            <input type="datetime-local" name="scheduled_at" value="<?= htmlspecialchars($schedVal, ENT_QUOTES) ?>"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <?php if (!empty($c['scheduled_at']) && ($c['status'] ?? '') === 'scheduled'): ?>
              <p class="text-xs mt-2 font-medium" style="color:#854d0e">Currently scheduled for <?= htmlspecialchars((string) $c['scheduled_at']) ?>. Clear the field and save to return it to a draft.</p>
            <?php endif; ?>
          </div>

          <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-black transition">Save <span class="font-normal text-gray-300">/</span> Schedule</button>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <!-- Live preview (rendered from the textarea) -->
  <div class="mt-6" x-data="{ body: <?= json_encode((string)($c['body_html'] ?? '')) ?> }">
    <h3 class="text-sm font-bold text-gray-900 mb-2">Preview</h3>
    <div class="rounded-xl border border-gray-200 overflow-hidden" style="background:#f3f4f6">
      <div style="max-width:600px;margin:16px auto;background:#fff;border-radius:12px;overflow:hidden">
        <div style="padding:16px;border-bottom:1px solid #eef2f7;text-align:center">
          <img src="<?= versionedAssetUrl('uploads/global/logo.png') ?>" alt="logo" style="height:34px;width:auto">
        </div>
        <div style="padding:22px;color:#111;font-size:14px;line-height:1.6"
             x-html="(document.querySelector('[name=body_html]')?.value || <?= json_encode((string)($c['body_html'] ?? '')) ?>)"></div>
      </div>
    </div>
    <p class="text-xs text-gray-400 mt-1">Preview uses placeholder text as-is; real sends substitute each subscriber's name and a unique unsubscribe link.</p>
  </div>

  <?php if ($c): ?>
    <!-- TEST SEND (own form; proof a single copy before the real blast) -->
    <div class="mt-6 rounded-xl border border-gray-200 p-4 flex items-center justify-between flex-wrap gap-3">
      <div>
        <h3 class="text-sm font-bold text-gray-900">Send a test</h3>
        <p class="text-xs text-gray-500 mt-0.5">Proof the saved version to yourself before sending to subscribers.</p>
      </div>
      <form method="POST" action="<?= root.admin ?>/campaigns/test" class="flex items-center gap-2">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <input type="email" name="test_email" value="<?= htmlspecialchars((string) ($_SESSION['user_email'] ?? ''), ENT_QUOTES) ?>"
               placeholder="you@example.com" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" style="min-width:220px">
        <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:border-gray-400 transition">Send test</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($c && $c['status'] !== 'sent'): ?>
    <!-- SEND -->
    <div class="mt-6 rounded-xl border p-4 flex items-center justify-between" style="border-color:#bfdbfe;background:#eff6ff">
      <div>
        <h3 class="text-sm font-bold text-gray-900">
          <?= $c['status'] === 'sending' ? 'Continue sending' : 'Send campaign' ?>
        </h3>
        <p class="text-xs text-gray-600 mt-0.5">
          <?php if ($c['status'] === 'sending'): ?>
            Sent so far: <?= number_format((int)$c['sent_count']) ?> / <?= number_format((int)$c['total_recipients']) ?>. Large lists send in batches — click to continue.
          <?php else: ?>
            Sends to <strong><?= number_format($audienceCount) ?></strong> subscriber<?= $audienceCount === 1 ? '' : 's' ?> via your active email provider. Save your latest changes first.
          <?php endif; ?>
        </p>
      </div>
      <form method="POST" action="<?= root.admin ?>/campaigns/send"
            onsubmit="return confirm('Send this campaign to <?= (int) $audienceCount ?> subscriber(s) now?');">
        <?= CSRF::tokenField() ?>
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <button type="submit" <?= $audienceCount === 0 ? 'disabled' : '' ?>
                class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700 transition disabled:opacity-50">
          <?= $c['status'] === 'sending' ? 'Continue' : 'Send now' ?>
        </button>
      </form>
    </div>
  <?php elseif ($c && $c['status'] === 'sent'): ?>
    <div class="mt-6 rounded-xl border p-4" style="border-color:#bbf7d0;background:#f0fdf4">
      <p class="text-sm font-semibold text-green-800">Campaign sent — <?= number_format((int)$c['sent_count']) ?> accepted by provider<?php if ((int)$c['failed_count'] > 0): ?>, <?= number_format((int)$c['failed_count']) ?> failed<?php endif; ?>.</p>
    </div>
  <?php endif; ?>

  <?php
  // ---- Delivery analytics (populated by the Resend webhook). ----------------
  // Only show for campaigns that have actually been sent. Counts come straight
  // from email_campaign_log; the *_at columns are stamped by /webhooks/resend/events.
  if ($c && in_array($c['status'], ['sent', 'sending'], true)):
      $acc        = (int) $db->count('email_campaign_log', ['campaign_id' => $cid, 'status' => 'sent']);
      $delivered  = (int) $db->count('email_campaign_log', ['campaign_id' => $cid, 'delivered_at[!]' => null]);
      $opened     = (int) $db->count('email_campaign_log', ['campaign_id' => $cid, 'opened_at[!]' => null]);
      $clicked    = (int) $db->count('email_campaign_log', ['campaign_id' => $cid, 'clicked_at[!]' => null]);
      $bounced    = (int) $db->count('email_campaign_log', ['campaign_id' => $cid, 'bounced_at[!]' => null]);
      $complained = (int) $db->count('email_campaign_log', ['campaign_id' => $cid, 'complained_at[!]' => null]);
      $anyEvents  = ($delivered + $opened + $clicked + $bounced + $complained) > 0;
      $pct = function (int $n) use ($acc): string {
          return $acc > 0 ? number_format($n * 100 / $acc, 1) . '%' : '—';
      };
      $cards = [
          ['Accepted',   $acc,        null,            '#475569'],
          ['Delivered',  $delivered,  $pct($delivered),'#166534'],
          ['Opened',     $opened,     $pct($opened),   '#1e40af'],
          ['Clicked',    $clicked,    $pct($clicked),  '#6d28d9'],
          ['Bounced',    $bounced,    $pct($bounced),  '#b45309'],
          ['Complaints', $complained, $pct($complained),'#991b1b'],
      ];
  ?>
    <div class="mt-6">
      <div class="flex items-center gap-2 mb-2">
        <h3 class="text-sm font-bold text-gray-900">Delivery analytics</h3>
        <span class="text-[11px] text-gray-400">via Resend webhook</span>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <?php foreach ($cards as [$label, $n, $rate, $color]): ?>
          <div class="rounded-xl border border-gray-100 bg-white p-3 text-center">
            <div class="text-xl font-bold tabular-nums" style="color:<?= $color ?>"><?= number_format($n) ?></div>
            <div class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mt-0.5"><?= $label ?></div>
            <?php if ($rate !== null): ?><div class="text-[11px] text-gray-500 mt-0.5"><?= $rate ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$anyEvents): ?>
        <div class="text-xs text-gray-500 mt-3 rounded-lg border border-gray-100 bg-gray-50 p-3 leading-relaxed">
          <strong class="text-gray-700">No delivery events yet.</strong> Opens, deliveries, clicks and bounces appear here
          once Resend sends webhooks. To enable:
          <ol class="list-decimal ml-5 mt-1 space-y-0.5">
            <li>In the Resend dashboard → <em>Webhooks</em> → <em>Add endpoint</em>, use this URL:
              <br><span class="font-mono text-gray-700"><?= htmlspecialchars(rtrim((string) root, '/') . '/webhooks/resend/events') ?></span></li>
            <li>Copy the generated <span class="font-mono">whsec_…</span> signing secret into
              <a href="<?= root.admin ?>/settings" class="text-blue-600 hover:underline">Settings → Email → Resend → Webhook Secret</a> and save.</li>
          </ol>
          Without the secret the endpoint can't verify events and silently records nothing.
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
