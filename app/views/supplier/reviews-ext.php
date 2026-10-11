<?php @$SECURE or die('Access Denied!'); ?>
<?php
// MODULE 23 deepen — PUBLIC guest satisfaction survey (NPS + per-category sub-ratings +
// comment). $property (or null if not eligible), $surveyToken, $elig, $surveyCategories.
$property = (isset($property) && is_array($property)) ? $property : null;
$elig = (isset($elig) && is_array($elig)) ? $elig : ['ok' => false];
$token = (string) ($surveyToken ?? '');
$cats = (isset($surveyCategories) && is_array($surveyCategories)) ? $surveyCategories : [];
$already = !empty($elig['survey_done']);
?>
<div class="max-w-xl mx-auto px-4 py-10">
  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?> mb-5">
      <span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span>
      <p class="text-sm"><?= htmlspecialchars($__x) ?></p>
    </div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <?php if (empty($elig['ok']) || !$property): ?>
    <div class="card p-8 text-center text-gray-500">
      <span class="material-symbols-outlined text-5xl text-gray-300">poll</span>
      <h1 class="mt-2 text-lg font-bold text-gray-900">Survey unavailable</h1>
      <p class="mt-1 text-sm"><?= htmlspecialchars($elig['message'] ?? 'This survey link is not valid or the stay is not yet eligible.') ?></p>
    </div>
  <?php elseif ($already): ?>
    <div class="card p-8 text-center text-gray-600">
      <span class="material-symbols-outlined text-5xl text-green-400">check_circle</span>
      <h1 class="mt-2 text-lg font-bold text-gray-900">Thank you!</h1>
      <p class="mt-1 text-sm">You've already completed the survey for your stay at <?= htmlspecialchars((string) $property['name']) ?>.</p>
    </div>
  <?php else: ?>
    <div class="card p-6" x-data="{ nps: 9, scores: {} }">
      <h1 class="text-xl font-bold text-gray-900">Tell us about your stay</h1>
      <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars((string) $property['name']) ?><?= !empty($property['location']) ? ' · ' . htmlspecialchars((string) $property['location']) : '' ?></p>
      <form action="<?= root ?>stay-survey/<?= htmlspecialchars($token) ?>" method="POST" class="space-y-5">
        <?= CSRF::tokenField() ?>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">How likely are you to recommend us? (0-10)</label>
          <div class="flex flex-wrap gap-1.5">
            <?php for ($i = 0; $i <= 10; $i++): ?>
              <button type="button" @click="nps = <?= $i ?>"
                class="w-8 h-8 rounded text-sm font-medium border focus:outline-none"
                :class="nps === <?= $i ?> ? 'bg-violet-600 text-white border-violet-600' : 'bg-white text-gray-700 border-gray-300'"><?= $i ?></button>
            <?php endfor; ?>
          </div>
          <input type="hidden" name="nps" :value="nps">
        </div>

        <?php foreach ($cats as $key => $meta): ?>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars($meta['label']) ?></label>
            <div class="flex items-center gap-1 text-2xl">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" @click="scores['<?= htmlspecialchars($key) ?>'] = <?= $i ?>" class="focus:outline-none"
                  :class="(scores['<?= htmlspecialchars($key) ?>'] || 0) >= <?= $i ?> ? 'text-amber-400' : 'text-gray-300'">★</button>
              <?php endfor; ?>
            </div>
            <input type="hidden" name="scores[<?= htmlspecialchars($key) ?>]" :value="scores['<?= htmlspecialchars($key) ?>'] || ''">
          </div>
        <?php endforeach; ?>

        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Anything else?</label>
          <textarea name="comment" rows="4" class="input" placeholder="Share more about your experience (optional)"></textarea>
        </div>
        <button type="submit" class="btn emerald w-full">Submit feedback</button>
      </form>
    </div>
  <?php endif; ?>
</div>
