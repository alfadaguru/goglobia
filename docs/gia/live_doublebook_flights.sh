#!/bin/bash
# Re-test ONLY the flights double-book guard after the fix.
set -u
BASE="http://localhost/goglobia"
PHP=/Applications/XAMPP/xamppfiles/bin/php; [ -x "$PHP" ] || PHP=php
MYSQL="/Applications/XAMPP/xamppfiles/bin/mysql -uroot goglobia -N -s"

FJAR=/tmp/db_flt2.txt; rm -f "$FJAR"
PAGE=$(curl -s -c "$FJAR" -b "$FJAR" -L "$BASE/gia")
FT=$(printf '%s' "$PAGE" | grep -oE "csrfToken[\"']?[[:space:]]*:[[:space:]]*[\"'][^\"']+|'csrfToken'[[:space:]]*=>[[:space:]]*'[^']+" | grep -oE "[a-f0-9]{16,}" | head -1)

send () {
  curl -s -c "$FJAR" -b "$FJAR" -o /tmp/f2.json -w "    [http=%{http_code} %{time_total}s]\n" \
    -X POST "$BASE/api/gia/chat" -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $FT" \
    -d "$(php -r 'echo json_encode(["message"=>$argv[1],"surface"=>"widget","csrf_token"=>$argv[2]]);' "$1" "$FT")" --max-time 160
  $PHP -r '$d=json_decode(file_get_contents("/tmp/f2.json"),true)?:[]; echo "    GIA: ".mb_substr(preg_replace("/\s+/"," ",$d["reply"]??""),0,150)."\n"; if(!empty($d["pay_options"]))echo "    >> pay options\n";'
}

BEFORE=$($MYSQL -e "SELECT COUNT(*) FROM bookings WHERE module_type='ai_trip' AND booking_data LIKE '%\"gia_service\":\"flights\"%';")
echo "flights bookings BEFORE=$BEFORE"
D=$(date -v+6d +%Y-%m-%d)
echo ">>> flights Lagos->Abuja $D"; send "flights from Lagos to Abuja on $D for 1 adult"
echo ">>> the 06:30 one";          send "the 06:30 one"
echo ">>> John Traveller dob";      send "John Traveller, male, date of birth 1990-05-12"
echo ">>> yes book it";            send "yes book it"
echo ">>> yes";                    send "yes"
echo ">>> yes please confirm";     send "yes please confirm"
AFTER=$($MYSQL -e "SELECT COUNT(*) FROM bookings WHERE module_type='ai_trip' AND booking_data LIKE '%\"gia_service\":\"flights\"%';")
echo "flights bookings AFTER=$AFTER  (delta=$((AFTER-BEFORE)) — MUST be 1)"
echo
echo "=== flights_book + reuse trace ==="
grep -E "flights_book|flights_find_held" /Applications/XAMPP/xamppfiles/htdocs/goglobia/_error.log 2>/dev/null | tail -8
