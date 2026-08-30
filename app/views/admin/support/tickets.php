<div class="container">

    <!-- Page Header -->
    <div class="bg-white border-b border-slate-200 px-6 py-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900"><?= T::support. ' '. T::tickets ?></h1>
                <p class="text-sm text-slate-500 mt-1">
                    <?php
                    // GET USER ROLE AND PERMISSIONS
                    $user_id = $_SESSION['user_id'];
                    $user_role = $db->get('users', 'role', ['user_id' => $user_id]);
                    $is_staff = ($user_role === 'admin' || $user_role === 'support');
                    echo $is_staff ? T::view . ' ' . T::and . ' ' . T::manage . ' ' . T::all . ' ' . T::support . ' ' . T::tickets : T::view . ' ' . T::and . ' ' . T::manage . ' ' . T::your . ' ' . T::support . ' ' . T::tickets;
                    ?>
                </p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                <a href="<?=root.admin?>/support/ticket/new" class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 transition w-full sm:w-auto">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <?= T::new ?> <?= T::ticket ?>
                </a>
                <a href="<?=root?>dashboard" class="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition w-full sm:w-auto">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    <?= T::back ?> <?= T::to ?> <?= T::dashboard ?>
                </a>
            </div>
        </div>
    </div>

    <div class="p-6">

        <?php if (isset($_SESSION['ticket_error'])): ?>
        <div class="alert-error mb-5">
            <span class="material-symbols-outlined">error</span>
            <div>
                <p class="font-semibold"><?= T::unable ?> <?= T::to ?> <?= T::create ?> <?= T::ticket ?></p>
                <p class="mt-1"><?= htmlspecialchars($_SESSION['ticket_error']) ?></p>
                <p class="text-xs mt-2">
                    <span class="material-symbols-outlined text-xs align-middle">info</span>
                    <strong><?= T::tip ?>:</strong> <?= T::you ?> <?= T::can ?> <?= T::close ?> <?= T::your ?> <?= T::open ?> <?= T::ticket ?> <?= T::yourself ?> <?= T::by ?> <?= T::clicking ?> <?= T::the ?> "<?= T::close ?> <?= T::ticket ?>" <?= T::button ?> <?= T::on ?> <?= T::the ?> <?= T::ticket ?> <?= T::details ?> <?= T::page ?>.
                </p>
            </div>
        </div>
        <?php unset($_SESSION['ticket_error']); endif; ?>

        <?php if (isset($_SESSION['ticket_success'])): ?>
        <div class="alert-success mb-5">
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold"><?= T::success ?></p>
                <p class="mt-1"><?= htmlspecialchars($_SESSION['ticket_success']) ?></p>
            </div>
        </div>
        <?php unset($_SESSION['ticket_success']); endif; ?>

        <?php
        // GET USER ROLE AND PERMISSIONS
        $user = $db->get('users', '*', ['user_id' => $user_id]);
        $is_admin_or_support = ($user['role'] === 'admin' || $user['role'] === 'support');

        // GET COUNTS FOR ALL TICKET STATUSES
        if ($is_admin_or_support) {
            // ADMIN/SUPPORT CAN SEE ALL TICKETS
            $total_tickets = $db->count('tickets', ['type' => 'parent']);
            $open_tickets = $db->count('tickets', ['type' => 'parent', 'status' => 'open']);
            $in_progress_tickets = $db->count('tickets', ['type' => 'parent', 'status' => 'in progress']);
            $waiting_tickets = $db->count('tickets', ['type' => 'parent', 'status' => 'waiting']);
            $closed_tickets = $db->count('tickets', ['type' => 'parent', 'status' => 'close']);
        } else {
            // REGULAR USERS ONLY SEE THEIR OWN TICKETS
            // FOR REGULAR USERS, "WAITING" TICKETS ARE COUNTED AS "OPEN"
            $total_tickets = $db->count('tickets', ['user_id' => $user_id, 'type' => 'parent']);
            $open_tickets = $db->count('tickets', ['user_id' => $user_id, 'type' => 'parent', 'status' => ['open', 'waiting']]);
            $in_progress_tickets = $db->count('tickets', ['user_id' => $user_id, 'type' => 'parent', 'status' => 'in progress']);
            $closed_tickets = $db->count('tickets', ['user_id' => $user_id, 'type' => 'parent', 'status' => 'close']);
        }

        $status = $_GET['type'] ?? 'all';
        ?>

        <!-- Quick Stats -->
        <?php
        $grid_cols = $is_admin_or_support ? 'md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5' : 'md:grid-cols-2 lg:grid-cols-4';
        ?>
        <div class="grid grid-cols-1 <?= $grid_cols ?> gap-4 mb-5">

            <a href="<?=root.admin?>/support/tickets?type=all" class="bg-white rounded-lg border border-slate-200 p-6 hover:border-primary transition <?= $status === 'all' ? 'border-primary ring-2 ring-primary/20' : '' ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500 mb-1"><?= T::all ?> <?= T::tickets ?></p>
                        <h3 class="text-3xl font-bold text-slate-900"><?= $total_tickets ?></h3>
                    </div>
                    <div class="p-3 bg-blue-50 rounded-lg">
                        <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
                        </svg>
                    </div>
                </div>
            </a>

            <a href="<?=root.admin?>/support/tickets?type=open" class="bg-white rounded-lg border border-slate-200 p-6 hover:border-green-500 transition <?= $status === 'open' ? 'border-green-500 ring-2 ring-green-500/20' : '' ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500 mb-1"><?= T::open ?></p>
                        <h3 class="text-3xl font-bold text-green-600"><?= $open_tickets ?></h3>
                    </div>
                    <div class="p-3 bg-green-50 rounded-lg">
                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
            </a>

            <a href="<?=root.admin?>/support/tickets?type=in-progress" class="bg-white rounded-lg border border-slate-200 p-6 hover:border-blue-500 transition <?= $status === 'in-progress' ? 'border-blue-500 ring-2 ring-blue-500/20' : '' ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500 mb-1"><?= T::in ?> <?= T::progress ?></p>
                        <h3 class="text-3xl font-bold text-blue-600"><?= $in_progress_tickets ?></h3>
                    </div>
                    <div class="p-3 bg-blue-50 rounded-lg">
                        <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
            </a>

            <?php if ($is_admin_or_support): ?>
            <a href="<?=root.admin?>/support/tickets?type=waiting" class="bg-white rounded-lg border border-slate-200 p-6 hover:border-yellow-500 transition <?= $status === 'waiting' ? 'border-yellow-500 ring-2 ring-yellow-500/20' : '' ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500 mb-1"><?= T::waiting ?></p>
                        <h3 class="text-3xl font-bold text-yellow-600"><?= $waiting_tickets ?></h3>
                    </div>
                    <div class="p-3 bg-yellow-50 rounded-lg">
                        <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
            </a>
            <?php endif; ?>

            <a href="<?=root.admin?>/support/tickets?type=close" class="bg-white rounded-lg border border-slate-200 p-6 hover:border-slate-400 transition <?= $status === 'close' ? 'border-slate-400 ring-2 ring-slate-400/20' : '' ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500 mb-1"><?= T::closed ?></p>
                        <h3 class="text-3xl font-bold text-slate-600"><?= $closed_tickets ?></h3>
                    </div>
                    <div class="p-3 bg-slate-50 rounded-lg">
                        <svg class="w-8 h-8 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                </div>
            </a>

        </div>

        <?php
        // BUILD WHERE CONDITION BASED ON STATUS AND USER ROLE
        if ($is_admin_or_support) {
            // ADMIN/SUPPORT SEE ALL TICKETS
            $where = ['type' => 'parent'];
        } else {
            // REGULAR USERS ONLY SEE THEIR OWN TICKETS
            $where = [
                'user_id' => $user_id,
                'type' => 'parent'
            ];
        }

        // MAP URL STATUS TO DATABASE STATUS
        $status_map = [
            'open' => 'open',
            'close' => 'close',
            'in-progress' => 'in progress',
            'waiting' => 'waiting'
        ];

        // Add status filter if not 'all'
        if ($status !== 'all' && isset($status_map[$status])) {
            // For regular users, when viewing "open" tickets, include both "open" and "waiting"
            if (!$is_admin_or_support && $status === 'open') {
                $where['status'] = ['open', 'waiting'];
            } else {
                $where['status'] = $status_map[$status];
            }
        }

        // SET TITLE BASED ON STATUS
        $titles = [
            'all' => T::all . ' ' . T::tickets,
            'open' => T::open . ' ' . T::tickets,
            'in-progress' => T::in . ' ' . T::progress . ' ' . T::tickets,
            'waiting' => T::waiting . ' ' . T::on . ' ' . T::customer,
            'close' => T::closed . ' ' . T::tickets
        ];
        $page_title = $titles[$status] ?? T::all . ' ' . T::tickets;

        // Dynamic status badge based on actual ticket status
        $status_badge = '
            <span x-data="{ status: \'{{status}}\', isAdmin: ' . ($is_admin_or_support ? 'true' : 'false') . ' }">
                <a x-show="status === \'open\'" href="'.root.admin.'/support/tickets?type=open" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700 hover:bg-green-200 transition">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                    <?= T::open ?>
                </a>
                <a x-show="status === \'in progress\'" href="'.root.admin.'/support/tickets?type=in-progress" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 hover:bg-blue-200 transition">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                    <?= T::in ?> <?= T::progress ?>
                </a>
                <a x-show="status === \'waiting\' && isAdmin" href="'.root.admin.'/support/tickets?type=waiting" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-700 hover:bg-yellow-200 transition">
                    <span class="w-1.5 h-1.5 rounded-full bg-yellow-500"></span>
                    <?= T::waiting ?>
                </a>
                <a x-show="status === \'waiting\' && !isAdmin" href="'.root.admin.'/support/tickets?type=open" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700 hover:bg-green-200 transition">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                    <?= T::open ?>
                </a>
                <a x-show="status === \'close\'" href="'.root.admin.'/support/tickets?type=close" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                    <span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span>
                    <?= T::closed ?>
                </a>
            </span>
        ';
        ?>

        <!-- Tickets Table -->
        <?php if ($total_tickets > 0): ?>
        <div class="bg-white rounded-lg border border-slate-200">
            <?php
            // DETERMINE IF USER CAN DELETE TICKETS BASED ON ROLE
            $can_delete = ($is_admin_or_support || $user['user_type'] === 'team');

            echo crud()->table('tickets')
                ->col('id,user_id,subject,status,date')
                ->where($where)
                ->title($page_title)
                ->row([
                    'status' => $status_badge,
                    'subject' => '<a href="'.root.admin.'/support/ticket/{{ticket_id}}" class="hover:text-primary transition"><strong>{{subject}}</strong></a>',
                    'user_id' => function($value) use ($db) {
                        $user = $db->get('users', ['first_name', 'last_name', 'email'], ['user_id' => $value]);
                        if ($user) {
                            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                            $email = $user['email'] ?? '';
                            return '<div class="py-1"><div class="font-medium text-slate-900">' . htmlspecialchars($name) . '</div><div class="text-xs text-slate-500 mt-0.5">' . htmlspecialchars($email) . '</div></div>';
                        }
                        return '<div class="text-slate-500">' . T::unknown . ' ' . T::user . '</div>';
                    }
                ])
                ->label(['user_id' => T::customer])
                ->actions([
                    'select-all' => $can_delete,
                    'add' => false,
                    'view' => true,
                    'delete' => $can_delete,
                    'edit' => false,
                    'status' => false,
                    'banned' => false,
                    'search' => true,
                ])
                ->action_urls([
                    'view' => root.admin.'/support/ticket/{ticket_id}',
                ])
                ->id_column('id')
                ->col_width('id', '0px')
                ->col_width('user_id', '200px')
                ->col_width('status', '120px')
                ->col_width('date', '150px')
                ->order('id', 'DESC')
                ->render();
            ?>
        </div>
        <?php else: ?>
        <!-- No Tickets Message -->
        <div class="bg-white rounded-lg border border-slate-200 p-12 text-center">
            <svg class="w-16 h-16 text-slate-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
            </svg>
            <h3 class="text-lg font-semibold text-slate-900 mb-2"><?= T::no ?> <?= T::tickets ?> <?= T::yet ?></h3>
            <p class="text-slate-500 mb-5"><?= T::you ?> <?= T::havent ?> <?= T::created ?> <?= T::any ?> <?= T::support ?> <?= T::tickets ?>. <?= T::click ?> <?= T::the ?> <?= T::button ?> <?= T::below ?> <?= T::to ?> <?= T::create ?> <?= T::your ?> <?= T::first ?> <?= T::ticket ?>.</p>
            <a href="<?=root.admin?>/support/ticket/new" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-primary rounded-lg hover:bg-primary/90 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <?= T::create ?> <?= T::your ?> <?= T::first ?> <?= T::ticket ?>
            </a>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Material Icons -->
<link href="https://fonts.googleapis.com/css2?family=Material+Icons+Outlined" rel="stylesheet">
