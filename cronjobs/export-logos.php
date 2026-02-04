<?php
/**
 * Export + normalize Krankenkasse logos to API folder (WHITE BACKGROUND)
 * Filename format: kassenid-0.webp
 */

// ================= BASIC SETTINGS =================
ini_set('display_errors', 0); // set to 1 while debugging
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

/* ================= CONFIG ================= */

$targetDir = $logoOutputPath;
$maxW = $logoMaxWidth;
$maxH = $logoMaxHeight;

/* ========================================= */

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

/* ---------- Convert function ---------- */

function convertLogo($url, $targetFile, $maxW, $maxH) {

    $data = @file_get_contents($url);
    if (!$data) return false;

    $src = @imagecreatefromstring($data);
    if (!$src) return false;

    $w = imagesx($src);
    $h = imagesy($src);

    // STEP 1 — FORCE FLATTEN TO WHITE
    $flat = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($flat, 255,255,255);
    imagefilledrectangle($flat, 0,0,$w,$h,$white);
    imagecopy($flat, $src, 0,0,0,0,$w,$h);

    imagedestroy($src);
    $src = $flat;

    // STEP 2 — AUTO TRIM WHITE BORDERS
    $bg = imagecolorallocate($src,255,255,255);

    $top=0;
    for($y=0;$y<$h;$y++){
        for($x=0;$x<$w;$x++){
            if(imagecolorat($src,$x,$y)!=$bg){break 2;}
        }
        $top++;
    }

    $bottom=$h-1;
    for($y=$h-1;$y>=0;$y--){
        for($x=0;$x<$w;$x++){
            if(imagecolorat($src,$x,$y)!=$bg){break 2;}
        }
        $bottom--;
    }

    $left=0;
    for($x=0;$x<$w;$x++){
        for($y=0;$y<$h;$y++){
            if(imagecolorat($src,$x,$y)!=$bg){break 2;}
        }
        $left++;
    }

    $right=$w-1;
    for($x=$w-1;$x>=0;$x--){
        for($y=0;$y<$h;$y++){
            if(imagecolorat($src,$x,$y)!=$bg){break 2;}
        }
        $right--;
    }

    $cw=$right-$left+1;
    $ch=$bottom-$top+1;

    if($cw>0 && $ch>0){
        $trim=imagecreatetruecolor($cw,$ch);
        imagecopy($trim,$src,0,0,$left,$top,$cw,$ch);
        imagedestroy($src);
        $src=$trim;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    // STEP 3 — FINAL CANVAS
    $dst=imagecreatetruecolor($maxW,$maxH);
    $white=imagecolorallocate($dst,255,255,255);
    imagefilledrectangle($dst,0,0,$maxW,$maxH,$white);

    $scale=min($maxW/$w,$maxH/$h);
    $nw=(int)($w*$scale);
    $nh=(int)($h*$scale);

    $x=0; // LEFT aligned
    $y=(int)(($maxH-$nh)/2);

    imagecopyresampled($dst,$src,$x,$y,0,0,$nw,$nh,$w,$h);

    imagewebp($dst,$targetFile,80);

    imagedestroy($src);
    imagedestroy($dst);

    return true;
}

/* ---------- Load Krankenkassen ---------- */

$posts = get_posts([
    'post_type' => 'krankenkasse',
    'posts_per_page' => -1
]);

$count = 0;

foreach ($posts as $p) {

    $thumb = get_the_post_thumbnail_url($p->ID, 'full');
    if (!$thumb) continue;

    $out = rtrim($targetDir,'/').'/'.$p->ID.'-0.webp';

    if (convertLogo($thumb, $out, $maxW, $maxH)) {
        $count++;
    }
}

echo "DONE: $count logos exported\n";
exit(0);
