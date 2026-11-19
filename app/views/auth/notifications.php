<?php
// ============================================================================
// CUSTOMER NOTIFICATIONS CENTRE — list, mark read, mark all read
// Provided by route: $notifData, $dashboardData, $user, $db
// ============================================================================
@$SECURE or die('Access Denied!');

$typeIcon = static function ($type, $icon) {
    if ($icon) { return $icon; }
    switch (strtolower((string) $type)) {
        case 'price_alert': return 'trending_down';
        case 'booking':     return 'confirmation_number';
        case 'payment':     return 'payments';
        case 'refund':      return 'undo';
        case 'cancellation':return 'cancel';
        default:            return 'notifications';
    }
};
?>

<div class="bg" x-data="notificationsCentre({ unread: <?= (int) $notifData['unread'] ?> })">
   <div class="flex gap-5 container mb-8">

      <div class="flex-shrink-0">
         <?php include views . 'auth/sidebar.php'; ?>
      </div>

      <div class="flex-1 min-w-0 pt-6 bg-white rounded-[8px] ml-0">

         <!-- Header -->
         <div class="flex items-center justify-between mb-6 px-4 lg:px-6">
            <div class="flex items-center gap-4">
               <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg text-slate-500">
                  <span class="material-symbols-outlined text-2xl">menu</span>
               </button>
               <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-slate-100 rounded-lg relative">
                  <span class="material-symbols-outlined text-2xl text-slate-500">notifications</span>
               </div>
               <div>
                  <h1 class="font-bold text-2xl text-gray-900">Notifications</h1>
                  <p class="text-gray-600 text-sm">
                     <span x-show="unread > 0"><span x-text="unread"></span> unread</span>
                     <span x-show="unread === 0">You're all caught up</span>
                  </p>
               </div>
            </div>
            <button type="button" class="btn light inline-flex items-center gap-2" x-show="unread > 0" @click="markAll()">
               <span class="material-symbols-outlined text-lg">done_all</span> Mark all read
            </button>
         </div>

         <div class="px-4 lg:px-6 pb-8">
            <?php if (empty($notifData['rows'])): ?>
               <div class="text-center py-16 text-slate-400">
                  <span class="material-symbols-outlined text-5xl mb-3 block">notifications_off</span>
                  <p class="font-medium text-slate-500">No notifications yet</p>
                  <p class="text-sm mt-1">Price drops, booking updates and payment receipts will show up here.</p>
               </div>
            <?php else: ?>
               <div class="border border-slate-200 rounded-lg divide-y divide-slate-100">
                  <?php foreach ($notifData['rows'] as $n):
                     $isRead = (int) ($n['is_read'] ?? 0) === 1;
                     $icon   = $typeIcon($n['type'] ?? '', $n['icon'] ?? '');
                     $url    = trim((string) ($n['url'] ?? ''));
                     $nid    = (int) ($n['id'] ?? 0);
                  ?>
                  <div class="flex items-start gap-3 p-4 <?= $isRead ? 'bg-white' : 'bg-blue-50/40' ?>"
                       x-data="{ read: <?= $isRead ? 'true' : 'false' ?> }">
                     <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 <?= $isRead ? 'bg-slate-100 text-slate-400' : 'bg-blue-100 text-blue-600' ?>">
                        <span class="material-symbols-outlined text-lg"><?= htmlspecialchars($icon) ?></span>
                     </div>
                     <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                           <p class="font-medium text-slate-800 text-sm"><?= htmlspecialchars((string) ($n['title'] ?? '')) ?></p>
                           <span class="w-2 h-2 rounded-full bg-blue-500" x-show="!read"></span>
                        </div>
                        <?php if (!empty($n['body'])): ?>
                           <p class="text-sm text-slate-500 mt-0.5"><?= htmlspecialchars((string) $n['body']) ?></p>
                        <?php endif; ?>
                        <div class="flex items-center gap-3 mt-1.5 text-xs">
                           <span class="text-slate-400 tabular-nums"><?= htmlspecialchars(date('d M Y, H:i', strtotime((string) ($n['created_at'] ?? 'now')))) ?></span>
                           <?php if ($url !== ''): ?>
                              <a href="<?= htmlspecialchars($url) ?>" class="text-blue-600 hover:underline font-medium">View</a>
                           <?php endif; ?>
                           <button type="button" class="text-slate-400 hover:text-slate-600" x-show="!read" @click="markOne(<?= $nid ?>); read = true">Mark read</button>
                        </div>
                     </div>
                  </div>
                  <?php endforeach; ?>
               </div>

               <?php if ($notifData['total_pages'] > 1): ?>
                  <div class="flex items-center justify-between mt-4 text-sm">
                     <span class="text-slate-500">Page <?= (int) $notifData['page'] ?> / <?= (int) $notifData['total_pages'] ?></span>
                     <div class="flex gap-2">
                        <?php if ($notifData['page'] > 1): ?><a href="<?= root ?>notifications?page=<?= $notifData['page'] - 1 ?>" class="btn light btn-sm">Previous</a><?php endif; ?>
                        <?php if ($notifData['page'] < $notifData['total_pages']): ?><a href="<?= root ?>notifications?page=<?= $notifData['page'] + 1 ?>" class="btn light btn-sm">Next</a><?php endif; ?>
                     </div>
                  </div>
               <?php endif; ?>
            <?php endif; ?>
         </div>

      </div>
   </div>
</div>

<script>
function notificationsCentre(init) {
   return {
      sidebarCollapsed: window.innerWidth < 1024,
      unread: init.unread || 0,
      _post(id) {
         return fetch('<?= root ?>notifications/read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '<?= CSRF::getToken() ?>' },
            body: JSON.stringify({ id: id, csrf_token: '<?= CSRF::getToken() ?>' })
         }).then(r => r.json());
      },
      async markOne(id) {
         try { const d = await this._post(id); if (typeof d.unread === 'number') this.unread = d.unread; } catch(e) {}
      },
      async markAll() {
         try {
            const d = await this._post(0);
            if (typeof d.unread === 'number') this.unread = d.unread;
            window.location.reload();
         } catch(e) {}
      }
   };
}
</script>
