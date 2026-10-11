<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Guest 360 (PMS #18 ext): consent management + communication history + complaints history.
// Vars: $profile, $segment, $consent, $comms, $complaints, $canEdit, $token.
$profile    = (isset($profile) && is_array($profile)) ? $profile : [];
$segment    = (isset($segment) && is_array($segment)) ? $segment : ['label' => 'New'];
$consent    = (isset($consent) && is_array($consent)) ? $consent : [];
$comms      = (isset($comms) && is_array($comms)) ? $comms : [];
$complaints = (isset($complaints) && is_array($complaints)) ? $complaints : [];
$canEdit    = !empty($canEdit);
$token      = isset($token) ? (string) $token : '';
$base       = root . 'supplier/guests';
$toneMap = ['vip' => 'bg-amber-100 text-amber-700', 'high' => 'bg-violet-100 text-violet-700', 'lapsed' => 'bg-rose-100 text-rose-700', 'repeat' => 'bg-emerald-100 text-emerald-700', 'new' => 'bg-sky-100 text-sky-700'];
$segTone = $toneMap[$segment['key'] ?? 'new'] ?? 'bg-sky-100 text-sky-700';
$chanLabels = function_exists('guestmsg_channels') ? guestmsg_channels() : ['note' => 'Note'];
$ticketCats = function_exists('ticket_categories') ? ticket_categories() : [];
$ticketStat = function_exists('ticket_statuses') ? ticket_statuses() : [];
?>
<div class="max-w-4xl mx-auto px-4 py-8 space-y-6">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <!-- Header -->
  <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
    <div>
      <div class="flex items-center gap-2">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($profile['name'] ?: $profile['email']) ?></h1>
        <span class="px-2 py-0.5 rounded text-[11px] font-semibold <?= $segTone ?>"><?= htmlspecialchars((string) ($segment['label'] ?? 'New')) ?></span>
      </div>
      <p class="text-sm text-gray-600"><?= htmlspecialchars((string) ($profile['email'] ?? '')) ?><?php if (!empty($profile['phone'])): ?> · <?= htmlspecialchars((string) $profile['phone']) ?><?php endif; ?></p>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= htmlspecialchars($base . '/' . $token) ?>" class="btn secondary text-sm">Profile &amp; history</a>
      <a href="<?= htmlspecialchars(root . 'supplier/guests-crm') ?>" class="text-sm text-gray-500 hover:underline self-center">Back to CRM</a>
    </div>
  </div>

  <!-- Lifetime value strip -->
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
    <div class="card p-4"><div class="text-xs text-gray-500">Lifetime value</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= htmlspecialchars((string) ($profile['currency'] ?? 'USD')) ?> <?= number_format((float) ($profile['total_spent'] ?? 0), 2) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Stays</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= (int) ($profile['stays'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Cancelled</div><div class="text-lg font-bold text-gray-900 tabular-nums"><?= (int) ($profile['cancelled'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">First seen</div><div class="text-lg font-bold text-gray-900"><?= htmlspecialchars(substr((string) ($profile['first_seen'] ?? ''), 0, 10)) ?></div></div>
  </div>

  <!-- Consent management -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-1 flex items-center gap-2"><span class="material-symbols-outlined text-blue-600 text-base">verified_user</span> Marketing consent</h2>
    <p class="text-xs text-gray-500 mb-3">Record the guest's explicit consent to receive communications. Respect this before any marketing send.</p>
    <form action="<?= htmlspecialchars($base . '/' . $token . '/consent') ?>" method="POST" class="space-y-3">
      <?= CSRF::tokenField() ?>
      <div class="flex flex-wrap gap-5">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="consent_marketing" value="1" class="checkbox-input" <?= !empty($consent['consent_marketing']) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> Marketing (general)</label>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="consent_email" value="1" class="checkbox-input" <?= !empty($consent['consent_email']) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> Email</label>
        <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="consent_sms" value="1" class="checkbox-input" <?= !empty($consent['consent_sms']) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> SMS / WhatsApp</label>
      </div>
      <?php if (!empty($consent['consent_updated_at'])): ?>
        <p class="text-[11px] text-gray-400">Last updated <?= htmlspecialchars((string) $consent['consent_updated_at']) ?><?php if (!empty($consent['consent_source'])): ?> · source <?= htmlspecialchars((string) $consent['consent_source']) ?><?php endif; ?></p>
      <?php endif; ?>
      <?php if ($canEdit): ?><button type="submit" class="btn secondary text-sm">Save consent</button><?php endif; ?>
    </form>
  </div>

  <!-- Communication history -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-emerald-600 text-base">forum</span> Communication history</h2>
    <?php if (empty($comms)): ?>
      <p class="text-sm text-gray-500">No logged communications with this guest.</p>
    <?php else: ?>
      <ul class="divide-y divide-gray-100">
        <?php foreach ($comms as $m): ?>
          <li class="py-3">
            <div class="flex items-center gap-2 text-xs text-gray-500 mb-1">
              <span class="px-1.5 py-0.5 rounded <?= ($m['direction'] ?? 'out') === 'in' ? 'bg-sky-100 text-sky-700' : 'bg-gray-100 text-gray-600' ?>"><?= ($m['direction'] ?? 'out') === 'in' ? 'Inbound' : 'Outbound' ?></span>
              <span class="font-medium text-gray-700"><?= htmlspecialchars($chanLabels[$m['channel'] ?? 'note'] ?? ucfirst((string) ($m['channel'] ?? 'note'))) ?></span>
              <span><?= htmlspecialchars((string) ($m['created_at'] ?? '')) ?></span>
            </div>
            <div class="text-sm text-gray-800 whitespace-pre-line"><?= htmlspecialchars((string) ($m['body'] ?? '')) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <!-- Complaints / service history -->
  <div class="card p-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2"><span class="material-symbols-outlined text-rose-600 text-base">support_agent</span> Complaints &amp; service history</h2>
    <?php if (empty($complaints)): ?>
      <p class="text-sm text-gray-500">No complaints or service tickets linked to this guest.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-gray-500 border-b border-gray-100">
            <th class="px-3 py-2 font-medium">Subject</th><th class="px-3 py-2 font-medium">Category</th>
            <th class="px-3 py-2 font-medium">Priority</th><th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium">Opened</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-100">
            <?php foreach ($complaints as $t): ?>
              <tr>
                <td class="px-3 py-2 font-medium text-gray-900"><?= htmlspecialchars((string) ($t['subject'] ?? '')) ?></td>
                <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($ticketCats[$t['category'] ?? ''] ?? (string) ($t['category'] ?? '')) ?></td>
                <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars((string) ($t['priority'] ?? '')) ?></td>
                <td class="px-3 py-2"><span class="px-1.5 py-0.5 rounded text-[11px] <?= ($t['status'] ?? '') === 'resolved' || ($t['status'] ?? '') === 'closed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>"><?= htmlspecialchars($ticketStat[$t['status'] ?? ''] ?? (string) ($t['status'] ?? '')) ?></span></td>
                <td class="px-3 py-2 text-gray-500 text-xs"><?= htmlspecialchars(substr((string) ($t['created_at'] ?? ''), 0, 16)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
