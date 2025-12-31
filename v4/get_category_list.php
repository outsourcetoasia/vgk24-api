<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once('../config.php');

try {
    // Create a new PDO instance
    $pdo = new PDO("mysql:host=$targetDBHost;dbname=$targetDBName;charset=utf8", $targetDBUser, $targetDBPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Prepare the SQL query to fetch all categories
    $stmt = $pdo->prepare('SELECT id, cat_title as name FROM categories');
    $stmt->execute();

    // Fetch results as an associative array
    $kassen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add the new entry at the beginning of the array
    array_unshift($kassen, ['id' => 0, 'name' => 'Meine Topleistungen']);

    // Set the correct JSON header for response
    header('Content-Type: application/json');

    // Convert the array to JSON
    echo json_encode($kassen);

} catch (PDOException $e) {
    // If an error occurs, return an error response
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}