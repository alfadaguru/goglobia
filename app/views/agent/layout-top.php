<?php
// ============================================================================
// app/views/agent/layout-top.php — DEDICATED agent-portal shell (top half).
// A standalone layout for /agent/* so the travel-agent workspace does NOT drag
// in the public/customer site chrome (top nav, footer, GIA widget) — that was
// the "two dashboards" problem. It keeps only what a portal needs: the <head>
// assets (Tailwind, fonts, CSRF meta) + the agent sidebar + a content <main>.
// Pair with layout-bottom.php. Expects $title (optional).
// ============================================================================
@$SECURE or die('Access Denied!');

// Flag the agent-portal context so shared views (e.g. auth/api-access.php,
// auth/api-docs.php) that normally render the CUSTOMER sidebar can SKIP it here —
// the agent layout already provides the sidebar + <main>. Prevents a second
// (customer) sidebar panel showing inside the agent portal.
$GLOBALS['__agent_portal'] = true;

// Resolve the acting agency context once for the shell (sidebar self-guards too).
$__actx = function_exists('agent_acting_context') ? agent_acting_context($GLOBALS['db'] ?? null) : null;
$__brandName = $GLOBALS['app']['business_name'] ?? 'GoGlobia';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?= CSRF::getToken() ?>">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars(($title ?? 'Agent Portal') . ' | ' . $__brandName) ?></title>
    <link rel="shortcut icon" href="<?= versionedAssetUrl('uploads/global/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <?php require_once "app/views/tailwind.php"; ?>
    <!-- The compiled stylesheets the shared header loads — tailwind.php only emits
         config/inline rules, NOT the built CSS, so without these the portal renders
         completely unstyled (and icons show as literal text). Mirror the shared
         header: precompiled Tailwind + Material Symbols icon font + app.css. -->
    <link rel="stylesheet" href="<?= versionedAssetUrl('assets/css/tailwind.build.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="<?= versionedAssetUrl('assets/css/app.css') ?>">
    <style>
        [x-cloak] { display: none !important; }
        /* Dedicated agent shell: fixed 240px sidebar on desktop, off-canvas on mobile. */
        @media (min-width: 1024px) { body.agent-portal { padding-left: 240px; } }
        @media (max-width: 1023px) { body.agent-portal { padding-left: 0; } }
        body { background:#f6f7f9; }
    </style>
</head>
<body class="agent-portal bg-background text-foreground">
    <?php
    // The agent sidebar (self-guards on agent_acting_context; renders nothing for
    // non-agents). This is the ONLY nav in the portal — no customer top-nav.
    include "app/views/agent/sidebar.php";
    ?>
    <main class="min-h-screen">
    <?php
    // GAP-4 (comms): slim portal top bar with the AGENCY notification bell. Polls
    // /agent/notifications/unread-count and lists via /agent/notifications/list
    // (owner-scoped). Only rendered for an actual agent/staff context.
    if (is_array($__actx) && empty($__actx['is_admin'])):
    ?>
    <div x-data="agentBell()" x-init="init()" class="sticky top-0 z-30 flex items-center justify-end gap-2 h-12 px-4 lg:px-6 bg-white/90 backdrop-blur border-b border-slate-200">
        <div class="relative">
            <button @click="toggle()" class="relative inline-flex items-center justify-center w-9 h-9 rounded-lg hover:bg-slate-100 text-slate-600" aria-label="Notifications">
                <span class="material-symbols-outlined text-[22px]">notifications</span>
                <span x-cloak x-show="unread > 0" x-text="unread > 99 ? '99+' : unread"
                      class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center"></span>
            </button>
            <div x-cloak x-show="open" @click.outside="open=false" x-transition
                 class="absolute right-0 mt-2 w-80 max-w-[90vw] bg-white rounded-xl shadow-lg border border-slate-200 overflow-hidden">
                <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-100">
                    <span class="text-sm font-semibold text-slate-800">Notifications</span>
                    <button @click="markAll()" x-show="unread > 0" class="text-[11px] text-violet-600 hover:underline">Mark all read</button>
                </div>
                <div class="max-h-96 overflow-y-auto divide-y divide-slate-50">
                    <template x-if="rows.length === 0">
                        <div class="px-4 py-8 text-center text-sm text-slate-400">No notifications yet.</div>
                    </template>
                    <template x-for="n in rows" :key="n.id">
                        <a :href="n.url || '#'" class="flex gap-3 px-4 py-3 hover:bg-slate-50" :class="n.is_read == 0 ? 'bg-violet-50/40' : ''">
                            <span class="material-symbols-outlined text-[20px] text-slate-500 mt-0.5" x-text="n.icon || 'notifications'"></span>
                            <span class="min-w-0">
                                <span class="block text-[13px] font-medium text-slate-800 truncate" x-text="n.title"></span>
                                <span class="block text-[12px] text-slate-500" x-text="n.body"></span>
                                <span class="block text-[11px] text-slate-400 mt-0.5" x-text="n.created_at"></span>
                            </span>
                        </a>
                    </template>
                </div>
                <a href="<?= root ?>agent/dashboard" class="block px-4 py-2 text-center text-[12px] text-slate-500 hover:bg-slate-50 border-t border-slate-100">Go to dashboard</a>
            </div>
        </div>
    </div>
    <script>
        function agentBell() {
            return {
                open: false, unread: 0, rows: [], loaded: false,
                csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
                init() { this.poll(); setInterval(() => this.poll(), 60000); },
                async poll() {
                    try {
                        const r = await fetch('<?= root ?>agent/notifications/unread-count', { headers: { 'Accept': 'application/json' } });
                        const j = await r.json(); this.unread = j.unread || 0;
                    } catch (e) {}
                },
                async toggle() {
                    this.open = !this.open;
                    if (this.open) { await this.load(); }
                },
                async load() {
                    try {
                        const r = await fetch('<?= root ?>agent/notifications/list', { headers: { 'Accept': 'application/json' } });
                        const j = await r.json(); this.rows = j.rows || []; this.unread = j.unread ?? this.unread; this.loaded = true;
                    } catch (e) {}
                },
                async markAll() {
                    try {
                        const r = await fetch('<?= root ?>agent/notifications/read', {
                            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                            body: JSON.stringify({ id: 0, csrf_token: this.csrf })
                        });
                        const j = await r.json(); this.unread = j.unread || 0;
                        this.rows = this.rows.map(n => ({ ...n, is_read: 1 }));
                    } catch (e) {}
                }
            };
        }
    </script>
    <?php endif; ?>
