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
