<?php
/**
 * omise Gateway Integration.
 *
 * Persisted by parent class MS_Model_Option. Singleton.
 *
 * @since  1.0.0
 * @package Membership2
 * @subpackage Model
 */
class MS_Gateway_Omise extends MS_Gateway {

	const ID = 'omise';

	/**
	 * Gateway singleton instance.
	 *
	 * @since  1.0.0
	 * @var string $instance
	 */
	public static $instance;

	/**
	 * omise test secret key (sandbox).
	 *
	 * @see https://support.omise.com/questions/where-do-i-find-my-api-keys
	 *
	 * @since  1.0.0
	 * @var string $test_secret_key
	 */
	protected $test_secret_key = '';

	/**
	 * omise Secret key (live).
	 *
	 * @since  1.0.0
	 * @var string $secret_key
	 */
	protected $secret_key = '';

	/**
	 * omise test publishable key (sandbox).
	 *
	 * @since  1.0.0
	 * @var string $test_publishable_key
	 */
	protected $test_publishable_key = '';

	/**
	 * omise publishable key (live).
	 *
	 * @since  1.0.0
	 * @var string $publishable_key
	 */
	protected $publishable_key = '';

	/**
	 * omise Vendor Logo.
	 *
	 * @since  1.0.3.4
	 * @var string $vendor_logo
	 */
	protected $vendor_logo = '';

	/**
	 * Instance of the shared omise API integration
	 *
	 * @since  1.0.0
	 * @var MS_Gateway_omise_Api $api
	 */
	protected $_api = null;

	/**
	 * Initialize the object.
	 *
	 * @since  1.0.0
	 * @internal
	 */
	public function after_load() {
		parent::after_load();
		$this->_api = MS_Factory::load( 'MS_Gateway_Omise_Api' );

		$this->id = self::ID;
		$this->name = __( 'Omise Single Gateway', 'membership2' );
		$this->group = 'Omise';
		$this->manual_payment = true; // Recurring billed/paid manually
		$this->pro_rate = true;

                $this->add_filter(
                        'ms_model_pages_get_ms_page_url',
                        'ms_model_pages_get_ms_page_url_cb',
                        99, 4
                );
	}

        /**
	 * Force SSL when omise in Live mode
	 *
	 * @since  1.0.2.5
	 *
	 * @param String $url The modified or raw URL
	 * @param String $page_type Check if this is a membership page
	 * @param Bool $ssl If SSL enabled or not
	 * @param Int $site_id The ID of site
	 *
	 * @return String $url Modified or raw URL
	 */
        public function ms_model_pages_get_ms_page_url_cb( $url, $page_type, $ssl, $site_id ) {
            /**
             * Constant M2_FORCE_NO_SSL
             *
             * It's needed, if :
             *      - the user has no SSL
             *      - the user has SSL but doesn't want to force
             *      - The user has multiple gateways like Paypal and omise and doesn't want to force
             *
             * If the user has SSL certificate, this rule won't work
             */
            if( ! defined( 'M2_FORCE_NO_SSL' ) ){
                if ( $this->active && $this->is_live_mode() ) {
                    if( $page_type == MS_Model_Pages::MS_PAGE_MEMBERSHIPS || $page_type == MS_Model_Pages::MS_PAGE_REGISTER ) {
                        $url = MS_Helper_Utility::get_ssl_url( $url );
                    }
                }
            }

	    return $url;
        }

	/**
	 * Processes purchase action.
	 *
	 * @since  1.0.0
	 * @api
	 *
	 * @param MS_Model_Relationship $subscription The related membership relationship.
	 */
	public function process_purchase( $subscription ) {
		$success = false;
		$note = '';
		$token = '';
		$external_id = '';
		$error = false;

		do_action(
			'ms_gateway_omise_process_purchase_before',
			$subscription,
			$this
		);
		$this->_api->set_gateway( $this );

		$member = $subscription->get_member();
		$invoice = $subscription->get_current_invoice();

		if ( ! empty( $_POST['omiseToken'] ) ) {
			lib3()->array->strip_slashes( $_POST, 'omiseToken' );

			$token = $_POST['omiseToken'];
			$external_id = $token;
			try {
				$customer = $this->_api->get_omise_customer( $member, $token );
				if ( 0 == $invoice->total ) {
					// Free, just process.
					$invoice->changed();
					$success = true;
					$note = __( 'No payment for free membership', 'membership2' );
				} else {
					// Send request to gateway.
					$charge = $this->_api->charge(
						$token,
						$invoice->total,
						$invoice->currency,
						$invoice->name
					);
					if ( true == $charge->offsetGet('paid') ) {
						$invoice->pay_it( self::ID, $charge->offsetGet('id') );
						$note = __( 'Payment successful', 'membership2' );
						$note .= ' - Token: ' . $token;
						$success = true;
					} else {
						$note = __( 'omise payment failed', 'membership2' );
					}
				}
			} catch ( Exception $e ) {
				$note = 'omise error: '. $e->getMessage();
				MS_Model_Event::save_event( MS_Model_Event::TYPE_PAYMENT_FAILED, $subscription );
				MS_Helper_Debug::log( $note );
				$error = $e;
			}
		} else {
			$note = 'omise gateway token not found.';
			MS_Helper_Debug::log( $note );
		}

		do_action(
			'ms_gateway_transaction_log',
			self::ID, // gateway ID
			'process', // request|process|handle
			$success, // success flag
			$subscription->id, // subscription ID
			$invoice->id, // invoice ID
			$invoice->total, // charged amount
			$note, // Descriptive text
			$external_id // External ID
		);
		
		// if ( $error ) {
		// 	throw $e;
		// }

		return apply_filters(
			'ms_gateway_omise_process_purchase',
			$invoice,
			$this
		);
	}

