<?php
/**
 * FLIGHT E-TICKET — print-ready HTML page (the owner's approved design, pixel-exact).
 *
 * Rendered in the BROWSER (not mPDF), so it reproduces the design perfectly —
 * flexbox, real fonts, crisp SVG/emoji, the connector line. The customer clicks
 * "Print / Save as PDF" to get a flawless PDF via the browser's own print engine.
 * The server-side mPDF ticket still exists for email/WhatsApp attachments.
 *
 * Driven by real booking data via the shared ft_* helpers. Expects (set by the
 * route before including this): $booking (array), $bookingData (array), $db,
 * $businessName, $businessEmail, $businessPhone, $siteUrl, $invoiceId.
 */
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/../../lib/flight_ticket_helpers.php';

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pnr = trim((string) ($booking['pnr'] ?? ''));
$isTicketed = $pnr !== '';
$invoiceId = (string) ($booking['invoice_id'] ?? '');
$db = $db ?? ($GLOBALS['db'] ?? null);
$businessName  = $businessName ?? 'GoGlobia';
$businessEmail = $businessEmail ?? '';
$businessPhone = $businessPhone ?? '';
$siteUrl = rtrim((string) ($siteUrl ?? 'https://goglobia.com'), '/');
$bookingDate = !empty($booking['created_at']) ? date('d M Y', strtotime($booking['created_at'])) : date('d M Y');
$manageUrl = $siteUrl . '/flights/booking/' . rawurlencode($invoiceId);
$qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=0&data=' . rawurlencode($manageUrl);

// Passengers (payer excluded by the helper).
$travellers = ft_passenger_list($booking);
$paxCount = count($travellers);

// Legs + segments — CRITICAL: real flight bookings carry the primary display data
// (departure_code/time/date, arrival_*, airline, flight_no, class, baggage, duration,
// stops) at the TOP LEVEL of flight_data (that is what the working invoice page reads,
// app/views/modules/flights/invoice/index.php). Nested segments[] may be absent or
// sparse. So we: (a) use nested segments when present, but MERGE the top-level
// flight_data fields in as a fallback onto each; (b) when there are no segments at all,
// synthesise ONE segment from the top-level fields. This is why the ticket was blank —
// it only looked inside segments and ignored the top level.
$fd = $bookingData['flight_data'] ?? $bookingData ?? [];

// The top-level flight summary fields (used to backfill any missing per-segment value).
$fdTop = array_filter([
    'airline'           => $fd['airline'] ?? ($fd['airline_name'] ?? null),
    'airline_name'      => $fd['airline_name'] ?? null,
    'img'               => $fd['img'] ?? ($fd['airline_code'] ?? null),
    'airline_code'      => $fd['airline_code'] ?? null,
    'flight_no'         => $fd['flight_no'] ?? ($fd['flight_number'] ?? null),
    'flight_number'     => $fd['flight_number'] ?? null,
    'class'             => $fd['class'] ?? null,
    'booking_class'     => $fd['booking_class'] ?? null,
    'departure_code'    => $fd['departure_code'] ?? ($fd['from_airport'] ?? null),
    'arrival_code'      => $fd['arrival_code'] ?? ($fd['to_airport'] ?? null),
    'departure_airport' => $fd['departure_airport'] ?? null,
    'arrival_airport'   => $fd['arrival_airport'] ?? null,
    'departure_time'    => $fd['departure_time'] ?? null,
    'arrival_time'      => $fd['arrival_time'] ?? null,
    'departure_date'    => $fd['departure_date'] ?? null,
    'arrival_date'      => $fd['arrival_date'] ?? null,
    'departure_iso'     => $fd['departure_iso'] ?? ($fd['departing_at'] ?? null),
    'arrival_iso'       => $fd['arrival_iso'] ?? ($fd['arriving_at'] ?? null),
    'departure_terminal'=> $fd['departure_terminal'] ?? null,
    'duration_time'     => $fd['duration_time'] ?? ($fd['total_duration'] ?? null),
    'baggage'           => $fd['baggage'] ?? null,
    'cabin_baggage'     => $fd['cabin_baggage'] ?? null,
    'stops'             => $fd['stops'] ?? null,
    'operated_by'       => $fd['operated_by'] ?? ($fd['operating_carrier'] ?? null),
], fn($v) => $v !== null && $v !== '');

