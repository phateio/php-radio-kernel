<?php
if (!defined('IN_SYSTEM'))
    exit('Access Denied');

define('SYSTEM_ROOT', substr(dirname(__FILE__), 0, -8));
define('SYSTEM_HOST', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']);
define('SYSTEM_URL', (isset($_SERVER['HTTPS']) ? 'https' : 'http').'://'.SYSTEM_HOST.dirname($_SERVER['PHP_SELF']));

ignore_user_abort(true);
ini_set('display_errors', 'Off');
ini_set('error_reporting', E_ALL);
ini_set('log_errors', 'On');
ini_set('error_log', SYSTEM_ROOT.'/tmp/errorlog_sys.txt');
if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
    function magicQuotes_awStripslashes(&$value, $key) {$value = stripslashes($value);}
    $gpc = array(&$_GET, &$_POST, &$_COOKIE, &$_REQUEST);
    array_walk_recursive($gpc, 'magicQuotes_awStripslashes');
}
if (!function_exists('sem_get')) {
    function sem_get($key) {return fopen(SYSTEM_ROOT.'/tmp/sem_0x'.dechex($key), 'w');}
    function sem_acquire($sem_identifier) {return flock($sem_identifier, LOCK_EX);}
    function sem_release($sem_identifier) {return flock($sem_identifier, LOCK_UN);}
}
ini_set('session.save_path', SYSTEM_ROOT.'/tmp');
ini_set('session.cookie_lifetime', '31536000');
ini_set('session.gc_maxlifetime', '31536000');
ini_set('magic_quotes_runtime', 'Off');
session_cache_limiter('nocache');
session_cache_expire(525600); // minutes
session_name('VIEWSTATE');

$timezone = 8;
$isGzip = isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip');

ob_implicit_flush(false);
if (!count(ob_list_handlers()))
    ob_start($isGzip ? 'ob_gzhandler' : NULL, 4096);

require_once SYSTEM_ROOT.'/config.inc.php';
require_once SYSTEM_ROOT.'/include/fragtable/loader.php';

function print_log($message)
{
    global $timezone;
    $datetime = gmdate('Y-m-d H:i:s', time() + $timezone*3600);
    $data = $datetime.' | '.trim(str_replace(array("\r", "\n"), ' ', $message))."\n";
    file_put_contents(SYSTEM_ROOT.'/tmp/errorlog.txt', $data, FILE_APPEND|LOCK_EX);
}
