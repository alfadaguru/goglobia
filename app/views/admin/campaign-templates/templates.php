<?php
// app/views/admin/campaign-templates/templates.php — template library list.
@$SECURE or die('Access Denied!');
$templates = $db->select('email_templates_library', '*', ['ORDER' => ['is_system' => 'DESC', 'name' => 'ASC']]) ?: [];
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

  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-xl font-bold text-gray-900">Campaign Templates</h1>
      <p class="text-sm text-gray-500 mt-0.5">Reusable email layouts your campaigns can start from.</p>
    </div>
    <a href="<?= root.admin ?>/campaign-templates/create"
       class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition">
      <span class="material-symbols-outlined !text-[18px]">add</span> New template
    </a>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($templates as $t): ?>
      <div class="rounded-xl border border-gray-100 bg-white overflow-hidden shadow-sm flex flex-col">
        <div class="h-40 overflow-hidden border-b border-gray-100 bg-gray-50 p-3">
          <div style="transform:scale(.72);transform-origin:top center;pointer-events:none">
            <?= $t['body_html'] /* rendered template preview; admin-authored */ ?>
          </div>
        </div>
        <div class="p-4 flex-1 flex flex-col">
          <div class="flex items-center gap-2">
            <h3 class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($t['name']) ?></h3>
            <?php if ((int) $t['is_system'] === 1): ?>
              <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full" style="background:#eff6ff;color:#1e40af">Built-in</span>
            <?php endif; ?>
          </div>
          <p class="text-xs text-gray-500 mt-1 flex-1"><?= htmlspecialchars((string) ($t['description'] ?? '')) ?></p>
          <div class="mt-3 flex items-center gap-3 text-sm">
            <a href="<?= root.admin ?>/campaign-templates/edit/<?= (int) $t['id'] ?>" class="text-blue-600 hover:underline font-medium"><?= (int) $t['is_system'] === 1 ? 'View / duplicate' : 'Edit' ?></a>
            <?php if ((int) $t['is_system'] !== 1): ?>
              <form method="POST" action="<?= root.admin ?>/campaign-templates/delete" class="inline" onsubmit="return confirm('Delete this template?');">
                <?= CSRF::tokenField() ?>
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <button type="submit" class="text-red-600 hover:underline font-medium">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
