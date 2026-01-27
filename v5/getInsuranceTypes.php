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
$insuranceTypes = [
    ["id" => 1, "label" => "Gesetzlich krankenversichert"],
    ["id" => 2, "label" => "Privat krankenversichert"],
    ["id" => 3, "label" => "Nicht krankenversichert"]
];

// --------- Output JSON ---------
echo json_encode([
    "success" => true,
    "data" => $insuranceTypes
], JSON_UNESCAPED_UNICODE);
