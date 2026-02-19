<?php
/**
 * VGK24 PLZ EXPORT CRON (SHADOW TABLE VERSION)
 */

// ================= BASIC SETTINGS =================
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(0);

// ================= BOOTSTRAP ======================
require_once __DIR__ . '/../bootstrap.php';

// ================= WORDPRESS ======================
define('WP_USE_THEMES', false);
require_once '/var/www/vhosts/vgk24.de/httpdocs/wp-load.php';

if (!function_exists('get_posts')) {
    die("WordPress not loaded\n");
}

// ================= MYSQL STRICT ===================
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Atomic swap helper
 */
function shadow_swap(mysqli $db, string $table) {

    $db->query("
        RENAME TABLE
            {$table} TO {$table}_old,
            {$table}_tmp TO {$table}
    ");

    $db->query("DROP TABLE {$table}_old");
}

/**
 * ===========================
 * EXPORT POSTLEITZAHLEN
 * ===========================
 */

try {

    global $targetDBHost, $targetDBUser, $targetDBPass, $targetDBName;

    $db = new mysqli($targetDBHost, $targetDBUser, $targetDBPass, $targetDBName);
    $db->set_charset('utf8mb4');

    echo "START PLZ EXPORT\n";

    export_postleitzahlen($db);

    $db->close();

    echo "FINISHED\n";

}
catch (Throwable $e) {

    echo "ERROR: ".$e->getMessage()."\n";
    exit(1);
}


/**
 * ===========================
 * POSTLEITZAHLEN + BUNDESLAND ID
 * ===========================
 */
function export_postleitzahlen(mysqli $db) {

    global $wpdb;

    $bundeslandMap = [
        'Baden-Württemberg'=>1,'Bayern'=>2,'Berlin'=>3,'Brandenburg'=>4,'Bremen'=>5,
        'Hamburg'=>6,'Hessen'=>7,'Mecklenburg-Vorpommern'=>8,'Niedersachsen'=>9,
        'Nordrhein-Westfalen'=>10,'Rheinland-Pfalz'=>11,'Saarland'=>12,'Sachsen'=>13,
        'Sachsen-Anhalt'=>14,'Schleswig-Holstein'=>15,'Thüringen'=>16,
        'Alle'=>99,'Deutschlandweit'=>99
    ];

    echo "Loading wp_vergleich_plz...\n";

    $rows = $wpdb->get_results("
        SELECT plz, bundesland
        FROM wp_vergleich_plz
    ", ARRAY_A);

    if (!$rows) {
        throw new Exception("No PLZ data found");
    }

    echo "Rows: ".count($rows)."\n";

    // ================= ENSURE MAIN TABLE =================

    $db->query("
        CREATE TABLE IF NOT EXISTS postleitzahlen (
            plz VARCHAR(5) PRIMARY KEY,
            bundesland VARCHAR(200),
            bundesland_id TINYINT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // ================= SHADOW TABLE =================

    $db->query("DROP TABLE IF EXISTS postleitzahlen_tmp");
    $db->query("CREATE TABLE postleitzahlen_tmp LIKE postleitzahlen");

    // ================= INSERT TMP =================

    $count = 0;

    foreach ($rows as $r) {

        $plz = trim($r['plz']);
        $bl  = trim($r['bundesland']);

        if (!$plz) continue;

        $blId = $bundeslandMap[$bl] ?? 99;

        $sql = sprintf(
            "INSERT INTO postleitzahlen_tmp VALUES ('%s','%s',%d)",
            $db->real_escape_string($plz),
            $db->real_escape_string($bl),
            (int)$blId
        );

        $db->query($sql);
        $count++;
    }

    echo "Inserted into tmp: $count PLZ rows\n";

    // ================= ATOMIC SWAP =================

    shadow_swap($db,'postleitzahlen');
}
