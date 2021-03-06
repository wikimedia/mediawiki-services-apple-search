<?php

// Fetch a suggestion set from the OpenSearch API and translate it
// to the form that Apple expects for their Dictionary app lookups.
//
// Brion Vibber <brion@wikimedia.org>
// 2007-12-28

$requestUri = $_SERVER['REQUEST_URI'];

if ( $requestUri === '/robots.txt' ) {
	header( 'Content-Type: text/plain' );
	die( file_get_contents( __DIR__ . '/robots.txt' ) );
} elseif ( $requestUri === '/healthz' ) {
	header( 'Content-Type: text/plain' );
	die( 'OK' );
}

/**
 * @param string $msg
 * @param int $code
 * @return never
 */
function dieOut( $msg = '', $code = 500 ) {
	$error = 'bad request';
	if ( $code < 400 ) {
		$code = 500;
	}
	if ( $code >= 500 ) {
		$error = 'internal error';
	}

	$htmlMsg = htmlspecialchars( $msg );

	http_response_code( $code );
	die( "Wikimedia search service $error.\n\n$htmlMsg" );
}

error_reporting( E_ALL );
ini_set( "display_errors", "false" );

$config = json_decode( file_get_contents( __DIR__ . '/config/config.json' ), true );

$lang = 'en';
$search = '';
$limit = 20;

if ( isset( $_GET['lang'] ) && preg_match( '/^[a-z]+(-[a-z]+)*$/', $_GET['lang'] ) ) {
	$lang = $_GET['lang'];
}

if ( isset( $_GET['search'] ) && is_string( $_GET['search'] ) ) {
	$search = trim( $_GET['search'] );
}
if ( !$search ) {
	dieOut( "Request must include a 'search' parameter", 400 );
}

// 0x1F at the beginning of the param produces a badvalue_notmultivalue error
// from MW-API. We still receive a HTTP 200 but the code to parse the response
// will error out as a 500 because the json does not contain the expected data.
// Better to send a 400 directly saving a call to the backend.
if ( $search[0] === chr( 0x1F ) ) {
	dieOut( "Invalid 'search' parameter", 400 );
}

if ( isset( $_GET['limit'] ) ) {
	$limitParam = intval( $_GET['limit'] );
	if ( $limitParam >= 0 && $limitParam <= 100 ) {
		$limit = $limitParam;
	}
}

$urlSearch = urlencode( $search );

$proxy = $config['proxy'] ?? null;
$baseUrl = $proxy ?? "https://$lang.wikipedia.org";

# OpenSearch JSON suggest API
$url = "$baseUrl/w/api.php?action=opensearch&search=$urlSearch&limit=$limit";

$c = curl_init( $url );
curl_setopt_array( $c, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_TIMEOUT_MS => 5000,
	CURLOPT_USERAGENT => 'Wikimedia OpenSearch to Apple Dictionary bridge'
] );

if ( $proxy ) {
	curl_setopt( $c, CURLOPT_HTTPHEADER, [ "Host: $lang.wikipedia.org" ] );
}

$result = curl_exec( $c );
$code = curl_getinfo( $c, CURLINFO_HTTP_CODE );
if ( $result === false || !$code ) {
	dieOut( "Backend failure." );
}
if ( $code != 200 ) {
	dieOut( "Backend failure: it returned HTTP code $code.", $code );
}

$suggest = json_decode( $result );

// Confirm return result was format we expect
// for opensearch...
if ( is_array( $suggest ) && count( $suggest ) >= 2
	 && is_string( $suggest[0] ) && is_array( $suggest[1] ) ) {
		$returnedTerm = $suggest[0];
		$results = $suggest[1];
} else {
	dieOut( "Unexpected result format." );
}

header( "Cache-Control: public, max-age: 1200, s-maxage: 1200" );
print "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" \"DTD/xhtml1-transitional.dtd\">\n" .
	"<html><head><meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\"></head>\n" .
	"<body>";

$htmlLang = htmlspecialchars( $lang );

if ( !$results ) {
	$htmlSearch = htmlspecialchars( $search );
	print "<p>No entries found for \"$htmlLang:$htmlSearch\"</p>";
} else {
	foreach ( $results as $result ) {
		$htmlResult = htmlspecialchars( str_replace( ' ', '_', $result ) );
		print "<div><span class=\"language\">$htmlLang</span>:<span class=\"key\">$htmlResult</span></div>";
	}
}

print "</body></html>\n";
