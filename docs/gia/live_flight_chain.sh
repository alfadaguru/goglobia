#!/bin/bash
# Drive the COMPLETE flight booking chain over the REAL GIA chat endpoint,
# one conversation (shared cookie + CSRF), showing each step's real result.
set -u
BASE="http://localhost/goglobia"
J=/tmp/gia_chain_cookies.txt; rm -f "$J"
OUT=/tmp/gia_chain_resp.json
PHP=/Applications/XAMPP/xamppfiles/bin/php; [ -x "$PHP" ] || PHP=php

# mint CSRF + session
PAGE=$(curl -s -c "$J" -b "$J" -L "$BASE/gia")
TOKEN=$(printf '%s' "$PAGE" | grep -oE "csrfToken[\"']?[[:space:]]*:[[:space:]]*[\"'][^\"']+|'csrfToken'[[:space:]]*=>[[:space:]]*'[^']+" | grep -oE "[a-f0-9]{16,}" | head -1)
echo "CSRF: ${TOKEN:0:12}... (len ${#TOKEN})"

say () {
  local msg="$1"
  echo
  echo ">>> USER: $msg"
  curl -s -c "$J" -b "$J" -o "$OUT" -w "    [http=%{http_code} %{time_total}s]\n" \
    -X POST "$BASE/api/gia/chat" \
    -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
    -d "$(php -r 'echo json_encode(["message"=>$argv[1],"surface"=>"widget","csrf_token"=>$argv[2]]);' "$msg" "$TOKEN")" \
    --max-time 160
  $PHP -r '
    $d=json_decode(file_get_contents("/tmp/gia_chain_resp.json"),true) ?: [];
    echo "    status=".(($d["status"]??false)?"true":"false")." err=".($d["error_code"]??"")."\n";
    echo "    GIA: ".mb_substr(preg_replace("/\s+/"," ",$d["reply"]??""),0,220)."\n";
    $c=$d["searches"][0]["cards"]??[]; if($c){ echo "    cards=".count($c)."\n"; foreach(array_slice($c,0,4) as $i=>$x){ echo "      [$i] ".($x["title"]??$x["name"]??"?")." ".($x["price"]??"")." ".($x["currency"]??"")." ref=".($x["ref"]??$x["id"]??"")."\n"; } }
    if(!empty($d["collecting"])) echo "    >> collecting (passengers/pax)\n";
    if(!empty($d["pay_options"])) echo "    >> PAY OPTIONS: ".json_encode($d["pay_options"]["options"]??[])."\n";
  '
}

say "flights from Lagos to Abuja on $(date -v+6d +%Y-%m-%d) for 1 adult"
say "the 06:30 one"
say "John Traveller, male, date of birth 1990-05-12"
say "yes book it"

echo
echo "=== FLIGHT TRACE (this run) ==="
grep -E "GIA-TRACE|\[gia_flights\]" /Applications/XAMPP/xamppfiles/htdocs/goglobia/_error.log 2>/dev/null | tail -20
