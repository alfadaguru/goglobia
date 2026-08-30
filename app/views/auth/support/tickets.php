<!-- app/views/auth/support/tickets.php -->
<div class="bg" x-data="{
    sidebarCollapsed: window.innerWidth < 1024
}">
   <div class="flex lg:gap-5 container mb-8">

      <div class="flex-shrink-0">
         <?php include views."auth/sidebar.php"; ?>
      </div>

      <div class="flex-1 min-w-0 pt-8 bg-white rounded-[8px] ml-0">

         <!-- Header Section -->
         <div class="flex sm:items-center sm:justify-between mb-5 flex-col sm:flex-row gap-3">
            <div>
               <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-3">
                  <a href="<?=root?>dashboard" class="hover:text-gray-700">Dashboard</a>
                  <span class="material-symbols-outlined !text-sm">chevron_right</span>
                  <span class="text-gray-900"><?= T::support ?> <?= T::tickets ?></span>
               </nav>
               <div class="flex items-center gap-4">
                  <button @click="sidebarCollapsed = false" class="lg:hidden flex items-center justify-center w-12 h-12 bg-blue-50 rounded-lg text-blue-600 hover:bg-blue-100 transition-colors">
                     <span class="material-symbols-outlined text-2xl">menu</span>
                  </button>
                  <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-blue-100 rounded-lg">
                     <span class="material-symbols-outlined text-2xl text-blue-600">support_agent</span>
                  </div>
                  <div>
                     <h1 class="font-bold text-2xl text-gray-900"><?= T::support ?> <?= T::tickets ?></h1>
                     <p class="text-sm text-gray-500"><?= T::manage ?> <?= T::your ?> <?= T::support ?> <?= T::tickets ?></p>
                  </div>
               </div>
            </div>
            <button onclick="showCreateTicketModal()" class="btn flex items-center gap-2">
               <span class="material-symbols-outlined text-sm">add</span>
               <?= T::create ?> <?= T::ticket ?>
            </button>
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

    <!-- Tickets List -->
    <?php
    $tickets = $db->select('tickets', '*', [
        'user_id' => $user_id,
        'type' => 'parent',
        'ORDER' => ['date' => 'DESC']
    ]);
    ?>

    <?php if (empty($tickets)): ?>
    <div class="card text-center py-12">
        <span class="material-symbols-outlined text-gray-300" style="font-size: 80px;">confirmation_number</span>
        <h3 class="text-xl font-semibold text-gray-700 mt-4"><?= T::no ?> <?= T::tickets ?> <?= T::found ?></h3>
        <p class="text-gray-500 mt-2"><?= T::create ?> <?= T::your ?> <?= T::first ?> <?= T::support ?> <?= T::ticket ?></p>
    </div>
    <?php else: ?>

    <!-- Desktop Table View -->
    <div class="card overflow-hidden hidden xl:block">
        <table class="w-full">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::ticket ?> ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::subject ?></th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::priority ?></th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::status ?></th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::date ?></th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= T::actions ?></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($tickets as $ticket): ?>
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="text-sm font-mono text-gray-900">#<?= htmlspecialchars($ticket['ticket_id']) ?></span>
                    </td>
                    <td class="px-6 py-4">
                        <a href="<?= root ?>support/ticket/<?= htmlspecialchars($ticket['ticket_id']) ?>" class="text-sm text-primary hover:underline font-medium">
                            <?= htmlspecialchars(substr($ticket['subject'], 0, 50)) ?><?= strlen($ticket['subject']) > 50 ? '...' : '' ?>
                        </a>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php
                        $priority_colors = [
                            'low' => 'bg-blue-100 text-blue-800',
                            'normal' => 'bg-gray-100 text-gray-800',
                            'high' => 'bg-red-100 text-red-800'
                        ];
                        $color = $priority_colors[$ticket['priority']] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $color ?>">
                            <?= ucfirst(htmlspecialchars($ticket['priority'])) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php
                        $status_colors = [
                            'open' => 'bg-green-100 text-green-800',
                            'in progress' => 'bg-yellow-100 text-yellow-800',
                            'waiting' => 'bg-orange-100 text-orange-800',
                            'close' => 'bg-gray-100 text-gray-800'
                        ];
                        $color = $status_colors[$ticket['status']] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $color ?>">
                            <?= ucfirst(htmlspecialchars($ticket['status'])) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?= date('M d, Y', strtotime($ticket['date'])) ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <a href="<?= root ?>support/ticket/<?= htmlspecialchars($ticket['ticket_id']) ?>" class="text-primary hover:text-primary-dark mr-3">
                            <span class="material-symbols-outlined text-sm align-middle">visibility</span>
                        </a>
                        <?php if ($ticket['status'] !== 'close'): ?>
                        <button onclick="closeTicket('<?= htmlspecialchars($ticket['ticket_id']) ?>')" class="text-gray-600 hover:text-red-600 inline-flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm align-middle">close</span>
                            <span><?= T::close ?> <?= T::ticket ?></span>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile Card View -->
    <div class="xl:hidden space-y-4">
        <?php foreach ($tickets as $ticket): ?>
        <div class="card p-4">
            <div class="flex justify-between items-start mb-3">
                <span class="text-sm font-mono text-gray-600">#<?= htmlspecialchars($ticket['ticket_id']) ?></span>
                <?php
                $status_colors = [
                    'open' => 'bg-green-100 text-green-800',
                    'in progress' => 'bg-yellow-100 text-yellow-800',
                    'waiting' => 'bg-orange-100 text-orange-800',
                    'close' => 'bg-gray-100 text-gray-800'
                ];
                $color = $status_colors[$ticket['status']] ?? 'bg-gray-100 text-gray-800';
                ?>
                <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $color ?>">
                    <?= ucfirst(htmlspecialchars($ticket['status'])) ?>
                </span>
            </div>
            <h3 class="font-medium text-gray-900 mb-2">
                <?= htmlspecialchars($ticket['subject']) ?>
            </h3>
            <div class="flex items-center justify-between text-sm text-gray-500 mb-3">
                <span><?= date('M d, Y', strtotime($ticket['date'])) ?></span>
                <?php
                $priority_colors = [
                    'low' => 'bg-blue-100 text-blue-800',
                    'normal' => 'bg-gray-100 text-gray-800',
                    'high' => 'bg-red-100 text-red-800'
                ];
                $color = $priority_colors[$ticket['priority']] ?? 'bg-gray-100 text-gray-800';
                ?>
                <span class="px-2 py-1 text-xs font-semibold rounded-full <?= $color ?>">
                    <?= ucfirst(htmlspecialchars($ticket['priority'])) ?>
                </span>
            </div>
            <div class="flex gap-2">
                <a href="<?= root ?>support/ticket/<?= htmlspecialchars($ticket['ticket_id']) ?>" class="btn flex-1 text-center">
                    <?= T::view ?>
                </a>
                <?php if ($ticket['status'] !== 'close'): ?>
                <button onclick="closeTicket('<?= htmlspecialchars($ticket['ticket_id']) ?>')" class="btn light px-4">
                    <?= T::close ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

      </div>
   </div>
