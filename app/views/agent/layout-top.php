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
