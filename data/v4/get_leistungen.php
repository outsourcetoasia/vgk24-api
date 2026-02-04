<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once('../config.php');

// Initialize global data storage
$GLOBALS['categories'] = [];
$GLOBALS['categoriesHierarchy'] = [];

try {
    // Connect to the database using PDO
    $pdo = new PDO("mysql:host={$GLOBALS['targetDBHost']};dbname={$GLOBALS['targetDBName']};charset=utf8", $GLOBALS['targetDBUser'], $GLOBALS['targetDBPass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch main categories
    fetchMainCategories($pdo);

    // Fetch subcategories and build hierarchy
    fetchSubCategories($pdo);

    // Return hierarchical data as JSON
    header('Content-Type: application/json');
    echo json_encode(array_values($GLOBALS['categoriesHierarchy']));
} catch (PDOException $e) {
    // Handle database errors
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

/**
 * Fetch main categories and store in GLOBALS
 *
 * @param PDO $pdo
 */
function fetchMainCategories(PDO $pdo)
{
    $stmt = $pdo->prepare("SELECT id, cat_title AS text FROM categories");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($categories as $category) {
        $GLOBALS['categories'][$category['id']] = $category['text'];
        $GLOBALS['categoriesHierarchy'][$category['id']] = [
            'id' => $category['id'],
            'text' => $category['text'],
            'category' => 'Kategorie',
            'htmlAttributes' => ['class' => 'e-checkbox-hidden'],
            'child' => []
        ];
    }
}

/**
 * Fetch subcategories and map them to parent categories
 *
 * @param PDO $pdo
 */
function fetchSubCategories(PDO $pdo)
{
    $stmt = $pdo->prepare("SELECT id, cat, label AS text FROM categories_subcategories");
    $stmt->execute();
    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($subcategories as $subcategory) {
        $parentId = $subcategory['cat'];
        if (isset($GLOBALS['categoriesHierarchy'][$parentId])) {
            $GLOBALS['categoriesHierarchy'][$parentId]['child'][] = [
                'id' => $subcategory['id'],
                'text' => $subcategory['text'],
                'category' => $GLOBALS['categories'][$parentId],
                'htmlAttributes' => []
            ];
        }
    }
}
?>