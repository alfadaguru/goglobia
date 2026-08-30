  <style>
    .ldr-root * { box-sizing: border-box; margin: 0; padding: 0; }

    .ldr-root {
      background: linear-gradient(160deg, #ffffff 0%, #f8fafc 40%, #f1f5f9 100%);
      min-height: 100vh;
      width: 100%;
      display: flex !important;
      flex-direction: column !important;
      align-items: center;
      justify-content: center;
      padding: 48px 16px;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    @keyframes ldrShimmer {
      0%   { background-position: -600px 0; }
      100% { background-position: 600px 0; }
    }
    .ldr-sk {
      background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
      background-size: 1200px 100%;
      animation: ldrShimmer 1.8s ease-in-out infinite;
      border-radius: 6px;
    }
    .ldr-round { border-radius: 9999px; }

    @keyframes ldrSpin { to { transform: rotate(360deg); } }
    .ldr-spinner {
      width: 32px;
      height: 32px;
      border: 2.5px solid #e2e8f0;
      border-top-color: #94a3b8;
      border-radius: 50%;
      animation: ldrSpin 0.9s linear infinite;
    }

    /* Logo */
    .ldr-logo {
      margin-bottom: 32px;
      user-select: none;
    }
    .ldr-logo img { display: block; max-height: 38px; width: auto; }

    /* Search widget card */
    .ldr-card {
      width: 100%;
      max-width: 56rem;
      border-radius: 16px;
      border: 1px solid #e2e8f0;
      background: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(4px);
      overflow: hidden;
    }

    /* Tabs row */
    .ldr-tabs {
      display: flex;
      align-items: center;
      flex-wrap: nowrap;
      gap: 4px;
      padding: 20px 24px 0;
      border-bottom: 1px solid #f1f5f9;
      overflow-x: auto;
      -ms-overflow-style: none;
      scrollbar-width: none;
    }
    .ldr-tabs::-webkit-scrollbar { display: none; }
    .ldr-tab {
      display: flex;
      align-items: center;
      flex: 0 0 auto;
      gap: 8px;
      padding: 10px 16px;
    }
    .ldr-tab.is-active { border-bottom: 2px solid #94a3b8; }

    /* Input fields row */
    .ldr-fields {
      display: flex;
      align-items: stretch;
      gap: 0;
      padding: 20px 24px;
    }
    .ldr-field {
      flex: 1;
      border: 1px solid #e2e8f0;
      border-right: 0;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      background: rgba(255, 255, 255, 0.8);
    }
    .ldr-field.is-first { border-radius: 12px 0 0 12px; }
    .ldr-swap {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      border-top: 1px solid #e2e8f0;
      border-bottom: 1px solid #e2e8f0;
      background: rgba(255, 255, 255, 0.8);
    }
    .ldr-search-btn {
      width: 112px;
      min-height: 80px;
      border-radius: 0 12px 12px 0;
      border: 1px solid #e2e8f0;
      flex: 0 0 auto;
    }

    /* Spinner wrapper */
    .ldr-spinner-wrap { margin-top: 40px; }

    /* Skeleton dimensions */
    .sk-h25 { height: 10px; }
    .sk-h3  { height: 12px; }
    .sk-h35 { height: 14px; }
    .sk-h4  { height: 16px; }
    .sk-w3  { width: 12px; }
    .sk-w35 { width: 14px; }
    .sk-w4  { width: 16px; }
    .sk-w8  { width: 32px; }
    .sk-w10 { width: 40px; }
    .sk-w12 { width: 48px; }
    .sk-w14 { width: 56px; }
    .sk-w16 { width: 64px; }
    .sk-w20 { width: 80px; }
    .sk-w24 { width: 96px; }
    .sk-w28 { width: 112px; }
    .sk-w32 { width: 128px; }

    @media (max-width: 640px) {
      .ldr-tabs { gap: 2px; padding: 16px 12px 0; }
      .ldr-tab { padding: 8px 8px; }
      .ldr-fields { flex-direction: column; }
      .ldr-field { border-right: 1px solid #e2e8f0; }
      .ldr-field.is-first { border-radius: 12px 12px 0 0; }
      .ldr-swap { display: none; }
      .ldr-search-btn { width: 100%; border-radius: 0 0 12px 12px; }
    }
  </style>
<div class="ldr-root">

  <!-- Logo -->
  <div class="ldr-logo">
    <img src="<?= root ?>uploads/global/logo.png" alt="">
  </div>

  <!-- Search widget card -->
  <div class="ldr-card">

    <!-- Tabs row -->
    <div class="ldr-tabs">
      <!-- Active tab simulation -->
      <div class="ldr-tab is-active">
        <div class="ldr-sk sk-w3 sk-h3 ldr-round"></div>
        <div class="ldr-sk sk-w12 sk-h3"></div>
      </div>
      <div class="ldr-tab">
        <div class="ldr-sk sk-w3 sk-h3 ldr-round"></div>
        <div class="ldr-sk sk-w10 sk-h3"></div>
      </div>
      <div class="ldr-tab">
        <div class="ldr-sk sk-w3 sk-h3 ldr-round"></div>
        <div class="ldr-sk sk-w14 sk-h3"></div>
      </div>
      <div class="ldr-tab">
        <div class="ldr-sk sk-w3 sk-h3 ldr-round"></div>
        <div class="ldr-sk sk-w10 sk-h3"></div>
      </div>
    </div>

    <!-- Input fields row -->
    <div class="ldr-fields">

      <!-- From field -->
      <div class="ldr-field is-first">
        <div class="ldr-sk sk-w10 sk-h25"></div>
        <div class="ldr-sk sk-w28 sk-h4"></div>
        <div class="ldr-sk sk-w20 sk-h25"></div>
      </div>

      <!-- Swap icon divider -->
      <div class="ldr-swap">
        <div class="ldr-sk sk-w4 sk-h4 ldr-round"></div>
      </div>

      <!-- To field -->
      <div class="ldr-field">
        <div class="ldr-sk sk-w8 sk-h25"></div>
        <div class="ldr-sk sk-w32 sk-h4"></div>
        <div class="ldr-sk sk-w16 sk-h25"></div>
      </div>

      <!-- Depart field -->
      <div class="ldr-field">
        <div class="ldr-sk sk-w14 sk-h25"></div>
        <div class="ldr-sk sk-w24 sk-h4"></div>
        <div class="ldr-sk sk-w12 sk-h25"></div>
      </div>

      <!-- Return field -->
      <div class="ldr-field">
        <div class="ldr-sk sk-w12 sk-h25"></div>
        <div class="ldr-sk sk-w24 sk-h4"></div>
        <div class="ldr-sk sk-w12 sk-h25"></div>
      </div>

      <!-- Passengers field -->
      <div class="ldr-field">
        <div class="ldr-sk sk-w16 sk-h25"></div>
        <div class="ldr-sk sk-w20 sk-h4"></div>
        <div class="ldr-sk sk-w10 sk-h25"></div>
      </div>

      <!-- Search button -->
      <div class="ldr-sk ldr-search-btn"></div>
    </div>

  </div>

  <!-- Spinner -->
  <div class="ldr-spinner-wrap">
    <div class="ldr-spinner"></div>
  </div>

</div>
