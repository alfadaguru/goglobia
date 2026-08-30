<?php
// Get ticket ID from URL segments
$url_segments = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
$ticket_id = end($url_segments);

// Remove query string if present
if (strpos($ticket_id, '?') !== false) {
    $ticket_id = substr($ticket_id, 0, strpos($ticket_id, '?'));
}

// Check if creating a new ticket
if ($ticket_id === 'new') {
    // Get current user info
    $user_id = $_SESSION['user_id'];
    $current_user = $db->get('users', '*', ['user_id' => $user_id]);
    $is_admin_or_support = ($current_user['role'] === 'admin' || $current_user['role'] === 'support');

    // Show new ticket form
    include 'ticket-new.php';
    exit;
}

if (!$ticket_id || empty($ticket_id)) {
    header('Location: ' . root . admin . '/support/tickets');
    exit;
}

// Get ticket details
$ticket = $db->get('tickets', '*', ['ticket_id' => $ticket_id, 'type' => 'parent']);

if (!$ticket) {
    $_SESSION['ticket_error'] = 'Ticket not found.';
    header('Location: ' . root . admin . '/support/tickets');
    exit;
}

// Get user who created the ticket
$ticket_user = $db->get('users', '*', ['user_id' => $ticket['user_id']]);
$ticket_user_name = ($ticket_user['first_name'] ?? '') . ' ' . ($ticket_user['last_name'] ?? '');
$ticket_user_name = trim($ticket_user_name) ?: 'Unknown User';

// Check permissions
$user_id = $_SESSION['user_id'];
$current_user = $db->get('users', '*', ['user_id' => $user_id]);
$is_admin_or_support = ($current_user['role'] === 'admin' || $current_user['role'] === 'support');

// Regular users can only view their own tickets
if (!$is_admin_or_support && $ticket['user_id'] !== $user_id) {
    $_SESSION['ticket_error'] = 'Access denied.';
    header('Location: ' . root . admin . '/support/tickets');
    exit;
}

// Get all replies for this ticket
$replies = $db->select('tickets', '*', ['ticket_id' => $ticket['ticket_id'], 'type' => 'reply'], 'ORDER BY date ASC');
if (!is_array($replies)) {
    $replies = [];
}

// Get user bookings if admin/support viewing
$user_bookings = [];
if ($is_admin_or_support) {
    $user_bookings = $db->select('bookings', '*', ['user_id' => $ticket['user_id']], 'ORDER BY id DESC LIMIT 10');
}
?>

