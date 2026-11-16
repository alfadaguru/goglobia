<?php
// Usage: php _e2e_state.php <invoice_id>   → prints booking row + agent wallet balance
$env=parse_ini_file(__DIR__.'/../.env'); $sock='/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
$pdo=new PDO("mysql:unix_socket=$sock;dbname={$env['DB_DATABASE']};charset=utf8mb4",trim($env['DB_USERNAME'],'"'),$env['DB_PASSWORD']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
require __DIR__.'/../vendor/autoload.php';
$db=new Medoo\Medoo(['type'=>'mysql','database'=>$env['DB_DATABASE'],'socket'=>$sock,'username'=>trim($env['DB_USERNAME'],'"'),'password'=>$env['DB_PASSWORD']??'']);
require __DIR__.'/../app/lib/wallet.php';
$AGENT='a359ef7dce43b2879209774';
$inv=$argv[1]??'';
if($inv){
  $b=$pdo->prepare("SELECT invoice_id,booking_status,payment_status,payment_gateway,agency_id,booked_by_staff_id,transaction_id,refund_amount,refund_status FROM bookings WHERE invoice_id=?");
  $b->execute([$inv]); $r=$b->fetch(PDO::FETCH_ASSOC);
  echo "booking $inv: ".($r?json_encode($r):'(NOT FOUND)')."\n";
}
echo "agent NGN wallet balance: ".number_format(wallet_balance($db,$AGENT,'NGN'),2)."\n";
// last 3 ledger rows
$l=$pdo->prepare("SELECT direction,amount,reason,note,created_at FROM wallet_ledger WHERE user_id=? ORDER BY id DESC LIMIT 4");
$l->execute([$AGENT]);
foreach($l as $row) printf("  ledger: %-6s %s %s | %s\n",$row['direction'],$row['amount'],$row['reason'],substr($row['note']??'',0,50));
