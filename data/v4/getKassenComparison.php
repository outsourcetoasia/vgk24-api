<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';

try {

    $pdo = new PDO(
        "mysql:host={$targetDBHost};dbname={$targetDBName};charset=utf8mb4",
        $targetDBUser,
        $targetDBPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $sql = "
        SELECT 
            k.id,
            k.name,
            k.zusatzbeitrag,
            k.ad,
            k.prio,
            f.leistung,
            f.status,
            f.description,
            f.additiv
        FROM kassen k
        LEFT JOIN `filter` f ON f.id = k.id
        ORDER BY k.prio DESC, k.name
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $result = [];

    foreach ($rows as $r) {

        $id = $r['id'];

        if (!isset($result[$id])) {
            $result[$id] = [
                "id"            => $id,
                "name"          => $r['name'],
                "rate"          => $r['zusatzbeitrag'],
                "price_monthly" => null,
                "savings_year"  => null,
                "logo_url"      => null,
                "filter_ids"    => [],              // MUST be array
                "apply_url"     => "#",
                "info_url"      => "#",
                "compare_url"   => "#",
                "benefits"      => []
            ];
        }

        // Only positive or warning benefits
        if ($r['leistung'] && in_array((int)$r['status'], [1,2])) {

            $result[$id]["filter_ids"][] = (string)$r['leistung'];

            $result[$id]["benefits"][] = [
                "title"   => $r['leistung'],
                "value"   => $r['additiv'],
                "status"  => (int)$r['status'],
                "tooltip" => $r['description']
            ];
        }
    }

    // Final normalization
    foreach ($result as &$k) {
        $k['filter_ids'] = implode(",", (array)$k['filter_ids']);
    }

    echo json_encode(array_values($result), JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);
    echo json_encode([
        "error" => true,
        "message" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
