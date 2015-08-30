<?php
/*
	Do asynchronous part of salesforce updates.
*/

namespace SDF;

require_once WP_PLUGIN_DIR . '/sdf/types.php';
require_once WP_PLUGIN_DIR . '/sdf/message.php';

class AsyncSalesforce extends Salesforce {

	private $valid_donations;
	private $all_donations;
	private $subscription;

	protected $contact;
	private $invoice;

	private static $DISPLAY_NAME = 'Spark';
	private static $DONOR_SINGLE_TEMPLATE = '00X50000001VaHS';
	private static $DONOR_MONTHLY_TEMPLATE = '00X50000001eVEX';
	private static $DONOR_ANNUAL_TEMPLATE = '00X50000001eVEc';

	public function init(&$info) {
		try {
			// We need these in a few places
			$info['dollar-amount'] = (float) $info['amount'] / 100;
			$info['amount-string'] = money_format('%.2n', $info['dollar-amount']);

			parent::api();
			$this->contact = parent::get_contact($info['email']);

			// XXX figure out if this contact has been called a bunch of times?
			if(is_null($this->contact->Id)) {
				// the contact hasn't been created yet?
				sdf_message_handler(MessageTypes::LOG, 'contact not ready');
				// http status code 424 failed dependency
				// hopefully this means that stripe will try again soon
				return 424;
			}

			// Get the other donations we need to know about
			self::get_donations();

			// add a new line in the description
			// do this before calculating sum, so that
			// we know the recurrence type
			self::description($info);

			// Calculate the totals, so we know what level
			// the donor is
			self::recalc_sum($info);

			// Directly update some fields
			$this->contact->Paid__c = $info['dollar-amount'];
			$this->contact->Paid_0__c = true;
			$this->contact->Payment_Type__c = 'Credit Card';
			$this->contact->Donation_Each__c = $this->data['amount'];

			$this->contact->Renewal_Date__c = 
					date(parent::$DATE_FORMAT, strtotime('+1 year'));

			$this->contact->Membership_Start_Date__c =
					date(parent::$DATE_FORMAT);

			parent::cleanup();
			parent::upsert_contact();

			self::update_or_create_donation($info);
			self::send_email($info);

		} catch(\Exception $e) {
			$msg = $e->getMessage();
			sdf_message_handler(MessageTypes::LOG,
					__FUNCTION__ . ' : General failure in AsyncSalesforce. ' 
					. $msg);

			parent::emergency_email($info, $msg);
		}

		// status code ok
		return 200;
	}



	// Find out how much the amount is for the year if it's monthly 
	private function get_ext_amount($recurrence, $dollar_amount) {
		if($recurrence == RecurrenceTypes::MONTHLY) {
			$times = 13 - intval(date('n'));
		} else {
			$times = 1;
		}

		return $times * $dollar_amount;
	}


	// Find the donations for our contact, so that we can determine their
	// donor level
	private function get_donations() {
		$donations_list = array();

		if($this->contact->Id !== null) {

			if(time() <= strtotime(date('Y') . '-01-04')) {
				// in this case, we need to also check for donations 
				// that occurred on the last day of the year, because
				// we might have gotten the webhook call this late.
				$cutoff_date = mktime(0, 0, 0, 12, 31, intval(date('Y')) - 1);
			} else {	
				// the first of the year
				$cutoff_date = mktime(0, 0, 0, 1, 1);
			}

			// legible query
			$query = 'SELECT 
							(SELECT
								Name, Amount__c, Donation_Date__c,
								 Stripe_Status__c, Stripe_Id__c, In_Honor_Of__c
							FROM Donations__r)
						FROM
							Contact
						WHERE
							Contact.Id = \'%s\'';

			// shorten it up a bit
			$query = preg_replace('/\s+/', ' ',
					sprintf($query, $this->Contact->Id));

			try {

				$response = parent::$connection->query($query);
				$records = $response->records[0]->Donations__r->records;

				foreach($records as $donation) {
					$date = strtotime($donation->Donation_Date__c);

 					// XXX what does --none-- look like? ''?
 					// we want the successes because that's how we count up
 					// the total donated this year.
					// we want pending because that might be the target line item.
					if(in_array($donation->Stripe_Status__c,
							array('Pending', 'Success', ''), true)) {
						
						if($date >= $cutoff_date) {
							// donations from this calendar year

							$valid_donations_list[] = get_object_vars($donation);
						}
					}

					$this->all_donations[] = get_object_vars($donation);

				} // end foreach

			} catch(\Exception $e) {
				// It's okay if there's no donations returned here,
				// though our calculation will be wrong
				sdf_message_handler(MessageTypes::LOG,
						__FUNCTION__ . ' : ' . $e->faultstring);
			}
		}

		$this->valid_donations = $valid_donations_list;
	}

