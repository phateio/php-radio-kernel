<?php
if (!defined('IN_SYSTEM'))
    exit('Access Denied');

function PDO_Inst()
{
    require SYSTEM_ROOT.'/config.mysql.php';
    try {
        return new PDO("mysql:host={$dbhost};dbname={$dbname}", $dbuser, $dbpass, array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
    } catch (PDOException $Exception) {
        print_log($Exception->getMessage());
    }
}
