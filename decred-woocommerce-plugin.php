<?php
// Copyright (c) 2021 The Decred developers
// Use of this source code is governed by an ISC
// license that can be found in the LICENSE file.

/*
 * Plugin Name: WooCommerce Decred Payments
 * Plugin URI: https://github.com/decred/decred-woocommerce-plugin
 * Description: Take Decred payments on your store.
 * Author: Decred Developers
 * Author URI: https://decred.org
 * Version: 0.1
 */

// prevent direct access
if (!defined('ABSPATH')) exit;

// make sure WooCommerce is active
if (!in_array ('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

// directory and file pointers
define('DCR_PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)));
define('DCR_PLUGIN_FILE', __FILE__);
define('DCR_ABS_PATH', dirname(DCR_PLUGIN_FILE));
define('DCR_PLUGIN_BASENAME', plugin_basename(DCR_PLUGIN_FILE));

// include decred-php-api dependencies
include __DIR__.'/vendor/autoload.php';
use Decred\Crypto\ExtendedKey;
use Decred\Data\Transaction;


global $woocommerce;
add_filter( 'woocommerce_payment_gateways', 'decred_add_gateway_class' );
function decred_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Decred_Gateway'; // our class name is here
	return $gateways;
}

// initialize gateway class after all plugins loaded
add_action( 'plugins_loaded', 'decred_init_gateway_class' );


function decred_init_gateway_class() {

	// Do nothing, if WooCommerce is not available
	if (!class_exists('WC_Payment_Gateway'))
	return;

	// Vendor
	if (!class_exists('QRinput')) {
        	require_once(plugin_basename('src/vendor/phpqrcode.php'));
	}

	class WC_Decred_Gateway extends WC_Payment_Gateway {

		// Configuration
		protected $config;

 		/**
 		 * Class constructor
 		 */
 		public function __construct() {

			$this->id = 'decred'; // payment gateway plugin ID
			$this->icon = DCR_PLUGIN_DIR . '/assets/img/dcr-accepted-positive.png'; // URL of the icon that will be displayed on checkout page near your gateway name
			$this->has_fields = true; // you need a custom form for a refund address
			$this->method_title = 'Decred on-chain Payment';
			$this->method_description = 'Pay with a Decred on-chain transaction'; // will be displayed on the options page

			// gatew<ays can support subscriptions, refunds, saved payment methods,
			// but we currently support simple payments
			$this->supports = array(
				'products'
			);

			// method with all the options fields
			$this->init_form_fields();

			// load the settings.
			$this->init_settings();
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled = $this->get_option( 'enabled' );
			$this->testmode = 'yes' === $this->get_option( 'testmode' );
			$this->private_key = $this->testmode ? $this->get_option( 'testnet_xpub' ) : $this->get_option( 'mainnet_xpub' );

            // Set network to work with decred-php-api
            if ( $this->testmode = 'yes' )  {
                $this->network = \Decred\TestNet::instance();
            }else{
                $this->network = \Decred\Mainnet::instance();
            }

		    // manipulate the order receive page with all payment information and logic
		    add_action( 'woocommerce_before_thankyou', array( $this, 'decred_add_content_thankyou') );


			// this action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// this action hook adds logic to include custom js and css 
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			if ( $this->testmode ) {
                $this->description = ' <hr />DECRED TESTNET MODE ENABLED. In testnet mode no real coins are used. Get testnet coins at <a href="https://faucet.decred.org">faucet.decred.org</a>.';
                $this->description  = trim( $this->description );
            }
 		}

		// plugin options and settings for the administration panel
 		public function init_form_fields(){

			$this->form_fields = array(
				'enabled' => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable Decred Payment Gateway',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'Decred on-chain payment',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay with a Decred on-chain transaction.',
				),
				'testmode' => array(
					'title'       => 'Testnet mode',
					'label'       => 'Enable Testnet Mode',
					'type'        => 'checkbox',
					'description' => 'Place the Decred payment gateway in testnet mode using testnet XPub keys.',
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'testnet_xpub' => array(
					'title'       => 'Testnet XPub Key',
					'type'        => 'text',
					'description' => 'Enter your testnet XPUB that you can export with the cli command: <em>dcrctl --wallet getmasterpubkey [default]</em>',
				),
				'mainnet_xpub' => array(
					'title'       => 'Mainnet XPub Key',
					'type'        => 'text',
					'description' => 'Enter your mainnet XPUB that you can export with the cli command: <em>dcrctl --wallet getmasterpubkey [default]</em>'
				)
			);

	 	}

		// add a custom field on the order form for a refund address and add a testnet disclaimer with faucet link if enabled
		public function payment_fields() {
			// display disclaimer before custom input field for refund address
			if ( $this->description ) {

				// display the description with <p> tags etc.
				echo wpautop( wp_kses_post( $this->description ) );
			}

			// echo the form
			echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-decred-form wc-payment-form" style="background:transparent;">';

			// echo the input for the refundaddress
			echo '<hr /><div class="form-row form-row-wide"><label>Decred Refund Address <span class="required">*</span></label>
				<input id="decred_refundaddress" name="decred_refundaddress" type="text" autocomplete="off">
				</div>
				<div class="clear"></div>';

			do_action( 'woocommerce_decred_form_end', $this->id );

			echo '<div class="clear"></div></fieldset>';
		}

		// custom JS and CSS inclusions
	 	public function payment_scripts() {
			if( is_page('checkout') && !empty( is_wc_endpoint_url('order-received') )){
			// custom JS to reload payment informations
			wp_register_script( 'woocommerce_decred', DCR_PLUGIN_DIR.'/assets/js/decred.js', array( 'jquery' ), false, false );
                        wp_enqueue_script( 'woocommerce_decred' );

			}
	 	}

		// first step after submit button pressed is check form inputs
		public function validate_fields() {
			// error when no refund address is given
			if( empty( $_POST[ 'decred_refundaddress' ]) ) {
					wc_add_notice(  '<strong>Decred refund address</strong> is a required field!', 'error' );
					return false;
			}

			// validate the refund address
			if ( $this->testmode ) {
				// validate a testnet address
				if (  !preg_match('/^[Ts][a-km-zA-HJ-NP-Z1-9]{31,34}$/', $_POST[ 'decred_refundaddress' ]) ) {
					wc_add_notice(  'Not a valid Decred testnet address!', 'error' );
					return false;
				}
			}else {
				// validate a mainnet address
				if (  !preg_match('/^[Ds][a-km-zA-HJ-NP-Z1-9]{31,34}$/', $_POST[ 'decred_refundaddress' ]) ) {
					wc_add_notice(  'Not a valid Decred mainnet address!', 'error' );
									return false;
				}
			}
			return true;
		}


		// processing after validate_fields, usually 3rd party payment processing logic
		public function process_payment( $order_id ) {

			// get order details
			$order = wc_get_order( $order_id );

			// are we on testnet or mainnet
			$environment = ( $this->testmode == "yes" ) ? 'TRUE' : 'FALSE';

			// decide which URL to use as blockchain explorer
			$blockchainexplorer_url = ( "FALSE" == $environment )
					   ? 'https://explorer.dcrdata.org/'
					   : 'https://testnet.dcrdata.org/';

			// store the refundaddress in the order db as metadata and current session variable
			$dcr_refundaddress  =  sanitize_text_field( $_POST[ 'decred_refundaddress' ]);
			WC()->session->set('decred_refundaddress', $dcr_refundaddress);
			update_post_meta($order_id, 'dcr_refundaddress', $dcr_refundaddress);

			// use the xpub of the current environment (test/main) and generate a DCR payment address:
			$master = $this->private_key;

			// Default account HD public key object
			$object  = ExtendedKey::fromString($this->private_key);

			// get order index id and use for hd address index
			$orderindex =  $order->get_id();
			update_post_meta($order_id, 'dcr_orderindex', $orderindex);

                        // get payment address
                        $dcr_paymentaddress = $object
                            ->publicChildKey(0)
                            ->publicChildKey($orderindex)
                            ->getAddress();

			// add paymentaddress to order meta data and session data
			update_post_meta($order_id, 'dcr_paymentaddress', $dcr_paymentaddress);
			WC()->session->set('decred_paymentaddress', $dcr_paymentaddress);
			$dcr_amount = WC()->session->get('dcr_amount', true);
			update_post_meta($order_id, 'dcr_amount', $dcr_amount);

			// add orderNote for admin
			$orderNote = sprintf('Awaiting payment of %s DCR to payment address %s',
			$dcr_amount,
			$dcr_paymentaddress);

			// Emails are fired once we update status to on-hold, so hook additional email details here
			add_action('woocommerce_email_order_details', array( $this, 'payment_email_details' ), 10, 4);

			// updating order status to on-hold fires customer email
			$order->update_status( 'on-hold', $orderNote );


			// after storing required informations return success and redirect to order received page
		        return array(
				'result' => 'success',
				'redirect'  => $this->get_return_url( $order ),
			);
	 	}

		// this is the order received page, followes after the process payment step
		// order received page - add Decred Payment informations and qr code on top
		public function decred_add_content_thankyou( $order_id ) {

			// get the order_id and the order object
			$order = wc_get_order( $order_id );

			// get the order details
			$dcr_refundaddress = get_post_meta( $order_id, 'dcr_refundaddress', true );
			$dcr_amount = get_post_meta( $order_id, 'dcr_amount', true );
			$dcr_paymentaddress = get_post_meta( $order_id, 'dcr_paymentaddress', true );
			$dcr_paymenttx = get_post_meta( $order_id, 'dcr_paymenttx', true );
			$dcr_paymentconfs = get_post_meta( $order_id, 'dcr_paymentconfs', true );
			$order_status = $order->get_status();
			$order_key =  $order->get_order_key();
			// generate url of this page for later payment, bookmark and prepare to send payment link in  email
			$return_url = $order->get_checkout_order_received_url();

			// prepare QR code
			$dirWrite = DCR_ABS_PATH . '/assets/img/';
			$formattedName = "decred";
			$cryptoTotal = $dcr_amount;
			$qrData = $formattedName . ':' . $dcr_paymentaddress . '?amount=' . $cryptoTotal;
			QRcode::png($qrData, $dirWrite . $order_key.'_qrcode.png', QR_ECLEVEL_H);
			$qrcode_src = DCR_PLUGIN_DIR . '/assets/img/'.$order_key.'_qrcode.png';

			// payments update
			// get all orders with status pending-payment
			$open_orders = wc_get_orders(array(
				'limit'=>-1,
				'type'=> 'shop_order',
				'status'=> array( 'on-hold' ),
				));
	
				// check open orders for payment on-chain
				foreach($open_orders as $open_order) {
					$checkorder = wc_get_order( $open_order );
					$openorder_id  =  $checkorder->get_id();
					$openorder_paymentaddress = get_post_meta( $openorder_id, 'dcr_paymentaddress', true );
					$openorder_dcr_amount = get_post_meta( $openorder_id, 'dcr_amount', true );
					$openorder_paymenttx = get_post_meta( $openorder_id, 'dcr_paymenttx', true );
					$openorder_status = $checkorder->get_status();
					$openorder_paymentconfs = get_post_meta( $openorder_id, 'dcr_paymentconfs', true );
					// Get dcrdata API client
					$client = $this->network->getDataClient();
	
					// get all transactions regarding the openorder paymentaddress
					$transactions = $client->getAddressRaw($openorder_paymentaddress);
	
					foreach ($transactions as $transaction) {
						$openorder_paymentconfs = $transaction->getConfirmations();
						if($openorder_paymentconfs == "") {
							$openorder_paymentfonfs =  "0";
						}

						// if transaction fills the expected DCR amount to be paid
						if ($transaction->getOutAmount($openorder_paymentaddress) >= $openorder_dcr_amount) {
							// store payment data into the order meta data
							update_post_meta( $openorder_id, 'dcr_paymenttx', $transaction->getTxid() );
							update_post_meta( $openorder_id, 'dcr_paymentamount', $transaction->getOutAmount($openorder_paymentaddress) );
							update_post_meta( $openorder_id, 'dcr_paymentconfs', $openorder_paymentconfs );
							if ($openorder_paymentconfs >= 2) {
								$checkorder->update_status( 'completed' );
							}
						}
					}
			 }

			// prepare incoming transaction information display for user:
			$additional_display = "";
			if ( $dcr_paymenttx != "" ) {
				$additional_display = "<br /><strong>Transaction detected: </strong>".$dcr_paymenttx."<br />".$dcr_paymentconfs."/2 Confirmations";
			}

			// render the html for the payment output on the thank you page
			echo '
			<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">

				<li class="woocommerce-order-overview__order order">
					<strong>DCR Payment QR</strong>	<img src="'.$qrcode_src.'" style="width:222px;height:222px;max-height:222px">
				</li>

				<li class="woocommerce-order-overview__date date">
					<table><tr>
					<td>Send this amount:<br /><strong>'.$dcr_amount.' DCR</strong></td>
					<td><div id="payment-status">Order status:<br /><strong>'.$order_status.'</strong>'.$additional_display.'</div></td>
					</tr></table>
				</li>

				<li class="woocommerce-order-overview__total total">
					To this Decred Address:					<strong>'.$dcr_paymentaddress.'</strong>
				</li>

				<li class="woocommerce-order-overview__payment-method method">
					Payment Link:						<strong>'.$formattedName . ':' . $dcr_paymentaddress . '?amount=' . $cryptoTotal.'</strong>
					</li>
								<li class="woocommerce-order-overview__total total">
										Bookmark this page to check your payment status later:	<strong><small><a href="'.$return_url.'">'.$return_url.'</a></small></strong>
								</li>
			</ul>';
		}

        	public function payment_email_details($order, $sent_to_admin, $plain_text, $email) {
			$order_id = $order->get_id();
			$order_key =  $order->get_order_key();
                        $dcr_refundaddress = get_post_meta( $order_id, 'dcr_refundaddress', true );
                        $dcr_amount = get_post_meta( $order_id, 'dcr_amount', true );
                        $dcr_paymentaddress = get_post_meta( $order_id, 'dcr_paymentaddress', true );
			$return_url = $order->get_checkout_order_received_url();
			$qrcode_src = DCR_PLUGIN_DIR . '/assets/img/'.$order_key.'_qrcode.png';
        		echo '
        			<h2>Decred Payment Details</h2>
        			<p>QR Code Payment: </p>
        			<div style="margin-bottom:12px;">
            			<img  src="' . $qrcode_src . '" />
        			</div>
        			<p>
            				Send this amount: ' . $dcr_amount . ' DCR
        			</p>
        			<p>
            				to this Decred address: ' . $dcr_paymentaddress . '
        			</p>';
    		}

 	}

	// Further display manipulations of checkout pages
	// order received page - change title
	add_filter( 'woocommerce_endpoint_order-received_title', 'decred_payment_title' );
	function decred_payment_title( $old_title ){
 		return 'Decred Payment';
	}

	// add decred accepted here sticker before order submit button on checkout page
	add_action( 'woocommerce_review_order_before_submit', 'decred_add_accepted_here_sticker_before_submit', 10 );
	function decred_add_accepted_here_sticker_before_submit(){
		$dirWrite = DCR_PLUGIN_DIR . '/assets/img/';
                echo '<img src="'.$dirWrite.'dcr-accepted-positive.png">';
	}

	// add total price in DCR on checkout page
	add_action( 'woocommerce_review_order_before_payment', 'decred_add_total_in_dcr', 1 );
	function decred_add_total_in_dcr( $order_id ) {
		// get current DCR price in USD
		$json_url = "https://explorer.dcrdata.org/api/exchanges";
		$json = file_get_contents($json_url);
		$data = json_decode($json, TRUE);
		$amount = WC()->cart->cart_contents_total + WC()->cart->tax_total;

		// convert cart total sum into DCR
		$dcr_amount = round($amount / $data['price'],4);
		WC()->session->set('dcr_amount', $dcr_amount);	
		echo '<strong>Total in DCR</strong> '.$dcr_amount;
	}

}
