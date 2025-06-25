<?php
// phpcs:ignoreFile

$options = array(
	'http' => array(
		'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n",
	),
);

$context      = stream_context_create( $options );
$file_content = file_get_contents( '../apple-developer-merchantid-domain-association.txt', false, $context );

header( 'Content-Type: text/plain' );
header( 'Content-Disposition: attachment; filename="apple-developer-merchantid-domain-association"' );
echo $file_content;
exit;
