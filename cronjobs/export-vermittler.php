<?php
/**
 * VGK24 VERMITTLER EXPORT (Gravity Forms)
 *
 * Shadow table swap version (zero downtime + auto-create)
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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function normalize_domains($raw) {

    if (!$raw) return '';

    $parts = preg_split('/\s*,\s*/', $raw);
    $domains = [];

    foreach ($parts as $p) {

        $p = trim($p);
        if (!$p) continue;

        if (!preg_match('#^https?://#i', $p)) {
            $p = 'http://' . $p;
        }

        $u = parse_url($p);
        if (empty($u['host'])) continue;

        $host = strtolower($u['host']);
        $host = preg_replace('/^www\./i', '', $host);

        $domains[] = $host;
    }

    return implode(',', array_unique($domains));
}

try {

    global $wpdb;
    global $targetDBHost, $targetDBUser, $targetDBPass, $targetDBName;

    echo "START VERMITTLER EXPORT\n";

    $db = new mysqli($targetDBHost, $targetDBUser, $targetDBPass, $targetDBName);
    $db->set_charset('utf8mb4');

    // ================= ENSURE MAIN TABLE EXISTS =================

    $db->query("
        CREATE TABLE IF NOT EXISTS vermittler (
            vid INT PRIMARY KEY,
            vorname VARCHAR(150),
            nachname VARCHAR(150),
            empfehlung INT,
            urls TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // ================= SHADOW TABLE =================

    $db->query("DROP TABLE IF EXISTS vermittler_tmp");

    $db->query("CREATE TABLE vermittler_tmp LIKE vermittler");

    // ================= FETCH GF DATA =================

    $rows = $wpdb->get_results("
        SELECT
            m.entry_id AS vid,

            MAX(CASE WHEN m.meta_key = '6' THEN m.meta_value END) AS vorname,
            MAX(CASE WHEN m.meta_key = '7' THEN m.meta_value END) AS nachname,
            MAX(CASE WHEN m.meta_key LIKE '66%' THEN m.meta_value END) AS empfehlung,
            MAX(CASE WHEN m.meta_key = '82' THEN m.meta_value END) AS urls

        FROM wp_gf_entry_meta m
        INNER JOIN wp_gf_entry e ON e.id = m.entry_id

        WHERE e.form_id = 23
          AND e.created_by > 0
          AND (
                m.meta_key IN ('6','7','82')
                OR m.meta_key LIKE '66%'
              )

        GROUP BY m.entry_id
    ", ARRAY_A);

    if (!$rows) throw new Exception("No Gravity Forms data found");

    echo "Rows: ".count($rows)."\n";

    // ================= INSERT INTO TMP =================

    $count = 0;

    foreach ($rows as $r) {

        $empfehlung = (int)($r['empfehlung'] ?? 0);

        $sql = sprintf(
            "INSERT INTO vermittler_tmp VALUES (%d,'%s','%s',%d,'%s')",
            (int)$r['vid'],
            $db->real_escape_string($r['vorname'] ?? ''),
            $db->real_escape_string($r['nachname'] ?? ''),
            $empfehlung,
            $db->real_escape_string(normalize_domains($r['urls'] ?? ''))
        );

        $db->query($sql);
        $count++;
    }

    echo "Inserted into tmp: $count\n";

    // ================= ATOMIC SWAP =================

    $db->query("
        RENAME TABLE
            vermittler TO vermittler_old,
            vermittler_tmp TO vermittler
    ");

    $db->query("DROP TABLE vermittler_old");

    $db->close();

    echo "FINISHED\n";

} catch (Throwable $e) {

    echo "ERROR: ".$e->getMessage()."\n";
    exit(1);
}
