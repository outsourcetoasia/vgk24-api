<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

require_once('../config.php');

try {

    // DB connect
    $pdo = new PDO(
        "mysql:host=$targetDBHost;dbname=$targetDBName;charset=utf8",
        $targetDBUser,
        $targetDBPass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Load categories
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            cat_title AS name 
        FROM categories
        ORDER BY name ASC
    ");
    $stmt->execute();

    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ensure array format
    if (!$categories) {
        $categories = [];
    }

    // Prepend default item
    array_unshift($categories, [
        'id'   => 0,
        'name' => 'Meine Topleistungen'
    ]);

    // Output JSON
    echo json_encode($categories, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);

    exit;
}

