<?php
// app/views/admin/campaign-templates/templates-manage.php — block-based designer.
// Edits an ordered list of blocks (hero/text/button/image/divider); on save the
// JSON is posted and the server compiles it to HTML (campaign_blocks_to_html).
@$SECURE or die('Access Denied!');

$tid = (int) ($_GET['id'] ?? 0);
$t = $tid > 0 ? $db->get('email_templates_library', '*', ['id' => $tid]) : null;
if ($tid > 0 && !$t) {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Template not found.'];
    redirect(root . admin . '/campaign-templates');
    return;
}
$isSystem = $t && (int) $t['is_system'] === 1;
$blocksJson = $t['blocks_json'] ?? '[]';
// Built-in templates are read-only; "save" creates a copy (duplicate) instead.
$logoUrl = versionedAssetUrl('uploads/global/logo.png');
?>
<div class="container my-4" style="max-width:1100px"
     x-data='campaignDesigner(<?= htmlspecialchars($blocksJson, ENT_QUOTES) ?>, <?= $isSystem ? 'true' : 'false' ?>)'>
  <?php if (isset($_SESSION['message'])): ?>
    <div class="mb-4 flex items-center gap-2 p-3 rounded-lg"
         style="<?= $_SESSION['message']['type'] === 'success' ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b' ?>">
      <span class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
      <div><?= htmlspecialchars($_SESSION['message']['text']) ?></div>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <div class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/campaign-templates" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900"><?= $tid > 0 ? ($isSystem ? 'View / duplicate template' : 'Edit template') : 'New template' ?></h1>
    <?php if ($isSystem): ?><span class="ml-2 text-[11px] font-bold uppercase px-2 py-0.5 rounded-full" style="background:#eff6ff;color:#1e40af">Built-in (read-only)</span><?php endif; ?>
  </div>

  <form method="POST" action="<?= root.admin ?>/campaign-templates/save" @submit="syncBeforeSubmit()">
    <?= CSRF::tokenField() ?>
    <!-- Built-in -> save as a NEW copy (id=0); custom -> update in place. -->
    <input type="hidden" name="id" value="<?= $isSystem ? 0 : $tid ?>">
    <input type="hidden" name="blocks_json" :value="JSON.stringify(blocks)">

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- LEFT: editor -->
      <div class="space-y-4">
        <div class="grid grid-cols-1 gap-3">
          <input type="text" name="name" value="<?= htmlspecialchars((string) ($t['name'] ?? ''), ENT_QUOTES) ?><?= $isSystem ? ' (copy)' : '' ?>" required placeholder="Template name"
                 class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
          <input type="text" name="description" value="<?= htmlspecialchars((string) ($t['description'] ?? ''), ENT_QUOTES) ?>" placeholder="Short description (optional)"
                 class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        </div>

        <!-- Add-block palette -->
        <div class="flex flex-wrap gap-2">
          <template x-for="bt in blockTypes" :key="bt.type">
            <button type="button" @click="addBlock(bt.type)"
                    class="inline-flex items-center gap-1.5 rounded-full border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:border-blue-500 hover:text-blue-600 transition">
              <span class="material-symbols-outlined !text-[16px]" x-text="bt.icon"></span>
              <span x-text="bt.label"></span>
            </button>
          </template>
        </div>

        <!-- Block list -->
        <div class="space-y-3">
          <template x-for="(b, i) in blocks" :key="b._k">
            <div class="rounded-xl border border-gray-200 bg-white p-3">
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wide text-gray-400" x-text="b.type"></span>
                <div class="flex items-center gap-1">
                  <button type="button" @click="move(i,-1)" :disabled="i===0" class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"><span class="material-symbols-outlined !text-[18px]">arrow_upward</span></button>
                  <button type="button" @click="move(i,1)" :disabled="i===blocks.length-1" class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30"><span class="material-symbols-outlined !text-[18px]">arrow_downward</span></button>
                  <button type="button" @click="remove(i)" class="p-1 text-red-400 hover:text-red-600"><span class="material-symbols-outlined !text-[18px]">delete</span></button>
                </div>
              </div>
              <!-- Per-type fields -->
              <div x-show="b.type==='hero'" class="space-y-2">
                <input type="text" x-model="b.title" placeholder="Headline" class="w-full rounded border border-gray-300 px-2.5 py-1.5 text-sm">
                <input type="text" x-model="b.subtitle" placeholder="Subheadline (optional)" class="w-full rounded border border-gray-300 px-2.5 py-1.5 text-sm">
              </div>
              <div x-show="b.type==='text'">
                <textarea x-model="b.html" rows="3" placeholder="Text / simple HTML — use {$subscriber.first_name}" class="w-full rounded border border-gray-300 px-2.5 py-1.5 text-sm font-mono"></textarea>
              </div>
              <div x-show="b.type==='button'" class="space-y-2">
                <input type="text" x-model="b.label" placeholder="Button label" class="w-full rounded border border-gray-300 px-2.5 py-1.5 text-sm">
                <input type="url" x-model="b.href" placeholder="https://… (or {{unsubscribe_url}})" class="w-full rounded border border-gray-300 px-2.5 py-1.5 text-sm">
              </div>
              <div x-show="b.type==='image'" class="space-y-2">
                <input type="url" x-model="b.src" placeholder="Image URL (https://)" class="w-full rounded border border-gray-300 px-2.5 py-1.5 text-sm">
                <input type="text" x-model="b.alt" placeholder="Alt text" class="w-full rounded border border-gray-300 px-2.5 py-1.5 text-sm">
                <input type="url" x-model="b.link" placeholder="Link on click (optional)" class="w-full rounded border border-gray-300 px-2.5 py-1.5 text-sm">
              </div>
              <div x-show="b.type==='divider'" class="text-xs text-gray-400">A horizontal divider line.</div>
            </div>
          </template>
          <p x-show="blocks.length===0" class="text-sm text-gray-400 text-center py-6 border border-dashed border-gray-200 rounded-xl">Add blocks above to build your layout.</p>
        </div>

        <button type="submit" class="w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-black transition">
          <?= $isSystem ? 'Save as a new template' : 'Save template' ?>
        </button>
      </div>

      <!-- RIGHT: live preview -->
      <div>
        <h3 class="text-sm font-bold text-gray-900 mb-2">Preview</h3>
        <div class="rounded-xl border border-gray-200 overflow-hidden sticky top-4" style="background:#f3f4f6">
          <div style="max-width:600px;margin:14px auto;background:#fff;border-radius:12px;overflow:hidden">
            <div style="padding:14px;border-bottom:1px solid #eef2f7;text-align:center">
              <img src="<?= $logoUrl ?>" alt="logo" style="height:32px;width:auto">
            </div>
            <div style="padding:20px;color:#111;font-size:14px;line-height:1.6" x-html="previewHtml()"></div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
