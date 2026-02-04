<?php
/**
 *  VGK24 API v4
 *  Endpoint: /v4/get_kassen_list.php
 *  Returns: List of statutory health insurance providers (JSON)
 */

// ---------- CORS (Frontend domain only) ----------
header('Access-Control-Allow-Origin: https://vergleichsrechner.vgk24.de');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

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

    // ---------- Response ----------
    echo json_encode([
        'success' => true,
        'count'   => count($data),
        'data'    => $data
    ]);

} catch (Throwable $e) {

    // ---------- Error ----------
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
        // Do NOT expose $e->getMessage() in production
    ]);
}
