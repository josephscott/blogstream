<?php
declare( strict_types = 1 );

use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;
use Workerman\Protocols\Http\ServerSentEvents;

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
			//
			// I tried simplexml_load_string originally, but it ran into
			// encoding issues.
			$parsed_xml = preg_match_all('/(\w+)="([^"]*)"/', $line, $matches);
			if ( $parsed_xml === false ) {
				continue;
			}
			$ping = array_combine( $matches[1], $matches[2] );

			$json = json_encode( $ping );
			if ( $json === false ) {
				continue;
			}

			// Skip if no clients to receive updates
			if ( empty( $worker->clients ) ) {
				continue;
			}

			$event = new ServerSentEvents( [
				'event' => 'ping',
				'data' => $json
			] );

			// Send the update to all clients that are still connected
			foreach ( $worker->clients as $client ) {
				if ( $client->getStatus() === TcpConnection::STATUS_ESTABLISHED ) {
					$client->send( $event );
				} else {
					// Remove disconnected clients
					unset( $worker->clients[ $client->id ] );
				}
			}
		}
	};

	$worker->blogs_connection->connect();
};

$worker->onWorkerStop = function($worker) {
	// Clean up resources
	if ( $worker->blogs_connection ) {
		$worker->blogs_connection->close();
	}
};

// Client connections
$worker->onMessage = function(
	TcpConnection $connection,
	Request $request
) use ( $worker ) {
	// Handle OPTIONS preflight request for CORS
	if ($request->method() === 'OPTIONS') {
		$connection->send( new Response(
			204,
			add_cors_headers()
		) );
		return;
	}

	// SSE client requests
	if (
		( $request->path() === '/sse' || $request->path() === '/sse/' )
		&& $request->header( 'accept') === 'text/event-stream'
	) {
		// Send initial SSE response
		$connection->send( new Response(
			200,
			add_cors_headers( [
				'Content-Type' => 'text/event-stream',
				'Cache-Control' => 'no-cache',
				'X-Accel-Buffering' => 'no'
			] ),
			"\r\n"
		) );

		// Add to the list of clients
		$worker->clients[ $connection->id ] = $connection;

		// Initial heartbeat to establish the connection
		$connection->send( ": connected\n\n" );

		// Handle client disconnect
		$connection->onClose = function() use ($connection, $worker) {
			// Remove client from the list
			unset($worker->clients[$connection->id]);
		};

		return;
	}
};

Worker::runAll();