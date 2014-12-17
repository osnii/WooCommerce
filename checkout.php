<?php
/**
 * Plugin Name:  Paymium WooCommerce
 * Plugin URI:   http://www.paymium.com
 * Description:  This plugin adds the Paymium gateway to your WooCommerce plugin.
 * Version:      1.3
 * Author:       Paymium
 * Author URI:   http://www.paymium.com
 * License:      MIT
 *
 * Text Domain: woocommerce-paymium
 * Domain Path: /i18n/languages/
 *
 */


if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins' )))) {

  // Writes a message to the log file
  function py_log($contents) {
    $file = plugin_dir_path(__FILE__).'paymium_log.txt';
    file_put_contents($file, date('Y-m-d H:i:s')." : ", FILE_APPEND);

    if (is_array($contents))
      file_put_contents($file, var_export($contents, true)."\n", FILE_APPEND);		
    else if (is_object($contents))
      file_put_contents($file, json_encode($contents)."\n", FILE_APPEND);
    else
      file_put_contents($file, $contents."\n", FILE_APPEND);
  }

  // Perform a POST request to the Paymium backend
  function doCurl($url, $api_key, $api_secret, $post = false) {

    py_log('Making curl request to  : ' . $url);
    py_log('Parameters              : ' . json_encode($post));

    $curl = curl_init($url);
    $length = 0;

    $api_nonce = round(microtime(true) * 1000);
    $api_to_sign = $api_nonce . $url;

    if ($post) {	
      curl_setopt($curl, CURLOPT_POST, 1);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
      $length = strlen($post);
      $api_to_sign = "$api_to_sign$post";
    }

    $api_signature = hash_hmac('sha256', $api_to_sign, $api_secret);

    $header = array(
      "Content-Type: application/json",
      "Content-Length: $length",
      'X-Paymium-Plugin: woocommerce-1.3.0',
      "Api-Token: $api_key",
      "Api-Nonce: $api_nonce",
      "Api-Signature: $api_signature"
    );

    if (parse_url($url)['scheme'] == 'https') {
      curl_setopt($curl, CURLOPT_PORT, 443);
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
      curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
    }
    else {
      curl_setopt($curl, CURLOPT_PORT, 80);
    }

    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

    $responseString = curl_exec($curl);

    py_log('Response                : ' . $responseString);

    if($responseString == false) {
      $response = curl_error($curl);
    } 
    else {
      $response = json_decode($responseString, true);
    }

    curl_close($curl);
    return $response;
  }

  // Create a payment request
  function pyCreateInvoice($order_id, $amount, $currency, $payment_split, $options = array()) {	
    $postOptions = array('amount', 'currency', 'payment_split');

    $post['merchant_reference'] = $order_id;
    $post['amount']             = $amount;
    $post['currency']           = $currency;
    $post['payment_split']      = $payment_split;
    $post['callback_url']       = $options['callback_url'];
    $post['redirect_url']       = $options['redirect_url'];
    $post['cancel_url']         = $options['cancel_url'];

    $post = json_encode($post);
    $request_uri = $options['gateway_url'] . '/api/v1/merchant/create_payment';

    $response = doCurl($request_uri, $options['api_key'], $options['api_secret'], $post);
    return $response;
  }

  function load_paymium_i18n() {
    $plugin_dir = basename(dirname(__FILE__));
    load_plugin_textdomain( 'woocommerce-paymium', false, $plugin_dir );
  }

  function declareWooPaymium() 
  {
    if (!class_exists('WC_Payment_Gateways')) 
      return;

    // The main payment gateway class
    class WC_Paymium extends WC_Payment_Gateway 
    {

      public function __construct() 
      {
        $this->id         = 'paymium';
        $this->has_fields = false;

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Define user set variables
        $this->title        = $this->settings['title'];
        $this->description  = $this->settings['description'];

        add_action('woocommerce_update_options_payment_gateways_'.$this->id, array(&$this, 'process_admin_options'));
        add_action('woocommerce_api_wc_paymium', array($this, 'handle_callback'));
      }

      // Initializes the admin settings fields
      function init_form_fields() {
        $this->form_fields = array(
          'enabled' => array(
            'title' => __( 'Enable/Disable', 'woocommerce-paymium' ),
            'type' => 'checkbox',
            'label' => __( 'Enable Paymium', 'woocommerce-paymium' ),
            'default' => 'yes'
          ),
          'api_key' => array(
            'title' => __('API key', 'woocommerce-paymium'),
            'type' => 'text',
            'description' => __('Enter the API key associated to your merchant API token', 'woocommerce-paymium'),
          ),
          'api_secret' => array(
            'title' => __('API secret', 'woocommerce-paymium'),
            'type' => 'text',
            'description' => __('Enter the API secret associated to your merchant API token', 'woocommerce-paymium'),
          ),
          'payment_split' => array(
            'title' => __( 'Currency conversion', 'woocommerce-paymium' ),
            'type' => 'select',
            'description' => __( 'Decide whether you want to receive payments in Bitcoin or in EUR', 'woocommerce-paymium' ),
            'default' => '1',
	    'options' => array(
		'1' => __( 'Convert to EUR', 'woocommerce-paymium' ),
		'0' => __( 'Keep in Bitcoin', 'woocommerce-paymium' )
	    ),
	  ),
          'gateway_url' => array(
            'title' => __( 'Gateway URL', 'woocommerce-paymium' ),
            'type' => 'text',
            'description' => __( 'It\'s usually not necessary to change this', 'woocommerce-paymium' ),
            'default' => __( 'https://paymium.com', 'woocommerce-paymium' )
          ),
          'title' => array(
            'title' => __( 'Title', 'woocommerce-paymium' ),
            'type' => 'text',
            'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-paymium' ),
            'default' => __( 'Bitcoin', 'woocommerce-paymium' )
          ),
          'description' => array(
            'title' => __( 'Customer Message', 'woocommerce-paymium' ),
            'type' => 'textarea',
            'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'woocommerce-paymium' ),
            'default' => __( 'You will be presented a Bitcoin address to which you should send your payment.', 'woocommerce-paymium' )
          ),
        );
      }

      // Handles the callbacks received from the Paymium backend
      function handle_callback() {
        $callback_body  = file_get_contents("php://input");
        $invoice_id     = json_decode($callback_body, true)['uuid'];

        py_log('------- HANDLING CALLBACK -------');
        py_log('Invoice ID : ' . $invoice_id);

        $api_key      = $this->settings['api_key'];
        $api_secret   = $this->settings['api_secret'];
        $gateway_url  = $this->settings['gateway_url'];
        $invoice      = doCurl("$gateway_url/api/v1/merchant/get_payment_private/$invoice_id", $api_key, $api_secret);

        $order    = new WC_Order($invoice['merchant_reference']);
        py_log($order);
        py_log('State : ' . $invoice['state']);

        switch($invoice['state']) {
        case 'processing':
          $order->add_order_note('Bitcoin payment received. Awaiting network confirmation and paid status.');
          break;

        case 'expired':
          $order->update_status('cancelled');
          $order->add_order_note('No payment was sent in the expected time-frame, the order has been cancelled.');
          break;

        case 'error':
          $order->update_status('failed');
          $order->add_order_note('An error occurred while processing this payment, please contact the Paymium support.');
          break;

        case 'paid':
          $order->update_status('processing');
          $order->payment_complete();
          $order->add_order_note('Bitcoin payment completed. Payment credited to your merchant account.');
          break;
        }

        py_log('------- DONE HANDLING CBK -------');
      }

      // Create an invoice, and redirect to it
      function process_payment($order_id) {

        global $woocommerce, $wpdb;

        $order = new WC_Order( $order_id );

        $thanks_link    = $this->get_return_url($order);
        $redirect       = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, $thanks_link));
        $callback_url   = WC()->api_request_url('WC_Paymium');
        $currency       = get_woocommerce_currency();
        $payment_split  = intval($this->settings['payment_split']);

        $options = array(
          'api_key' =>          $this->settings['api_key'],
          'api_secret' =>       $this->settings['api_secret'],
          'currency' =>         $currency,
          'redirect_url' =>     $redirect,
          'callback_url' =>     $callback_url,
          'cancel_url' =>       $woocommerce->cart->get_cart_url(),
          'gateway_url' =>      $this->settings['gateway_url'],
        );

        $invoice = pyCreateInvoice($order_id, $order->order_total, $currency, $payment_split, $options );
        $order->add_order_note(__('Awaiting payment notification from paymium.com', 'woocommerce-paymium'));

        py_log("Created invoice:");
        py_log($invoice);

        if (isset($invoice['errors']))
        {
          $order->add_order_note(var_export($invoice['errors'], true));
          $woocommerce->add_error(__('Error creating invoice.  Please try again or try another payment method.', 'woocommerce-paymium'));
        }
        else {
          $woocommerce->cart->empty_cart();

          return array(
            'result'    => 'success',
            'redirect'  => $this->settings['gateway_url'] . '/invoice/' . $invoice['uuid'],
          );
        }			 
      }

      public function admin_options() {
        ?>
          <h3><?php _e('Bitcoin Payment', 'woocommerce-paymium'); ?></h3>
          <p><?php _e('Allows bitcoin payments via Paymium.com', 'woocommerce-paymium'); ?></p>

          <table class="form-table">
            <?php $this->generate_settings_html(); ?>
          </table>
        <?php
      }

    }
  }

  function add_paymium_gateway($methods) {
    $methods[] = 'WC_Paymium'; 
    return $methods;
  }

  add_filter('woocommerce_payment_gateways', 'add_paymium_gateway' );
  add_action('plugins_loaded', 'declareWooPaymium', 0);
  add_action('plugins_loaded', 'load_paymium_i18n');
}
