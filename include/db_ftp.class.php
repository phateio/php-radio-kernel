<?php
if (!defined('IN_SYSTEM'))
    exit('Access Denied');

class clsftp
{
    public static $fp = NULL;
    public static $cid = NULL;
    public static $ret = NULL;
    public static $root = '';
    public static $prefix = 'db_';
    public static $suffix = '.tmp';
    public static $size = 0;
    public static $counts = 0;
    public static $temp_file = '';
    public static $local_file = '';
    public static $cachedir = '/tmp/cache';
    public static $filelock = '';

    function __construct($filelock = '')
    {
        self::$filelock = $filelock;
    }

    function islogin()
    {
        clearstatcache();
        if (!self::$cid)
            return false;
        $ret = @ftp_raw(self::$cid, 'NOOP '.time());
        if (!(is_array($ret) && count($ret)))
            return false;
        return preg_match('/^200.*$/', $ret[0]) > 0;
    }

    function login()
    {
        require SYSTEM_ROOT.'/config.remote.php';
        if (!(self::$cid = @ftp_connect($ftp_host, $ftp_port, $ftp_timeout))) {
            //self::halt("Can't connect to FTP server on '$ftp_host'");
            return false;
        }
        if (!@ftp_login(self::$cid, $ftp_user, $ftp_pass)) {
            //self::halt("Can't login to FTP server on '$ftp_host' with username '$ftp_user'");
            return false;
        }
        self::$root = $ftp_root;
        $ftp_host = $ftp_user = $ftp_pass = $ftp_root = $ftp_port = NULL;
        ftp_set_option(self::$cid, FTP_TIMEOUT_SEC, $ftp_timeout);
        ftp_pasv(self::$cid, true);
        if (self::islogin()) {
            return true;
        } else {
            //self::halt("Can't query from FTP server");
            return false;
        }
    }

    function temp($remote_file)
    {
        return SYSTEM_ROOT.self::$cachedir.'/'.self::$prefix.md5($remote_file);
    }

    function query($local_file, $remote_file)
    {
        if (!self::islogin())
            if (!self::login())
                return 0;
        self::$temp_file = $local_file.self::$suffix;
        self::$local_file = $local_file;
        //self::$ret = @ftp_nb_get(self::$cid, self::$temp_file, self::$root.$remote_file, FTP_BINARY);
        self::$fp = fopen(self::$temp_file, 'wb');
        self::$ret = @ftp_nb_fget(self::$cid, self::$fp, self::$root.$remote_file, FTP_BINARY);
        return self::$ret != FTP_FAILED;
    }

    function isbusy()
    {
        $result = false;
        do {
            if (self::$ret != FTP_MOREDATA)
                break;
            self::$ret = @ftp_nb_continue(self::$cid);
            switch (self::$ret) {
                case FTP_MOREDATA:
                    clearstatcache();
                    $size = filesize(self::$temp_file);
                    if (self::$size == $size && ++self::$counts > 10) {
                        self::$size = 0;
                        self::$counts = 0;
                        self::close();
                        break 2;
                    }
                    self::$size = $size;
                    return true;
                case FTP_FINISHED:
                    self::$size = 0;
                    self::$counts = 0;
                    rename(self::$temp_file, self::$local_file);
                    self::close();
                    $result = true;
                    break 2;
                default: // FTP_FAILED
                    self::$size = 0;
                    self::$counts = 0;
                    self::halt("Lost connection to FTP server during query");
                    break 2;
            }
        } while (false);

        if (self::$fp) {
            fclose(self::$fp);
            self::$fp = NULL;
        }
        return $result;
    }

