<?php
/**
 * VGK24 API v5
 * Endpoint: /v5/getCalculations.php
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

ob_start();

require_once __DIR__ . '/config.php';

/* ---------- Helpers ---------- */
function deMoney($v) {
    return number_format((float)$v, 2, ',', '.') . ' €';
}

function dePercent($v) {
    return number_format((float)$v, 2, ',', '.') . ' %';
}

try {

    /* ---------- DB ---------- */
    $pdo = new PDO(
        "mysql:host={$targetDBHost};dbname={$targetDBName};charset=utf8mb4",
        $targetDBUser,
        $targetDBPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    /* ---------- INPUT ---------- */
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) throw new Exception("Invalid JSON");

    $jobGroup = (int)($input['jobGroup'] ?? 0);
    $income   = (float)($input['income'] ?? 0);
    $extra    = (float)($input['extraIncome'] ?? 0);
    $sickPay  = (int)($input['sickPay'] ?? 0);
    $currentProvider = (int)($input['currentInsuranceProvider'] ?? 0);

    if (!$jobGroup || $income <= 500) throw new Exception("Missing fields");

    /* ---------- CONFIG ---------- */
    $cfg = $pdo->query("SELECT * FROM kassen_beitragsgrundlagen WHERE id=1 LIMIT 1")->fetch();
    if (!$cfg) throw new Exception("Config missing");

    $student_beitrag = (float)$cfg['studenten_grundeinkommen'];
    $student_satz    = (float)$cfg['studenten_beitragssatz'];
    $azubi           = (float)$cfg['azubi_grenzbetrag'];
    $hBetrag         = (float)$cfg['hoechstbetrag'];
    $bSatz           = (float)$cfg['beitragssatz'];
    $eSatz           = (float)$cfg['ermaessigter_beitragssatz'];

    /* ---------- LOAD KASSEN ---------- */
    $kassen = $pdo->query("
        SELECT id,name,zusatzbeitrag AS zusatz
        FROM kassen_liste
    ")->fetchAll();

    if (!$kassen) throw new Exception("No providers");

    /* monthly income + bonus/12 */
    $monthly = $income + ($extra / 12);

    /* ---------- CALC ---------- */
    foreach ($kassen as $i => $k) {

        $z = (float)$k['zusatz'];
        $m = $monthly;

        switch ($jobGroup) {

            case 1: // Arbeitnehmer
                $m=max(556,min($m,$hBetrag));
                $satz=($bSatz/2)+($z/2);
                break;

            case 2: // Azubi
                if ($m<=$azubi) $m=0;
                $m=min($m,$hBetrag);
                $satz=($bSatz/2)+($z/2);
                break;

            case 3: // Student
                $m=$student_beitrag;
                $satz=$student_satz+$z;
                break;

            case 4: // Selbstständig
                $c=$sickPay?$bSatz:$eSatz;
                $m=max(1248.33,min($m,$hBetrag));
                $satz=$c+$z;
                break;

            case 5: // Rentner
                $m=max(1,min($m,$hBetrag));
                $satz=($bSatz/2)+($z/2);
                break;

            case 6: // Arbeitslos
                $satz=0;
                $m=0;
                break;

            default: // Sonstige
                $m=max(1248.33,min($m,$hBetrag));
                $satz=$eSatz+$z;
        }

        $monthlyCost = ($m*$satz)/100;

        $kassen[$i]['_raw'] = $monthlyCost;
        $kassen[$i]['beitragssatz'] = $satz;

        unset($kassen[$i]['zusatz']);
    }

    /* ---------- CURRENT FIRST ---------- */
    $current=null;
    $others=[];

    foreach($kassen as $k){
        if($currentProvider && $k['id']==$currentProvider) $current=$k;
        else $others[]=$k;
    }

    usort($others,fn($a,$b)=>$a['_raw']<=>$b['_raw']);

    $kassen=$current?array_merge([$current],$others):$others;

    /* ---------- SAVINGS (YEARLY) ---------- */
    $base=$kassen[0]['_raw'];

    foreach($kassen as $i=>$k){
        $kassen[$i]['beitrag'] = deMoney($k['_raw']);           // monthly €
        $kassen[$i]['satz']    = dePercent($k['beitragssatz']); // %
        $kassen[$i]['ersparnis'] = deMoney(($base-$k['_raw'])*12);
        unset($kassen[$i]['_raw']);
        unset($kassen[$i]['beitragssatz']);
        unset($kassen[$i]['_raw']);
    }

    ob_clean();

    echo json_encode([
        "success"=>true,
        "count"=>count($kassen),
        "data"=>$kassen
    ],JSON_UNESCAPED_UNICODE);

    exit;

}
catch(Throwable $e){

    ob_clean();

    http_response_code(500);
    echo json_encode([
        "success"=>false,
        "error"=>$e->getMessage()
    ]);
    exit;
}