<div class="min-h-screen bg-slate-50">

    <!-- Page Header -->
    <div class="bg-white border-b border-slate-200 px-6 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 max-w-5xl mx-auto">
            <div class="flex-1 min-w-0">
                <h1 class="text-2xl font-bold text-slate-900 truncate"><?=T::ticket?> #<?= $ticket['ticket_id'] ?></h1>
                <p class="text-sm text-slate-500 mt-1 truncate"><?= $ticket['subject'] ?></p>
            </div>
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full sm:w-auto">
                <?php if ($is_admin_or_support): ?>
                <select
                    id="statusSelect"
                    class="select w-full sm:w-auto"
                    onchange="handleStatusChange('<?= $ticket['ticket_id'] ?>', this.value)"
                >
                    <option value="" disabled><?=T::change_status?></option>
                    <option value="open" <?= $ticket['status'] === 'open' ? 'selected' : '' ?>><?=T::open?></option>
                    <option value="in progress" <?= $ticket['status'] === 'in progress' ? 'selected' : '' ?>><?=T::in_progress?></option>
                    <option value="waiting" <?= $ticket['status'] === 'waiting' ? 'selected' : '' ?>><?=T::waiting?></option>
                    <option value="close" <?= $ticket['status'] === 'close' ? 'selected' : '' ?>><?=T::closed?></option>
                </select>

                <button
                    onclick="deleteTicket('<?= $ticket['ticket_id'] ?>')"
                    class="inline-flex items-center justify-center gap-2 px-4 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition w-full sm:w-auto"
                    style="height: 40px;"
                    title="<?=T::delete.' '.T::ticket?>"
                >
                    <span class="material-icons-outlined" style="font-size: 18px;">delete</span>
                    <?=T::delete?>
                </button>
                <?php endif; ?>

                <?php
                // Allow users to close their own tickets if not already closed
                if ($ticket['status'] !== 'close' && !$is_admin_or_support):
                ?>
                <button
                    onclick="updateTicketStatus('<?= $ticket['ticket_id'] ?>', 'close')"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition w-full sm:w-auto"
                >
                    <span class="material-icons-outlined" style="font-size: 18px;">close</span>
                    <?=T::close_ticket?>
                </button>
                <?php endif; ?>

                <a href="<?=root.admin?>/support/tickets" class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition w-full sm:w-auto">
                    <span class="material-icons-outlined" style="font-size: 18px;">arrow_back</span>
                    <?=T::back_to_tickets?>
                </a>
            </div>
        </div>
    </div>

    <div class="p-6">
        <div class="max-w-5xl mx-auto">

            <?php if (isset($_SESSION['ticket_success'])): ?>
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-green-600 text-xl">check_circle</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-green-900"><?=T::success?></p>
                        <p class="text-sm text-green-700 mt-1"><?= htmlspecialchars($_SESSION['ticket_success']) ?></p>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['ticket_success']); endif; ?>

            <?php if (isset($_SESSION['ticket_error'])): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-red-600 text-xl">error</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-red-900"><?=T::error?></p>
                        <p class="text-sm text-red-700 mt-1"><?= htmlspecialchars($_SESSION['ticket_error']) ?></p>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['ticket_error']); endif; ?>

            <!-- Ticket Status Bar -->
            <div class="bg-gradient-to-r from-primary to-primary/80 rounded-lg p-5 mb-6 text-white">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-6 flex-wrap">
                        <div>
                            <p class="text-sm opacity-90"><?=T::status?></p>
                            <div class="flex items-center gap-2 mt-1">
                                <?php
                                $status_display = [
                                    'open' => [T::open, 'bg-green-600'],
                                    'in progress' => [T::in_progress, 'bg-blue-600'],
                                    'waiting' => [T::waiting, 'bg-yellow-600'],
                                    'close' => [T::closed, 'bg-slate-600']
                                ];
                                $current_status = $status_display[$ticket['status']] ?? ['Unknown', 'bg-gray-600'];
                                ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold <?= $current_status[1] ?> shadow-sm">
                                    <span class="w-2 h-2 rounded-full bg-white"></span>
                                    <?= $current_status[0] ?>
                                </span>
                            </div>
                        </div>
                        <div class="h-10 w-px bg-white/20"></div>
                        <div>
                            <p class="text-sm opacity-90"><?=T::customer?></p>
                            <p class="text-base font-semibold mt-1"><?= htmlspecialchars($ticket_user_name) ?></p>
                        </div>
                        <div class="h-10 w-px bg-white/20"></div>
                        <div>
                            <p class="text-sm opacity-90"><?=T::email?></p>
                            <p class="text-base font-semibold mt-1"><?= htmlspecialchars($ticket_user['email'] ?? 'N/A') ?></p>
                        </div>
                        <div class="h-10 w-px bg-white/20"></div>
                        <div>
                            <p class="text-sm opacity-90"><?=T::priority?></p>
                            <p class="text-base font-semibold mt-1"><?= ucfirst($ticket['priority'] ?? T::normal) ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm opacity-90"><?=T::created?></p>
                        <p class="text-base font-semibold mt-1"><?= date('M d, Y', strtotime($ticket['date'])) ?></p>
                        <p class="text-sm opacity-75"><?= date('h:i A', strtotime($ticket['date'])) ?></p>
                    </div>
                </div>
            </div>

            <!-- Help Info for Customers -->
            <?php if ($ticket['status'] !== 'close' && !$is_admin_or_support): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-5">
                <div class="flex items-start gap-3">
                    <span class="material-icons-outlined text-blue-600 text-xl">info</span>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-blue-900"><?=T::manage?> <?=T::ticket?></p>
                        <p class="text-sm text-blue-700 mt-1">
                            <?=T::ticket?> <?= T::close ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Customer Bookings (if admin/support) -->
            <?php if ($is_admin_or_support && !empty($user_bookings)): ?>
            <div class="bg-white rounded-lg border border-slate-200 p-4 mb-4">
                <h3 class="text-base font-semibold text-slate-900 mb-3 flex items-center gap-2">
                    <span class="material-icons-outlined text-primary">flight_takeoff</span>
                    <?=T::customer?> <?=T::recent?> <?=T::bookings?>
                </h3>
                <div class="overflow-x-auto">
                    <?php
                    echo crud()->table('bookings')
                        ->col('id,invoice_id,first_name,last_name,email,phone,price_markup,booking_status,booking_date')
                        ->where(['user_id' => $ticket['user_id']])
                        ->title('')
                        ->label([
                            'invoice_id' => 'Invoice',
                            'first_name' => 'First Name',
                            'last_name' => 'Last Name',
                            'email' => 'Email',
                            'phone' => 'Phone',
                            'price_markup' => 'Amount',
                            'booking_status' => 'Status',
                            'booking_date' => 'Date'
                        ])
                        ->row([
                            'invoice_id' => '<a href="'.root.admin.'/bookings/{{invoice_id}}" class="text-primary hover:underline font-medium">{{invoice_id}}</a>',
                            'price_markup' => '<?=CURRENCY?> {{price_markup}}'
                        ])
                        ->actions([
                            'select-all' => false,
                            'add' => false,
                            'view' => true,
                            'delete' => false,
                            'edit' => false,
                            'status' => false,
                            'banned' => false,
                            'search' => false,
                        ])
                        ->action_urls([
                            'view' => root.admin.'/bookings/{invoice_id}'
                        ])
                        ->id_column('id')
                        ->col_width('id', '0px')
                        ->order('id', 'DESC')
                        ->render();
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Original Ticket Message -->
            <div class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-5 shadow-sm">
                <div class="bg-slate-50 px-6 py-3.5 border-b border-slate-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary to-primary/70 flex items-center justify-center text-white font-semibold shadow-sm">
                                <?= strtoupper(substr($ticket_user_name, 0, 1)) ?>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($ticket_user_name) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars($ticket_user['email'] ?? '') ?></p>
                            </div>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-primary/10 text-primary">
                                <span class="material-icons-outlined" style="font-size: 14px;">person</span>
                                <?=T::customer?>
                            </span>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-slate-900"><?= date('M d, Y', strtotime($ticket['date'])) ?></p>
                            <p class="text-xs text-slate-500"><?= date('h:i A', strtotime($ticket['date'])) ?></p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="prose max-w-none">
                        <?= $ticket['desc'] ?>
                    </div>

                    <?php if (!empty($ticket['attachments'])):
                        $attachments = json_decode($ticket['attachments'], true);
                        if (!empty($attachments) && is_array($attachments)):
                    ?>
                    <div class="mt-5 pt-5 border-t border-slate-200">
                        <p class="text-sm font-medium text-slate-700 mb-3 flex items-center gap-2">
                            <span class="material-icons-outlined" style="font-size: 18px;">attach_file</span>
                            <?=T::attachments?>
                        </p>
                        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
                            <?php foreach ($attachments as $attachment): ?>
                            <a href="<?= root . $attachment ?>"
                               class="ticket-image group relative block bg-slate-50 rounded-lg overflow-hidden border border-slate-200 hover:border-primary transition cursor-pointer"
                               data-lightbox="ticket-gallery"
                               data-title="Attachment">
                                <img src="<?= root . $attachment ?>"
                                     alt="Attachment"
                                     class="w-full h-24 object-cover group-hover:scale-105 transition-transform duration-200">
                                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors flex items-center justify-center">
                                    <span class="material-icons-outlined text-white opacity-0 group-hover:opacity-100 transition-opacity">
                                        zoom_in
                                    </span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; endif; ?>
                </div>
            </div>

            <!-- Ticket Replies -->
            <?php foreach ($replies as $reply):
                $reply_user = $db->get('users', '*', ['user_id' => $reply['user_id']]);
                $reply_user_name = $reply_user ? (($reply_user['first_name'] ?? '') . ' ' . ($reply_user['last_name'] ?? '')) : 'Unknown User';
                $reply_user_name = trim($reply_user_name) ?: 'Unknown User';
                $is_ticket_creator = ($reply['user_id'] === $ticket['user_id']);
                $is_staff = $reply_user && (($reply_user['role'] ?? '') === 'admin' || ($reply_user['role'] ?? '') === 'support');
            ?>
            <div class="bg-white rounded-lg border border-slate-200 overflow-hidden mb-5 shadow-sm <?= $is_staff ? 'ring-1 ring-blue-200' : '' ?>">
                <div class="<?= $is_staff ? 'bg-blue-50' : 'bg-slate-50' ?> px-6 py-3.5 border-b border-slate-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full <?= $is_staff ? 'bg-gradient-to-br from-blue-600 to-blue-400' : 'bg-gradient-to-br from-slate-600 to-slate-400' ?> flex items-center justify-center text-white font-semibold shadow-sm">
                                <?= strtoupper(substr($reply_user_name, 0, 1)) ?>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($reply_user_name) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars($reply_user['email'] ?? '') ?></p>
                            </div>
                            <?php if ($is_staff): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-blue-600 text-white shadow-sm">
                                <span class="material-icons-outlined" style="font-size: 14px;">support_agent</span>
                                <?=T::support?> <?=T::team?>
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-slate-600 text-white">
                                <span class="material-icons-outlined" style="font-size: 14px;">person</span>
                                <?=T::customer?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-slate-900"><?= date('M d, Y', strtotime($reply['date'])) ?></p>
                            <p class="text-xs text-slate-500"><?= date('h:i A', strtotime($reply['date'])) ?></p>
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <div class="prose max-w-none">
                        <?= $reply['desc'] ?>
                    </div>

                    <?php if (!empty($reply['attachments'])):
                        $reply_attachments = json_decode($reply['attachments'], true);
                        if (!empty($reply_attachments) && is_array($reply_attachments)):
                    ?>
                    <div class="mt-4 pt-4 border-t border-slate-200">
                        <p class="text-xs font-medium text-slate-700 mb-2 flex items-center gap-1">
                            <span class="material-icons-outlined" style="font-size: 16px;">attach_file</span>
                            <?=T::attachments?>
                        </p>
                        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2">
                            <?php foreach ($reply_attachments as $attachment): ?>
                            <a href="<?= root . $attachment ?>"
                               class="ticket-image group relative block bg-slate-50 rounded-lg overflow-hidden border border-slate-200 hover:border-primary transition cursor-pointer"
                               data-lightbox="ticket-gallery"
                               data-title="Reply Attachment">
                                <img src="<?= root . $attachment ?>"
                                     alt="Attachment"
                                     class="w-full h-20 object-cover group-hover:scale-105 transition-transform duration-200">
                                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors flex items-center justify-center">
                                    <span class="material-icons-outlined text-white text-sm opacity-0 group-hover:opacity-100 transition-opacity">
                                        zoom_in
                                    </span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Reply Form -->
            <?php if ($ticket['status'] !== 'close'): ?>
            <div class="bg-white rounded-lg border border-slate-200 overflow-hidden shadow-sm">
                <div class="bg-gradient-to-r from-slate-700 to-slate-600 px-6 py-3.5">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <span class="material-icons-outlined">reply</span>
                        <?=T::add_reply?>
                    </h3>
                </div>
                <form action="<?=root.admin?>/support/tickets/reply" method="POST" enctype="multipart/form-data" class="p-6 space-y-5">

                    <div>
                        <label for="reply_desc" class="block text-sm font-medium text-slate-700 mb-2">
                            <?=T::your?> <?=T::response?> <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            id="reply_desc"
                            name="desc"
                            required
                            rows="5"
                            class="textarea w-full"
                            placeholder="Type your response here..."
                        ></textarea>
                    </div>

                    <div x-data="{
                        imagePreview: '',
                        uploadMethod: 'file',

                        handleFileSelect(event) {
                            const file = event.target.files[0];
                            if (file) {
                                const reader = new FileReader();
                                reader.onload = (e) => {
                                    this.imagePreview = e.target.result;
                                    this.uploadMethod = 'file';
                                };
                                reader.readAsDataURL(file);
                            }
                        },

                        clearImage() {
                            this.imagePreview = '';
                            document.getElementById('attachment').value = '';
                        }
                    }">
                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            <?=T::attachment?> (<?=T::optional?>)
                        </label>

                        <div class="space-y-3">
                            <!-- File Upload -->
                            <div class="relative">
                                <input
                                    type="file"
                                    id="attachment"
                                    name="attachment"
                                    accept="image/*"
                                    @change="handleFileSelect($event)"
                                    class="hidden"
                                >
                                <label
                                    for="attachment"
                                    class="flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-slate-300 rounded-lg hover:border-primary hover:bg-slate-50 transition cursor-pointer"
                                >
                                    <span class="material-icons-outlined text-slate-400">cloud_upload</span>
                                    <span class="text-sm text-slate-600">Click to upload image</span>
                                </label>
                            </div>

                            <!-- Image Preview -->
                            <div x-show="imagePreview" x-cloak class="relative">
                                <img :src="imagePreview" alt="Preview" class="max-w-xs rounded-lg border border-slate-200">
                                <button
                                    type="button"
                                    @click="clearImage()"
                                    class="absolute top-2 right-2 p-1.5 bg-red-600 text-white rounded-full hover:bg-red-700 transition shadow-lg"
                                >
                                    <span class="material-icons-outlined" style="font-size: 18px;">close</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Notification Methods (Admin/Support Only) -->
                    <?php if ($is_admin_or_support): ?>
                    <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="notify_email" value="1" checked class="w-4 h-4 text-primary border-slate-300 rounded focus:ring-primary">
                            <span class="material-icons-outlined text-primary" style="font-size: 18px;">email</span>
                            <span class="text-sm font-medium text-slate-700"><?=T::send?> <?=T::email?></span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-center justify-end gap-3 pt-5 border-t border-slate-200">
                        <button
                            type="submit"
                            id="replySubmitBtn"
                            class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 transition shadow-sm"
                        >
                            <span id="replySubmitLoader" class="material-icons-outlined animate-spin" style="font-size: 18px; display: none;">refresh</span>
                            <span id="replySubmitIcon" class="material-icons-outlined" style="font-size: 18px;">send</span>
                            <span id="replySubmitText"><?=T::send_reply?></span>
                        </button>
                    </div>

                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="ticket_id" value="<?= $ticket['ticket_id'] ?>">
                    <input type="hidden" name="reply" value="1">
                    <?php
                    $last_reply = $is_admin_or_support ? 'support' : 'user';
                    ?>
                    <input type="hidden" name="last_reply" value="<?= $last_reply ?>">
                </form>
            </div>
            <?php else: ?>
            <!-- Closed Ticket Message -->
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-8 text-center">
                <span class="material-icons-outlined text-slate-400 mx-auto mb-3" style="font-size: 48px;">lock</span>
                <h3 class="text-lg font-semibold text-slate-900 mb-2"><?=T::ticket_closed?></h3>
                <p class="text-slate-600 max-w-lg mx-auto">
                    <?=T::ticket?> <?=T::closed?> <?=  T::message ?>
                    <?php if (!$is_admin_or_support): ?>
                    <?=T::create?> <?=T::new_ticket?>.
                    <?php else: ?>
                    <?=T::open?> <?=T::or?> <?=T::create?> <?=T::new_ticket?>.
                    <?php endif; ?>
                </p>
                <?php if (!$is_admin_or_support): ?>
                <a href="<?=root?>ticket-new" class="inline-flex items-center gap-2 px-6 py-2.5 mt-6 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 transition shadow-sm">
                    <span class="material-icons-outlined text-lg">add</span>
                    <?=T::create_ticket?>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- Lightbox2 for Image Gallery -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>

