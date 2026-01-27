<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

require_once(__DIR__ . '/config.php');

try {

    // DB connect
    $pdo = new PDO(
        "mysql:host=$targetDBHost;dbname=$targetDBName;charset=utf8",
        $targetDBUser,
        $targetDBPass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Load ALL sub-categories, sorted case-insensitive
    $stmt = $pdo->prepare("
        SELECT 
            id,
            cat,
            label,
            description
        FROM categories_subcategories
        ORDER BY LOWER(TRIM(label)) ASC
    ");

    $stmt->execute();

    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Output JSON
    echo json_encode($subcategories, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'error'   => 'Database error',
        'message' => $e->getMessage()
    ]);
}
