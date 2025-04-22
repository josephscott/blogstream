<?php
declare( strict_types = 1 );

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker( 'http://0.0.0.0:39999' );
$worker->count = 4;

// Track shared state
$worker->clients = [];
$worker->blogs_connection = null;
$worker->buffer = '';