<!-- Material Icons -->
<link href="https://fonts.googleapis.com/css2?family=Material+Icons+Outlined" rel="stylesheet">

<script>
    // Configure Lightbox2 options
    lightbox.option({
        'resizeDuration': 200,
        'wrapAround': true,
        'albumLabel': "Image %1 of %2"
    });

    // Handle form submission
    const replyForm = document.querySelector('form[action*="tickets/reply"]');
    if (replyForm) {
        replyForm.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('replySubmitBtn');
            const submitLoader = document.getElementById('replySubmitLoader');
            const submitIcon = document.getElementById('replySubmitIcon');
            const submitText = document.getElementById('replySubmitText');

            submitBtn.disabled = true;
            submitLoader.style.display = 'inline-block';
            submitIcon.style.display = 'none';
            submitText.textContent = '<?=T::sending?>...';
        });
    }

    function handleStatusChange(ticketId, newStatus) {
        const select = document.getElementById('statusSelect');
        const currentStatus = '<?= $ticket['status'] ?>';

        if (!newStatus || newStatus === '' || newStatus === currentStatus) {
            select.value = currentStatus;
            return;
        }

        let statusLabel = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
        if (newStatus === 'in progress') {
            statusLabel = 'In Progress';
        }

        if (confirm(`Are you sure you want to change the status to "${statusLabel}"?`)) {
            updateTicketStatus(ticketId, newStatus);
        } else {
            select.value = currentStatus;
        }
    }

    function updateTicketStatus(ticketId, status) {
        let confirmMessage = '';

        if (status === 'close') {
            confirmMessage = 'Are you sure you want to close this ticket?\n\n';
            confirmMessage += 'Important:\n';
            confirmMessage += '• No further replies will be possible\n';
            confirmMessage += '• The ticket will be marked as resolved\n';
            confirmMessage += '• Customer will be notified\n\n';
            confirmMessage += 'Do you want to proceed?';
        } else {
            confirmMessage = `Are you sure you want to change the status to "${status}"?`;
        }

        if (confirm(confirmMessage)) {
            const buttons = document.querySelectorAll('button[onclick*="updateTicketStatus"]');
            buttons.forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.6';
            });

            fetch('<?=root.admin?>/support/tickets/update-status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ticket_id=${ticketId}&status=${status}&csrf_token=<?= CSRF::getToken() ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message and reload
                    alert('<?=T::status?> updated successfully!');
                    location.reload();
                } else {
                    alert('<?=T::error?>: ' + (data.message || 'Unknown error'));
                    buttons.forEach(btn => {
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating the ticket status.');
                buttons.forEach(btn => {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                });
            });
        }
    }

    function deleteTicket(ticketId) {
        const confirmMessage = 'Are you sure you want to DELETE this ticket?\n\n';
        const warning = 'WARNING:\n';
        const details = '• This will permanently delete the ticket\n';
        const details2 = '• All replies will be deleted\n';
        const details3 = '• This action CANNOT be undone\n\n';
        const question = 'Do you really want to proceed?';

        if (confirm(confirmMessage + warning + details + details2 + details3 + question)) {
            const buttons = document.querySelectorAll('button');
            buttons.forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.6';
            });

            fetch('<?=root.admin?>/support/tickets/delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `ticket_id=${ticketId}&csrf_token=<?= CSRF::getToken() ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Ticket deleted successfully!');
                    window.location.href = '<?=root.admin?>/support/tickets';
                } else {
                    alert('Failed to delete ticket: ' + (data.message || 'Unknown error'));
                    buttons.forEach(btn => {
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the ticket.');
                buttons.forEach(btn => {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                });
            });
        }
    }
</script>

<style>
    .prose {
        max-width: none;
    }
    .prose img {
        max-width: 100%;
        height: auto;
        border-radius: 0.5rem;
    }
    [x-cloak] {
        display: none !important;
    }
</style>
