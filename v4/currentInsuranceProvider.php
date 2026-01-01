<?php
/**
 *  VGK24 API v4
 *  Endpoint: /v4/currentInsuranceProvider.php
 *  Returns: List of statutory health insurance providers (JSON)
 */

// ---------- CORS ----------
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");              // allow all origins (public read API)
header("Access-Control-Allow-Methods: GET, OPTIONS");  // allow preflight requests
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

    // ---------- Query ----------
    $stmt = $pdo->query("
        SELECT id, name 
        FROM kassen 
        ORDER BY name ASC
    ");

    $data = $stmt->fetchAll();

    // ---------- Success Response ----------
    echo json_encode([
        'success' => true,
        'count'   => count($data),
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    // ---------- Error ----------
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
