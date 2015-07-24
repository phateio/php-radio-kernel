<?php
if(!defined('IN_SYSTEM'))
    exit('Access Denied');

class clsdb{
    public static $version = '';
    public static $querynum = 0;
    public static $link = NULL;
    public static $errstr = '存取資料庫時發生錯誤 (,,ﾟДﾟ)ζ';

    function connect(){
        require SYSTEM_ROOT.'/config.mysql.php';
        $func = 'mysql_connect';
        $dbcharset = 'utf8';
        if(self::$link = @$func($dbhost, $dbuser, $dbpass, 1)){
            if(self::version() > '4.1'){
                $serverset = sprintf("character_set_connection=%s,character_set_results=%s,character_set_client=binary", $dbcharset, $dbcharset);
                if(self::version() > '5.0.1')
                    $serverset .=  ",sql_mode=''";
                mysql_query("SET $serverset", self::$link);
            }
            mysql_select_db($dbname, self::$link);
        }else{
            self::halt('Can not connect to MySQL server');
        }
    }

    function query($sql, $type = NULL){
        if(!($query = @mysql_query($sql, self::$link))){
            if(in_array(self::errno(), array(2006, 2013)) && $type == NULL){
                self::close();
                self::connect();
                self::query($sql, 'RETRY');
            }else{
                self::halt('An error encountered during MySQL query', $sql);
            }
        }
        self::$querynum++;
        return $query;
    }

    function affected_rows(){
        return mysql_affected_rows(self::$link);
    }

    function error(){
        return (self::$link) ? mysql_error(self::$link) : mysql_error();
    }

    function errno(){
        return intval((self::$link) ? mysql_errno(self::$link) : mysql_errno());
    }

    function version(){
        if(!self::$version)
            self::$version = mysql_get_server_info(self::$link);
        return self::$version;
    }

    function close(){
        return self::$link && mysql_close(self::$link);
    }

    function halt($message = '', $sql = ''){
        $error = self::error();
        $errno = self::errno();
        $errmsg = "MySQL: [$errno] $message | SQL: \"$sql\" | Error: $error";
        print_log($errmsg);
    }
}