function campaignDesigner(initial, isSystem) {
  let start = [];
  try { start = Array.isArray(initial) ? initial : (JSON.parse(initial || '[]') || []); } catch (e) { start = []; }
  // give each block a stable key for x-for
  start = start.map((b, i) => Object.assign({ _k: 'k' + i + '_' + Math.random().toString(36).slice(2, 7) }, b));
  return {
    isSystem,
    blocks: start,
    blockTypes: [
      { type: 'hero',    label: 'Hero',    icon: 'title' },
      { type: 'text',    label: 'Text',    icon: 'notes' },
      { type: 'button',  label: 'Button',  icon: 'smart_button' },
      { type: 'image',   label: 'Image',   icon: 'image' },
      { type: 'divider', label: 'Divider', icon: 'horizontal_rule' },
    ],
    _key() { return 'k' + Date.now() + '_' + Math.random().toString(36).slice(2, 7); },
    addBlock(type) {
      const base = { _k: this._key(), type };
      if (type === 'hero')   { base.title = 'Your headline'; base.subtitle = ''; }
      if (type === 'text')   { base.html = '<p>Hi {$subscriber.first_name},</p><p>Write your message…</p>'; }
      if (type === 'button') { base.label = 'Learn more'; base.href = 'https://'; }
      if (type === 'image')  { base.src = ''; base.alt = ''; base.link = ''; }
      this.blocks.push(base);
    },
    move(i, d) { const j = i + d; if (j < 0 || j >= this.blocks.length) return; const a = this.blocks; [a[i], a[j]] = [a[j], a[i]]; this.blocks = [...a]; },
    remove(i) { this.blocks.splice(i, 1); },
    esc(s) { const d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; },
    // Client-side preview mirrors the server renderer (campaign_blocks_to_html).
    previewHtml() {
      return this.blocks.map(b => {
        if (b.type === 'hero')   return '<h1 style="margin:0 0 8px;font-size:24px;font-weight:800;color:#0f172a">' + this.esc(b.title) + '</h1>' + (b.subtitle ? '<p style="margin:0 0 10px;color:#475569">' + this.esc(b.subtitle) + '</p>' : '');
        if (b.type === 'text')   return '<div>' + (b.html || '') + '</div>';
        if (b.type === 'button') return '<p><a href="#" style="background:#2563eb;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:700;display:inline-block">' + this.esc(b.label || 'Learn more') + '</a></p>';
        if (b.type === 'image')  return b.src ? '<img src="' + this.esc(b.src) + '" alt="' + this.esc(b.alt) + '" style="max-width:100%;border-radius:8px;display:block;margin:8px 0">' : '<div style="padding:16px;border:1px dashed #cbd5e1;border-radius:8px;color:#94a3b8;text-align:center">Image</div>';
        if (b.type === 'divider')return '<div style="height:1px;background:#e5e7eb;margin:12px 0"></div>';
        return '';
      }).join('');
    },
    syncBeforeSubmit() { /* hidden input is bound via :value to JSON.stringify(blocks) */ return true; },
  };
}
</script>
