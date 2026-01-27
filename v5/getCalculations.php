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

// ---------------- CONFIG ----------------
require_once __DIR__ . '/config.php';

try {

    // ---------- DB ----------
    $pdo = new PDO(
        "mysql:host={$targetDBHost};dbname={$targetDBName};charset=utf8mb4",
        $targetDBUser,
        $targetDBPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3
        ]
    );

    // ---------- INPUT ----------
    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        throw new Exception("Invalid JSON");
    }

    $jobGroup = (int)($input['jobGroup'] ?? 0);
    $income   = (float)($input['income'] ?? 0);
    $extraIncome = (float)($input['extraIncome'] ?? 0);
    $sickPay  = (int)($input['sickPay'] ?? 0);

    if (!$jobGroup || $income <= 500) {
        throw new Exception("Missing required fields");
    }

    // ---------- LOAD CONFIG ----------
    $cfg = $pdo->query("
        SELECT *
        FROM kassen_beitragsgrundlagen
        WHERE id = 1
        LIMIT 1
    ")->fetch();

    if (!$cfg) throw new Exception("Config not found");

    $student_beitrag = (float)$cfg['studenten_grundeinkommen'];
    $student_satz    = (float)$cfg['studenten_beitragssatz'];
    $azubi           = (float)$cfg['azubi_grenzbetrag'];
    $hBetrag         = (float)$cfg['hoechstbetrag'];
    $bSatz           = (float)$cfg['beitragssatz'];
    $eSatz           = (float)$cfg['ermaessigter_beitragssatz'];

    // ---------- LOAD KASSEN ----------
    $stmt = $pdo->query("
        SELECT id, name, zusatzbeitrag AS zusatz
        FROM kassen_liste
        ORDER BY name ASC       
    ");

    $kassen = $stmt->fetchAll();

    if (!$kassen) throw new Exception("No providers");

    // monthly income + yearly extra divided by 12
    $monthly = $income + ($extraIncome / 12);

    // ---------- CALCULATIONS ----------
    foreach ($kassen as $i => $k) {

        $zSatz = (float)$k['zusatz'];
        $m = $monthly;

        switch ($jobGroup) {

            case 1: // Arbeitnehmer
                $m = max(556, min($m,$hBetrag));
                $b = ($m*(($bSatz/2)+($zSatz/2)))/100;
                break;

            case 2: // Azubi
                if ($m <= $azubi) $m=0;
                $m=min($m,$hBetrag);
                $b=($m*(($bSatz/2)+($zSatz/2)))/100;
                break;

            case 3: // Student
                $m=$student_beitrag;
                $b=($m*($student_satz+$zSatz))/100;
                break;

            case 4: // Selbstständig
                $c = $sickPay ? $bSatz : $eSatz;
                $m=max(1248.33,min($m,$hBetrag));
                $b=($m*($c+$zSatz))/100;
                break;

            case 5: // Rentner
                $m=max(1,min($m,$hBetrag));
                $b=($m*(($bSatz/2)+($zSatz/2)))/100;
                break;

            case 6: // Arbeitslos
                $b=0;
                break;

            default:
                $m=max(1248.33,min($m,$hBetrag));
                $b=($m*($eSatz+$zSatz))/100;
        }

        $k['monthly'] = round($b,2);
        $k['yearly']  = round($b*12,2);

         $kassen[$i] = $k;
    }

    // ---------- RESPONSE ----------
    ob_clean();

    echo json_encode([
        "success" => true,
        "count" => count($kassen),
        "data" => $kassen
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    exit;

}
catch (Throwable $e) {

    ob_clean();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);

    exit;
}