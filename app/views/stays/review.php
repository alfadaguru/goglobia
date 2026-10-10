<?php @$SECURE or die('Access Denied!'); ?>
<?php
// Public post-stay review form (inc S31). $property (or null if not eligible),
// $reviewToken, $elig (eligibility result).
$property = (isset($property) && is_array($property)) ? $property : null;
$elig = (isset($elig) && is_array($elig)) ? $elig : ['ok' => false];
$token = (string) ($reviewToken ?? '');
$already = !empty($elig['already']);
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
      <span class="material-symbols-outlined text-5xl text-gray-300">rate_review</span>
      <h1 class="mt-2 text-lg font-bold text-gray-900">Review unavailable</h1>
      <p class="mt-1 text-sm"><?= htmlspecialchars($elig['message'] ?? 'This review link is not valid or the stay is not yet eligible.') ?></p>
    </div>
  <?php elseif ($already): ?>
    <div class="card p-8 text-center text-gray-600">
      <span class="material-symbols-outlined text-5xl text-green-400">check_circle</span>
      <h1 class="mt-2 text-lg font-bold text-gray-900">Thanks!</h1>
      <p class="mt-1 text-sm">You've already reviewed your stay at <?= htmlspecialchars((string) $property['name']) ?>.</p>
    </div>
  <?php else: ?>
    <div class="card p-6" x-data="{ rating: 5 }">
      <h1 class="text-xl font-bold text-gray-900">How was your stay?</h1>
      <p class="text-sm text-gray-600 mb-4"><?= htmlspecialchars((string) $property['name']) ?><?= !empty($property['location']) ? ' · ' . htmlspecialchars((string) $property['location']) : '' ?></p>
      <form action="<?= root ?>stay-review/<?= htmlspecialchars($token) ?>" method="POST" class="space-y-4">
        <?= CSRF::tokenField() ?>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Your rating</label>
          <div class="flex items-center gap-1 text-2xl">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <button type="button" @click="rating = <?= $i ?>" class="focus:outline-none" :class="rating >= <?= $i ?> ? 'text-amber-400' : 'text-gray-300'">★</button>
            <?php endfor; ?>
          </div>
          <input type="hidden" name="rating" :value="rating">
        </div>
        <div class="form-control">
          <label class="block text-sm font-medium text-gray-700 mb-1">Your review</label>
          <textarea name="comment" rows="4" class="input" placeholder="Tell future guests about your stay (optional)"></textarea>
        </div>
        <button type="submit" class="btn emerald w-full">Submit review</button>
      </form>
    </div>
  <?php endif; ?>
</div>
