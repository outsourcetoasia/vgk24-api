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
$jobGroups = [
    ["id" => 1, "label" => "Arbeitnehmer"],
    ["id" => 2, "label" => "Auszubildender"],
    ["id" => 3, "label" => "Student"],
    ["id" => 4, "label" => "Selbstständiger"],
    ["id" => 5, "label" => "Rentner"],
    ["id" => 6, "label" => "Arbeitslos"],
    ["id" => 7, "label" => "Sonstige"]
];


// --------- Output JSON ---------
echo json_encode([
    "success" => true,
    "data" => $jobGroups
], JSON_UNESCAPED_UNICODE);
