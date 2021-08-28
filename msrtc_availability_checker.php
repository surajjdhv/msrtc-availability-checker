<?php

use GuzzleHttp\Client;
use React\EventLoop\Loop;

require __DIR__ . '/vendor/autoload.php';

// Load enviornment variables from .env file
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

// Setup http client
$http = $client = new Client();

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

$xml = <<<XML
<v:Envelope
	xmlns:i="http://www.w3.org/2001/XMLSchema-instance"
	xmlns:d="http://www.w3.org/2001/XMLSchema"
	xmlns:c="http://schemas.xmlsoap.org/soap/encoding/"
	xmlns:v="http://schemas.xmlsoap.org/soap/envelope/">
	<v:Header />
	<v:Body>
		<n0:getSeatAvailability id="o0" c:root="1"
			xmlns:n0="http://service.msrtc.trimax.com/">
			<SeatAvailabilityRequest i:type="d:anyType">
				<serviceId i:type="d:int">{$_ENV['SERVICE_ID']}</serviceId>
				<fromStopId i:type="d:string">{$_ENV['FROM_STOP']}</fromStopId>
				<toStopId i:type="d:string">{$_ENV['TO_STOP']}</toStopId>
				<dateOfJourney i:type="d:string">{$_ENV['DATE_OF_JOURNEY']}</dateOfJourney>
			</SeatAvailabilityRequest>
		</n0:getSeatAvailability>
	</v:Body>
</v:Envelope>
XML;

// Run loop every 5 minutes after finishing the callback
Loop::addPeriodicTimer(1, function ($timer) use ($http, $xml) {
	echo "Sending query ... \n";

	$response = $http->post('http://api.msrtcors.com:8083/MSRTCMobileServices/MSRTCMobileService?wsdl', [
		'headers' => [
			'Content-Type' => 'text/xml; charset=UTF8',
		],
		'body' => $xml,
	]);

	echo "Processing response ... \n";

	$responseXml = (array) simplexml_load_string((string) $response->getBody())
		->children('S', true)
		->children('ns3', true)
		->children('', true)
		->SeatAvailabilityResponse;

	if (array_key_exists('availableSeats', $responseXml)) {
		
		echo "Seats available ... \n";
		
		$availableSeatNumbers = (array) $responseXml['availableSeats']->availableSeat;
	
		sort($availableSeatNumbers);

		echo "Sending notification ... \n";
	
		notify('Seats available', 'Seats are available for ' . $_ENV['DATE_OF_JOURNEY'] . ' with seat numbers ' . implode(', ', $availableSeatNumbers));

		echo "Stopping process ... \n";
		
		Loop::cancelTimer($timer);
	} else if (array_key_exists('serviceError', $responseXml) && $responseXml['serviceError']->errorReason == "No seat available !") {
		echo "No seats available, will check back in 5 minutes ... \n";

		error_log('Last ran: ' . date("m/d/Y h:i:s a", time()) . PHP_EOL, 3, __DIR__ . '/logs.txt');
	} else {
		echo "Invalid response! Notifying ... \n";
		
		notify('Oops!', 'Something is wrong in response!');
		
		echo "Stopping process ... \n";
		
		Loop::cancelTimer($timer);
	}
});
