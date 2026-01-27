<?php

// ---------- CORS ----------
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// ---------- Preflight ----------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------- Load DB Config ----------
require_once __DIR__ . '/config.php';

try {

    // ---------- DB Connection ----------
    $pdo = new PDO(
        "mysql:host={$targetDBHost};dbname={$targetDBName};charset=utf8mb4",
        $targetDBUser,
        $targetDBPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // ---------- Load Presets (sorted by name) ----------
    $stmt = $pdo->prepare("
        SELECT 
            name, 
            filter_ids  
        FROM preselect_filter
        ORDER BY name ASC
    ");
    $stmt->execute();

    $filter = $stmt->fetchAll();

    if (!$filter) {
        $filter = [];
    }

    // ---------- Output JSON ----------
    echo json_encode($filter, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "error"   => "Database error",
        "message" => $e->getMessage()
    ]);
}
