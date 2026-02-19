<?php
/**
 * VGK24 FULL EXPORT CRON (SHADOW TABLE VERSION)
 */

// ================= BASIC SETTINGS =================
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(0);

// ================= BOOTSTRAP ======================
require_once realpath(__DIR__ . '/../bootstrap.php');

// ================= CRON LOCK ======================
$lockFile = sys_get_temp_dir() . '/vgk24_export.lock';

if (file_exists($lockFile) && time() - filemtime($lockFile) < 1800) {
    die("Already running\n");
}
touch($lockFile);

// ================= WORDPRESS ======================
define('WP_USE_THEMES', false);
require_once '/var/www/vhosts/vgk24.de/httpdocs/wp-load.php';

// ================= MYSQL STRICT ===================
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ================= TIMER ==========================
$start = microtime(true);

/**
 * Atomic table swap helper
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
 * MAIN EXPORT RUNNER
 * ===========================
 */
function export_vgk24() {

    global $targetDBHost, $targetDBUser, $targetDBPass, $targetDBName;

    $db = new mysqli($targetDBHost, $targetDBUser, $targetDBPass, $targetDBName);
    $db->set_charset('utf8mb4');

    export_kassen_beitragsgrundlagen($db);
    export_kassen_liste($db);
    export_kassen_werbung($db);
    export_kassen_leistungen($db);
    export_categories($db);

    $db->close();
}

/**
 * ===========================
 * BEITRAGSGRUNDLAGEN
 * ===========================
 */
