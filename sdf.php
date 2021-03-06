<?php
/*
	Plugin Name: Spark Donation Form
	Plugin URI: https://github.com/schavery/sdf
	Description: Create and integrate a form with payment processing and CRM
	Author: Steve Avery
	Version: 2.4
	Author URI: https://stevenavery.com/
*/

$hostname = exec('hostname');
if(strpos($hostname, 'bluehost.com') !== false) {
	define('SDFLIVEMODE', 1);
} else {
	define('SDFLIVEMODE', 0);
}

// cultural imperialism
date_default_timezone_set('America/Los_Angeles');
setlocale(LC_MONETARY, 'en_US.UTF-8');

if(SDFLIVEMODE) {
	ini_set('display_errors', 0);
} else {
	error_reporting(E_ERROR | E_WARNING | E_PARSE);
}

defined('ABSPATH') or die("Unauthorized.");

require_once WP_PLUGIN_DIR . '/sdf/types.php';
require_once WP_PLUGIN_DIR . '/sdf/message.php';
require_once WP_PLUGIN_DIR . '/sdf/wordpress.php';
require_once WP_PLUGIN_DIR . '/sdf/Stripe.php';
require_once WP_PLUGIN_DIR . '/sdf/Salesforce.php';
require_once WP_PLUGIN_DIR . '/sdf/UCSalesforce.php';
require_once WP_PLUGIN_DIR . '/sdf/AsyncSalesforce.php';

define('SDFLOGLEVEL', \SDF\MessageTypes::DEBUG);

class SDF {

	private $data;
	private $stripe;
	private $emergency_email_sent = false;

	public function begin($postdata) {
		sdf_message_handler(\SDF\MessageTypes::DEBUG,
				'Entered SDF class. Beginning data validation');

		$this->data = $postdata;

		self::required_fields();
		self::hearabout_category();
		self::check_email();
		self::set_full_name();
		self::set_amount();
		self::set_recurrence();

		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Data validation complete');

		// do stripe first, so that we can get an ID
		self::do_stripe();
		self::do_init_salesforce();

		sdf_message_handler(\SDF\MessageTypes::DEBUG,
				'Done with synchronous donation processing. Next message should be success.');
	}


	// this is an alternative entrypoint to the sdf class.
	public function do_stripe_endpoint(&$info) {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Entered SDF class for endpoints');

		// get the plan details attached to this charge
		$info['invoice'] = $this->get_stripe_invoice($info['invoice-id']);

		if(is_null($info['email'])) {
			$info['email'] = $this->get_stripe_customer($info['customer']);
		} else {
			sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Stripe customer email is available, no need to look it up');
		}

		// send it to salesforce
		$salesforce = new \SDF\AsyncSalesforce();

		// returns http code
		$status = $salesforce->init($info);

		sdf_message_handler(\SDF\MessageTypes::LOG,
				sprintf('Endpoint request, status: %d', $status));

		return $status;
	}


	private function do_stripe() {
		// we keep this instance of stripe referenced so we can get the ID
		// of the charge or the subscription

		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Beginning to do Stripe');

		$this->stripe = static::make_stripe($this->stripe);
		$this->stripe->charge(self::get_stripe_details());

		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Finished doing Stripe');
	}

	
	private function do_init_salesforce() {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Beginning to do Salesforce');

		$salesforce = new \SDF\UCSalesforce();
		$salesforce->init(self::get_sf_init_details());
		$emergency_email_sent = $salesforce->has_emergency_email_been_sent();

		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Finished doing Salesforce');		
	}


	// ************************************************************************

	public static function make_stripe($ctx = null) {
		sdf_message_handler(\SDF\MessageTypes::DEBUG,
				'Checking for Stripe instance');

		if(is_null($ctx)) {
			sdf_message_handler(\SDF\MessageTypes::DEBUG,
					'Creating instance of Stripe class.');

			$ctx = new \SDF\Stripe();
			$ctx->api();
			return $ctx;
		}

		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Using existing Stripe instance');
	}

	private function get_stripe_invoice($invoice_id) {
		sdf_message_handler(\SDF\MessageTypes::DEBUG,
				'Attempting to get Stripe invoice data');

		$invoice = null;
		if(!is_null($invoice_id)) {
			try {
				$this->stripe = static::make_stripe($this->stripe);
				$invoice = \Stripe\Invoice::retrieve($invoice_id);

				sdf_message_handler(\SDF\MessageTypes::DEBUG,
						sprintf('Stripe invoice %s found', $invoice->id));
			} catch(\Stripe\Error\Base $e) {
				sdf_message_handler(\SDF\MessageTypes::LOG,
						__FUNCTION__ . ' : ' . $e);
			}
		} else {
			sdf_message_handler(\SDF\MessageTypes::DEBUG,
					'Can\' retrieve Stripe invoice without an invoice id');
		}
		return $invoice;
	}

