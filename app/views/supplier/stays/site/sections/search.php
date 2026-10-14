<?php @$SECURE or die('Access Denied!'); ?>
<?php require __DIR__ . '/_prep.php'; ?>
<?php if ($wlSid > 0 && $wlSlug !== ''): ?>
<style>
  .wl-search{background:var(--wl-primary);color:#fff}
  .wl-search .row{display:grid;grid-template-columns:repeat(4,1fr) auto;align-items:end;gap:0}
  .wl-search .cell{padding:22px 24px;border-right:1px solid rgba(255,255,255,.12)}
  .wl-search .cell label{display:block;font-size:.6rem;letter-spacing:.22em;text-transform:uppercase;color:var(--wl-accent);margin-bottom:8px}
  .wl-search .cell input{width:100%;background:transparent;border:0;color:#fff;font-family:var(--wl-body);font-size:1rem;outline:none}
  .wl-search .cell input::-webkit-calendar-picker-indicator{filter:invert(1);opacity:.6}
  .wl-search .go{padding:0 clamp(24px,4vw,44px);background:var(--wl-accent);color:#fff;border:0;letter-spacing:.2em;text-transform:uppercase;font-size:.8rem;cursor:pointer;font-family:var(--wl-body)}
  @media(max-width:760px){.wl-search .row{grid-template-columns:1fr 1fr}.wl-search .go{grid-column:span 2;padding:18px}}
</style>
<section class="wl-search" id="wl-search"
  x-data="{ ci:'', co:'', adults:2, children:0, go(){ if(!this.ci||!this.co)return; window.location.href='<?= htmlspecialchars($wlBookBase) ?>'+this.ci+'/'+this.co+'/US/1/'+this.adults+'-'+this.children } }">
  <div class="wl-wrap" style="padding:0;max-width:1180px">
    <div class="row">
      <div class="cell"><label>Arrival</label><input type="date" x-model="ci"></div>
      <div class="cell"><label>Departure</label><input type="date" x-model="co"></div>
      <div class="cell"><label>Adults</label><input type="number" min="1" max="20" x-model.number="adults"></div>
      <div class="cell"><label>Children</label><input type="number" min="0" max="20" x-model.number="children"></div>
      <button class="go" @click="go()">Check availability</button>
    </div>
  </div>
</section>
<?php endif; ?>
