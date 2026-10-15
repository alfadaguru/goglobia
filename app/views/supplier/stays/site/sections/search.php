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
  .wl-search .go{align-self:stretch;min-width:210px;padding:0 clamp(32px,4vw,52px);background:var(--wl-accent);color:#fff;border:0;letter-spacing:.14em;text-transform:uppercase;font-size:.95rem;font-weight:600;cursor:pointer;font-family:var(--wl-body);transition:filter .15s;display:inline-flex;align-items:center;justify-content:center;gap:8px}
  .wl-search .go:hover{filter:brightness(1.08)}
  .wl-search .go .material-symbols-outlined{font-size:20px}
  @media(max-width:760px){.wl-search .row{grid-template-columns:1fr 1fr}.wl-search .go{grid-column:span 2;min-width:0;width:100%;padding:20px;font-size:1rem}}
</style>
<section class="wl-search" id="wl-search"
  x-data="{ ci:'', co:'', adults:2, children:0, go(){ if(!this.ci||!this.co)return; window.location.href='<?= htmlspecialchars($wlBookBase) ?>'+this.ci+'/'+this.co+'/<?= htmlspecialchars($wlNationality ?? 'US') ?>/1/'+this.adults+'-'+this.children } }">
  <div class="wl-wrap" style="padding:0;max-width:1180px">
    <div class="row">
      <div class="cell"><label>Arrival</label><input type="date" id="wl-arrival" x-model="ci" :min="new Date().toISOString().split('T')[0]"></div>
      <div class="cell"><label>Departure</label><input type="date" x-model="co" :min="ci || new Date().toISOString().split('T')[0]"></div>
      <div class="cell"><label>Adults</label><input type="number" min="1" max="20" x-model.number="adults"></div>
      <div class="cell"><label>Children</label><input type="number" min="0" max="20" x-model.number="children"></div>
      <button class="go" @click="go()"><span class="material-symbols-outlined">search</span> Check availability</button>
    </div>
  </div>
</section>
<?php endif; ?>