function export_kassen_beitragsgrundlagen(mysqli $db) {

    $studenten_grundeinkommen = (float)get_field('kassen_studenten_grundeinkommen', 'option');
    $studenten_beitragssatz   = (float)get_field('kassen_studenten_beitragssatz', 'option');
    $azubi_grenzbetrag        = (float)get_field('kassen_azubi_grenzbetrag', 'option');
    $arbeitslosen_zusatz      = (float)get_field('kassen_arbeitslosen_zusatzbeitrag', 'option');

    $hoechstbetrag_raw = get_field('kassen_hoechstbetrag', 'option');
    $hoechstbetrag = (float)str_replace(',', '.', str_replace('.', '', $hoechstbetrag_raw));

    $beitragssatz              = (float)get_field('kassen_beitragssatz', 'option');
    $ermaessigter_beitragssatz = (float)get_field('kassen_ermasigter_beitragssatz', 'option');

    $db->query("
        CREATE TABLE IF NOT EXISTS kassen_beitragsgrundlagen (
            id TINYINT PRIMARY KEY,
            studenten_grundeinkommen DOUBLE,
            studenten_beitragssatz DOUBLE,
            azubi_grenzbetrag DOUBLE,
            arbeitslosen_zusatzbeitrag DOUBLE,
            hoechstbetrag DOUBLE,
            beitragssatz DOUBLE,
            ermaessigter_beitragssatz DOUBLE
        );
    ");

    $db->query("DROP TABLE IF EXISTS kassen_beitragsgrundlagen_tmp");
    $db->query("CREATE TABLE kassen_beitragsgrundlagen_tmp LIKE kassen_beitragsgrundlagen");

    $db->query("
        INSERT INTO kassen_beitragsgrundlagen_tmp VALUES (
            1,
            $studenten_grundeinkommen,
            $studenten_beitragssatz,
            $azubi_grenzbetrag,
            $arbeitslosen_zusatz,
            $hoechstbetrag,
            $beitragssatz,
            $ermaessigter_beitragssatz
        );
    ");

    shadow_swap($db,'kassen_beitragsgrundlagen');
}

/**
 * ===========================
 * KASSEN LISTE
 * ===========================
 */
function export_kassen_liste(mysqli $db) {

    global $wpdb;

    $bundeslandMap = [
        'Baden-Württemberg'=>1,'Bayern'=>2,'Berlin'=>3,'Brandenburg'=>4,'Bremen'=>5,
        'Hamburg'=>6,'Hessen'=>7,'Mecklenburg-Vorpommern'=>8,'Niedersachsen'=>9,
        'Nordrhein-Westfalen'=>10,'Rheinland-Pfalz'=>11,'Saarland'=>12,'Sachsen'=>13,
        'Sachsen-Anhalt'=>14,'Schleswig-Holstein'=>15,'Thüringen'=>16,
        'Alle'=>99,'Deutschlandweit'=>99
    ];

    $db->query("
        CREATE TABLE IF NOT EXISTS kassen_liste (
            id INT PRIMARY KEY,
            name VARCHAR(255),
            zusatzbeitrag DOUBLE,
            bundesland VARCHAR(255)
        );
    ");

    $db->query("DROP TABLE IF EXISTS kassen_liste_tmp");
    $db->query("CREATE TABLE kassen_liste_tmp LIKE kassen_liste");

    $rows = $wpdb->get_results("
        SELECT
            p.ID AS id,
            p.post_title AS name,
            MAX(CASE WHEN pm.meta_key='zusatzbeitrag' THEN pm.meta_value END) AS zusatzbeitrag,
            MAX(CASE WHEN pm.meta_key='bundesland' THEN pm.meta_value END) AS bundesland
        FROM wp_posts p
        INNER JOIN wp_postmeta pm1
            ON p.ID=pm1.post_id AND pm1.meta_key='aktiv' AND pm1.meta_value='1'
        LEFT JOIN wp_postmeta pm
            ON p.ID=pm.post_id
           AND pm.meta_key IN ('zusatzbeitrag','bundesland')
        WHERE p.post_type='krankenkasse'
          AND p.post_status='publish'
        GROUP BY p.ID,p.post_title
        ORDER BY p.post_title
    ", ARRAY_A);

    foreach ($rows as $r) {

        $ids = [];
        $raw = maybe_unserialize($r['bundesland']);

        foreach ((array)$raw as $val) {
            if (isset($bundeslandMap[$val])) $ids[] = $bundeslandMap[$val];
        }

        if (!$ids) $ids = [99];

        $csv = implode(',', array_unique($ids));

        $db->query(sprintf(
            "INSERT INTO kassen_liste_tmp VALUES (%d,'%s',%f,'%s')",
            (int)$r['id'],
            $db->real_escape_string($r['name']),
            (float)$r['zusatzbeitrag'],
            $db->real_escape_string($csv)
        ));
    }

    shadow_swap($db,'kassen_liste');
}

/**
 * ===========================
 * KASSEN WERBUNG
 * ===========================
 */
function export_kassen_werbung(mysqli $db) {

    global $wpdb;

    $db->query("
        CREATE TABLE IF NOT EXISTS kassen_werbung (
            kassen_id INT PRIMARY KEY,
            siegel JSON,
            topwerbung JSON
        );
    ");

    $db->query("DROP TABLE IF EXISTS kassen_werbung_tmp");
    $db->query("CREATE TABLE kassen_werbung_tmp LIKE kassen_werbung");

    $kassen = $wpdb->get_results("
        SELECT ID, post_title FROM wp_posts
        WHERE post_type='krankenkasse' AND post_status='publish'
    ");

    foreach ($kassen as $k) {

        $postId = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM wp_posts
             WHERE post_title=%s AND post_type='werbung' AND post_status='publish'",
            $k->post_title
        ));

        if (!$postId) continue;

        $siegel = [];

        for ($i=1;$i<=6;$i++) {
            $att = get_post_meta($postId,"sigel$i",true);
            if (is_numeric($att)) {
                $file = get_post_meta($att,'_wp_attached_file',true);
                if ($file) $siegel[] = $file;
            }
        }

        if (!$siegel) continue;

        $top = [];

        for ($i=1;$i<=2;$i++) {

            $raw = trim(get_post_meta($postId,"top_werbung".($i==1?'':'_2'),true));

            $top[$i] = $raw ?: 0;
        }

        $db->query(sprintf(
            "INSERT INTO kassen_werbung_tmp VALUES (%d,'%s','%s')",
            (int)$k->ID,
            $db->real_escape_string(json_encode($siegel)),
            $db->real_escape_string(json_encode($top))
        ));
    }

    shadow_swap($db,'kassen_werbung');
}

/**
 * ===========================
 * KASSEN LEISTUNGEN
 * ===========================
 */
function export_kassen_leistungen(mysqli $db) {

    global $wpdb;

    $db->query("
        CREATE TABLE IF NOT EXISTS kassen_leistungen (
            kassen_id INT,
            leistung_id INT,
            status TINYINT,
            description TEXT,
            additiv TEXT,
            PRIMARY KEY (kassen_id, leistung_id)
        );
    ");

    $db->query("DROP TABLE IF EXISTS kassen_leistungen_tmp");
    $db->query("CREATE TABLE kassen_leistungen_tmp LIKE kassen_leistungen");

    $rows = $wpdb->get_results("
        SELECT
            p.ID AS kassen_id,
            CAST(SUBSTRING(pm.meta_key,7,3) AS UNSIGNED) AS leistung_id,
            MAX(CASE WHEN pm.meta_key REGEXP '^field_[0-9]{3}a$' THEN pm.meta_value END) AS status,
            MAX(CASE WHEN pm.meta_key REGEXP '^field_[0-9]{3}b$' THEN pm.meta_value END) AS description,
            MAX(CASE WHEN pm.meta_key REGEXP '^field_[0-9]{3}c$' THEN pm.meta_value END) AS additiv
        FROM wp_posts p
        JOIN wp_postmeta pm ON p.ID=pm.post_id
        WHERE p.post_type='krankenkasse'
          AND p.post_status='publish'
          AND pm.meta_key REGEXP '^field_[0-9]{3}[abc]$'
        GROUP BY p.ID, leistung_id
        HAVING status IS NOT NULL
    ", ARRAY_A);

    foreach ($rows as $r) {

        $db->query(sprintf(
            "INSERT INTO kassen_leistungen_tmp VALUES (%d,%d,%d,'%s','%s')",
            (int)$r['kassen_id'],
            (int)$r['leistung_id'],
            (int)$r['status'],
            $db->real_escape_string($r['description'] ?? ''),
            $db->real_escape_string($r['additiv'] ?? '')
        ));
    }

    shadow_swap($db,'kassen_leistungen');
}

/**
 * ===========================
 * CATEGORIES
 * ===========================
 */
function export_categories(mysqli $db) {

    global $wpdb;

    $table = $wpdb->prefix . 'vergleich_cat';

    $db->query("
        CREATE TABLE IF NOT EXISTS categories (
            id INT PRIMARY KEY,
            label VARCHAR(255),
            description TEXT
        );
    ");

    $db->query("DROP TABLE IF EXISTS categories_tmp");
    $db->query("CREATE TABLE categories_tmp LIKE categories");

    $rows = $wpdb->get_results("
        SELECT
            grp AS id,
            MAX(grp_label) AS label,
            MAX(grp_desc) AS description
        FROM $table
        WHERE active = 1
        GROUP BY grp
        ORDER BY grp
    ", ARRAY_A);

    foreach ($rows as $r) {

        $db->query(sprintf(
            "INSERT INTO categories_tmp VALUES (%d,'%s','%s')",
            (int)$r['id'],
            $db->real_escape_string($r['label']),
            $db->real_escape_string($r['description'] ?? '')
        ));
    }

    shadow_swap($db,'categories');
}

// ================= RUN =================

try {

    echo "VGK24 export started\n";

    export_vgk24();

    $runtime = round(microtime(true) - $start, 2);
    echo "Finished in {$runtime}s\n";

} catch (Throwable $e) {

    echo "ERROR: ".$e->getMessage()."\n";

} finally {

    if (file_exists($lockFile)) unlink($lockFile);
}
