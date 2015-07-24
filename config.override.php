<?php
if (!defined('IN_SYSTEM'))
    exit('Access Denied');

//$deviation = $icy_metaint ? rand(4, 8) : rand(1, 4); // Seconds
$deviation = 2; // Seconds

if (strpos($Client['useragent'], 'PSP') !== false) { // PSPRadio/1.18.1400
    $icy_metaint = false;
    header('icy-metaint: false');
} else if ($_GET['mode'] == 'cast') {
//    if (strpos($Client['useragent'], 'Mozilla') !== false || number_format(microtime(true) - $_SERVER['REQUEST_TIME'], 6) > 1.0) {
//        header("Location: http://stream.phate.io/phatecc");
//    } else {
//        $REQUEST_URI = isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 7) : '';
//        header("Location: http://stream.phate.io/phatecc{$REQUEST_URI}");
//    }
    $REQUEST_URI = isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 7) : '';
    header("Location: http://stream.phate.io/phatecc{$REQUEST_URI}");
    die();
}
