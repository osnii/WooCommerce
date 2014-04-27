<?php
/*
Plugin Name:  Paymium Woocommerce
Plugin URI:   http://www.paymium.com
Description:  This plugin adds the Paymium gateway to your Woocommerce plugin.
Version:      1.0
Author:       David FRANCOIS
Author URI:   http://www.paymium.com
License:      MIT
 */

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins' )))) {

  // Writes a message to the log file
  function py_log($contents) {
    $file = plugin_dir_path(__FILE__).'paymium_log.txt';
    file_put_contents($file, date('Y-m-m H:i:s')." : ", FILE_APPEND);

    if (is_array($contents))
      file_put_contents($file, var_export($contents, true)."\n", FILE_APPEND);		
    else if (is_object($contents))
      file_put_contents($file, json_encode($contents)."\n", FILE_APPEND);
    else
      file_put_contents($file, $contents."\n", FILE_APPEND);
  }

  // Perform a POST request to the Paymium backend
  function doCurl($url, $post = false) {

    py_log('Making curl request to  : ' . $url);
    py_log('Parameters              : ' . json_encode($post));

    $curl = curl_init($url);
    $length = 0;

    if ($post) {	
      curl_setopt($curl, CURLOPT_POST, 1);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
      $length = strlen($post);
    }

    $header = array(
      "Content-Type: application/json",
      "Content-Length: $length",
      'X-Paymium-Plugin: woocommerce-1.0',
    );

    curl_setopt($curl, CURLOPT_PORT, 443);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
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
    $postOptions = array('amount', 'currency', 'payment_split', 'token');

    $post['token']              = $options['token'];
    $post['merchant_reference'] = $order_id;
    $post['amount']             = $amount;
    $post['currency']           = $currency;
    $post['payment_split']      = $payment_split;
    $post['callback_url']       = $options['callback_url'];
    $post['redirect_to']        = $options['redirect_to'];

    $post = json_encode($post);
    $request_uri = $options['gateway_url'] . '/api/v1/merchant/create_payment';

    $response = doCurl($request_uri, $post);
    return $response;
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
            'title' => __( 'Enable/Disable', 'woothemes' ),
            'type' => 'checkbox',
            'label' => __( 'Enable Paymium', 'woothemes' ),
            'default' => 'yes'
          ),
          'token' => array(
            'title' => __('Merchant token', 'woothemes'),
            'type' => 'text',
            'description' => __('Enter the merchant token associated to your Paymium account'),
          ),
          'payment_split' => array(
            'title' => __( 'Conversion split', 'woothemes' ),
            'type' => 'number',
            'description' => __( 'The percentage of funds to convert from BTC to the chosen currency', 'woothemes' ),
            'default' => 100
          ),
          'gateway_url' => array(
            'title' => __( 'Gateway URL', 'woothemes' ),
            'type' => 'text',
            'description' => __( 'It\'s usually not necessary to change this', 'woothemes' ),
            'default' => __( 'https://bitcoin-central.net', 'woothemes' )
          ),
          'title' => array(
            'title' => __( 'Title', 'woothemes' ),
            'type' => 'text',
            'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
            'default' => __( 'Bitcoin', 'woothemes' )
          ),
          'description' => array(
            'title' => __( 'Customer Message', 'woothemes' ),
            'type' => 'textarea',
            'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'woothemes' ),
            'default' => __( 'You will be presented a Bitcoin address to which you should send your payment.', 'woothemes' )
          ),
        );
      }

      // Handles the callbacks received from the Paymium backend
      function handle_callback() {
        $req_body   = file_get_contents("php://input");
        $signature  = $_SERVER['HTTP_X_PAYMENT_SIGNATURE'];
        $token      = $this->settings['token'];
        $hash       = hash('sha256', $this->settings['token'] . $req_body);

        // We check that the request is legitimate and hasn't been tampered with
        if ($signature === $hash) {
          $decoded  = json_decode($req_body, true);
          $order    = new WC_Order($decoded['merchant_reference']);
          py_log($order);

          switch($decoded['state']) {
            case 'processing':
              $order->add_order_note('Bitcoin payment received. Awaiting network confirmation and paid status.');
              break;
            case 'paid':
              $order->payment_complete();
              $order->add_order_note('Bitcoin payment completed. Payment credited to your merchant account.');
              break;
          }
        }
        else {
          py_log("Signature verification failure for invoice callback");

          py_log("Request body:");
          py_log($req_body);

          py_log("Our merchant token:");
          py_log($token);

          py_log("X-Payment-Signature header:");
          py_log($signature);

          py_log("Calculated hash:");
          py_log($hash);
        }
      }

      // Create an invoice, and redirect to it
      function process_payment($order_id) {

        global $woocommerce, $wpdb;

        $order = new WC_Order( $order_id );

        $thanks_link    = get_permalink(get_option('woocommerce_thanks_page_id'));
        $redirect       = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, $thanks_link));
        $callback_url   = WC()->api_request_url('WC_Paymium');
        $currency       = get_woocommerce_currency();
        $payment_split  = $this->settings['payment_split']/100;

        $options = array(
          'token' =>            $this->settings['token'],
          'currency' =>         $currency,
          'redirectURL' =>      $redirect,
          'callback_url' =>     $callback_url,
          'redirect_to' =>      $redirect,
          'gateway_url' =>      $this->settings['gateway_url'],
        );

        $invoice = pyCreateInvoice($order_id, $order->order_total, $currency, $payment_split, $options );
        $order->update_status('on-hold', __('Awaiting payment notification from paymium.com', 'woothemes'));

        py_log("Created invoice:");
        py_log($invoice);

        if (isset($invoice['errors']))
        {
          $order->add_order_note(var_export($invoice['errors'], true));
          $woocommerce->add_error(__('Error creating invoice.  Please try again or try another payment method.'));
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
          <h3><?php _e('Bitcoin Payment', 'woothemes'); ?></h3>
          <p><?php _e('Allows bitcoin payments via Paymium.com', 'woothemes'); ?></p>

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

}
