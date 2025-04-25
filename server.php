<?php
declare( strict_types = 1 );

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker( 'http://0.0.0.0:39999' );
$worker->count = 1;

// Track shared state
$worker->clients = [];
$worker->blogs_connection = null;
$worker->buffer = '';

// CORS headers
function add_cors_headers( array $headers = [] ): array {
	$headers = array_merge( [
		'Access-Control-Allow-Origin' => '*',
		'Access-Control-Allow-Methods' => 'GET, OPTIONS',
		'Access-Control-Allow-Headers' => 'Content-Type, Accept',
		'Access-Control-Max-Age' => '86400',
	], $headers );

	return $headers;
}

$worker->onWorkerStart = function ( Worker $worker ) {
	// Shared connection per worker to ping.blo.gs
	$worker->blogs_connection = new AsyncTcpConnection( 'tcp://ping.blo.gs:29999' );

	// Data from ping.blo.gs
	$worker->blogs_connection->onMessage = function(
		AsyncTcpConnection $connection,
		$data
	) use ( $worker ) {
		$worker->buffer .= $data;

		// We need a complete line
		while ( ( $pos = strpos( $worker->buffer, "\n" ) ) !== false ) {
		}
	}
};
