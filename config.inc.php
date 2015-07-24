<?php
if (!defined('IN_SYSTEM'))
    exit('Access Denied');
define('CONFIGURE_LOADED', true);
define('PHP_MAX_MEMORY_USAGE', 1.8*1024*1024);

$runlock = SYSTEM_ROOT.'/running.lock';
$sername = 'Phate Radio';
$version = 'v1.2';
$homehost = SYSTEM_HOST;
$homepage = sprintf("%s://%s/", (isset($_SERVER['HTTPS']) ? 'https' : 'http'), SYSTEM_HOST);
$streamurl = sprintf("%s://%s%s", (isset($_SERVER['HTTPS']) ? 'https' : 'http'), SYSTEM_HOST, $_SERVER['REQUEST_URI']);
$cachedir = SYSTEM_ROOT.'/tmp/cache';

$track_bitrate = 192;
$track_sample_rate = 44100;
$track_channels = 2;
$track_padding = 0;
$track_frame_size = round(144*($track_bitrate*1000)/($track_sample_rate + $track_padding));
$client_frame_size = 24000;
$server_frame_size = $track_frame_size; // frame size per read
$server_frame_dps = $track_bitrate*1000/8; // frame size per second
$server_frame_fps = $client_frame_size/$server_frame_dps; // frames per second
$track_position = 100;
$track_sync = "\xFF\xFB";

$mp3_302 = NULL;
$mp3_503 = 'http://ge.tt/api/1/files/5HjI81L2/0/blob';

$timeout = 1200;
$cache = 8;
