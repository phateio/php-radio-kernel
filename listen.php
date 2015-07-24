<?php
/******************************************************************************
                              Phate Radio Kernel
                                                    Req. PHP_VERSION >= '5.1.0'
*******************************************************************************/

define('IN_SYSTEM', true);
require_once './include/common.php';
require_once SYSTEM_ROOT.'/include/shmop.inc.php';
require_once SYSTEM_ROOT.'/include/db_ftp.class.php';
require_once SYSTEM_ROOT.'/include/db_pdo.func.php';

if (!defined('CONFIGURE_LOADED'))
    die('Load configure failed.');
if (!function_exists('time_sleep_until')) {
    print_log("Function 'time_sleep_until' not exists");
    die();
}
if (!function_exists('session_value')) {
    print_log("Function 'session_value' not exists");
    die();
}
if (!function_exists('posix_kill')) {
    print_log("Function 'posix_kill' not exists");
    die();
}

header('Expires: -1');
header('Cache-Control: private, max-age=0');
header('Content-Type: text/html; charset=utf-8');

$Client = array();
$Client['pid'] = getmypid();
$Client['uid'] = md5(uniqid(getmypid(), true));
$Client['ip'] = $_SERVER['REMOTE_ADDR'];
$Client['host'] = gethostbyaddr($_SERVER['REMOTE_ADDR']);
$Client['useragent'] = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : NULL;
$icy_metaint = isset($_SERVER['HTTP_ICY_METADATA']) ? ($_SERVER['HTTP_ICY_METADATA'] == '1') : false;

$inittime = time();
$isMaster = false;
$buffer = $cache;
$track_data = NULL;
$onlinedir = $cachedir.'/online';
if (!file_exists($onlinedir))
    @mkdir($onlinedir);
include SYSTEM_ROOT.'/config.override.php';

/*************************************************************************************************/

if (!isset($_GET['mode'])) {
    header("Location: {$homepage}");
    die();
}
if ($_GET['mode'] == 'info') {
    phpinfo();
    die();
}
if ($_GET['mode'] == 'key') {
    header('Content-Type: text/plain; charset=utf-8');
    die('1db63b390031a89b4b4bc9f8966fbc3b');
}
if ($_GET['mode'] != 'cast' || !isrunning()) {
    header("Location: {$mp3_503}");
    die();
}
if (!is_null($mp3_302)) {
    header("Location: {$mp3_302}");
    die();
}

/*************************************************************************************************/

$file_list = NULL;
$rand_file = NULL;
$ftp_ctrl = NULL;
$dbh = NULL;
onload_func();

$shm_key['writ'] = ftok(__FILE__, 't');
$shm_key['queu'] = ftok(__FILE__, 'u');
$shm_key['sync'] = ftok(__FILE__, 'v');

$mastlock = fopen(SYSTEM_ROOT.'/master.lock', 'w');
$sem_id['writ'] = sem_get($shm_key['writ'], 1, 0666, 0);
$sem_id['queu'] = sem_get($shm_key['queu'], 1, 0666, 0);
$sem_id['sync'] = sem_get($shm_key['sync'], 1, 0666, 0);

header("HTTP/1.0 200 OK");
header("Content-Type: audio/mpeg");
header("icy-br: {$track_bitrate}");
header("ice-audio-info: ice-samplerate={$track_sample_rate};ice-bitrate={$track_bitrate};ice-channels={$track_channels}");
header("icy-br: {$track_bitrate}", false);
header("icy-description: {$sername} {$version}");
header("icy-genre: JPop");
header("icy-name: {$sername}");
header("icy-private: 0");
header("icy-pub: 1");
header("icy-url: {$homepage}");

if ($icy_metaint)
    header("icy-metaint: {$client_frame_size}");

register_shutdown_function('shutdown_func');
pcntl_signal(SIGUSR1, "sig_handler", false);

/*************************************************************************************************/

