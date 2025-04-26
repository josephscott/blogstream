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
		string $data
	) use ( $worker ) {
		$worker->buffer .= $data;

		// We need one line at a time
		while ( ( $pos = strpos( $worker->buffer, "\n" ) ) !== false ) {
			$line = substr( $worker->buffer, 0, $pos );
			$worker->buffer = substr( $worker->buffer, $pos + 1 );

			// Skip the XML headers
			// http://blo.gs/cloud.php
			if ( ! str_starts_with( $line, '<weblog ' ) ) {
				continue;
			}

			// Parse the XML
			$xml = simplexml_load_string( $line );
			if ( $xml === false ) {
				continue;
			}

			$json = json_encode( $xml );
			if ( $json === false ) {
				continue;
			}

			error_log( "\n\n--\n$json\n--\n\n" );

			// Skip if no clients to receive updates
			if ( empty( $worker->clients ) ) {
				continue;
			}

			// Send the update to all clients
			foreach ( $worker->clients as $client ) {
				$client->send( $json );
			}
		}
	};

	$worker->blogs_connection->connect();
};

Worker::runAll();