#!/bin/bash
# Drive the COMPLETE stays booking chain over the REAL GIA chat endpoint.
set -u
BASE="http://localhost/goglobia"
J=/tmp/gia_stays_cookies.txt; rm -f "$J"
OUT=/tmp/gia_stays_resp.json
PHP=/Applications/XAMPP/xamppfiles/bin/php; [ -x "$PHP" ] || PHP=php

PAGE=$(curl -s -c "$J" -b "$J" -L "$BASE/gia")
TOKEN=$(printf '%s' "$PAGE" | grep -oE "csrfToken[\"']?[[:space:]]*:[[:space:]]*[\"'][^\"']+|'csrfToken'[[:space:]]*=>[[:space:]]*'[^']+" | grep -oE "[a-f0-9]{16,}" | head -1)
echo "CSRF: ${TOKEN:0:12}... (len ${#TOKEN})"

say () {
  local msg="$1"; echo; echo ">>> USER: $msg"
  curl -s -c "$J" -b "$J" -o "$OUT" -w "    [http=%{http_code} %{time_total}s]\n" \
    -X POST "$BASE/api/gia/chat" -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
    -d "$(php -r 'echo json_encode(["message"=>$argv[1],"surface"=>"widget","csrf_token"=>$argv[2]]);' "$msg" "$TOKEN")" \
    --max-time 160
  $PHP -r '
    $d=json_decode(file_get_contents("/tmp/gia_stays_resp.json"),true) ?: [];
    echo "    status=".(($d["status"]??false)?"true":"false")." err=".($d["error_code"]??"")."\n";
    echo "    GIA: ".mb_substr(preg_replace("/\s+/"," ",$d["reply"]??""),0,240)."\n";
    $c=$d["searches"][0]["cards"]??[]; if($c){ echo "    cards=".count($c)."\n"; foreach(array_slice($c,0,5) as $i=>$x){ echo "      [$i] ".($x["title"]??$x["name"]??"?")." ".($x["price"]??"")." ".($x["currency"]??"")." id=".($x["id"]??"")." ref=".($x["ref"]??"")."\n"; } }
    if(!empty($d["pay_options"])) echo "    >> PAY OPTIONS: ".json_encode($d["pay_options"]["options"]??[])."\n";
  '
}

say "hotel in Kano"
say "the first one, checking in $(date -v+5d +%Y-%m-%d) out $(date -v+7d +%Y-%m-%d), 2 guests"
say "the Standard Room"
say "yes book it"

echo
echo "=== STAYS TRACE (this run) ==="
grep -E "GIA-TRACE|\[gia_stays\]" /Applications/XAMPP/xamppfiles/htdocs/goglobia/_error.log 2>/dev/null | tail -20
