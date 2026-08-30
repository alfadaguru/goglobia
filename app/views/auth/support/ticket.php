<!-- app/views/auth/support/ticket.php -->
<div class="bg" x-data="{
    sidebarCollapsed: window.innerWidth < 1024
}">
   <div class="flex gap-5 container mb-8">

      <div class="flex-shrink-0">
         <?php include views."auth/sidebar.php"; ?>
      </div>

      <div class="flex-1 min-w-0 pt-8 bg-white rounded-[8px] ml-0">

         <div class="flex items-center gap-3 mb-5">
            <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-blue-50 rounded-lg text-blue-600 hover:bg-blue-100 transition-colors shrink-0">
               <span class="material-symbols-outlined text-2xl">menu</span>
            </button>
            <a href="<?= root ?>support/tickets" class="btn light inline-flex items-center gap-2">
               <span class="material-symbols-outlined text-sm">arrow_back</span>
               <?= T::back ?> <?= T::to ?> <?= T::tickets ?>
            </a>
         </div>

         <!-- Alert Messages -->
         <?php if (isset($_SESSION['success'])): ?>
         <div class="alert alert-success mb-5">
            <span class="material-symbols-outlined mr-2">check_circle</span>
            <?= htmlspecialchars($_SESSION['success']) ?>
         </div>
         <?php unset($_SESSION['success']); endif; ?>

         <?php if (isset($_SESSION['error'])): ?>
         <div class="alert alert-danger mb-5">
            <span class="material-symbols-outlined mr-2">error</span>
            <?= htmlspecialchars($_SESSION['error']) ?>
         </div>
         <?php unset($_SESSION['error']); endif; ?>

         <!-- Ticket Header -->
         <div class="card p-6 mb-5">
        <div class="flex flex-wrap justify-between items-start gap-4 mb-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">
                    <?= htmlspecialchars($ticket['subject']) ?>
                </h1>
                <div class="flex flex-wrap gap-3 text-sm text-gray-600">
                    <span class="flex items-center">
                        <span class="material-symbols-outlined text-sm mr-1">confirmation_number</span>
                        #<?= htmlspecialchars($ticket['ticket_id']) ?>
                    </span>
                    <span class="flex items-center">
                        <span class="material-symbols-outlined text-sm mr-1">schedule</span>
                        <?= date('M d, Y - H:i', strtotime($ticket['date'])) ?>
                    </span>
                </div>
            </div>
            <div class="flex gap-2">
                <?php
                $priority_colors = [
                    'low' => 'bg-blue-100 text-blue-800',
                    'normal' => 'bg-gray-100 text-gray-800',
                    'high' => 'bg-red-100 text-red-800'
                ];
                $status_colors = [
                    'open' => 'bg-green-100 text-green-800',
                    'in progress' => 'bg-yellow-100 text-yellow-800',
                    'waiting' => 'bg-orange-100 text-orange-800',
                    'close' => 'bg-gray-100 text-gray-800'
                ];
                $priority_color = $priority_colors[$ticket['priority']] ?? 'bg-gray-100 text-gray-800';
                $status_color = $status_colors[$ticket['status']] ?? 'bg-gray-100 text-gray-800';
                ?>
                <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $priority_color ?>">
                    <?= ucfirst(htmlspecialchars($ticket['priority'])) ?> <?= T::priority ?>
                </span>
                <span class="px-3 py-1 text-xs font-semibold rounded-full <?= $status_color ?>">
                    <?= ucfirst(htmlspecialchars($ticket['status'])) ?>
                </span>
            </div>
        </div>

        <!-- Ticket Description -->
        <div class="border-t pt-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2"><?= T::description ?>:</h3>
            <div class="text-gray-700 prose max-w-none">
                <?= nl2br(htmlspecialchars($ticket['desc'])) ?>
            </div>
        </div>

        <!-- Attachments -->
        <?php if (!empty($ticket['attachments'])): ?>
        <?php $attachments = json_decode($ticket['attachments'], true); ?>
        <?php if (is_array($attachments) && count($attachments) > 0): ?>
        <div class="border-t pt-4 mt-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2"><?= T::attachments ?>:</h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($attachments as $attachment): ?>
                <a href="<?= root . htmlspecialchars($attachment) ?>" target="_blank" class="flex items-center gap-2 px-3 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 transition text-sm">
                    <span class="material-symbols-outlined text-sm">attach_file</span>
                    <?= basename($attachment) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

            <!-- Action Buttons -->
            <?php if ($ticket['status'] !== 'close'): ?>
            <div class="border-t pt-4 mt-4">
               <button onclick="closeTicket('<?= htmlspecialchars($ticket['ticket_id']) ?>')" class="btn rose flex items-center gap-2">
                  <span class="material-symbols-outlined text-sm">close</span>
                  <?= T::close ?> <?= T::ticket ?>
               </button>
            </div>
            <?php endif; ?>
         </div>

         <!-- Replies Section -->
         <div id="replies" class="mb-5">
            <h2 class="text-xl font-bold text-gray-800 mb-4"><?= T::replies ?></h2>

        <?php
        $replies = $db->select('tickets', '*', [
            'ticket_id' => $ticket['ticket_id'],
            'type' => 'reply',
            'ORDER' => ['date' => 'ASC']
        ]);
        ?>

            <?php if (empty($replies)): ?>
            <div class="card text-center p-6 text-gray-500">
               <span class="material-symbols-outlined text-gray-300" style="font-size: 48px;">forum</span>
               <p class="mt-2"><?= T::no ?> <?= T::replies ?> <?= T::yet ?></p>
            </div>
            <?php else: ?>
            <div class="space-y-3">
               <?php foreach ($replies as $reply): ?>
               <?php
               // Check if reply is from admin
               $reply_user = $db->get('users', ['first_name', 'last_name', 'role'], ['user_id' => $reply['user_id']]);
               $is_admin = ($reply_user && in_array($reply_user['role'], ['admin', 'support']));
               $reply_name = $reply_user ? htmlspecialchars($reply_user['first_name'] . ' ' . $reply_user['last_name']) : 'Unknown';
               ?>
               <div class="bg-white border border-gray-200 rounded-lg hover:shadow-sm transition-shadow overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3 <?= $is_admin ? 'bg-blue-50 border-b border-blue-100' : 'bg-gray-50 border-b border-gray-200' ?>">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold <?= $is_admin ? 'text-blue-900' : 'text-gray-900' ?>"><?= $reply_name ?></span>
                            <?php if ($is_admin): ?>
                            <span class="px-2.5 py-0.5 text-xs font-medium rounded-md bg-blue-100 text-blue-700 border border-blue-200">
                                <?= T::support ?> <?= T::team ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <span class="text-xs text-gray-500">
                            <?= date('M d, Y · H:i', strtotime($reply['date'])) ?>
                        </span>
                    </div>
                    <div class="px-5 py-4 text-gray-700 text-sm leading-relaxed">
                        <?= nl2br(htmlspecialchars($reply['desc'])) ?>
                    </div>

                    <!-- Reply Attachments -->
                    <?php if (!empty($reply['attachments'])): ?>
                    <?php $reply_attachments = json_decode($reply['attachments'], true); ?>
                    <?php if (is_array($reply_attachments) && count($reply_attachments) > 0): ?>
                    <div class="px-5 pb-4">
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($reply_attachments as $attachment): ?>
                            <a href="<?= root . htmlspecialchars($attachment) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 rounded-md transition-colors text-xs text-gray-700">
                                <span class="material-symbols-outlined text-sm">attach_file</span>
                                <?= basename($attachment) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
            </div>
            <?php endforeach; ?>
         </div>
         <?php endif; ?>
      </div>

      <!-- Reply Form -->
      <?php if ($ticket['status'] !== 'close'): ?>
      <div class="card p-6">
         <h3 class="text-lg font-bold text-gray-800 mb-4"><?= T::add ?> <?= T::reply ?></h3>
         <form action="<?= root ?>support/tickets/reply" method="POST" enctype="multipart/form-data" x-data="{ loading: false }" @submit="loading = true">
            <?= CSRF::tokenField() ?>
            <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket['ticket_id']) ?>">

            <div class="form-control mb-5">
               <label class="label"><?= T::your ?> <?= T::message ?> <span class="text-red-500">*</span></label>
               <textarea name="desc" required minlength="5" rows="5" class="textarea" placeholder="<?= T::type ?> <?= T::your ?> <?= T::reply ?>..."></textarea>
            </div>

            <div class="form-control mb-5">
               <label class="label"><?= T::attachment ?> <span class="text-gray-500 text-sm">(<?= T::optional ?>)</span></label>
               <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.zip" class="input">
               <p class="text-xs text-gray-500 mt-1">Max 5MB. Allowed: JPG, PNG, GIF, PDF, ZIP</p>
            </div>

            <button type="submit" class="btn" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
               <span x-show="!loading" class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-sm">send</span>
                  <?= T::send ?> <?= T::reply ?>
               </span>
               <span x-show="loading" class="flex items-center gap-2" x-cloak>
                  <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                     <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                     <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <span><?= T::sending ?>...</span>
               </span>
            </button>
         </form>
      </div>
      <?php else: ?>
      <div class="card p-6 text-center text-gray-600">
         <span class="material-symbols-outlined text-gray-400" style="font-size: 48px;">lock</span>
         <p class="mt-2 font-medium"><?= T::this ?> <?= T::ticket ?> <?= T::is ?> <?= T::closed ?></p>
         <p class="text-sm mt-1"><?= T::you ?> <?= T::cannot ?> <?= T::add ?> <?= T::more ?> <?= T::replies ?></p>
      </div>
      <?php endif; ?>

      </div>
   </div>
</div>

<script>
function closeTicket(ticketId) {
    if (!confirm('<?= T::are ?> <?= T::you ?> <?= T::sure ?> <?= T::you ?> <?= T::want ?> <?= T::to ?> <?= T::close ?> <?= T::this ?> <?= T::ticket ?>?')) {
        return;
    }

    const formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('csrf_token', '<?= CSRF::getToken() ?>');

    fetch('<?= root ?>support/tickets/close', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
    });
}
</script>