	/**
	 * Request automatic payment to the gateway.
	 *
	 * @since  1.0.0
	 * @api
	 *
	 * @param MS_Model_Relationship $subscription The related membership relationship.
	 * @return bool True on success.
	 */
	public function request_payment( $subscription ) {
		$was_paid = false;
		$note = '';
		$external_id = '';

		do_action(
			'ms_gateway_omise_request_payment_before',
			$subscription,
			$this
		);
		$this->_api->set_gateway( $this );

		$member = $subscription->get_member();
		$invoice = $subscription->get_current_invoice();

		if ( ! $invoice->is_paid() ) {
			try {
				$customer = $this->_api->find_customer( $member );

				if ( ! empty( $customer ) ) {
					if ( 0 == $invoice->total ) {
						$invoice->changed();
						$success = true;
						$note = __( 'No payment for free membership', 'membership2' );
					} else {
						$charge = $this->_api->charge(
							$customer,
							$invoice->total,
							$invoice->currency,
							$invoice->name
						);
						$external_id = $charge->offsetGet('id');

						if ( true == $charge->offsetGet('paid') ) {
							$was_paid = true;
							$invoice->pay_it( self::ID, $external_id );
							$note = __( 'Payment successful', 'membership2' );
						} else {
							$note = __( 'omise payment failed', 'membership2' );
						}
					}
				} else {
					$note = "omise customer is empty for user $member->username";
					MS_Helper_Debug::log( $note );
				}
			} catch ( Exception $e ) {
				$note = 'omise error: '. $e->getMessage();
				MS_Model_Event::save_event( MS_Model_Event::TYPE_PAYMENT_FAILED, $subscription );
				MS_Helper_Debug::log( $note );
			}
		} else {
			// Invoice was already paid earlier.
			$was_paid = true;
			$note = __( 'Invoice already paid', 'membership2' );
		}

		do_action(
			'ms_gateway_transaction_log',
			self::ID, // gateway ID
			'request', // request|process|handle
			$was_paid, // success flag
			$subscription->id, // subscription ID
			$invoice->id, // invoice ID
			$invoice->total, // charged amount
			$note, // Descriptive text
			$external_id // External ID
		);

		do_action(
			'ms_gateway_omise_request_payment_after',
			$subscription,
			$was_paid,
			$this
		);

		return $was_paid;
	}

	/**
	 * Get omise publishable key.
	 *
	 * @since  1.0.0
	 * @api
	 *
	 * @return string The omise API publishable key.
	 */
	public function get_publishable_key() {
		$publishable_key = null;

		if ( $this->is_live_mode() ) {
			$publishable_key = $this->publishable_key;
		} else {
			$publishable_key = $this->test_publishable_key;
		}

		return apply_filters(
			'ms_gateway_omise_get_publishable_key',
			$publishable_key
		);
	}

	/**
	 * Get omise secret key.
	 *
	 * @since  1.0.0
	 * @internal The secret key should not be used outside this object!
	 *
	 * @return string The omise API secret key.
	 */
	public function get_secret_key() {
		$secret_key = null;

		if ( $this->is_live_mode() ) {
			$secret_key = $this->secret_key;
		} else {
			$secret_key = $this->test_secret_key;
		}

		return apply_filters(
			'ms_gateway_omise_get_secret_key',
			$secret_key
		);
	}

	/**
	 * Get omise Vendor Logo.
	 *
	 * @since  1.0.3.4
	 * @api
	 *
	 * @return string The omise Vendor Logo.
	 */

	public function get_vendor_logo() {
		$vendor_logo = null;

		$vendor_logo = $this->vendor_logo;

		return apply_filters(
			'ms_gateway_omise_get_vendor_logo',
			$vendor_logo
		);
	}

	/**
	 * Verify required fields.
	 *
	 * @since  1.0.0
	 * @api
	 *
	 * @return boolean True if configured.
	 */
	public function is_configured() {
		$key_pub = $this->get_publishable_key();
		$key_sec = $this->get_secret_key();

		$is_configured = ! ( empty( $key_pub ) || empty( $key_sec ) );

		return apply_filters(
			'ms_gateway_omise_is_configured',
			$is_configured
		);
	}

	/**
	 * Auto-update some fields of the _api instance if required.
	 *
	 * @since  1.0.0
	 * @internal
	 *
	 * @param string $key Field name.
	 * @param mixed $value Field value.
	 */
	public function __set( $key, $value ) {
		switch ( $key ) {
			case 'test_secret_key':
			case 'test_publishable_key':
			case 'secret_key':
			case 'publishable_key':
				$this->_api->$key = $value;
				break;
		}

		if ( property_exists( $this, $key ) ) {
			$this->$key = $value;
		}
	}
}
