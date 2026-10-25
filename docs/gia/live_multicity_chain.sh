#!/bin/bash
# Drive a MULTI-CITY flight search over the REAL GIA chat endpoint (web widget),
# one conversation (shared cookie + CSRF). Proves segments -> multicity gateway call.
set -u
BASE="http://localhost/goglobia"
J=/tmp/gia_mc_cookies.txt; rm -f "$J"
OUT=/tmp/gia_mc_resp.json
PHP=/Applications/XAMPP/xamppfiles/bin/php; [ -x "$PHP" ] || PHP=php

PAGE=$(curl -s -c "$J" -b "$J" -L "$BASE/gia")
TOKEN=$(printf '%s' "$PAGE" | grep -oE "csrfToken[\"']?[[:space:]]*:[[:space:]]*[\"'][^\"']+|'csrfToken'[[:space:]]*=>[[:space:]]*'[^']+" | grep -oE "[a-f0-9]{16,}" | head -1)
echo "CSRF: ${TOKEN:0:12}... (len ${#TOKEN})"

say () {
  local msg="$1"; echo; echo ">>> USER: $msg"
  curl -s -c "$J" -b "$J" -o "$OUT" -w "    [http=%{http_code} %{time_total}s]\n" \
    -X POST "$BASE/api/gia/chat" -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
    -d "$(php -r 'echo json_encode(["message"=>$argv[1],"surface"=>"widget","csrf_token"=>$argv[2]]);' "$msg" "$TOKEN")" \
    --max-time 200
  $PHP -r '
    $d=json_decode(file_get_contents("/tmp/gia_mc_resp.json"),true) ?: [];
    echo "    status=".(($d["status"]??false)?"true":"false")." err=".($d["error_code"]??"")."\n";
    echo "    GIA: ".mb_substr(preg_replace("/\s+/"," ",$d["reply"]??""),0,240)."\n";
    $c=$d["searches"][0]["cards"]??[]; if($c){ echo "    cards=".count($c)."\n"; foreach(array_slice($c,0,6) as $i=>$x){
      echo "      [".($i+1)."] ".($x["title"]??"?")."\n";
      if(!empty($x["subtitle"])) echo "          ".$x["subtitle"]."\n";
      if(!empty($x["return_line"])) foreach(explode("\n",$x["return_line"]) as $rl) echo "          ".$rl."\n";
      echo "          trip=".($x["trip_type"]??"")." ref=".($x["ref"]??"")."\n";
    } }
  '
}

D1=$(date -v+10d +%Y-%m-%d); D2=$(date -v+14d +%Y-%m-%d); D3=$(date -v+20d +%Y-%m-%d)
say "I want a multi-city trip: Lagos to Abuja on $D1, then Abuja to Kano on $D2, then Kano to Lagos on $D3, 1 adult"
# If GIA asks to confirm, a nudge:
say "yes please search all three legs"

echo
echo "=== MULTICITY TRACE (this run) ==="
grep -E "MULTICITY|flights_search|multicity" /Applications/XAMPP/xamppfiles/htdocs/goglobia/_error.log 2>/dev/null | tail -12