	// Set $info['desc']
	private function description(&$info) {

		if(is_null($info['invoice'])) {
			$info['recurrence-string'] = 'One time';
			$info['recurrence-type'] = RecurrenceTypes::ONE_TIME;
		} else {
			// Get the recurrence string from the invoice data
			$this->invoice = json_decode($info['invoice'], true);

			unset($info['invoice']);

			// This means the user is signed up for recurring donations
			foreach($this->invoice['lines']['data'] as $ili) {
				if(strcmp($ili['type'],	'subscription') === 0) {

					// we assume that the first line item is the most recent one
					// and the only relevant subscription.
					$this->subscription = $ili['id'];

					$interval = $ili['plan']['interval'];

					if(strcmp($interval, 'year') === 0) {
						$info['recurrence-string'] = 'Annual';
						$info['recurrence-type'] = RecurrenceTypes::ANNUAL;
					} elseif(strcmp($interval, 'month') === 0) {
						$info['recurrence-string'] = 'Monthly';
						$info['recurrence-type'] = RecurrenceTypes::MONTHLY;
					}

					// bail after first success.
					break;
				}
			}
		}
		$fmt = sprintf('%s - %s - %s - Online donation from %s.',
				$info['recurrence-string'], '%.2n', date('n/d/y'), home_url());

		$desc = money_format($fmt, $info['dollar-amount']);
		$info['desc'] = $desc;
	}


	// Find out if the donation li's will update the 
	// contact's membership level
	// we want the TOTAL amount of donations for this calendar year
	// and we want to know whether that passes the 75 dollar cutoff.
	private function recalc_sum(&$info) {
		$sum = 0;

		foreach($this->valid_donations as $donation) {
			// don't want to add empty amounts,
			// who knows what PHP would do
			if(is_numeric($donation['Amount__c'])) {
				$sum += $donation['Amount__c'];
			}
		}

		$sum += $info['dollar-amount'];

		$this->contact->Total_paid_this_year__c = $sum;

		// now we'll take into account the donations we expect
		// for the rest of the year from this donor.
		$sum += self::get_ext_amount($info['recurrence-type'],
				$info['dollar_amount']);


		if($sum >= 75) { 
			$this->contact->Type__c = 'Spark Member';
		} else {
			$this->contact->Type__c = 'Donor';
		}
		

		// Get text label for membership level
		$level = 'Donor';

		if($sum >= 75 && $sum < 100) {
			$level = 'Friend';
		} else if($sum >= 100 && $sum < 250) {
			$level = 'Member';
		} else if($sum >= 250 && $sum < 500) {
			$level = 'Affiliate';
		} else if($sum >= 500 && $sum < 1000) {
			$level = 'Sponsor';
		} else if($sum >= 1000 && $sum < 2500) {
			$level = 'Investor';
		} else if($sum >= 2500) {
			$level = 'Benefactor';
		}

		$this->contact->Member_Level__c = $level;
	}


	// Create the donation line item child object
	private function update_or_create_donation(&$info) {

		$dli_match_count = 0;
		foreach($this->valid_donations as $dli) {

			if(strcmp($dli['Stripe_Status__c'],'Pending') === 0) {

				// charge id, which would indicate a one-time donation
				if(strcmp($dli['Stripe_Id__c'], $info['charge-id']) === 0) {
					$dli_match_count++;

					// update donation with amount and success.
					$this->update_donation($info, $dli);

					// we need this for the email:
					if(strlen($dli['In_Honor_Of__c']) > 0) {
						$info['honor'] = sprintf('In Honor of: %s',
								$dli['In_Honor_Of__c']);
					}
		
				} else {

					$invoice_li = $this->invoice['lines']['data'];

					// there could be more than one subscription on an invoice?
					foreach($invoice_li as $ili) {
						if(strcmp($dli['Stripe_Id__c'], $ili['id']) === 0) {
							
							$dli_match_count++;

							// we need this for the email:
							if(strlen($dli['In_Honor_Of__c']) > 0) {
								$info['honor'] = sprintf('In Honor of: %s',
										$dli['In_Honor_Of__c']);
							}

							if(empty($info['honor'])) {
								$this->find_previous_donation_honor($info);
							}

							$this->update_donation($info, $dli);
						}
					}
				}
			}
		}

		if($dli_match_count === 0) {
			// this donation is part of a subscription,
			// and is not the first donation. Completely async!
			$this->find_previous_donation_honor($info);
			
			$this->create_standard_donation($info);
		}
	}