	private function get_stripe_customer($cus_id) {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Attempting to look up Stripe customer email');

		$email = null;
		if(!is_null($cus_id)) {
			try {
				$this->stripe = static::make_stripe($this->stripe);
				$customer = \Stripe\Customer::retrieve($cus_id);
				$email = $customer['email'];

				sdf_message_handler(\SDF\MessageTypes::DEBUG,
						sprintf('Customer email retrieved: %s', $email));
			} catch(\Stripe\Error\Base $e) {
				sdf_message_handler(\SDF\MessageTypes::LOG,
						__FUNCTION__ . ' : ' . $e);
			}
		} else {
			sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Cannot retrieve Stripe customer without customer id');
		}
		return $email;
	}

	private function get_sf_init_details() {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Copying Salesforce required data');
		$info = array();

		$info['first-name']      = $this->data['first-name'];
		$info['last-name']       = $this->data['last-name'];
		$info['email']           = $this->data['email'];
		$info['tel']             = $this->data['tel'];
		$info['address1']        = $this->data['address1'];
		$info['address2']        = $this->data['address2'];
		$info['city']            = $this->data['city'];
		$info['state']           = $this->data['state'];
		$info['zip']             = $this->data['zip'];
		$info['country']         = $this->data['country'];
		$info['company']         = $this->data['company'];
		$info['birthday-month']  = $this->data['birthday-month'];
		$info['birthday-year']   = $this->data['birthday-year'];
		$info['gender']          = $this->data['gender'];
		$info['hearabout']       = $this->data['hearabout'];
		$info['hearabout-extra'] = $this->data['hearabout-extra'];
		$info['inhonorof']       = $this->data['inhonorof'];
		
		$info['stripe-id']       = $this->stripe->get_stripe_id();

		// we're done with this now
		unset($this->stripe);

		return $info;
	}

	private function get_stripe_details() {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Copying Stripe required data');

		$info = array();

		$info['amount-cents']      = $this->data['amount-cents'];
		$info['amount-string']     = $this->data['amount-string'];
		$info['token']             = $this->data['stripe-token'];
		$info['email']             = $this->data['email'];
		$info['name']              = $this->data['full-name'];
		$info['recurrence-type']   = $this->data['recurrence-type'];
		$info['recurrence-string'] = $this->data['recurrence-string'];

		return $info;
	}

	private function required_fields() {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Checking required fields');
		$fields = array(
			'annual-value',
			'monthly-value',
			'amount-to-use',
			'first-name',
			'last-name',
			'email',
			'tel',
			'address1',
			'city',
			'state',
			'zip',
			'stripe-token'
		);

		foreach($fields as $key) {
			if(!array_key_exists($key, $this->data)
				|| empty($this->data[$key])) {
				sdf_message_handler(\SDF\MessageTypes::ERROR,
						'Error: Missing required fields.');
			}
		}
	}

	private function hearabout_category() {
		sdf_message_handler(\SDF\MessageTypes::DEBUG,
				'Checking for how the donor heard about us');
		$cats = array(
			'Renewing Membership',
			'Friend',
			'Website',
			'Search',
			'Event'
		);

		if(!empty($this->data['hearabout'])) {
			if(!in_array($this->data['hearabout'], $cats)) {
				sdf_message_handler(\SDF\MessageTypes::LOG,
						'Invalid hearabout category.');

				unset($this->data['hearabout']);
				unset($this->data['hearabout-extra']);
			}
		}
	}

	private function check_email() {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Checking email');
		$this->data['email'] = filter_var(
				$this->data['email'], FILTER_SANITIZE_EMAIL);
		if(!filter_var($this->data['email'], FILTER_VALIDATE_EMAIL)) {

			sdf_message_handler(\SDF\MessageTypes::ERROR,
					'Invalid email address.');
		}
		sdf_message_handler(\SDF\MessageTypes::DEBUG,
				sprintf('email is: %s', $this->data['email']));
	}

	private function set_full_name() {
		$this->data['full-name'] = 
				$this->data['first-name'] . ' ' . $this->data['last-name'];
	}

