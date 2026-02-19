<?php

require_once dirname(__DIR__, 2) . '/bootstrap.php';

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

try {

    $plz = $_GET['plz'] ?? '';

    // Fast format check
    if (!preg_match('/^\d{5}$/', $plz)) {
        echo json_encode(['valid' => false]);
        exit;
    }

    // SAME PDO CONNECTION STYLE AS YOUR OTHER API
    $pdo = new PDO(
        "mysql:host={$targetDBHost};dbname={$targetDBName};charset=utf8mb4",
        $targetDBUser,
        $targetDBPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $stmt = $pdo->prepare("SELECT 1 FROM postleitzahlen WHERE plz = ? LIMIT 1");
    $stmt->execute([$plz]);

    echo json_encode([
        'valid' => (bool)$stmt->fetch()
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'valid' => false,
        'error' => $e->getMessage()
    ]);
}
