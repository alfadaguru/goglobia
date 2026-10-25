<?php
// Drive gia_channel_handle() DIRECTLY (the real WhatsApp webhook entry) to prove a
// bare-number pick ("2") after a flight list does NOT re-search but SELECTS.
// Run: /Applications/XAMPP/xamppfiles/bin/php docs/gia/channel_numeric_pick_test.php
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/';
$_SERVER['SERVER_NAME']='localhost'; $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['SCRIPT_NAME']='/index.php';
chdir(dirname(__DIR__, 2));
require 'config.php';
$SECURE = true; $GLOBALS['SECURE'] = true; // tool/route files guard on @$SECURE
global $db;
$ai = __DIR__ . '/../../app/lib/ai/';
require_once $ai . 'gia_channel.php';

$chan = 'whatsapp';
$user = '+234999TEST' . substr((string)getmypid(), -4); // unique sender per run
$mid = 0;
function turn($db,$chan,$user,&$mid,$text){
    $mid++;
    $r = \App\lib\ai\gia_channel_handle($db,$chan,$user,$text,'mid-'.$mid.'-'.$text);
    $reply = trim((string)($r['reply'] ?? ''));
    $cards = $r['rich']['cards'] ?? [];
    echo "\n>>> USER: $text\n";
    echo "    GIA: ".mb_substr(preg_replace('/\s+/',' ',$reply),0,200)."\n";
    if ($cards) {
        echo "    cards=".count($cards)."\n";
        foreach (array_slice($cards,0,6) as $i=>$c){
            $bid = $c['buttons'][0]['id'] ?? '';
            echo "      [".($i+1)."] ".mb_substr((string)($c['title']??'?'),0,46)."  btn=$bid\n";
        }
    }
    return $r;
}

echo "=== CHANNEL numeric-pick test (WhatsApp, bare numbers) ===\n";
$d = date('Y-m-d', strtotime('+6 day'));
turn($db,$chan,$user,$mid,"Flight from Lagos to Abuja on $d for 1 adult please");
turn($db,$chan,$user,$mid,"2");            // bare number — MUST select, NOT re-search
turn($db,$chan,$user,$mid,"2");            // again, to be sure it doesn't loop

echo "\n=== TRACE (this run) ===\n";
$log = __DIR__ . '/../../_error.log';
if (is_file($log)) {
    $lines = array_slice(array_filter(explode("\n", file_get_contents($log)), function($l){
        return strpos($l,'GIA-TRACE')!==false || strpos($l,'numeric_pick')!==false || strpos($l,'[gia_flights]')!==false;
    }), -18);
    echo implode("\n", $lines)."\n";
}
