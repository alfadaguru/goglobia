#!/bin/bash
# Prove the double-book guard: drive umrah & flights with TRAILING confirms after
# the booking is already held, then assert exactly ONE booking row was created.
set -u
BASE="http://localhost/goglobia"
PHP=/Applications/XAMPP/xamppfiles/bin/php; [ -x "$PHP" ] || PHP=php
MYSQL="/Applications/XAMPP/xamppfiles/bin/mysql -uroot goglobia -N -s"

csrf_and_jar () { # $1 = jar file
  local page tok
  page=$(curl -s -c "$1" -b "$1" -L "$BASE/gia")
  printf '%s' "$page" | grep -oE "csrfToken[\"']?[[:space:]]*:[[:space:]]*[\"'][^\"']+|'csrfToken'[[:space:]]*=>[[:space:]]*'[^']+" | grep -oE "[a-f0-9]{16,}" | head -1
}

send () { # $1=jar $2=token $3=msg
  curl -s -c "$1" -b "$1" -o /tmp/db_resp.json -w "    [http=%{http_code} %{time_total}s]\n" \
    -X POST "$BASE/api/gia/chat" -H "Content-Type: application/json" -H "X-CSRF-TOKEN: $2" \
    -d "$(php -r 'echo json_encode(["message"=>$argv[1],"surface"=>"widget","csrf_token"=>$argv[2]]);' "$3" "$2")" \
    --max-time 160
  $PHP -r '$d=json_decode(file_get_contents("/tmp/db_resp.json"),true)?:[]; echo "    GIA: ".mb_substr(preg_replace("/\s+/"," ",$d["reply"]??""),0,160)."\n"; if(!empty($d["pay_options"]))echo "    >> pay options\n"; if(!empty($d["collecting"]))echo "    >> collecting\n";'
}

echo "################## UMRAH double-book ##################"
UJAR=/tmp/db_umrah.txt; rm -f "$UJAR"; UT=$(csrf_and_jar "$UJAR")
BEFORE_U=$($MYSQL -e "SELECT COUNT(*) FROM umrah_bookings;")
echo "umrah_bookings BEFORE=$BEFORE_U"
echo ">>> I want umrah";            send "$UJAR" "$UT" "I want umrah"
echo ">>> the 16th December one";   send "$UJAR" "$UT" "the 16th December one"
echo ">>> 1 pilgrim standard";      send "$UJAR" "$UT" "1 pilgrim, standard"
echo ">>> yes book it";             send "$UJAR" "$UT" "yes book it"
echo ">>> yes";                     send "$UJAR" "$UT" "yes"
echo ">>> confirm please";          send "$UJAR" "$UT" "yes confirm it please"
AFTER_U=$($MYSQL -e "SELECT COUNT(*) FROM umrah_bookings;")
echo "umrah_bookings AFTER=$AFTER_U  (delta=$((AFTER_U-BEFORE_U)) — MUST be 1)"

echo
echo "################## FLIGHTS double-book ##################"
FJAR=/tmp/db_flt.txt; rm -f "$FJAR"; FT=$(csrf_and_jar "$FJAR")
BEFORE_F=$($MYSQL -e "SELECT COUNT(*) FROM bookings WHERE module_type='ai_trip' AND booking_data LIKE '%\"gia_service\":\"flights\"%';")
echo "flights ai_trip bookings BEFORE=$BEFORE_F"
D=$(date -v+6d +%Y-%m-%d)
echo ">>> flights Lagos->Abuja $D";  send "$FJAR" "$FT" "flights from Lagos to Abuja on $D for 1 adult"
echo ">>> the 06:30 one";           send "$FJAR" "$FT" "the 06:30 one"
echo ">>> John Traveller dob";       send "$FJAR" "$FT" "John Traveller, male, date of birth 1990-05-12"
echo ">>> yes book it";             send "$FJAR" "$FT" "yes book it"
echo ">>> yes";                     send "$FJAR" "$FT" "yes"
echo ">>> confirm please";          send "$FJAR" "$FT" "yes please confirm"
AFTER_F=$($MYSQL -e "SELECT COUNT(*) FROM bookings WHERE module_type='ai_trip' AND booking_data LIKE '%\"gia_service\":\"flights\"%';")
echo "flights ai_trip bookings AFTER=$AFTER_F  (delta=$((AFTER_F-BEFORE_F)) — MUST be 1)"

echo
echo "=== BOOK-CALL TRACE (this run) ==="
grep -E "umrah_book|flights_book" /Applications/XAMPP/xamppfiles/htdocs/goglobia/_error.log 2>/dev/null | tail -20