	private function set_recurrence() {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Getting donation recurrence');
		if(strpos($this->data['amount-to-use'], 'monthly') !== false) {
			$recurrence = 'Monthly';
			$type = \SDF\RecurrenceTypes::MONTHLY;
		} else {
			if(array_key_exists('make-annual', $this->data)
					&& $this->data['make-annual'] == 'on') {

				$recurrence = 'Annual';
				$type = \SDF\RecurrenceTypes::ANNUAL;
			} else {
				$recurrence = 'One time';
				$type = \SDF\RecurrenceTypes::ONE_TIME;
			}
		}

		$this->data['recurrence-type'] = $type;
		$this->data['recurrence-string'] = $recurrence;

		sdf_message_handler(\SDF\MessageTypes::DEBUG,
				sprintf('Donation recurrence is: %s', $this->data['recurrence-string']));
	}

	private function set_amount() {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'Getting donation amount');
		$donated_value =
				self::get_cents($this->data[$this->data['amount-to-use']]);

		if($donated_value <= 50) { // cents
			sdf_message_handler(\SDF\MessageTypes::ERROR,
					'Invalid request. Donation amount too small.');
		}

		$this->data['amount-cents'] = $donated_value;
		$this->data['amount-string'] = money_format('%.2n',
				(float) $donated_value / 100);

		sdf_message_handler(\SDF\MessageTypes::DEBUG,
				sprintf('Donation amount is: %d cents', $this->data['amount-cents']));
	}

	public function get_cents($value_string) {
		$vs = preg_replace('/[^\d.]/', '', $value_string);

		if(strpos($vs, '.') === false) {
			// this value is in dollars
			$donated_value = 100 * intval($vs);
		} else {
			$ex = explode('.', $vs);
			$donated_value = 100 * intval($ex[0]);

			if(intval($ex[1]) < 10 && strlen($ex[1]) == 1) {
				$donated_value += 10 * intval($ex[1]);
			} else {
				$donated_value += intval($ex[1]);
			}

		}

		if(!is_numeric($donated_value)) {
			sdf_message_handler(\SDF\MessageTypes::ERROR,
					'Unable to parse donation amount.');
		}

		return $donated_value;
	}

} // end class sdf_data


// Ajax response function
function sdf_parse() {
	if(!isset($_POST['data'])) {
		sdf_message_handler(\SDF\MessageTypes::LOG,
				__FUNCTION__ . ' No data received');

	} else {
		sdf_message_handler(\SDF\MessageTypes::DEBUG, 'sdf_parse begin');
		$sdf = new SDF();
		$sdf->begin($_POST['data']);
		
		if(!$emergency_email_sent) {
			sdf_message_handler(\SDF\MessageTypes::SUCCESS,
				'Thank you for your donation!');
		} else {
			$email = get_option('sf_email_reply_to');
			sdf_message_handler(\SDF\MessageTypes::ERROR,
					sprintf('Something went wrong, but we\'re not sure what.'
					. ' <a href="mailto:%s?subject=Spark Donation Error" target="_blank">'
					. 'Please get in contact</a> to make sure your donation went through.'
					, $email));
		}
		
	}
	
	die(); // prevent trailing 0 from admin-ajax.php
}


// HTML and redirect functions
function sdf_template() {
	global $wp;
	if(array_key_exists('pagename', $wp->query_vars)) {
		if($wp->query_vars['pagename'] == 'donate') { // TODO: make setting
			$return_template = 'templates/page_donation.php';
			sdf_theme_redirect($return_template);
		}
	}
}


function sdf_theme_redirect($url) {
	global $wp_query;
	if(have_posts()) {
		include($url);
		die();
	} else {
		$wp_query->is_404 = true;
	}
}


function sdf_ajaxurl() { ?>
	<script type="text/javascript">
		var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
	</script>
<?php }


function sdf_check_ssl() {
	if(SDFLIVEMODE) {
		if(!(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on')) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: https://' . $_SERVER['SERVER_NAME']
					. $_SERVER['REQUEST_URI']);
			die();
		}
	}
}

// ****************************************************************************
// Setup hooks

if(is_admin()) {
	add_action('admin_init', 'sdf_register_settings');
	add_action('admin_menu', 'sdf_create_menu');
	add_action('wp_ajax_sdf_parse', 'sdf_parse');
	add_action('wp_ajax_nopriv_sdf_parse', 'sdf_parse');
}

add_action('template_redirect', 'sdf_template');
add_action('wp_head', 'sdf_ajaxurl');

$plugin = plugin_basename(__FILE__); 
add_filter('plugin_action_links_' . $plugin, 'sdf_settings_link' );

register_activation_hook(__FILE__, 'sdf_activate');
register_deactivation_hook(__FILE__, 'sdf_deactivate');