// backfill: for each segment, any missing key falls back to the top-level value.
$mergeTop = function (array $seg) use ($fdTop) { return $seg + $fdTop; };

$legs = [];
if (!empty($fd['routes']) && is_array($fd['routes'])) {
    foreach ($fd['routes'] as $i => $route) {
        $segs = $route['segments'] ?? [];
        if ($segs) { $legs[] = ['label' => $i === 0 ? 'Outbound' : ($i === 1 ? 'Return' : 'Leg ' . ($i + 1)), 'segments' => array_map($mergeTop, array_values($segs))]; }
    }
} else {
    if (!empty($fd['segments']) && is_array($fd['segments'])) { $legs[] = ['label' => 'Outbound', 'segments' => array_map($mergeTop, array_values($fd['segments']))]; }
    if (!empty($fd['returnSegments']) && is_array($fd['returnSegments'])) { $legs[] = ['label' => 'Return', 'segments' => array_map($mergeTop, array_values($fd['returnSegments']))]; }
}
// No nested segments anywhere → the whole flight IS the top-level flight_data.
if (empty($legs) && !empty($fdTop)) {
    $legs[] = ['label' => 'Flight', 'segments' => [$fdTop]];
}
$segVal = function (array $s, array $keys, $default = '') {
    foreach ($keys as $k) { if (isset($s[$k]) && $s[$k] !== '' && $s[$k] !== null) { return $s[$k]; } }
    return $default;
};
$cabinOverall = !empty($legs[0]['segments'][0]) ? (string) $segVal($legs[0]['segments'][0], ['class', 'cabin'], '') : '';

