#!/bin/bash
# Drive umrah ALL THE WAY: search -> tiers -> book -> passport (typed) -> YES -> PAY,
# one conversation (shared cookie + CSRF). Proves Passport -> pay on the real endpoint.
set -u
BASE="http://localhost/goglobia"
J=/tmp/gia_upp_cookies.txt; rm -f "$J"
OUT=/tmp/gia_upp_resp.json
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
    $d=json_decode(file_get_contents("/tmp/gia_upp_resp.json"),true) ?: [];
    echo "    status=".(($d["status"]??false)?"true":"false")." err=".($d["error_code"]??"")."\n";
    echo "    GIA: ".mb_substr(preg_replace("/\s+/"," ",$d["reply"]??""),0,260)."\n";
    if(!empty($d["collecting"])) echo "    >> collecting (passports)\n";
    if(!empty($d["pay_options"])){ echo "    >> PAY OPTIONS present\n"; }
    if(isset($d["pay_options"]["invoice_id"])) echo "    >> invoice_id=".$d["pay_options"]["invoice_id"]."\n";
  '
}

# capture the invoice id that the pay_options block carries
invoice_from_resp () {
  $PHP -r '$d=json_decode(file_get_contents("/tmp/gia_upp_resp.json"),true)?:[]; echo $d["pay_options"]["invoice_id"]??"";'
}

say "I want umrah"
say "the 16th December one"
say "1 pilgrim, standard"
say "yes book it"
# now mid passport-collection — type ALL 7 required fields in parser-accepted phrasing
say "first name is John, last name is Pilgrim, male, date of birth 1985-06-15, nationality Nigerian, passport number A1234567, expiry 2030-05-01"
say "YES"

INV=$(invoice_from_resp)
echo
echo "=== invoice for pay: ${INV:-<none captured>} ==="

if [ -n "$INV" ]; then
  echo; echo ">>> PAY (paybank) invoice=$INV"
  curl -s -c "$J" -b "$J" -o /tmp/gia_upp_pay.json -w "    [http=%{http_code} %{time_total}s]\n" \
    -X POST "$BASE/api/gia/umrah/pay" -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $TOKEN" \
    -d "$(php -r 'echo json_encode(["option"=>"paybank","invoice_id"=>$argv[1],"csrf_token"=>$argv[2]]);' "$INV" "$TOKEN")" \
    --max-time 120
  echo "    --- pay response ---"
  $PHP -r '$d=json_decode(file_get_contents("/tmp/gia_upp_pay.json"),true)?:[]; echo json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";'
fi

echo
echo "=== UMRAH P->PAY TRACE (this run) ==="
grep -E "GIA-TRACE|\[gia_umrah\]|\[gia_pt|\[gia_bp\]|stays_|DVA|dva" /Applications/XAMPP/xamppfiles/htdocs/goglobia/_error.log 2>/dev/null | tail -30
