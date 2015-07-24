<?php
if (!defined('IN_SYSTEM'))
    exit('Access Denied');

class Fragtable
{
    public static $threshold = 90; //MAX: 99
    public static $intervsl = 60;
    public static $timezone = 8;
    public static $mailto = 'jrs@phate.org';

    function __construct() {}

    function execute()
    {
        set_error_handler('fragtable_error_handler');
        define('FRAGTABLE_ROOT', dirname(__FILE__));
        $HTTP_HOST = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'NULL';
        $HTTP_USER_AGENT = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'NULL';
        if (!isset($_SERVER['REQUEST_URI']))
            trigger_error("'REQUEST_URI' not found", E_USER_WARNING);
        $salt = $HTTP_USER_AGENT.$_SERVER['REQUEST_URI'];
        $hashi = hexdec(substr(hash('crc32b', $salt), 0, 2));

        $subject = "[{$HTTP_HOST}] DDoS WARNING!!";
        $accucnt = 0;
        $logfile = FRAGTABLE_ROOT.'/secure.log';

        $shm_key['data'] = ftok(__FILE__, 't');
        $shm_key['lock'] = ftok(__FILE__, 'u');
        $sem_id = sem_get($shm_key['lock']);
        $shm_size = 9626;
        sem_acquire($sem_id);
        $shm_id = shmop_open($shm_key['data'], 'c', 0644, $shm_size);
        $shm_data = shmop_read($shm_id, 0, $shm_size);
        $fragtable = @unserialize($shm_data);
        if (!$fragtable)
            $fragtable = array();
        $nowtime = time();
        if (isset($fragtable[$hashi]) && $nowtime < $fragtable[$hashi][0] + self::$intervsl) {
            $accucnt = $fragtable[$hashi][1] < 99 ? $fragtable[$hashi][1] + 1 : $fragtable[$hashi][1];
            $acctime = $fragtable[$hashi][0];
        } else {
            $accucnt = 1;
            $acctime = $nowtime;
        }
        $fragtable[$hashi] = array($acctime, $accucnt);
        $shm_data = serialize($fragtable);
        shmop_write($shm_id, str_pad($shm_data, $shm_size, "\x00", STR_PAD_RIGHT), 0);
        shmop_close($shm_id);
        sem_release($sem_id);
        $fragtable = $shm_data = NULL;

        if ($accucnt > self::$threshold) {
            if (!file_exists($logfile) || filesize($logfile) < 10*1024*1024) {
                $message = sprintf("%s | %d | %d | %s | %s | %s\n"
                                , gmdate('Y-m-d H:i:s', $nowtime + self::$timezone*3600)
                                , $acctime
                                , $hashi
                                , str_pad($_SERVER["REMOTE_ADDR"], 15, ' ', STR_PAD_RIGHT)
                                , "{$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']} {$_SERVER['SERVER_PROTOCOL']}"
                                , $HTTP_USER_AGENT);
                if (!file_exists($logfile) || $nowtime > filemtime($logfile) + 3600)
                    @mail(self::$mailto, $subject, $message);
                file_put_contents($logfile, $message, FILE_APPEND|LOCK_EX);
            }
            header('HTTP/1.1 503 Service Temporarily Unavailable');
            die('<h1>Service Temporarily Unavailable</h1>');
        }
        restore_error_handler();
    }
}

function fragtable_error_handler($errno, $errstr)
{
    if (!(error_reporting() & $errno))
        return;
    header('HTTP/1.1 500 Internal Server Error');
    error_log("FragTable: [$errno] $errstr");
}

$fragtable_ctrl = new Fragtable;
$fragtable_ctrl::execute();
$fragtable_ctrl = NULL;