for(;;) {
    $time_start = microtime(true);
    validate_connection();
    sem_acquire($sem_id['queu']);
    set_time_limit($timeout);

    /*************************************************************************************************/

    if (flock($mastlock, LOCK_EX | LOCK_NB)) {
        if ($isMaster) {
            sem_release($sem_id['sync']);
        } else {
            if (function_exists('pcntl_fork')) {
                $pid = pcntl_fork();
                if ($pid == -1) {
                    print_log('Function pcntl_fork() failed');
                } else if (!$pid) {
                    $Client['uid'] = false;
                    @header("Location: {$streamurl}");
                    exit();
                }
                $pid = NULL;
                $Client['ip'] = '127.0.0.1';
                $Client['host'] = '::1';
                $Client['useragent'] = 'localhost';
                onunload_func();
                onload_func();
            }
            $isMaster = true;
            session_init();
            session_value('update', 'config');
            session_value('update', 'ipdata');
        }

        /*************************************************************************************************/

        do {
            validate_connection();

            $islocal = false;
            $hashstr = $title = $artist = $source = NULL;
            $timepass = 0;

            $file_list = array();
            if (!$rand_file)
                $rand_file = array('hash' => '', 'title' => '', 'artist' => '', 'source' => '');
            if (!$ftp_ctrl)
                $ftp_ctrl = new clsftp();
            if (!$dbh) {
                $dbh = PDO_Inst();
            } else if ($dbh->errorCode() != '00000') {
                print_log("PDO errorCode: ".$dbh->errorCode());
                $dbh = PDO_Inst();
            }

            $sth = $dbh->prepare("SELECT `cdb_library`.`hash`, `title`, `artist`, `source`, `islocal`, `time` FROM `cdb_playlist`"
                               ." LEFT OUTER JOIN `cdb_library`"
                               ." ON `cdb_playlist`.`hash` = `cdb_library`.`hash` ORDER BY `cdb_playlist`.`id` ASC LIMIT 3");
            $sth->execute();
            $query = $sth->fetchAll(PDO::FETCH_ASSOC);
            foreach ($query as $item) {
                if (!count($file_list)) {
                    $hashstr = $item['hash'];
                    $title = $item['title'];
                    $artist = $item['artist'];
                    $source = $item['source'];
                    $islocal = $item['islocal'];
                    if ($item['time'])
                        $timepass = time() - $item['time'];
                }
                $file_list[] = array('source' => $item['source'], 'islocal' => $item['islocal']);
            }
            if ($source == $rand_file['source'])
                $rand_file['source'] = '';

            /*************************************************************************************************/

            $local_file = $ftp_ctrl::temp($source);
            if (!$islocal && (!count($file_list) || !file_exists($local_file))) {
                $hashstr = $rand_file['hash'];
                $title = $rand_file['title'];
                $artist = $rand_file['artist'];
                $source = $rand_file['source'];
                $file_list[] = array('source' => $rand_file['source'], 'islocal' => '0');
                $rand_file['source'] = NULL;
            }

            if (!$rand_file['source']) {
                $nowtime = time();
                $temp = NULL;

                $sth = $dbh->prepare("SELECT `hash`, `title`, `artist`, `source`, `count` FROM `cdb_library`"
                                   ." WHERE `cdb_library`.`hash` NOT IN (SELECT `hash` FROM `cdb_history`)"
                                   ." AND `hash` != :hash AND `islocal` = 0"
                                   ." ORDER BY `count` ASC, RAND() LIMIT 1");
                $sth->execute(array(':hash' => $hashstr));
                $query = $sth->fetchAll(PDO::FETCH_ASSOC);
                foreach ($query as $item) {
                    $rand_file['hash'] = $item['hash'];
                    $rand_file['title'] = $item['title'];
                    $rand_file['artist'] = $item['artist'];
                    $rand_file['source'] = $item['source'];
                }
                if (!$rand_file['source']) {
                    print_log('TRUNCATE TABLE `cdb_history`');
                    $dbh->query("TRUNCATE TABLE `cdb_history`");
                    usleep(5000);
                    continue;
                }
            }
            $file_list[] = array('source' => $rand_file['source'], 'islocal' => '0');

            /*************************************************************************************************/

            $local_file = $ftp_ctrl::temp($source);
            if ($islocal) {
                $local_file = SYSTEM_ROOT.$source;
                if (!file_exists($local_file)) {
                    print_log("Local file '{$source}' not exists");
                    $dbh->query("DELETE FROM `cdb_playlist` WHERE `time` = 0 ORDER BY `id` ASC LIMIT 1");
                    usleep(5000);
                    continue;
                }
            } else if ($source && file_exists($local_file)) {
                touch($local_file);
                if (!filesize($local_file)) {
                    print_log("Remote file '{$source}' not exists");
                    $dbh->query("DELETE FROM `cdb_playlist` WHERE `time` = 0 ORDER BY `id` ASC LIMIT 1");
                    usleep(5000);
                    continue;
                }
            } else {
                $sth = $dbh->prepare("SELECT `hash`, `title`, `artist`, `source` FROM `cdb_library` WHERE `islocal` = 1 ORDER BY RAND() LIMIT 1");
                $sth->execute();
                $query = $sth->fetchAll(PDO::FETCH_ASSOC);
                foreach ($query as $item) {
                    $hashstr = $item['hash'];
                    $title = $item['title'];
                    $artist = $item['artist'];
                    $source = $item['source'];
                    $local_file = SYSTEM_ROOT.$source;
                }
            }

            if ($track_position + $server_frame_dps*$timepass > filesize($local_file)) {
                usleep(1000);
                continue;
            }

            /*************************************************************************************************/

            session_value('refresh', 'ipdata');
            $onlines = count($shm_var['ipdata']['ipdata']);
            $nowtime = time();
            try {
                $dbh->beginTransaction();
                //$dbh->query("DELETE FROM `cdb_playlist` WHERE `time` > 0");
                //$sth = $dbh->prepare("UPDATE `cdb_library` SET `count` = `count` + 1 WHERE `hash` = :hash");
                //$sth->execute(array(':hash' => $hashstr));
                //$sth = $dbh->prepare("INSERT INTO `cdb_history` (`time`, `hash`) VALUES (:time, :hash)");
                //$sth->execute(array(':time' => $nowtime, ':hash' => $hashstr));
                $sth = $dbh->prepare("INSERT INTO `cdb_variables` (`key`, `value`) VALUES ('onlines', :onlines) ON DUPLICATE KEY UPDATE `value` = :onlines");
                $sth->execute(array(':onlines' => $onlines));
                //$sth = $dbh->prepare("INSERT INTO `cdb_playlist` (`time`, `hash`) VALUES (:time, :hash) ON DUPLICATE KEY UPDATE `time` = :time");
                //$sth->execute(array(':time' => $nowtime, ':hash' => $hashstr));
                $dbh->query("OPTIMIZE TABLE `cdb_playlist`");
                $dbh->commit();
                break;
            } catch(PDOExecption $e) {
                $dbh->rollback();
                print_log($e->getMessage());
                break;
            }
        } while (true);

        /*************************************************************************************************/

        $shm_var['config']['title'] = $title;
        $shm_var['config']['artist'] = $artist;
        $shm_var['config']['foload'] = $local_file;
        $shm_var['config']['pivot'] = $track_position + $server_frame_dps*$timepass;
        session_value('update', 'config');

        /*************************************************************************************************/

        if (file_exists($ftp_ctrl::$temp_file))
            touch($ftp_ctrl::$temp_file);
        foreach ($file_list as $remote_file) {
            $local_file = $ftp_ctrl::temp($remote_file['source']);
            if (file_exists($local_file))
                touch($local_file);
        }
        $ftp_ctrl::clear();

        sem_acquire($sem_id['sync']);
    }

    /*************************************************************************************************/

    online_status(true, number_format(microtime(true) - $time_start, 2, '.', ''));
    sem_release($sem_id['queu']);

    session_value('refresh', 'config');
    $rawpath = $shm_var['config']['foload'];
    $datapath = $rawpath;
    if (!file_exists($datapath) || is_dir($datapath)) {
        print_log("Stream file '{$datapath}' not found");
        usleep(5000);
        continue;
    }

    $a = $shm_var['config']['pivot'];
    if (!$isMaster) {
        if ($buffer || !$cache) {
            // First time or reach the master
            $a -= $server_frame_dps*($buffer + $deviation);
            if ($a < $track_position)
                $a = $track_position;
        } else {
            // Normal check
            if ($a > $server_frame_dps*($cache + $deviation)) {
                // Speed up the procedure of client
            }
            $a = $track_position;
        }
    }

    $artist = preg_replace("/(﹑.+?﹑.+?﹑.+?)﹑.+$/i", "$1﹑……", $shm_var['config']['artist']);
    $longtitle = ($artist ? $artist.' - ' : '').$shm_var['config']['title'];

    /*************************************************************************************************/

    $filedata = fopen($datapath, 'rb');
    fseek($filedata, $a, SEEK_SET);

    // Research the position.
    $offset = strpos(fread($filedata, $track_frame_size), $track_sync);
    if ($offset === false)
        print_log("Stream file '{$datapath}'({$longtitle}) frame sync not found from \$a[{$a}]");
    if ($offset)
        $a += $offset;
    fseek($filedata, $a, SEEK_SET);
    $time_wake_up = time();

    /*************************************************************************************************/

    for(;;) {
        validate_connection();
        if (!feof($filedata)) {
            $track_data .= fread($filedata, $server_frame_size);
            stream_cast($track_data);
            fread($filedata, 1);
            if (feof($filedata)) {
                break;
            } else {
                fseek($filedata, -1, SEEK_CUR);
            }
            while ($isMaster && $time_wake_up + $server_frame_fps >= time()) {
                if (!load_remote_file($file_list))
                    break;
                usleep(1000);
            }
        }
    }
    fclose($filedata);
    $filedata = NULL;

    /*************************************************************************************************/

    if (!$isMaster) {
        session_value('refresh', 'config');
        if ($rawpath == $shm_var['config']['foload']) {
            //$buffer += $cache;
            sem_acquire($sem_id['sync']);
            sem_release($sem_id['sync']);
            //sleep($cache + $deviation);
        }
    }
}

