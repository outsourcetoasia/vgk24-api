<?php
// -------- Headers (VERY IMPORTANT) --------
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *"); // allow all origins (safe for read-only)
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --------- Data (static for now, can later come from DB) ---------
$federalStates = [
    ["id" => 8,  "label" => "Baden-Württemberg"],
    ["id" => 9,  "label" => "Bayern"],
    ["id" => 11, "label" => "Berlin"],
    ["id" => 12, "label" => "Brandenburg"],
    ["id" => 4,  "label" => "Bremen"],
    ["id" => 2,  "label" => "Hamburg"],
    ["id" => 6,  "label" => "Hessen"],
    ["id" => 13, "label" => "Mecklenburg-Vorpommern"],
    ["id" => 3,  "label" => "Niedersachsen"],
    ["id" => 5,  "label" => "Nordrhein-Westfalen"],
    ["id" => 7,  "label" => "Rheinland-Pfalz"],
    ["id" => 10, "label" => "Saarland"],
    ["id" => 14, "label" => "Sachsen"],
    ["id" => 15, "label" => "Sachsen-Anhalt"],
    ["id" => 1,  "label" => "Schleswig-Holstein"],
    ["id" => 16, "label" => "Thüringen"]
];

// --------- Output JSON ---------
echo json_encode(["success" => true, "data" => $federalStates], JSON_UNESCAPED_UNICODE);