	private function find_previous_donation_honor(&$info) {

		if(isset($this->subscription)) {
			$stripe = new Stripe();
			$stripe->api();
			
			foreach($this->all_donations as $dli) {
				if(strlen($dli['In_Honor_Of__c']) > 0) {
					if(strlen($dli['Stripe_Id__c']) > 0) {
						$old_subscription =	$stripe->get_subscription_from_charge(
								$dli['Stripe_Id__c']);

						if(strcmp($old_subscription, $this->subscription) === 0) {
							$info['honor'] = sprintf('In Honor of: %s',
									$dli['In_Honor_Of__c']);
							return;
						}
					}
				}
			}
		}
		$info['honor'] = '';
	}


	private function update_donation(&$info, &$donation_li) {
		$donation = (object) $donation_li;

		$donation->Amount__c = $info['dollar-amount'];
		$donation->Stripe_Id__c = $info['charge-id'];
		$donation->Description__c = parent::string_truncate($info['desc'], 255);
		$donation->Stripe_Status__c =
						self::event_type_to_stripe_status($info['type']);

		parent::$connection->update(array($donation), 'Donation__c');
	}

	private function create_standard_donation(&$info) {
		$donation = new \stdClass();

		$donation->Type__c = 'Membership';
		$donation->Amount__c = $info['dollar-amount'];
		$donation->Contact__c = $this->contact->Id;
		$donation->Stripe_Id__c = $info['charge-id'];
		$donation->Description__c = parent::string_truncate($info['desc'], 255);
		$donation->Donation_Date__c = date(parent::$DATE_FORMAT);
		// there is no in-honor-of info here. :(

		$donation->Stripe_Status__c =
				self::event_type_to_stripe_status($info['type']);

		parent::create(array($donation), 'Donation__c');
	}


	// I made the decision to cut down on a few of the different types
	// of charge that stripe exposes. This function maps them to the
	// Stripe status picklist on the donation object.
	private function event_type_to_stripe_status($type, &$dispute = null) {
		switch($type) {
			case 'charge.dispute.create':
			case 'charge.dispute.updated':
			case 'charge.dispute.closed':
				if(is_null($dispute)) {
					return 'Disputed';
				} else {
					// We only need this for the dispute.closed type
					if($dispute['status'] == 'won') {
						return 'Success';
					} elseif($dispute['status'] == 'lost') {
						return 'Chargedback';
					}
				}

			case 'charge.disputed.funds_withdrawn':
				return 'Chargedback';

			case 'charge.captured':
			case 'charge.succeeded':
			case 'charge.dispute.funds_reinstated':
			case 'charge.updated': // ??? XXX not sure.
				return 'Success';

			case 'charge.failed':
				return 'Failed';

			case 'charge.refunded':
				return 'Refunded';
			
			default: // XXX none type: --none--
				return '';
		}
	}


	// Send an email to the Spark team
	// Send an email to our lovely donor
	private function send_email(&$info) {

		switch($info['recurrence-type']) {
			case RecurrenceTypes::MONTHLY: 
					$template = self::$DONOR_MONTHLY_TEMPLATE; break;
			case RecurrenceTypes::ANNUAL: 
					$template = self::$DONOR_ANNUAL_TEMPLATE; break;
			case RecurrenceTypes::ONE_TIME: 
					$template = self::$DONOR_SINGLE_TEMPLATE; break;
		}

		$donor_email = new \SingleEmailMessage();
		$donor_email->setTemplateId($template);
		$donor_email->setTargetObjectId($this->contact->Id);
		$donor_email->setReplyTo(get_option('sf_email_reply_to'));
		$donor_email->setSenderDisplayName(self::$DISPLAY_NAME);


		$result = parent::$connection->sendSingleEmail(array($donor_email));

		$errors = array_pop($result)->errors; 
		if(count($errors) > 0) {
			sdf_message_handler(MessageTypes::LOG,
					__FUNCTION__ . ' : Donor email failure! ' 
					. $errors[0]->message);
		}


		// Alert email

		$body = <<<EOF
A donation has been made!

Name: {$this->contact->FirstName} {$this->contact->LastName}
Amount: {$info['amount-string']}
Recurrence: {$info['recurrence-string']}
Email: {$this->contact->Email}
Location: {$this->contact->MailingCity}, {$this->contact->MailingState}
{$info['honor']}
Salesforce Link: https://na32.salesforce.com/{$this->contact->Id}
EOF;

		$spark_email = new \SingleEmailMessage();
		$spark_email->setSenderDisplayName('Spark Donations');
		$spark_email->setPlainTextBody($body);
		$spark_email->setSubject('New Donation Alert');
		$spark_email->setToAddresses(explode(', ', 
				get_option('alert_email_list')));


		$result = parent::$connection->sendSingleEmail(array($spark_email));

		$errors = array_pop($result)->errors; 
		if(count($errors) > 0) {
			sdf_message_handler(MessageTypes::LOG,
					__FUNCTION__ . ' : Alert email failure! ' 
					. $e->faultstring);
		}
	}
} // end class ?>
