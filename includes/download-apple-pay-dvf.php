<?php
// phpcs:ignoreFile
// This file serves the Apple Pay domain verification file without .txt extension
// It needs to be accessible directly via HTTP, so we use a different security approach
if ( ! defined( 'ABSPATH' ) ) {
	// Allow direct access for Apple Pay domain verification
	// This file serves the apple-developer-merchantid-domain-association file
	// which must be accessible at the domain root for Apple Pay verification
	
	// Basic security: only allow GET requests and check for reasonable user agents
	$request_method = $_SERVER['REQUEST_METHOD'] ?? '';
	$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
	
	// Block obviously malicious requests
	if ( $request_method !== 'GET' ) {
		http_response_code( 405 );
		exit( 'Method not allowed' );
	}
	
	// Block requests with suspicious user agents (basic bot protection)
	$suspicious_patterns = array(
		'sqlmap', 'nikto', 'nmap', 'masscan', 'zap', 'burp',
		'havij', 'acunetix', 'nessus', 'openvas'
	);
	
	foreach ( $suspicious_patterns as $pattern ) {
		if ( stripos( $user_agent, $pattern ) !== false ) {
			http_response_code( 403 );
			exit( 'Access denied' );
		}
	}
}

// Read the domain verification file content
$file_path = dirname( __FILE__ ) . '/../apple-developer-merchantid-domain-association.txt';

if ( ! file_exists( $file_path ) ) {
	http_response_code( 404 );
	exit( 'File not found' );
}

$file_content = file_get_contents( $file_path );

if ( $file_content === false ) {
	http_response_code( 500 );
	exit( 'Error reading file' );
}

// Set headers to download the file without .txt extension
header( 'Content-Type: text/plain; charset=utf-8' );
header( 'Content-Disposition: attachment; filename="apple-developer-merchantid-domain-association"' );
header( 'Content-Length: ' . strlen( $file_content ) );
header( 'Cache-Control: no-cache, must-revalidate' );
header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' );
header( 'X-Content-Type-Options: nosniff' );
header( 'X-Frame-Options: DENY' );

// Output the file content
echo $file_content;
exit;
