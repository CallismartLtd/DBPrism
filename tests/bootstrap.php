<?php

if ( 'cli' !== PHP_SAPI ) {
	function_exists( 'http_response_code' ) && http_response_code( 403 );
	exit( 'This script can only be run from the command line.' );
}

