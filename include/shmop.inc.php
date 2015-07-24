<?php
if (!defined('IN_SYSTEM'))
    exit('Access Denied');

$shm_var = array();
$shm_key = array();
$sem_id = array();
$shm_key['config'] = ftok(__FILE__, 't');
$shm_key['ipdata'] = ftok(__FILE__, 'u');
$shm_key['shlock'] = ftok(__FILE__, 'v');
$sem_id['shlock'] = sem_get($shm_key['shlock'], 1, 0666, 0);

function session_init()
{
    global $shm_var;
    $shm_var['config'] = array('artist'    =>    'Artist'
                             , 'title'    =>    'Title'
                             , 'foload'    =>    ''
                             , 'pivot'    =>    0
    );
    $shm_var['ipdata'] = array('ipdata'    =>    array());
}

function session_value($name, $index)
{
    global $shm_key, $shm_var, $sem_id;
    switch ($index) {
        case 'config':
            $shm_size = 859;
            break;
        case 'ipdata':
            $shm_size = 30050;
            break;
        default:
            $shm_size = 0;
    }
    sem_acquire($sem_id['shlock']);
    $shm_id = shmop_open($shm_key[$index], 'c', 0644, $shm_size);
    if ($name == 'update') {
        $shm_data = serialize($shm_var[$index]);
        shmop_write($shm_id, str_pad($shm_data, $shm_size, "\x00", STR_PAD_RIGHT), 0);
    } else {
        $shm_data = shmop_read($shm_id, 0, $shm_size);
        $shm_var[$index] = @unserialize($shm_data);
    }
    shmop_close($shm_id);
    sem_release($sem_id['shlock']);
}