// ---- Rich ancillary data (seats, extra baggage, meals, fare type) -----------
$fareType = (string) ($bookingData['fare_type'] ?? '');
$ancBaggage = [];
if (!empty($bookingData['baggage']) && is_array($bookingData['baggage']) && isset($bookingData['baggage'][0]['passengerId'])) {
    $ancBaggage = $bookingData['baggage'];
} elseif (!empty($bookingData['ancillary_data']['baggage']) && is_array($bookingData['ancillary_data']['baggage'])) {
    $ancBaggage = array_values($bookingData['ancillary_data']['baggage']);
}
$bagSummary = [];
foreach ($ancBaggage as $bag) {
    if (!is_array($bag)) { continue; }
    $lbl = (string) ($bag['name'] ?? 'Extra bag') . (!empty($bag['description']) ? ' (' . $bag['description'] . ')' : '');
    $bagSummary[$lbl] = ($bagSummary[$lbl] ?? 0) + (int) ($bag['quantity'] ?? 1);
}
$ancSeats = $bookingData['seat'] ?? ($bookingData['ancillary_data']['seats'] ?? []);
$seatCount = 0;
if (is_array($ancSeats)) { foreach ($ancSeats as $ss) { $seatCount += is_array($ss) ? count($ss) : 1; } }
$ancMeals = $bookingData['meals'] ?? ($bookingData['ancillary_data']['meals'] ?? []);
$mealCount = 0;
if (is_array($ancMeals)) { foreach ($ancMeals as $mm) { $mealCount += is_array($mm) ? count($mm) : 1; } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flight E-Ticket <?= $e($invoiceId) ?> — <?= $e($businessName) ?></title>
<style>
  :root{
    --brand:#0058e6; --brand-deep:#104bcb;
    --ink:#0a1a3a; --slate:#5b6b8c; --slate-2:#8793ad;
    --hair:#e4e9f2; --panel:#f5f7fb; --card:#ffffff;
    --ok:#0b875b; --ok-bg:#e8f6ef; --pend:#b4690e; --pend-bg:#fdf3e3;
    --mono:"SF Mono",ui-monospace,"Roboto Mono","DejaVu Sans Mono",monospace;
    --sans:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,Helvetica,Arial,sans-serif;
  }
  *{box-sizing:border-box;}
  body{background:#dfe4ec;margin:0;padding:24px 16px 60px;font-family:var(--sans);color:var(--ink);}

  /* action bar (screen only — hidden when printing) */
  .actions{max-width:760px;margin:0 auto 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;}
  .actions .ttl{font-size:14px;color:var(--slate);}
  .actions .ttl b{color:var(--ink);}
  .btn{appearance:none;border:0;cursor:pointer;font-family:var(--sans);font-size:14px;font-weight:700;
       padding:10px 20px;border-radius:9px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;}
  .btn-print{background:var(--brand);color:#fff;}
  .btn-print:hover{background:var(--brand-deep);}
  .btn-ghost{background:#fff;color:var(--ink);border:1px solid var(--hair);}

  /* ---- the ticket page ---- */
  .page{width:760px;margin:0 auto;background:var(--card);border-radius:6px;
        box-shadow:0 10px 40px rgba(10,26,58,.14);overflow:hidden;}
  .pad{padding:30px 34px;}

  .top{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;
       padding:22px 34px;border-bottom:3px solid var(--brand);}
  .brand{display:flex;flex-direction:column;gap:3px;}
  /* fixed height, natural width (keeps aspect ratio) — big enough to read clearly. */
  .brand .logo-img{height:46px;width:auto;max-width:240px;display:block;}
  .brand .logo{font-size:21px;font-weight:800;letter-spacing:-.02em;color:var(--brand);}
  .brand .logo span{color:var(--ink);}
  .brand .issuer{font-size:11px;color:var(--slate);line-height:1.5;margin-top:4px;}
  .docmeta{text-align:right;}
  .doctitle{font-size:12px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:var(--ink);}
  .docsub{font-size:10.5px;color:var(--slate-2);letter-spacing:.04em;margin-top:2px;}
  .chip{display:inline-flex;align-items:center;gap:5px;margin-top:9px;font-size:11px;font-weight:700;
        letter-spacing:.03em;padding:4px 10px;border-radius:999px;}
  .chip.ok{color:var(--ok);background:var(--ok-bg);}
  .chip.pend{color:var(--pend);background:var(--pend-bg);}
  .chip .dot{width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block;}

  .refstrip{display:flex;justify-content:space-between;align-items:stretch;gap:20px;
            background:var(--panel);border:1px solid var(--hair);border-radius:10px;padding:16px 18px;}
  .refs{display:flex;gap:30px;flex-wrap:wrap;}
  .ref{display:flex;flex-direction:column;gap:3px;}
  .ref .lbl{font-size:9.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--slate);}
  .ref .val{font-family:var(--mono);font-size:19px;font-weight:600;color:var(--ink);letter-spacing:.04em;line-height:1;}
  .ref .val.pending{font-family:var(--sans);font-size:13px;font-weight:600;color:var(--pend);letter-spacing:0;}
  .ref .hint{font-size:10px;color:var(--slate-2);}
  .qrwrap{display:flex;flex-direction:column;align-items:center;gap:5px;padding-left:20px;border-left:1px solid var(--hair);}
  .qr{width:78px;height:78px;border:3px solid #fff;outline:1px solid var(--hair);border-radius:4px;}
  .qr-cap{font-size:9px;color:var(--slate-2);letter-spacing:.03em;text-align:center;line-height:1.3;}

  .sec{margin-top:24px;}
  .sec-h{display:flex;align-items:center;gap:9px;margin-bottom:11px;}
  .sec-h .t{font-size:11px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:var(--ink);white-space:nowrap;}
  .sec-h .t.city{text-transform:none;letter-spacing:0;font-size:13px;}
  .sec-h .rule{flex:1;height:1px;background:var(--hair);}
  .sec-h .leg{font-size:10px;font-weight:700;letter-spacing:.06em;color:#fff;background:var(--brand);border-radius:4px;padding:2px 8px;white-space:nowrap;}

  .pax{display:grid;grid-template-columns:1fr 1fr;gap:9px;}
  .pax .p{border:1px solid var(--hair);border-radius:9px;padding:11px 13px;display:flex;justify-content:space-between;align-items:center;}
  .pax .nm{font-size:13.5px;font-weight:700;color:var(--ink);}
  .pax .meta{font-size:10.5px;color:var(--slate);margin-top:2px;}
  .pax .etk{text-align:right;}
  .pax .etk .k{font-size:9px;letter-spacing:.07em;text-transform:uppercase;color:var(--slate-2);}
  .pax .etk .v{font-family:var(--mono);font-size:12px;color:var(--ink);font-weight:600;}
  .pax .etk .v.pending{font-family:var(--sans);font-size:10.5px;color:var(--pend);font-weight:600;}

  .seg{border:1px solid var(--hair);border-radius:12px;overflow:hidden;margin-bottom:11px;}
  .seg-top{display:flex;justify-content:space-between;align-items:center;background:var(--panel);
           padding:9px 15px;border-bottom:1px solid var(--hair);}
  .seg-air{display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:700;color:var(--ink);}
  .seg-air .dotmark{width:24px;height:24px;border-radius:5px;background:var(--brand);color:#fff;
        font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;letter-spacing:.02em;}
  .seg-air .fno{font-family:var(--mono);font-weight:600;color:var(--slate);font-size:11.5px;}
  .seg-cabin{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--brand);
        background:#e8f0ff;border-radius:4px;padding:3px 9px;}
  .seg-body{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10px;padding:16px 18px;}
  .ep{display:flex;flex-direction:column;gap:2px;}
  .ep.arr{text-align:right;align-items:flex-end;}
  .ep .code{font-size:30px;font-weight:800;letter-spacing:-.02em;line-height:.95;color:var(--ink);font-variant-numeric:tabular-nums;}
  .ep .time{font-size:15px;font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums;margin-top:3px;}
  .ep .date{font-size:10.5px;color:var(--slate-2);font-weight:600;}
  .ep .city{font-size:11px;color:var(--slate);line-height:1.35;max-width:160px;margin-top:3px;}
  .mid{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:118px;}
  .mid .dur{font-size:10px;font-weight:700;color:var(--slate);letter-spacing:.03em;font-variant-numeric:tabular-nums;}
  .mid .line{position:relative;width:118px;height:2px;background:var(--hair);}
  .mid .line::before{content:"";position:absolute;left:0;top:50%;transform:translateY(-50%);width:6px;height:6px;border-radius:50%;background:var(--brand);}
  .mid .line::after{content:"";position:absolute;right:0;top:50%;transform:translateY(-50%);width:6px;height:6px;border-radius:50%;border:2px solid var(--brand);background:#fff;}
  .mid .plane{color:var(--brand);font-size:13px;line-height:1;}
  .mid .stops{font-size:9.5px;color:var(--slate-2);letter-spacing:.03em;}
  .seg-foot{display:flex;gap:22px;padding:9px 18px;border-top:1px dashed var(--hair);background:#fcfdff;}
  .seg-foot .f{display:flex;align-items:center;gap:6px;font-size:10.5px;color:var(--slate);}
  .seg-foot .f b{color:var(--ink);font-weight:600;}
  .seg-foot .ic{font-size:13px;}
  .opby{font-size:10px;color:var(--pend);padding:0 18px 9px;background:#fcfdff;}

  /* fare & extras detail cards */
  .detail{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:4px;}
  .detail .d{border:1px solid var(--hair);border-radius:9px;padding:11px 13px;}
  .detail .dk{font-size:9px;letter-spacing:.07em;text-transform:uppercase;color:var(--slate-2);font-weight:700;margin-bottom:5px;}
  .detail .row{display:flex;align-items:center;gap:7px;font-size:11px;color:var(--slate);margin-bottom:3px;}
  .detail .row b{color:var(--ink);font-weight:700;}
  .detail .row.add{color:var(--brand);font-weight:700;}
  .detail .ic{font-size:13px;}
  .detail .muted{font-size:10.5px;color:var(--slate-2);}
  .layover{display:flex;align-items:center;gap:9px;justify-content:center;margin:0 0 11px;font-size:10.5px;color:var(--slate);}
  .layover .bar{height:1px;width:40px;background:var(--hair);}
  .layover b{color:var(--pend);font-weight:700;}

  .guide{margin-top:24px;background:#eef4ff;border:1px solid #cfe0ff;border-left:4px solid var(--brand);
         border-radius:10px;padding:15px 17px;}
  .guide .gt{font-size:12px;font-weight:800;color:var(--brand-deep);margin-bottom:7px;letter-spacing:.01em;}
  .guide ul{margin:0;padding-left:17px;}
  .guide li{font-size:11px;color:#28406b;line-height:1.6;margin-bottom:2px;}
  .guide b{color:var(--brand-deep);}

  .foot{margin-top:22px;padding-top:14px;border-top:1px solid var(--hair);display:flex;justify-content:space-between;gap:20px;}
  .foot p{font-size:9.5px;color:var(--slate-2);line-height:1.55;margin:0;max-width:62%;}
  .foot .issue{text-align:right;font-size:9.5px;color:var(--slate-2);line-height:1.6;}
  .foot .issue b{color:var(--slate);}

  /* print: hide the action bar + screen chrome, let the sheet fill the page */
  @media print{
    @page{ margin:14mm; }
    body{background:#fff;padding:0;}
    .actions{display:none !important;}
    .page{width:auto;box-shadow:none;border-radius:0;margin:0;}
    .seg,.pax .p,.guide{break-inside:avoid;}
  }
  @media (max-width:800px){ .page,.actions{width:100%;} }
</style>
</head>
<body>
  <div class="actions">
    <div class="ttl">Your e-ticket &middot; <b><?= $e($invoiceId) ?></b></div>
    <div>
      <a href="<?= $e(root ?? '/') ?>api/booking/download-ticket/<?= $e($invoiceId) ?>" class="btn btn-ghost">Download PDF</a>
      <button type="button" class="btn btn-print" onclick="window.print()">&#128424;&nbsp; Print / Save as PDF</button>
    </div>
  </div>

  <div class="page">
    <div class="top">
      <div class="brand">
        <?php if (!empty($logoSrc)): ?>
          <img src="<?= $e($logoSrc) ?>" class="logo-img" alt="<?= $e($businessName) ?>">
        <?php else: ?>
          <div class="logo">Go<span>Globia</span></div>
        <?php endif; ?>
        <div class="issuer">Issued by <?= $e($businessName) ?><?php if ($businessEmail): ?> &middot; <?= $e($businessEmail) ?><?php endif; ?><?php if ($businessPhone): ?> &middot; <?= $e($businessPhone) ?><?php endif; ?><br>IATA / NANTA / NCAA accredited</div>
      </div>
      <div class="docmeta">
        <div class="doctitle">Itinerary Receipt</div>
        <div class="docsub">Electronic Ticket &middot; Passenger Copy</div>
        <?php if ($isTicketed): ?><div class="chip ok"><span class="dot"></span> Ticketed &middot; Confirmed</div>
        <?php else: ?><div class="chip pend"><span class="dot"></span> Reserved &middot; Ticket pending</div><?php endif; ?>
      </div>
    </div>

    <div class="pad">
      <div class="refstrip">
        <div class="refs">
          <div class="ref">
            <div class="lbl">Airline Booking Ref (PNR)</div>
            <?php if ($isTicketed): ?>
              <div class="val"><?= $e($pnr) ?></div>
              <div class="hint">Use this to check in with the airline</div>
            <?php else: ?>
              <div class="val pending">Pending &mdash; issued on ticketing</div>
              <div class="hint">You&rsquo;ll receive it once the airline tickets your booking</div>
            <?php endif; ?>
          </div>
          <div class="ref">
            <div class="lbl"><?= $e($businessName) ?> Booking ID</div>
            <div class="val" style="font-size:16px;"><?= $e($invoiceId) ?></div>
            <div class="hint">Quote for support &amp; changes</div>
          </div>
          <div class="ref">
            <div class="lbl">Issued</div>
            <div class="val" style="font-size:13px;"><?= $e($bookingDate) ?></div>
            <div class="hint"><?= $cabinOverall !== '' ? $e(ucfirst($cabinOverall)) . ' &middot; ' : '' ?><?= (int) $paxCount ?> passenger<?= $paxCount === 1 ? '' : 's' ?></div>
          </div>
        </div>
        <div class="qrwrap">
          <img class="qr" src="<?= $e($qrImg) ?>" alt="Scan to manage booking">
          <div class="qr-cap">Scan to<br>Manage Booking</div>
        </div>
      </div>

      <div class="sec">
        <div class="sec-h"><span class="t">Passengers</span><span class="rule"></span></div>
        <div class="pax">
          <?php foreach ($travellers as $pax):
            $fn = strtoupper(trim((string) ($pax['first_name'] ?? '')));
            $ln = strtoupper(trim((string) ($pax['last_name'] ?? '')));
            $ttl = strtoupper(trim((string) ($pax['title'] ?? '')));
            $name = trim($ln . ' / ' . $fn . ($ttl ? ' ' . $ttl : ''), ' /');
            $ptypeRaw = strtoupper((string) ($pax['type'] ?? $pax['passenger_type'] ?? 'ADT'));
            $ptype = ['ADT'=>'Adult','CHD'=>'Child','INF'=>'Infant','ADULT'=>'Adult','CHILD'=>'Child','INFANT'=>'Infant'][$ptypeRaw] ?? ucfirst(strtolower($ptypeRaw));
            $nat = (string) ($pax['nationality_name'] ?? $pax['nationality'] ?? '');
            $etk = trim((string) ($pax['eticket'] ?? $pax['ticket_number'] ?? ''));
          ?>
          <div class="p">
            <div>
              <div class="nm"><?= $e($name ?: 'Passenger') ?></div>
              <div class="meta"><?= $e($ptype) ?><?= $nat ? ' &middot; ' . $e($nat) : '' ?></div>
            </div>
            <div class="etk"><div class="k">E-Ticket</div>
              <?php if ($etk !== ''): ?><div class="v"><?= $e($etk) ?></div><?php else: ?><div class="v pending">On ticketing</div><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php foreach ($legs as $leg):
        $segs = array_values($leg['segments']); if (empty($segs)) continue;
        $legSum = ft_leg_summary($segs);
        $fromAp = ft_airport_resolve($legSum['from'], $db, $segs[0]);
        $toAp   = ft_airport_resolve($legSum['to'], $db, $segs[count($segs)-1]);
      ?>
      <div class="sec">
        <div class="sec-h"><span class="leg"><?= $e(ucfirst(strtolower($leg['label']))) ?></span><span class="t city"><?= $e($fromAp['city'] ?: $legSum['from']) ?> &rarr; <?= $e($toAp['city'] ?: $legSum['to']) ?></span><span class="rule"></span></div>
        <?php foreach ($segs as $si => $s):
          $al = ft_airline_resolve($s, $db, null);
          $cabin = $segVal($s, ['class','cabin'], '');
          $classCode = $segVal($s, ['booking_class','class_code'], '');
          $depCode = strtoupper((string) $segVal($s, ['departure_code','from_airport'], '--'));
          $arrCode = strtoupper((string) $segVal($s, ['arrival_code','to_airport'], '--'));
          $dAp = ft_airport_resolve($depCode, $db, $s); $aAp = ft_airport_resolve($arrCode, $db, $s);
          $depName = $dAp['name'] !== '' ? $dAp['name'] : (string) $segVal($s, ['departure_airport','from_airport_name'], '');
          $arrName = $aAp['name'] !== '' ? $aAp['name'] : (string) $segVal($s, ['arrival_airport','to_airport_name'], '');
          $depTerm = (string) $segVal($s, ['departure_terminal','dep_terminal'], '');
          if ($depTerm !== '') { $depName = $depName . ' · Terminal ' . $depTerm; }
          $depTime = $segVal($s, ['departure_time'], ''); $arrTime = $segVal($s, ['arrival_time'], '');
          $depDate = $segVal($s, ['departure_date'], ''); $arrDate = $segVal($s, ['arrival_date'], '');
          $dur = (string) $segVal($s, ['duration_time','total_duration','duration'], '');
          if ($dur === '') { $sm = ft_minutes_between(ft_seg_dep($s), ft_seg_arr($s)); $dur = ft_fmt_duration($sm['minutes']); if ($dur !== '' && !$sm['exact']) { $dur = '~'.$dur; } }
          $bag = $segVal($s, ['baggage','included_baggage'], '');
          $cabinBag = $segVal($s, ['cabin_baggage'], '');
          $operatedBy = $segVal($s, ['operating_carrier','operating','operated_by'], '');
          $showOp = $operatedBy !== '' && stripos((string)$operatedBy, (string)$al['name']) === false && strtoupper((string)$operatedBy) !== strtoupper((string)$al['code']);
        ?>
        <div class="seg">
          <div class="seg-top">
            <div class="seg-air"><span class="dotmark"><?= $e($al['monogram']) ?></span> <?= $e($al['name']) ?> <?php if ($al['flight_no']): ?><span class="fno"><?= $e($al['flight_no']) ?></span><?php endif; ?></div>
            <?php if ($cabin !== ''): ?><div class="seg-cabin"><?= $e(ucfirst($cabin)) ?><?= $classCode ? ' &middot; ' . $e(strtoupper($classCode)) : '' ?></div><?php endif; ?>
          </div>
          <div class="seg-body">
            <div class="ep">
              <div class="code"><?= $e($depCode) ?></div>
              <?php if ($depTime): ?><div class="time"><?= $e($depTime) ?></div><?php endif; ?>
              <?php if ($depDate): ?><div class="date"><?= $e($depDate) ?></div><?php endif; ?>
              <?php if ($depName): ?><div class="city"><?= $e($depName) ?></div><?php endif; ?>
            </div>
            <div class="mid">
              <?php if ($dur): ?><div class="dur"><?= $e($dur) ?></div><?php endif; ?>
              <div class="line"></div>
              <div class="plane">&#9992;</div>
              <div class="stops">Non-stop</div>
            </div>
            <div class="ep arr">
              <div class="code"><?= $e($arrCode) ?></div>
              <?php if ($arrTime): ?><div class="time"><?= $e($arrTime) ?></div><?php endif; ?>
              <?php if ($arrDate): ?><div class="date"><?= $e($arrDate) ?></div><?php endif; ?>
              <?php if ($arrName): ?><div class="city"><?= $e($arrName) ?></div><?php endif; ?>
            </div>
          </div>
          <?php if ($showOp): ?><div class="opby">Operated by <?= $e($operatedBy) ?> on behalf of <?= $e($al['name']) ?></div><?php endif; ?>
          <div class="seg-foot">
            <?php if ($bag): ?><div class="f"><span class="ic">&#129520;</span> Checked <b><?= $e($bag) ?></b></div><?php endif; ?>
            <?php if ($cabinBag): ?><div class="f"><span class="ic">&#127890;</span> Cabin <b><?= $e($cabinBag) ?></b></div><?php endif; ?>
            <div class="f"><span class="ic">&#9203;</span> Status <b><?= $isTicketed ? 'OK / Confirmed' : 'Reserved' ?></b></div>
          </div>
        </div>
        <?php
          if ($si < count($segs) - 1) {
            $lay = $legSum['layovers'][$si] ?? null;
            $layCode = $lay['code'] ?? $arrCode;
            $layAp = ft_airport_resolve((string) $layCode, $db, $s);
            $layWhere = $layAp['city'] !== '' ? $layAp['city'] : $layCode;
            $layDur = $lay && $lay['display'] !== '' ? ($lay['exact'] ? $lay['display'] : '~' . $lay['display']) : '';
            echo '<div class="layover"><span class="bar"></span> Connection at ' . $e($layWhere) . ($layCode && $layWhere !== $layCode ? ' (' . $e($layCode) . ')' : '')
               . ($layDur !== '' ? ' &middot; <b>' . $e($layDur) . ' layover</b>' : '') . ' &middot; terminal change <span class="bar"></span></div>';
          }
        endforeach; ?>
      </div>
      <?php endforeach; ?>

      <!-- ===== Fare & Extras (richer detail) ===== -->
      <div class="sec">
        <div class="sec-h"><span class="t">Fare &amp; Baggage</span><span class="rule"></span></div>
        <div class="detail">
          <div class="d">
            <div class="dk">Fare &amp; Cabin</div>
            <div class="row"><span class="ic">&#128188;</span> Cabin <b><?= $cabinOverall !== '' ? $e(ucfirst($cabinOverall)) : 'Economy' ?></b></div>
            <div class="row"><span class="ic">&#127903;</span> Fare type <b><?= $fareType !== '' ? $e(ucfirst($fareType)) : 'Standard' ?></b></div>
            <?php
              // Fare rules note: refundable/changeable if the data says so.
              $refundable = $bookingData['refundable'] ?? ($fd['refundable'] ?? null);
              if ($refundable !== null && $refundable !== ''):
                $refTxt = (is_bool($refundable) ? ($refundable ? 'Refundable' : 'Non-refundable') : (string) $refundable);
            ?>
            <div class="row muted"><span class="ic">&#128196;</span> <?= $e($refTxt) ?></div>
            <?php endif; ?>
          </div>
          <div class="d">
            <div class="dk">Baggage Allowance</div>
            <?php
              $anyBag = false;
              foreach ($legs as $lg) { foreach ($lg['segments'] as $ss) {
                if (!empty($ss['baggage']) || !empty($ss['cabin_baggage'])) { $anyBag = true;
                  echo '<div class="row"><span class="ic">&#129520;</span> Checked <b>' . $e($segVal($ss, ['baggage','included_baggage'], '—')) . '</b></div>';
                  echo '<div class="row"><span class="ic">&#127890;</span> Cabin <b>' . $e($segVal($ss, ['cabin_baggage'], '—')) . '</b></div>';
                  break 2; }
              } }
              if (!empty($bagSummary)) { $anyBag = true; foreach ($bagSummary as $lbl => $n) {
                echo '<div class="row add"><span class="ic">&#10133;</span> ' . (int) $n . ' &times; ' . $e($lbl) . '</div>';
              } }
              if (!$anyBag) { echo '<div class="row muted">As per airline policy</div>'; }
            ?>
          </div>
        </div>
        <?php if ($seatCount > 0 || $mealCount > 0): ?>
        <div class="detail" style="grid-template-columns:1fr; margin-top:9px;">
          <div class="d">
            <div class="dk">Extras selected</div>
            <?php if ($seatCount > 0): ?><div class="row add"><span class="ic">&#128100;</span> <b><?= (int) $seatCount ?></b> seat<?= $seatCount === 1 ? '' : 's' ?> selected</div><?php endif; ?>
            <?php if ($mealCount > 0): ?><div class="row add"><span class="ic">&#127869;</span> <b><?= (int) $mealCount ?></b> meal<?= $mealCount === 1 ? '' : 's' ?> added</div><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="guide">
        <div class="gt"><?= $isTicketed ? 'Before you fly — this is your itinerary receipt, not a boarding pass' : 'What happens next' ?></div>
        <ul>
        <?php if ($isTicketed): ?>
          <li>Check in online with the operating airline using PNR <b><?= $e($pnr) ?></b>, or at the airport desk, to get your boarding pass.</li>
          <li>Bring a <b>printed or mobile copy</b> of this receipt and a <b>valid passport / government ID</b> matching the passenger name.</li>
          <li>Arrive at the airport <b>at least 2 hours</b> before domestic and <b>3 hours</b> before international departure.</li>
          <li>Baggage and cabin allowances are per passenger, per segment, as shown above.</li>
        <?php else: ?>
          <li>Your seats are <b>reserved</b>. Once the airline issues your ticket, we&rsquo;ll email an updated receipt with your <b>PNR</b> and <b>e-ticket numbers</b>.</li>
          <li>Keep your Booking ID <b><?= $e($invoiceId) ?></b> for any questions in the meantime.</li>
          <li>This document is an itinerary receipt, <b>not a boarding pass</b>. Online check-in opens after ticketing.</li>
        <?php endif; ?>
        </ul>
      </div>

      <div class="foot">
        <p>This document confirms your reservation<?= $isTicketed ? ' and ticketing' : '' ?>. Carriage is subject to the operating airline&rsquo;s Conditions of Carriage and fare rules. Fare and payment details are shown on your separate invoice/receipt. Schedules may change &mdash; reconfirm with the airline before travel.</p>
        <div class="issue"><b><?= $e($businessName) ?></b><br><?php if ($businessEmail): ?><?= $e($businessEmail) ?><br><?php endif; ?>Generated <?= $e(date('d M Y, H:i')) ?></div>
      </div>
    </div>
  </div>
</body>
</html>
