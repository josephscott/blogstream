<?php
declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

const SERVER_URL = 'http://127.0.0.1:39999';

test( 'Server responds to HTTP request', function () {
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
} );

test( 'Server responds with correct CORS headers', function () {
	$context = stream_context_create( [
		'http' => [
			'method' => 'OPTIONS',
			'timeout' => 1,
		],
	] );

	$response = file_get_contents( SERVER_URL, false, $context );

	if ( $response === false ) {
		$error = error_get_last();
		fail(
			'OPTIONS request failed: '
			. ( $error['message'] ?? 'Unknown error' )
		);
	}

	$cors_headers = array_filter( $http_response_header ?? [], function ( $header ) {
		return strpos( $header, 'Access-Control-' ) !== false;
	} );

	expect( $cors_headers )->not->toBeEmpty( 'No CORS headers found in response' );
} );
