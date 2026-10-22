<?php
// ============================================================================
// DRY TEST — GIA umrah search -> resolve -> tiers (+ self-heal)
// Uses the REAL function bodies (umrah_price_resolve, umrah_b2c_unit_price,
// umrahV2PublishedDepartures, gia_umrah_resolve_departure, gia_umrah_tiers,
// gia_umrah_tiers_from_search) copied VERBATIM from the source files, run
// against a fake Medoo $db seeded with realistic rows. No live DB needed.
// ============================================================================
error_reporting(E_ALL & ~E_DEPRECATED);
$LOG = [];
function error_log_capture($m){ global $LOG; $LOG[] = $m; }
// Shim: route the real code's error_log() calls into our capture.
// (We can't override error_log, so the copied bodies call elog() instead —
//  every error_log(...) below is written as elog(...) to capture evidence.)
function elog($m){ global $LOG; $LOG[] = (string)$m; }

function umrah_is_agent(): bool { return strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent'; }

// ---------- Fake Medoo ----------
class FakeDB {
    public $departures = [];   // umrah_departures rows
    public $tiers = [];        // umrah_departure_tiers rows
    public $tierNames = [];    // umrah_tiers rows (id => row)
    function matches($row,$where){
        foreach($where as $k=>$v){
            if($k==='ORDER'||$k==='GROUP'||$k==='LIMIT') continue;
            if(($row[$k] ?? null) != $v) return false;
        }
        return true;
    }
    function tableRows($t){
        if($t==='umrah_departures') return $this->departures;
        if($t==='umrah_departure_tiers') return $this->tiers;
        if($t==='umrah_tiers') return array_values($this->tierNames);
        if($t==='umrah_package_templates') return [];
        return [];
    }
    function get($t,$cols,$where=null){
        if($where===null){ $where=$cols; }
        foreach($this->tableRows($t) as $r){ if($this->matches($r,$where)) return $r; }
        return null;
    }
    function select($t,$cols,$where=[]){
        $out=[]; foreach($this->tableRows($t) as $r){ if($this->matches($r,$where)) $out[]=$r; }
        return $out;
    }
    function query($sql,$p=[]){ return null; }
    function sum(){ return 0; }
}

// ---------- REAL bodies (verbatim; error_log -> elog) ----------
function umrah_b2c_unit_price(array $dt): float {
    $now = time();
    $promoActive = (int)($dt['promo_active'] ?? 0) === 1 && $dt['promo_price'] !== null && (float)$dt['promo_price'] > 0;
    if ($promoActive) {
        $start = !empty($dt['promo_start']) ? strtotime((string)$dt['promo_start']) : null;
        $end   = !empty($dt['promo_end'])   ? strtotime((string)$dt['promo_end'])   : null;
        if (($start === null || $now >= $start) && ($end === null || $now <= $end)) return (float)$dt['promo_price'];
    }
    if ($dt['regular_price'] !== null && (float)$dt['regular_price'] > 0) return (float)$dt['regular_price'];
    return 0.0;
}
function umrah_price_resolve($db, array $dt, ?bool $forAgent = null): array {
    $currency = strtoupper(trim((string)($dt['currency'] ?? 'NGN'))) ?: 'NGN';
    $now = time();
    $isAgent = ($forAgent === null) ? umrah_is_agent() : $forAgent;
    $b2cUnit = umrah_b2c_unit_price($dt);
    if ($isAgent) {
        $b2bNet = null;
        $bStart = !empty($dt['promo_start']) ? strtotime((string)$dt['promo_start']) : null;
        $bEnd   = !empty($dt['promo_end'])   ? strtotime((string)$dt['promo_end'])   : null;
        $inWin = ($bStart===null || $now>=$bStart) && ($bEnd===null || $now<=$bEnd);
        if ($inWin && isset($dt['b2b_promo_price']) && $dt['b2b_promo_price']!==null && (float)$dt['b2b_promo_price']>0) $b2bNet=(float)$dt['b2b_promo_price'];
        elseif (isset($dt['b2b_net_price']) && $dt['b2b_net_price']!==null && (float)$dt['b2b_net_price']>0) $b2bNet=(float)$dt['b2b_net_price'];
        if ($b2bNet!==null && $b2cUnit>0) return ['unit'=>$b2bNet,'currency'=>$currency,'source'=>'departure_tier_b2b_net','promo'=>null,'b2c_unit'=>$b2cUnit,'is_agent'=>true];
    }
    $promoActive = (int)($dt['promo_active'] ?? 0) === 1 && $dt['promo_price'] !== null && (float)$dt['promo_price']>0;
    if ($promoActive) {
        $start = !empty($dt['promo_start']) ? strtotime((string)$dt['promo_start']) : null;
        $end   = !empty($dt['promo_end'])   ? strtotime((string)$dt['promo_end'])   : null;
        if (($start===null || $now>=$start) && ($end===null || $now<=$end)) {
            $regular = ($dt['regular_price']!==null)?(float)$dt['regular_price']:null;
            $promo=(float)$dt['promo_price'];
            return ['unit'=>$promo,'currency'=>$currency,'source'=>'departure_tier_promo','promo'=>['regular'=>$regular,'promo'=>$promo],'b2c_unit'=>$b2cUnit,'is_agent'=>$isAgent];
        }
    }
    if ($dt['regular_price']!==null && (float)$dt['regular_price']>0)
        return ['unit'=>(float)$dt['regular_price'],'currency'=>$currency,'source'=>'departure_tier_regular','promo'=>null,'b2c_unit'=>$b2cUnit,'is_agent'=>$isAgent];
    $base=(float)($dt['cost_price'] ?? 0);
    return ['unit'=>0.0,'currency'=>$currency,'source'=>'unpriced','promo'=>null,'b2c_unit'=>0.0,'is_agent'=>$isAgent];
}
function umrah_capacity_for($db,int $id):array { return ['remaining'=>50]; } // assume seats
function umrahV2PublishedDepartures($db):array {
    $rows = $db->select('umrah_departures','*',['status'=>'published','archived'=>0]);
    $out=[];
    foreach($rows as $d){
        $tiers=$db->select('umrah_departure_tiers','*',['departure_id'=>$d['id'],'status'=>'active']);
        if(!$tiers) continue;
        $best=null;$bestPriced=null;
        foreach($tiers as $dt){ $p=umrah_price_resolve($db,$dt); if(($p['unit']??0)<=0)continue; if($best===null||$p['unit']<$bestPriced['unit']){$best=$dt;$bestPriced=$p;} }
        if($best===null) continue;
        $tier=$db->get('umrah_tiers',['code','public_label','room_sharing'],['id'=>$best['tier_id']]);
        $out[]=[
            'departure_id'=>(int)$d['id'],'departure_tier_id'=>(int)$best['id'],'origin_city'=>$d['origin_city']?:'Kano',
            'departure_date'=>$d['departure_date'],'return_date'=>$d['return_date'],'month_bucket'=>$d['month_bucket']??date('F Y',strtotime($d['departure_date'])),
            'tier_code'=>$tier['code']??'standard','tier_label'=>$tier['public_label']??'Standard','room_sharing'=>$tier['room_sharing']??'',
            'unit_price'=>$bestPriced['unit'],'currency'=>$bestPriced['currency'],'availability'=>'available','tier_count'=>count($tiers),
        ];
    }
    return $out;
}

// ---- gia_umrah_resolve_departure (verbatim, elog) ----
function gia_umrah_resolve_departure($db,string $when,int $idHint=0):int {
    $rows=$db->select('umrah_departures',['id','departure_date'],['status'=>'published','archived'=>0]); if(!$rows)return 0;
    $when=strtolower(trim($when)); $day=0;
    if($when!=='' && preg_match('/\b([0-3]?\d)(st|nd|rd|th)?\b/',$when,$m)){$d=(int)$m[1];if($d>=1&&$d<=31)$day=$d;}
    if($day===0&&$idHint>=1&&$idHint<=31)$day=$idHint;
    $monthNum=0;$months=['january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'sept'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    foreach($months as $n=>$num){if($when!==''&&strpos($when,$n)!==false){$monthNum=$num;break;}}
    $wantYmd='';if($when!==''){$ts=strtotime($when);if($ts!==false&&preg_match('/\d/',$when))$wantYmd=date('Y-m-d',$ts);}
    $matches=[];
    foreach($rows as $r){$dd0=(string)($r['departure_date']??'');if($dd0==='')continue;$dd=(int)date('j',strtotime($dd0));$dm=(int)date('n',strtotime($dd0));$ddY=date('Y-m-d',strtotime($dd0));
        if($wantYmd!==''&&$ddY===$wantYmd)return (int)$r['id'];
        if($day>0&&$dd===$day&&($monthNum===0||$dm===$monthNum))$matches[]=(int)$r['id'];
        elseif($day===0&&$monthNum>0&&$dm===$monthNum)$matches[]=(int)$r['id'];}
    return (count($matches)===1)?$matches[0]:0;
}
// ---- gia_umrah_tiers_from_search (verbatim, elog) ----
function gia_umrah_tiers_from_search($db,int $departureId,int $pax=0):array {
    $deps=umrahV2PublishedDepartures($db); if(!is_array($deps))return [];
    foreach($deps as $d){ if((int)($d['departure_id']??0)!==$departureId)continue;
        $unit=(float)($d['unit_price']??0); if($unit<=0)return [];
        $dtId=(int)($d['departure_tier_id']??0); if($dtId<=0)return [];
        return [['departure_tier_id'=>$dtId,'tier_code'=>(string)($d['tier_code']??'standard'),'tier_label'=>(string)($d['tier_label']?:'Standard'),'room_sharing'=>(string)($d['room_sharing']??''),'sort_order'=>0,'unit_price'=>round($unit,2),'price'=>$pax>0?round($unit*$pax,2):round($unit,2),'currency'=>strtoupper((string)($d['currency']??'NGN')),'regular'=>null,'availability'=>(string)($d['availability']??'available'),'inclusions'=>[]]];
    }
    return [];
}
// ---- gia_umrah_tiers (verbatim core, elog) ----
function gia_umrah_tiers($db,int $departureId,int $pax=0,string $when=''):array {
    $dep=($departureId>0)?$db->get('umrah_departures','*',['id'=>$departureId,'status'=>'published','archived'=>0]):null;
    if(!is_array($dep)&&$departureId>0){$dt=$db->get('umrah_departure_tiers',['departure_id'],['id'=>$departureId]);
        if(is_array($dt)&&(int)($dt['departure_id']??0)>0){$realDep=(int)$dt['departure_id'];$dep=$db->get('umrah_departures','*',['id'=>$realDep,'status'=>'published','archived'=>0]);if(is_array($dep)){elog('[gia_umrah] tiers: recovered tier_id='.$departureId.' -> dep='.$realDep);$departureId=$realDep;}}}
    if(!is_array($dep)){$resolved=gia_umrah_resolve_departure($db,$when,$departureId);
        if($resolved>0){$dep=$db->get('umrah_departures','*',['id'=>$resolved,'status'=>'published','archived'=>0]);if(is_array($dep)){elog('[gia_umrah] tiers: resolved by when="'.$when.'" -> dep='.$resolved);$departureId=$resolved;}}}
    if(!is_array($dep)){ $any=($departureId>0)?$db->get('umrah_departures',['id','status','archived'],['id'=>$departureId]):null;
        if(!is_array($any)) return ['ok'=>false,'tiers'=>[],'departure'=>[],'message'=>'Which departure did you mean?'];
        return ['ok'=>false,'tiers'=>[],'departure'=>[],'message'=>'That departure is not open for booking.']; }
    $rows=$db->select('umrah_departure_tiers','*',['departure_id'=>$departureId,'status'=>'active']);
    $tiers=[];
    foreach($rows as $dt){$p=umrah_price_resolve($db,$dt);$unit=(float)($p['unit']??0);
        if($unit<=0){elog('[gia_umrah] tiers: dep='.$departureId.' tier_row='.(int)$dt['id'].' unit=0 source='.$p['source']);continue;}
        $tier=$db->get('umrah_tiers',['code','name','public_label','room_sharing','sort_order'],['id'=>(int)$dt['tier_id']]);
        $tiers[]=['departure_tier_id'=>(int)$dt['id'],'tier_label'=>(string)($tier['public_label']?:'Standard'),'unit_price'=>round($unit,2),'price'=>$pax>0?round($unit*$pax,2):round($unit,2),'currency'=>$p['currency']];
    }
    if($tiers===[]){ $tiers=gia_umrah_tiers_from_search($db,$departureId,$pax); if($tiers!==[])elog('[gia_umrah] tiers: SELF-HEALED from search ('.count($tiers).')'); }
    return ['ok'=>true,'tiers'=>$tiers,'departure'=>['departure_id'=>$departureId,'origin_city'=>$dep['origin_city']??'Kano']];
}

// ================= SEED: your real 2 departures, 3 data states =================
function seed(string $state): FakeDB {
    $db=new FakeDB();
    $db->departures=[
        ['id'=>1,'departure_date'=>'2026-12-16','return_date'=>'2026-12-30','origin_city'=>'Kano','status'=>'published','archived'=>0,'month_bucket'=>'December 2026'],
        ['id'=>2,'departure_date'=>'2026-12-25','return_date'=>'2027-01-08','origin_city'=>'Kano','status'=>'published','archived'=>0,'month_bucket'=>'December 2026'],
    ];
    $db->tierNames=[10=>['id'=>10,'code'=>'standard','name'=>'Standard','public_label'=>'Standard Economy','room_sharing'=>'4-5','sort_order'=>1],
                    11=>['id'=>11,'code'=>'vip','name'=>'VIP','public_label'=>'VIP','room_sharing'=>'2','sort_order'=>2]];
    // base tier template
    $mk=function($id,$depId,$tierId,$over=[]) {
        return array_merge(['id'=>$id,'departure_id'=>$depId,'tier_id'=>$tierId,'status'=>'active','currency'=>'NGN',
            'promo_active'=>0,'promo_price'=>null,'promo_start'=>null,'promo_end'=>null,'regular_price'=>2450000,
            'b2b_net_price'=>null,'b2b_promo_price'=>null,'cost_price'=>null],$over);
    };
    if($state==='normal'){ // both departures: 1 active regular-priced tier
        $db->tiers=[$mk(100,1,10),$mk(101,2,10)];
    } elseif($state==='promo_expired'){ // dep1 tier has promo_price but NULL regular, promo window CLOSED
        $db->tiers=[$mk(100,1,10,['regular_price'=>null,'promo_active'=>1,'promo_price'=>2450000,'promo_start'=>'2026-01-01','promo_end'=>'2026-06-01']),
                    $mk(101,2,10)];
    } elseif($state==='dep1_tier_hidden'){ // dep1's only tier is status=hidden (not active) but priced
        $db->tiers=[$mk(100,1,10,['status'=>'hidden']),$mk(101,2,10)];
    }
    return $db;
}

function run_case($label,$state,$when,$idArg){
    global $LOG; $LOG=[];
    $db=seed($state);
    // search first (what the user saw)
    $search=umrahV2PublishedDepartures($db);
    $searchIds=array_map(fn($d)=>$d['departure_id'],$search);
    // then tiers for the pick
    $res=gia_umrah_tiers($db,$idArg,1,$when);
    $tierCount=count($res['tiers']??[]);
    echo "── $label\n";
    echo "   state=$state  pick when=\"$when\" idArg=$idArg\n";
    echo "   SEARCH showed departures: [".implode(',',$searchIds)."]\n";
    echo "   TIERS result: ok=".(($res['ok']??false)?'1':'0')." tiers=$tierCount".($tierCount? " (first: ".$res['tiers'][0]['tier_label']." @ ".$res['tiers'][0]['currency']." ".$res['tiers'][0]['unit_price'].")":"")."\n";
    if($res['message']??'') echo "   message: ".$res['message']."\n";
    foreach($LOG as $l) echo "   log: $l\n";
    // VERDICT: if search showed dep1, tiers for dep1 must be non-empty
    $expectDep1 = in_array(1,$searchIds);
    $pass = $expectDep1 ? ($tierCount>0) : true;
    echo "   VERDICT: ".($pass?"PASS":"*** FAIL ***")." (search-showed-dep1=".($expectDep1?'yes':'no').", tiers-nonempty=".($tierCount>0?'yes':'no').")\n\n";
    return $pass;
}

echo "========= RUN 1: normal data (both departures priced) =========\n";
$p=[]; $_SESSION=[];
$p[]=run_case("16 December, no id","normal","16 december",0);
$p[]=run_case("25 December, no id","normal","25 december",0);
$p[]=run_case("model sent tier id 100 as departure_id","normal","",100);

echo "========= RUN 2: dep1 promo expired + NULL regular (the auditor's #1 theory) =========\n";
$_SESSION=[];
$p[]=run_case("16 December (promo expired on its tier)","promo_expired","16 december",0);

echo "========= RUN 3: dep1's tier is status=hidden (not active) =========\n";
$_SESSION=[];
$p[]=run_case("16 December (tier hidden)","dep1_tier_hidden","16 december",0);

echo "========= RUN 3b: agent session =========\n";
$_SESSION=['user_role'=>'agent'];
$p[]=run_case("16 December as AGENT","normal","16 december",0);

$fail=count(array_filter($p,fn($x)=>!$x));
echo "================= SUMMARY: ".count($p)." cases, ".($fail? "$fail FAILED":"ALL PASS")." =================\n";