/*************************************************************************************************/

function isrunning()
{
    global $runlock;
    return file_exists($runlock);
}

function onload_func()
{
    global $onlinedir, $Client, $inittime;
    $olfile = $onlinedir.'/'.$Client['uid'];
    if (file_exists($olfile))
        print_log("Client file '{$olfile}' duplicated");
    $data = array('uid' => $Client['uid']
                , 'pid' => $Client['pid']
                , 'ip' => $Client['ip']
                , 'host' => $Client['host']
                , 'useragent' => $Client['useragent']
                , 'inittime' => $inittime
    );
    file_put_contents($olfile, serialize($data));
}

function onunload_func()
{
    global $onlinedir, $Client;
    if ($Client['uid']) {
        $olfile = $onlinedir.'/'.$Client['uid'];
        unlink($olfile);
    }
}

function shutdown_func()
{
    online_status(false);
    onunload_func();
    if (memory_get_usage(true) > PHP_MAX_MEMORY_USAGE) {
        posix_kill(posix_getpid(), SIGTERM);
    }
    exit();
}

function validate_connection()
{
    pcntl_signal_dispatch();
    global $isMaster, $sem_id;
    //if (!isrunning() || ($isMaster ? false : connection_aborted())) {
    if ($isMaster ? false : connection_aborted()) {
        if ($isMaster) {
            sem_release($sem_id['sync']);
        }
        exit();
    }
}

