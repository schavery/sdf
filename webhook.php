<?php

/**
* Capture the events from Stripe,
* so that customers that are charged again will
* be updated in Salesforce.
*/

// we need the wordpress options stuff to be loaded
// this function assumes we are in some subdirectory of wordpress base
// like wp-content/plugins/sdf/ ...
function find_wordpress_base_path() {
	$count = 0;
	$dir = dirname(__FILE__);

	// local development
	if(file_exists('../wordpress/wp-load.php')) {
		return realpath('../wordpress/wp-load.php');
	}

	// default wordpress location
	if(file_exists('../../../wp-config.php')) {
		return realpath('../../../wp-load.php');
	}

	while($count < 255) {
		// otherwise we'll head for the moon
		if(file_exists($dir . '/wp-load.php')) {
			return $dir . '/wp-load.php';
		} else {
			$count++;
			$dir = realpath("$dir/..");
		}
	}

	throw new Exception('Wordpress base path not found');
}

require_once find_wordpress_base_path();
require_once 'sdf.php';
$sdf = new SDF();

// get and unwrap request
$body = @file_get_contents('php://input');
$event = json_decode($body, true);
$response_code = 200;

sdf_message_handler(\SDF\MessageTypes::DEBUG,
			sprintf('Stripe webhook with type: %s', $event['type']));

if(strpos($event['type'], 'charge.succeeded') === 0) {
	$type     = $event['type'];
	$email    = $event['data']['object']['receipt_email'];
	$customer = $event['data']['object']['customer'];
	$cents    = $event['data']['object']['amount'];
	$invoice  = $event['data']['object']['invoice'];

	$charge   = $event['data']['object']['id'];

	// Stripe seems to not handle certain email addresses,
	// so we fall back to the charge description
	if(is_null($email)) {
		sdf_message_handler(\SDF\MessageTypes::LOG, 'Stripe webhook was missing email');

		$email = $event['data']['object']['description'];
	}
	// even still, we may have to look up the customer by their stripe id

	$info = array(
		'type'       => $type,
		'email'      => $email,
		'amount'     => $cents,
		'customer'   => $customer,
		'charge-id'  => $charge,
		'invoice-id' => $invoice,
	);

	// do the rest of the processing in the class
	$response_code = $sdf->do_stripe_endpoint($info);
}

http_response_code($response_code); ?>
