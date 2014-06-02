<?php
/**
 * GPX to Runkeeper mass data importer
 *
 * @author Daniel Seripap <daniel@seripap.com>
 * @copyright Daniel Seripap (c) 2014
 * @version 1.0
 * @license MIT
 *
 * This uses the HealthGraph API wrapper to import GPX data to Runkeeper.
 *
 * The GPX format for this has been tailored for
 * https://mattstuehler.com/lab/NikePlus/
 *
 *
 */
require_once 'vendor/autoload.php';
use HealthGraph\Authorization;
use HealthGraph\HealthGraphClient;

/**
 * [$client_id Runkeeper client ID]
 * @var string
 * [$client_secret Runkeeper client secret]
 * @var string
 * [$redirect_url app location]
 * @var string
 */
$client_id = 'YOUR_CLIENT_ID';
$client_secret = 'YOUR_CLIENT_SECRET';
$redirect_url = 'http://localhost/runkeeper_mass_import';

/**
 * [$button Runkeeper connect button]
 * @var [type]
 */
$button = Authorization::getAuthorizationButton($client_id, $redirect_url);

// Spit connect button to the page
echo $button['html'];

// the healthgraph api returns ?code=xxxx on success...I havent confirmed this but it should be on success
if (isset($_REQUEST['code'])) {
	$token = Authorization::authorize($_GET['code'], $client_id, $client_secret, $redirect_url);
	$hgc = HealthGraphClient::factory();
	$hgc->getUser(array(
		'access_token' => $token['access_token'],
		'token_type' => $token['token_type'],
		));

	// Scan all files in the data folder excluding .DS_Store
	// TODO: Check if valid GPX file. There is no error handling for that yet
	$dir   = getcwd() . '/data/';
	$files = scandir($dir);
	$files = array_diff($files, array('.', '..', '.DS_Store'));

	$gpxCount = 0;
	$gpxData = array();

	foreach($files as $file) {
		$xml = simplexml_load_file($dir.$file);

		$track = array();
		$count = 0;

	// Dump track data to an array
		foreach ($xml->trk->trkseg->trkpt as $trackInfo) {
			$track[$count] = array(
				"latitude" => floatval($trackInfo->attributes()->lat),
				"longitude" => floatval($trackInfo->attributes()->lon),
				"altitude" => floatval($trackInfo->ele),
				"timestamp" => strtotime((string)$trackInfo->time),
				"type" => $count === 0 ? "start" : "gps",
				);
			$count++;
		}

	// Set the last track to end, minus 1 to the $count as well
		$track[--$count]['type'] = "end";

	// Grab start
		$startTime = $track[0]['timestamp'];
		$startTimeFormatted = date('D, j M Y H:i:s', $startTime);

	// Recalculate timestamp to seconds
		foreach ($track as $key => $value) {
			$track[$key]['timestamp'] = floatval($track[$key]['timestamp'] - $startTime);
		}

		// Get entire duration; Runkeeper is expecting this data in meters so we have to do some math
		$duration = $track[$count]['timestamp'];

		// There's probably a better way to do this....let me know if so :)
		$theta = floatval($xml->metadata->bounds->attributes()->minlon) - floatval($xml->metadata->bounds->attributes()->maxlon);
		$dist = sin(deg2rad(floatval($xml->metadata->bounds->attributes()->minlat))) * sin(deg2rad(floatval($xml->metadata->bounds->attributes()->maxlat))) +  cos(deg2rad(floatval($xml->metadata->bounds->attributes()->minlat))) * cos(deg2rad(floatval($xml->metadata->bounds->attributes()->maxlat))) * cos(deg2rad($theta));
		$dist = acos($dist);
		$dist = rad2deg($dist);
		$miles = $dist * 60 * 1.1515;

		// Convert all that to meters
		$total_distance = $miles * 1609.3;

		$gpxData[$gpxCount] = array(
			"start_time" => $startTimeFormatted,
			"duration" => $duration,
			"total_distance" => $total_distance,
			"path" => $track,
			);
		$gpxCount++;
	}

	// Each activity, send to Runkeeper
	foreach ($gpxData as $singledGpx) {
		$data = array(
			"type" => "Running",
			"start_time" => $singledGpx['start_time'],
			"duration" => $singledGpx['duration'],
			"total_distance" => $singledGpx['total_distance'],
			"path" => $singledGpx['path'],
			"notes" => "Imported via Seripap's GPX Mass Import Tool",
			"post_to_facebook" => false,
			"post_to_twitter" => false
			);
		$command = $hgc->getCommand('NewFitnessActivity', $data);
		$result = $command->execute();

		echo $result . "<BR>";
	}

} else {
	// Something went wrong with RK auth
	echo "There was an error :(";
}

?>
