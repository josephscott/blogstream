<?php
declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

const SERVER_URL = 'http://127.0.0.1:39999';

test( 'Server responds to HTML home page request', function () {
	$context = stream_context_create( [
		'http' => [
			'timeout' => 1,
		],
	] );

	$response = file_get_contents( SERVER_URL, false, $context );

	if ( $response === false ) {
		$error = error_get_last();
		fail(
			'Connection to server failed: '
			. ( $error['message'] ?? 'Unknown error' )
			. '. Is the server running at ' . SERVER_URL . '?'
		);
	}

	expect( $response )->toBeString();
	expect( $response )->toContain( '<title>Blogstream: Real-time blog updates from blo.gs</title>' );
	expect( $response )->toContain( 'Real-time blog updates from' );
	expect( $response )->toContain( '<button id="connect_button">Connect Stream</button>' );
} );

test( 'server returns 404 for non-existent path', function () {
	$context = stream_context_create( [
		'http' => [
			'timeout' => 1,
			'ignore_errors' => true,
		],
	] );

	$random_path = '/invalid_path_' . rand( 10000, 99999 );
	$response = file_get_contents( SERVER_URL . $random_path, false, $context );
	$response_headers = $http_response_header ?? [];

	// Check status code (404 Not Found)
	$status_line = $response_headers[0] ?? '';
	expect( strpos( $status_line, '404' ) )->toBeGreaterThan( 0 );

	// Check content
	expect( $response )->toBe( 'Not found' );
} );

test( 'Server responds with correct CORS headers', function () {
	$context = stream_context_create( [
		'http' => [
			'timeout' => 1,
			'method' => 'OPTIONS',
			'header' => [
				'Accept: */*',
			],
		],
	] );

	$response = file_get_contents( SERVER_URL, false, $context );
	$response_headers = $http_response_header ?? [];

	// Check status code (204 No Content for OPTIONS)
	$status_line = $response_headers[0] ?? '';
	expect( strpos( $status_line, '204' ) )->toBeGreaterThan( 0 );

	// Check CORS headers
	$headers = [];
	foreach ( $response_headers as $header ) {
		$parts = explode( ':', $header, 2 );
		if ( count( $parts ) === 2 ) {
			$headers[trim( $parts[0] )] = trim( $parts[1] );
		}
	}

	expect( $headers )->toHaveKey( 'Access-Control-Allow-Origin' );
	expect( $headers['Access-Control-Allow-Origin'] )->toBe( '*' );

	expect( $headers )->toHaveKey( 'Access-Control-Allow-Methods' );
	expect( $headers['Access-Control-Allow-Methods'] )->toContain( 'GET' );
	expect( $headers['Access-Control-Allow-Methods'] )->toContain( 'OPTIONS' );

	expect( $headers )->toHaveKey( 'Access-Control-Allow-Headers' );
	expect( $headers )->toHaveKey( 'Access-Control-Max-Age' );
} );

test( 'SSE endpoint establishes connection and sends initial data', function () {
	// Use a 1 second timeout
	$context = stream_context_create( [
		'http' => [
			'method' => 'GET',
			'timeout' => 1,
			'header' => [
				'Accept: text/event-stream',
			],
			'ignore_errors' => true,
		],
	] );

	// Open the stream manually to read up to the first event
	$fp = fopen( SERVER_URL . '/sse', 'r', false, $context );

	if ( ! $fp ) {
		// If we can't connect, check the response headers and fail with a message
		$error = error_get_last();
		fail( 'Failed to connect to SSE endpoint: ' . ( $error['message'] ?? 'Unknown error' ) );
	}

	// Set non-blocking mode so we can check for data without waiting
	stream_set_blocking( $fp, false );

	// Read the headers
	$meta_data = stream_get_meta_data( $fp );
	$headers = $meta_data['wrapper_data'] ?? [];

	// Check for the specific headers we need
	$found_content_type = false;
	$found_cache_control = false;

	foreach ( $headers as $header ) {
		if ( stripos( $header, 'Content-Type:' ) !== false && stripos( $header, 'text/event-stream' ) !== false ) {
			$found_content_type = true;
		}
		if ( stripos( $header, 'Cache-Control:' ) !== false && stripos( $header, 'no-cache' ) !== false ) {
			$found_cache_control = true;
		}
	}

	expect( $found_content_type )->toBeTrue( 'Content-Type: text/event-stream header missing' );
	expect( $found_cache_control )->toBeTrue( 'Cache-Control: no-cache header missing' );

	// Read some data with a timeout
	$data = '';
	$start_time = microtime( true );

	// Try to read for at most 1 second
	while ( microtime( true ) - $start_time < 1 ) {
		$chunk = fread( $fp, 4096 );
		if ( $chunk ) {
			$data .= $chunk;

			// If we received the connected event, we can stop
			if ( strpos( $data, 'event: connected' ) !== false ) {
				break;
			}
		}
		// Short sleep to prevent CPU spinning
		usleep( 50000 ); // 50ms
	}

	// Close connection
	fclose( $fp );

	// Verify we received the connected event
	expect( $data )->toContain( 'event: connected' );
} );