</div>

<!-- Create Ticket Modal -->
<div id="createTicketModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 bg-white border-b px-6 py-4 flex justify-between items-center">
            <h2 class="text-xl font-bold text-gray-800"><?= T::create ?> <?= T::new ?> <?= T::ticket ?></h2>
            <button onclick="hideCreateTicketModal()" class="text-gray-500 hover:text-gray-700">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form action="<?= root ?>support/tickets/create" method="POST" enctype="multipart/form-data" class="p-6" x-data="{ loading: false }" @submit="loading = true">
            <?= CSRF::tokenField() ?>

            <div class="form-control mb-5">
                <label class="label"><?= T::subject ?> <span class="text-red-500">*</span></label>
                <input type="text" name="subject" required minlength="5" maxlength="200" class="input" placeholder="<?= T::enter ?> <?= T::ticket ?> <?= T::subject ?>">
            </div>

            <div class="form-control mb-5">
                <label class="label"><?= T::priority ?></label>
                <select name="priority" class="input">
                    <option value="low"><?= T::low ?></option>
                    <option value="normal" selected><?= T::normal ?></option>
                    <option value="high"><?= T::high ?></option>
                </select>
            </div>

            <div class="form-control mb-5">
                <label class="label"><?= T::description ?> <span class="text-red-500">*</span></label>
                <textarea name="desc" required minlength="10" rows="6" class="textarea" placeholder="<?= T::describe ?> <?= T::your ?> <?= T::issue ?>..."></textarea>
            </div>

            <div class="form-control mb-5">
                <label class="label"><?= T::attachment ?> <span class="text-gray-500 text-sm">(<?= T::optional ?>)</span></label>
                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.zip" class="input">
                <p class="text-xs text-gray-500 mt-1">Max 5MB. Allowed: JPG, PNG, GIF, PDF, ZIP</p>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="btn flex-1" :disabled="loading" :class="loading ? 'opacity-75 cursor-not-allowed' : ''">
                    <span x-show="!loading" class="flex items-center justify-center gap-2">
                        <?= T::create ?> <?= T::ticket ?>
                    </span>
                    <span x-show="loading" class="flex items-center justify-center gap-2" x-cloak>
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span><?= T::creating ?>...</span>
                    </span>
                </button>
                <button type="button" onclick="hideCreateTicketModal()" class="btn light px-6" :disabled="loading">
                    <?= T::cancel ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showCreateTicketModal() {
    document.getElementById('createTicketModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function hideCreateTicketModal() {
    document.getElementById('createTicketModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

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

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideCreateTicketModal();
    }
});

// Close modal on outside click
document.getElementById('createTicketModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideCreateTicketModal();
    }
});
</script>