    function reload($directory, &$file_list, $timeout)
    {
        $errmsg = array();
        do {
            set_time_limit($timeout);
            if (self::connection_aborted()) {
                self::halt('Lost connection with client during query');
                return $errmsg;
            }
            $rlist = self::ftp_mlsd($directory);
        } while ($rlist === false);
        foreach ($rlist as $file) {
            $remote_file = $directory.'/'.$file['filename'];
            if ($file['type'] == 'file') {
                if (substr($remote_file, -4) == '.rar') {
                    $meta_file = substr($remote_file, 0, -4).'.txt';
                    do {
                        set_time_limit($timeout);
                        if (self::connection_aborted()) {
                            self::halt('Lost connection with client during query');
                            return $errmsg;
                        }
                        $text = self::ftp_get_contents($meta_file);
                        if ($text === false && self::file_exists($meta_file) === false) {
                            $errmsg[] = "'$meta_file' meta file not found\r\n";
                            break;
                        }
                    } while ($text === false);
                    $meta = explode("\r\n", $text);
                    if (count($meta) != 4) {
                        $errmsg[] = "'$meta_file' meta file error\r\n";
                        continue;
                    }
                    $file_list[] = array('source' => $remote_file
                                       , 'hash' => $file['unique']
                                       , 'size' => $file['size']
                                       , 'mtime' => strtotime($file['modify'].'UTC')
                                       , 'meta' => $meta);
                }
            } else if ($file['type'] == 'dir') {
                $errmsg = array_merge($errmsg, self::reload($remote_file, $file_list, $timeout));
            }
        }
        return $errmsg;
    }

    function ftp_get_contents($remote_file)
    {
        if (!self::islogin())
            if (!self::login())
                return false;
        $fp = fopen('php://temp', 'r+');
        if (!$fp || !@ftp_fget(self::$cid, $fp, $remote_file, FTP_BINARY))
            return false;
        rewind($fp);
        return stream_get_contents($fp);
    }

    function ftp_mlsd($directory)
    {
        require SYSTEM_ROOT.'/config.remote.php';
        if (!self::islogin())
            if (!self::login())
                return false;
        if (!@ftp_chdir(self::$cid, $directory))
            return false;
        $ret = ftp_raw(self::$cid, 'PASV');
        if (!count($ret))
            return false;
        if (!preg_match('/^227.*\(([0-9]+,[0-9]+,[0-9]+,[0-9]+),([0-9]+),([0-9]+)\)$/', $ret[0], $matches))
            return false;
        $conn_IP = str_replace(',', '.', $matches[1]);
        $conn_Port = intval($matches[2])*256 + intval($matches[3]);
        $socket = @fsockopen($conn_IP, $conn_Port, $errno, $errstr, $ftp_timeout);
        if (!$socket)
            return false;
        stream_set_timeout($socket, $ftp_timeout);
        ftp_raw(self::$cid, 'MLSD');
        $s = '';
        while (!feof($socket)) {
            $s .= fread($socket, 1024);
            $stream_meta_data = stream_get_meta_data($socket);
            if ($stream_meta_data['timed_out']) {
                fclose($socket);
                return false;
            }
        }
        fclose($socket);
        $files = array();
        foreach (explode("\n", $s) as $line) {
            if (!$line)
                continue;
            $file = array();
            $elements = explode(';', $line, 8);
            $cnt = count($elements);
            if ($cnt > 0) {
                $cnt--;
                $file['filename'] = trim($elements[$cnt]);
                for ($i=0; $i<$cnt; $i++) {
                    if ($i < $cnt) {
                        $attribute = explode('=', $elements[$i]);
                        $file[$attribute[0]] = $attribute[1];
                    }
                }
            }
            $files[] = $file;
        }
        return $files;
    }

    function connection_aborted()
    {
        echo("\n");
        @ob_flush();
        flush();
        return connection_aborted() || !file_exists(self::$filelock);
    }

    function file_exists($remote_file)
    {
        if (!self::islogin())
            if (!self::login())
                return 0;
        $directory = dirname($remote_file);
        $filename = basename($remote_file);
        $contents = self::ftp_mlsd($directory);
        if (!is_array($contents)) {
            if (self::islogin())
                if (!@ftp_chdir(self::$cid, $directory))
                    return false;
            return 0;
        }
        foreach ($contents as $haystack) {
            if ($haystack['filename'] == $filename && $haystack['type'] == 'file')
                return true;
        }
        return false;
    }

    function clear()
    {
        clearstatcache();
        $dh = opendir(SYSTEM_ROOT.self::$cachedir);
        while ($file = readdir($dh)) {
            if ($file == '.' || $file == '..')
                continue;
            $local_file = SYSTEM_ROOT.self::$cachedir.'/'.$file;
            if (!is_file($local_file))
                continue;
            if (time() > fileatime($local_file) + 30)
                unlink($local_file);
        }
        closedir($dh);
        return true;
    }

    function close()
    {
        if (!self::$cid)
            return false;
        ftp_close(self::$cid);
        self::$cid = NULL;
        return true;
    }

    function info($str)
    {
        print_log($str);
    }

    function halt($str)
    {
        self::close();
        print_log($str);
    }
}
