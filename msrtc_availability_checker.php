<?php

use GuzzleHttp\Client;
use PHPHtmlParser\Dom;
use React\EventLoop\Loop;
use GuzzleHttp\Cookie\CookieJar;

require __DIR__ . '/vendor/autoload.php';

// Load enviornment variables from .env file
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

// Setup http client
$http = $client = new Client();

$cookies = CookieJar::fromArray([
	'PHPSESSID' => $_ENV['SESSION_ID']
], 'public.msrtcors.com');

$dom = new Dom;

function notify($title, $message)
{
	global $http;

	$response = $http->post($_ENV['GOTIFY_URL'] . '/message?token=' . $_ENV['GOTIFY_TOKEN'], [
		'form_params' => [
			'title' => $title,
			'message' => $message,
			'priority' => 5,
		]
	]);

	return $response->getStatusCode() == 200;
}

// Run loop every 5 minutes after finishing the callback
Loop::addPeriodicTimer(1, function ($timer) use ($http, $cookies, $dom) {
	echo "Sending query ... \n";

	$response = $http->get('https://public.msrtcors.com/ticket_booking/availability_lookup.php', [
		'query' => [
			'time' => $_ENV['BUS_TIME'],
			'bsn' => $_ENV['BSN_NO']
		],
		'cookies' => $cookies
	]);

	echo "Processing response ... \n";

	$allSeats = $dom->loadStr((string) $response->getBody())->find('#seat_layout td')->toArray();

	if (count($allSeats)) {
		$availableSeats = array_filter($allSeats, function ($seat) {
			return str_contains($seat->outerHtml, 'background-color:#00FFFF');
		});
	
		if (count($availableSeats)) {
			echo "Seats available ... \n";
	
			$availableSeatNumbers = array_map(function ($seat) {
				return trim($seat->find('td')->innerHtml);
			}, $availableSeats);
		
			sort($availableSeatNumbers);
	
			echo "Sending notification ... \n";
		
			notify('Seats available', 'Seats are available for ' . $_ENV['BUS_TIME'] . ' with seat numbers ' . implode(', ', $availableSeatNumbers));
	
			echo "Stopping process ... \n";
			
			Loop::cancelTimer($timer);
		} else {
			echo "No seats available, will check back in 5 minutes ... \n";

			error_log('Last ran: ' . date("m/d/Y h:i:s a", time()) . PHP_EOL, 3, __DIR__ . '/logs.txt');
		}
	} else {
		echo "Invalid response! Notifying ... \n";
		
		notify('Oops!', 'Something is wrong in response!');
		
		echo "Stopping process ... \n";
		
		Loop::cancelTimer($timer);
	}
});
