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

	// Basic web page to demo the SSE stream
	if ( $request->path() === '/' ) {
		$connection->send( new Response(
			200,
			add_cors_headers( [
				'Content-Type' => 'text/html; charset=utf-8',
			] ),
			<<< HTML
			<html>
				<head>
					<title>SSE Demo</title>
					<style>
						body {
							font-family: system-ui, -apple-system, sans-serif;
							max-width: 800px;
							margin: 0 auto;
							padding: 20px;
							line-height: 1.5;
							height: 100vh;
							display: flex;
							flex-direction: column;
							overflow: hidden;
						}
						#ping_container {
							border: 1px solid #ddd;
							border-radius: 4px;
							padding: 15px;
							margin: 10px 0;
							flex: 1;
							overflow-y: auto;
							background-color: #f8f8f8;
							min-height: 0;
						}
						.ping_item {
							border-bottom: 2px solid #ddd;
						}
						.ping_item:last-child {
							border-bottom: none;
						}
						.controls {
							display: flex;
							align-items: center;
							gap: 10px;
							margin-bottom: 15px;
						}
						button {
							padding: 8px 16px;
							background-color: #0066cc;
							color: white;
							border: none;
							border-radius: 4px;
							cursor: pointer;
							font-size: 16px;
						}
						button:hover {
							background-color: #0055aa;
						}
						button:disabled {
							background-color: #cccccc;
							cursor: not-allowed;
						}
						#connection_status {
							display: inline-block;
							width: 12px;
							height: 12px;
							border-radius: 50%;
							background-color: #cc0000;
							margin-right: 8px;
							vertical-align: middle;
							transition: background-color 0.3s ease;
						}
						#connection_status.connected {
							background-color: #22cc22;
						}
						#connection_status.connecting {
							background-color: #ffaa00;
						}
						.warning {
							background-color: #fff3cd;
							color: #856404;
							border: 1px solid #ffeeba;
							border-radius: 4px;
							padding: 12px;
							margin: 15px 0;
							font-weight: bold;
						}
						.json_display {
							background-color: #f0f0f0;
							padding: 10px;
							border-radius: 4px;
							font-family: monospace;
							white-space: pre-wrap;
							margin-top: 10px;
							overflow-x: auto;
							border: 1px solid #ddd;
						}
						@media (max-width: 600px) {
							body {
								padding: 10px;
							}
							.controls {
								flex-direction: column;
							}
						}
					</style>
				</head>
				<body>
					<h1>Real-time blog updates from <a href="http://blo.gs/">blo.gs</a></h1>
					<div class="warning">
						<p>⚠️ Warning: This ping stream contains a significant amount of spam content. The data is unfiltered and comes directly from ping.blo.gs.</p>
					</div>
					<div class="controls">
						<span id="connection_status" title="Disconnected"></span>
						<button id="connect_button">Connect Stream</button>
						<button id="disconnect_button" disabled>Disconnect Stream</button>
						<button id="clear_button">Clear Pings</button>
					</div>
					<div id="ping_container">
						<div id="ping_list"></div>
					</div>
					<script>
						// DOM elements
						const connect_button = document.getElementById('connect_button');
						const disconnect_button = document.getElementById('disconnect_button');
						const clear_button = document.getElementById('clear_button');
						const connection_status = document.getElementById('connection_status');
						const ping_list = document.getElementById('ping_list');
						
						// Stream state
						let event_source = null;
						
						// Connect to SSE stream
						function connect_stream() {
							if (event_source) {
								return;
							}
							
							event_source = new EventSource('/sse');
							connection_status.className = 'connecting';
							connection_status.title = 'Connecting...';
							
							// Handle connection open
							event_source.onopen = function() {
								connection_status.className = 'connected';
								connection_status.title = 'Connected';
								connect_button.disabled = true;
								disconnect_button.disabled = false;
							};
							
							// Handle ping events
							event_source.addEventListener('ping', function(event) {
								const ping_data = JSON.parse(event.data);
								const ping_item = document.createElement('div');
								ping_item.className = 'ping_item';
								
								// Only show pretty-printed JSON
								ping_item.innerHTML = `<div class="json_display">\${JSON.stringify(ping_data, null, 2)}</div>`;
								
								ping_list.insertBefore(ping_item, ping_list.firstChild);
							});
							
							// Handle errors
							event_source.onerror = function() {
								connection_status.className = '';
								connection_status.title = 'Disconnected';
								connect_button.disabled = false;
								disconnect_button.disabled = true;
							};
						}
						
						// Disconnect from SSE stream
						function disconnect_stream() {
							if (event_source) {
								event_source.close();
								event_source = null;
								connection_status.className = '';
								connection_status.title = 'Disconnected';
								connect_button.disabled = false;
								disconnect_button.disabled = true;
							}
						}
						
						// Clear ping list
						function clear_pings() {
							ping_list.innerHTML = '';
						}
						
						// Event listeners
						connect_button.addEventListener('click', connect_stream);
						disconnect_button.addEventListener('click', disconnect_stream);
						clear_button.addEventListener('click', clear_pings);
						
						// Start connected by default
						connect_stream();
					</script>
				</body>
			</html>
HTML
		) );

	}
};

Worker::runAll();