function sig_handler($signo)
{
    global $isMaster, $sem_id;
    switch ($signo) {
        case SIGHUP:
            break;
        case SIGUSR1:
            if ($isMaster) {
                sem_release($sem_id['sync']);
            }
            exit();
            break;
        default:
    }
}

function online_status($include_self, $msg = NULL)
{
    global $Client, $sem_id, $isMaster, $shm_var;
    sem_acquire($sem_id['writ']);
    session_value('refresh', 'ipdata');
    $row = $shm_var['ipdata']['ipdata'];
    $data = array();

    if ($include_self) {
        $data[] = array('tid' => time()
                      , 'uid' => $Client['uid']
                      , 'msg' => $msg
        );
    }

    if (is_array($row)) {
        foreach ($row as $col) {
            if ($col['uid'] != $Client['uid'])
                $data[] = $col;
        }
    }

    $shm_var['ipdata']['ipdata'] = $data;
    session_value('update', 'ipdata');
    sem_release($sem_id['writ']);
}

function stream_cast(&$track_data)
{
    global $isMaster, $client_frame_size, $server_frame_fps, $cache, $buffer, $icy_metaint, $shm_var, $longtitle, $a, $time_wake_up;
    while (strlen($track_data) >= $client_frame_size) {
        validate_connection();
        if (!$isMaster) {
            echo(substr($track_data, 0, $client_frame_size));
            if ($icy_metaint) {
                if ($longtitle) {
                    $metaint = sprintf("StreamTitle='%s';\x00", substr(str_replace(array(';', "'"), array('；', "`"), $longtitle), 0, 4064));
                    $metaint_prefix = ceil(strlen($metaint)/16);
                    $metaint_len = $metaint_prefix*16;
                    echo(chr($metaint_prefix).str_pad($metaint, $metaint_len, "\x00", STR_PAD_RIGHT));
                    $longtitle = NULL;
                } else {
                    echo("\x00");
                }
            }
        }
        $track_data = substr($track_data, $client_frame_size);
        if ($isMaster) {
            $a += $client_frame_size;
            $shm_var['config']['pivot'] = $a;
            session_value('update', 'config');
            //usleep($client_frame_usec);
            $time_wake_up += $server_frame_fps;
            @time_sleep_until($time_wake_up);
        } else {
            if ($buffer > 0) {
                $buffer--;
            } else {
                //usleep($client_frame_usec);
                $time_wake_up += $server_frame_fps;
                if (time() > $time_wake_up + $cache*2)
                    exit();
                @time_sleep_until($time_wake_up);
            }
        }
    }
}

function load_remote_file($remote_file_list)
{
    global $ftp_ctrl;
    foreach ($remote_file_list as $remote_file) {
        $local_file = $ftp_ctrl::temp($remote_file['source']);
        if (file_exists($local_file) || $remote_file['islocal'])
            continue;
        if (!$ftp_ctrl::isbusy()) {
            if ($ftp_ctrl::file_exists($remote_file['source']) === false) {
                touch($local_file);
            } else if ($ftp_ctrl::query($local_file, $remote_file['source']) === false) {
                //print_log("Failed to retrieve file '{$remote_file['source']}'");
            }
        }
        return true;
    }
    return false;
}
