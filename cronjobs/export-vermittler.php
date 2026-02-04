<?php
/**
 * VGK24 VERMITTLER EXPORT (Gravity Forms)
 *
 * Logic:
 * - Only entries with created_by (real WP users)
 * - GF field 65 = aktiv
 * - GF field 66 = value
 * - if 65 > 0 → empfehlung = 66
 * - else → 0
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

/**
 * Normalize comma separated URLs to pure domains
 */
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

    // recreate table
    $db->query("DROP TABLE IF EXISTS vermittler");

    $db->query("
        CREATE TABLE vermittler (
            vid INT PRIMARY KEY,
            user_id INT,
            vorname VARCHAR(150),
            nachname VARCHAR(150),
            firma VARCHAR(255),
            email VARCHAR(255),
            empfehlung INT,
            urls TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Gravity Forms pivot + created_by join
    $rows = $wpdb->get_results("
        SELECT *
        FROM (
            SELECT
                m.entry_id AS vid,
                e.created_by AS user_id,

                MAX(CASE WHEN m.meta_key = '6' THEN m.meta_value END) AS vorname,
                MAX(CASE WHEN m.meta_key = '7' THEN m.meta_value END) AS nachname,
                MAX(CASE WHEN m.meta_key = '8' THEN m.meta_value END) AS firma,
                MAX(CASE WHEN m.meta_key = '5' THEN m.meta_value END) AS email,

                MAX(CASE WHEN m.meta_key LIKE '65%' THEN m.meta_value END) AS empfehlung_active,
                MAX(CASE WHEN m.meta_key LIKE '66%' THEN m.meta_value END) AS empfehlung_value,

                MAX(CASE WHEN m.meta_key = '82' THEN m.meta_value END) AS urls

            FROM wp_gf_entry_meta m
            INNER JOIN wp_gf_entry e ON e.id = m.entry_id

            WHERE e.form_id = 23
              AND e.created_by > 0
              AND (
                    m.meta_key IN ('5','6','7','8','82')
                    OR m.meta_key LIKE '65%'
                    OR m.meta_key LIKE '66%'
                  )

            GROUP BY m.entry_id, e.created_by
        ) gf
        INNER JOIN wp_users u ON u.ID = gf.user_id
    ", ARRAY_A);

    if (!$rows) throw new Exception("No Gravity Forms data found");

    echo "Rows: ".count($rows)."\n";

    $count = 0;

    foreach ($rows as $r) {

        $empfehlung = ((int)$r['empfehlung_active'] > 0)
            ? (int)$r['empfehlung_value']
            : 0;

        $sql = sprintf(
            "INSERT INTO vermittler VALUES (%d,%d,'%s','%s','%s','%s',%d,'%s')",
            (int)$r['vid'],
            (int)$r['user_id'],
            $db->real_escape_string($r['vorname'] ?? ''),
            $db->real_escape_string($r['nachname'] ?? ''),
            $db->real_escape_string($r['firma'] ?? ''),
            $db->real_escape_string($r['email'] ?? ''),
            $empfehlung,
            $db->real_escape_string(normalize_domains($r['urls'] ?? ''))
        );

        if (!$db->query($sql)) {
            throw new Exception($db->error);
        }

        $count++;
    }

    echo "Inserted: $count vermittler rows\n";

    $db->close();

    echo "FINISHED\n";

} catch (Throwable $e) {

    echo "ERROR: ".$e->getMessage()."\n";
    exit(1);
}
