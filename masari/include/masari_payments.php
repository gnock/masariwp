<?php

/* 
 * Main Gateway of Monero using a daemon online 
 * Authors: Serhack, cryptochangements and gnock
 * Modified to work with Masari
 */


class Masari_Gateway extends WC_Payment_Gateway
{
    private $reloadTime = 10000;
    private $discount;
    private $confirmed = false;
    private $masari_daemon;
    private $non_rpc = false;
    private $confirmations = 0;
    private $msr_tools;
    private $confirmations_wait;
	
	private $version;
	/** @var WC_Logger  */
	private $log;
	/** @var string|null  */
	private $host;
	/** @var string|null  */
	private $port;
	/** @var string|null  */
	private $address;
	/** @var string|null  */
	private $viewKey;
	/** @var string|null  */
	private $accept_zero_conf;
	/** @var string|null  */
	private $use_viewKey;
	/** @var string|null  */
	private $use_rpc;
	/** @var bool  */
	private $zero_confirm;
	/** @var string  */
	private $darkTheme;
	/** @var bool  */
	private $mempool_tx_found = false;
	
	

    function __construct()
    {
        $this->id = "masari_gateway";
        $this->method_title = __("Masari GateWay", 'masari_gateway');
        $this->method_description = __("Masari Payment Gateway Plug-in for WooCommerce. You can find more information about this payment gateway on our website. You'll need a daemon online for your address.", 'masari_gateway');
        $this->title = __("Masari Gateway", 'masari_gateway');
        $this->version = "2.0";
        //
        $this->icon = apply_filters('woocommerce_offline_icon', '');
        $this->has_fields = false;

        $this->log = new WC_Logger();

        $this->init_form_fields();
		$this->darkTheme = $this->get_option('darkTheme');
        $this->host = $this->get_option('daemon_host');
        $this->port = $this->get_option('daemon_port');
        $this->address = $this->get_option('masari_address');
        $this->viewKey = $this->get_option('viewKey');
        $this->discount = $this->get_option('discount');
        $this->confirmations_wait = $this->get_option('confs');
        
        $this->use_viewKey = $this->get_option('use_viewKey');
        $this->use_rpc = $this->get_option('use_rpc');
        
        if($this->use_viewKey == 'yes')
        {
            $this->non_rpc = true;
        }
        if($this->use_rpc == 'yes')
        {
            $this->non_rpc = false;
        }
        if($this->confirmations_wait == 0)
        {
            $this->zero_confirm = true;
        }
        // After init_settings() is called, you can get the settings and load them into variables, e.g:
        // $this->title = $this->get_option('title' );
        $this->init_settings();

        // Turn these settings into variables we can use
        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }

        add_action('admin_notices', array($this, 'do_ssl_check'));
        add_action('admin_notices', array($this, 'validate_fields'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'instruction'));
        if (is_admin()) {
            /* Save Settings */
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 2);
        }
		
        $this->masari_daemon = new Masari_Library($this->host, $this->port);
        $this->msr_tools = new NodeTools();
    }
    
    public static function install(){
		global $wpdb;
		// This will create a table named whatever the payment id is inside the database "WordPress"
		$create_table = "CREATE TABLE IF NOT EXISTS ".$wpdb->prefix."masari_gateway_payments_rate(
									rate INT NOT NULL,
									payment_id VARCHAR(64) PRIMARY KEY,
									payed boolean NOT NULL DEFAULT 0,
									order_id INT NOT NULL
									)";
		$wpdb->query($create_table);
	}

    public function get_icon(){
		$pluginDirectory = plugin_dir_url(__FILE__).'../';
		return apply_filters('woocommerce_gateway_icon', '<img src="'.$pluginDirectory.'assets/masari_icon_small.png" />');
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable / Disable', 'masari_gateway'),
                'label' => __('Enable this payment gateway', 'masari_gateway'),
                'type' => 'checkbox',
                'default' => 'no'
            ),

            'title' => array(
                'title' => __('Title', 'masari_gateway'),
                'type' => 'text',
                'desc_tip' => __('Payment title the customer will see during the checkout process.', 'masari_gateway'),
                'default' => __('Masari Currency', 'masari_gateway')
            ),
            'description' => array(
                'title' => __('Description', 'masari_gateway'),
                'type' => 'textarea',
                'desc_tip' => __('Payment description the customer will see during the checkout process.', 'masari_gateway'),
                'default' => __('Pay securely using MSR.', 'masari_gateway')
            ),
			'darkTheme' => array(
				'title' => __('Dark theme', 'masari_gateway'),
				'label' => __('Enable the dark theme for the Masari payment box', 'masari_gateway'),
				'type' => 'checkbox',
				'default' => 'no'
			),
            
            'use_viewKey' => array(
                'title' => __('Use ViewKey', 'masari_gateway'),
                'label' => __(' Verify Transaction with ViewKey ', 'masari_gateway'),
                'type' => 'checkbox',
                'description' => __('Fill in the Address and ViewKey fields to verify transactions with your ViewKey', 'masari_gateway'),
                'default' => 'no'
            ),
            'masari_address' => array(
                'title' => __('Masari Address', 'masari_gateway'),
                'label' => __('Useful for people that have not a daemon online'),
                'type' => 'text',
                'desc_tip' => __('Masari Wallet Address', 'masari_gateway')
            ),
            'viewKey' => array(
                'title' => __('Secret ViewKey', 'masari_gateway'),
                'label' => __('Secret ViewKey'),
                'type' => 'text',
                'desc_tip' => __('Your secret ViewKey', 'masari_gateway')
            ),
            'use_rpc' => array(
                'title' => __('Use masari-wallet-rpc', 'masari_gateway'),
                'label' => __(' Verify transactions with the masari-wallet-rpc ', 'masari_gateway'),
                'type' => 'checkbox',
                'description' => __('This must be setup seperatly', 'masari_gateway'),
                'default' => 'no'
            ),
            'daemon_host' => array(
                'title' => __('Masari wallet rpc Host/ IP', 'masari_gateway'),
                'type' => 'text',
                'desc_tip' => __('This is the Daemon Host/IP to authorize the payment with port', 'masari_gateway'),
                'default' => 'localhost',
            ),
            'daemon_port' => array(
                'title' => __('Masari wallet rpc port', 'masari_gateway'),
                'type' => 'text',
                'desc_tip' => __('This is the Daemon Host/IP to authorize the payment with port', 'masari_gateway'),
                'default' => '38082',
            ),
            'discount' => array(
                'title' => __('% discount for using MSR', 'masari_gateway'),

                'desc_tip' => __('Provide a discount to your customers for making a private payment with MSR!', 'masari_gateway'),
                'description' => __('Do you want to spread the word about Masari? Offer a small discount! Leave this empty if you do not wish to provide a discount', 'masari_gateway'),
                'type' => __('number'),
                'default' => '5'

            ),
            'environment' => array(
                'title' => __(' Testnet', 'masari_gateway'),
                'label' => __(' Check this if you are using testnet ', 'masari_gateway'),
                'type' => 'checkbox',
                'description' => __('Check this box if you are using testnet', 'masari_gateway'),
                'default' => 'no'
            ),
            'confs' => array(
                'title' => __(' Confirmations to wait for', 'masari_gateway'),
                'type' => 'number',
                'description' => __('For small amounts transactions you can use zero. Three transactions is generally regarded as safe', 'masari_gateway'),
                'default' => '1'
            ),
            'onion_service' => array(
                'title' => __(' SSL warnings ', 'masari_gateway'),
                'label' => __(' Check to Silence SSL warnings', 'masari_gateway'),
                'type' => 'checkbox',
                'description' => __('Check this box if you are running on an Onion Service (Suppress SSL errors)', 'masari_gateway'),
                'default' => 'no'
            ),
        );
    }

    public function admin_options()
    {
        $this->log->add('masari_gateway', '[SUCCESS] Masari Settings OK');
        echo "<h1>Masari Payment Gateway</h1>";
        echo "<p>Welcome to Masari Extension for WooCommerce. Getting started: Make a connection with daemon";
        echo "<div style='border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#223079;background-color:#9ddff3;'>";
        
        if(!$this->non_rpc) // only try to get balance data if using wallet-rpc
            $this->getamountinfo();
        
        echo "</div>";
        echo "<table class='form-table'>";
        $this->generate_settings_html();
        echo "</table>";
        echo "<h4>Learn more about using masari-wallet-rpc <a href=\"https://github.com/masari-project/masariwp/blob/master/README.md\">here</a> and viewkeys <a href=\"https://getmonero.org/resources/moneropedia/viewkey.html\">here</a> </h4>";
    }

    public function getamountinfo()
    {
        $wallet_amount = $this->masari_daemon->getbalance();
        if (!isset($wallet_amount)) {
            $this->log->add('masari_gateway', '[ERROR] Can not connect to masari-wallet-rpc');
            echo "</br>Your balance is: Not Avaliable </br>";
            echo "Unlocked balance: Not Avaliable";
        }
        else
        {
            $real_wallet_amount = $wallet_amount['balance'] / 1000000000000;
            $real_amount_rounded = round($real_wallet_amount, 6);

            $unlocked_wallet_amount = $wallet_amount['unlocked_balance'] / 1000000000000;
            $unlocked_amount_rounded = round($unlocked_wallet_amount, 6);
        
            echo "Your balance is: " . $real_amount_rounded . " MSR </br>";
            echo "Unlocked balance: " . $unlocked_amount_rounded . " MSR </br>";
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $order->update_status('on-hold', __('Awaiting offline payment', 'masari_gateway'));
        // Reduce stock levels
        $order->reduce_order_stock();

        // Remove cart
        WC()->cart->empty_cart();

        // Return thank you redirect
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );

    }

    // Submit payment and handle response

    public function validate_fields()
    {
        if ($this->check_masari() != TRUE) {
            echo "<div class=\"error\"><p>Your Masari Address doesn't look valid. Have you checked it?</p></div>";
        }
        if(!$this->check_viewKey())
        {
            echo "<div class=\"error\"><p>Your ViewKey doesn't look valid. Have you checked it?</p></div>";
        }
        if($this->check_checkedBoxes())
        {
            echo "<div class=\"error\"><p>You must choose to either use masari-wallet-rpc or a ViewKey, not both</p></div>";
        }

    }

    // Validate fields

    public function check_masari()
    {
        $masari_address = $this->settings['masari_address'];
        if (strlen($masari_address) == 95) {
            return true;
        }
        return false;
    }
    public function check_viewKey()
    {
        if($this->use_viewKey == 'yes')
        {
            if (strlen($this->viewKey) == 64) {
                return true;
            }
            return false;
        }
        return true;
    }
    public function check_checkedBoxes()
    {
        if($this->use_viewKey == 'yes'){
            if($this->use_rpc == 'yes'){
                return true;
            }
        }
        return false;
    }
    
    public function is_virtual_in_cart($order_id){
        $order = wc_get_order( $order_id );
        $items = $order->get_items();
        
        foreach ( $items as $item ) {
            $product = new WC_Product( $item['product_id'] );
            if ( $product->is_virtual() ) {
                return true;
            }
        }
        
        return false;
    }
    
    public function instruction($order_id, $ajax=false)
    {
    	$pluginDirectory = plugin_dir_url(__FILE__).'../';
		$order = wc_get_order($order_id);
		$amount = floatval(preg_replace('#[^\d.]#', '', $order->get_total()));
		$payment_id = $this->get_paymentid_cookie($order_id);
		$currency = $order->get_currency();
		$amount_msr2 = $this->changeto( $amount, $currency, $payment_id, $order_id);
		$address = $this->address;
		
		$order->update_meta_data( "Payment ID", $payment_id);
		$order->update_meta_data( "Amount requested (MSR)", $amount_msr2);
		$order->save();
		
		$displayedPaymentAddress = null;
		$displayedPaymentId = null;
		$displayedDarkTheme = $this->darkTheme === 'yes';
	
		if($amount_msr2 !== null){
			$qrUri = "masari:$address?tx_payment_id=$payment_id";
			
			if($this->non_rpc){
				$displayedPaymentAddress = $address;
				$displayedPaymentId = $payment_id;
				
				if($this->zero_confirm){
					$this->verify_zero_conf($payment_id, $amount_msr2, $order_id);
				}else{
					$this->verify_non_rpc($payment_id, $amount_msr2, $order_id);
				}
			}else{
				$array_integrated_address = $this->masari_daemon->make_integrated_address($payment_id);
				if(!isset($array_integrated_address)){
					$this->log->add('Masari_Gateway', '[ERROR] Unable get integrated address');
					// Seems that we can't connect with daemon, then set array_integrated_address, little hack
					$array_integrated_address["integrated_address"] = $address;
					
					$displayedPaymentAddress = $address;
					$displayedPaymentId = $payment_id;
				}else{
					$displayedPaymentAddress = $array_integrated_address["integrated_address"];
					$displayedPaymentId = null;
				}
				$this->verify_payment($payment_id, $amount_msr2, $order);
			}
		}
		
		$displayedCurrentConfirmation = null;
		if($this->mempool_tx_found)
			$displayedCurrentConfirmation = 0;
		if($this->confirmations > 0)
			$displayedCurrentConfirmation = $this->confirmations;
		
		$displayedMaxConfirmation = (int)$this->confirmations_wait;
	
		$transactionConfirmed = $this->confirmed;
		$pluginIdentifier = 'masari_gateway';
		if(!$ajax){
			$ajaxurl = admin_url('admin-ajax.php');
			include 'html/paymentBox.php';
		}else{
			header('Content-Type: application/json');
			echo json_encode(array(
				'confirmed'=>$transactionConfirmed,
				'currentConfirmations'=>$displayedCurrentConfirmation,
				'maxConfirmation'=>$displayedMaxConfirmation,
				'paymentAddress'=>$displayedPaymentAddress,
				'paymentId'=>$displayedPaymentId,
				'amount'=>$amount_msr2
			));
		}
    }
    
    public function handlePaymentAjax(){
    	if(isset($_POST['order_id'])){
    		$this->instruction(htmlentities($_POST['order_id']), true);
		}else{
    		echo json_encode(array('error'=>'missing_order_id'));
		}
	}

    private function get_paymentid_cookie($order_id){
		global $wpdb;
		$stored_rate = $wpdb->get_results("SELECT rate FROM ".$wpdb->prefix."masari_gateway_payments_rate WHERE order_id='".$order_id."'");
    	if(count($stored_rate) > 0){
			return $stored_rate[0]->payment_id;
		}else{
			$size = $this->non_rpc ? 32 : 8;
			$payment_id = bin2hex(openssl_random_pseudo_bytes($size));
			return $payment_id;
		}
    }
	
    public function sanatize_id($payment_id)
    {
        // Limit payment id to alphanumeric characters
        $sanatized_id = preg_replace("/[^a-zA-Z0-9]+/", "", $payment_id);
		return $sanatized_id;
    }

    public function changeto($amount, $fiatCurrency, $payment_id, $order_id)
    {
        global $wpdb;
        $rows_num = $wpdb->get_results("SELECT count(*) as count FROM ".$wpdb->prefix."masari_gateway_payments_rate WHERE order_id='".$order_id."'");
        
        if ($rows_num[0]->count > 0) // Checks if the row has already been created or not
        {
            $stored_rate = $wpdb->get_results("SELECT rate FROM ".$wpdb->prefix."masari_gateway_payments_rate WHERE order_id='".$order_id."'");
			$rate = $stored_rate[0]->rate / 10000; //this will turn the stored rate back into a decimaled number
        } else // If the row has not been created then the live exchange rate will be grabbed and stored
        {
            $msr_live_price = $this->retrievePrice($fiatCurrency);
            if($msr_live_price === null)
            	return null;
            
            $live_for_storing = $msr_live_price * 10000; //This will remove the decimal so that it can easily be stored as an integer

            $wpdb->query("INSERT INTO ".$wpdb->prefix."masari_gateway_payments_rate (payment_id,rate,order_id) VALUES ('".$payment_id."',$live_for_storing, $order_id)");
			$rate = $msr_live_price;
        }
	
		if (isset($this->discount)) {
			$sanatized_discount = preg_replace('/[^0-9]/', '', $this->discount);
			$discount_decimal = $sanatized_discount / 100;
			$new_amount = $amount / $rate;
			$discount = $new_amount * $discount_decimal;
			$final_amount = $new_amount - $discount;
			$rounded_amount = round($final_amount, 12);
		} else {
			$new_amount = $amount / $rate;
			$rounded_amount = round($new_amount, 12); //the moneo wallet can't handle decimals smaller than 0.000000000001
		}

        return $rounded_amount;
    }

    
    public function retrievePrice($fiatCurrency){
		if($fiatCurrency === 'MSR')
			return 1;
   
		if(!(
			$fiatCurrency === 'AUD' ||
			$fiatCurrency === 'BRL' ||
			$fiatCurrency === 'CAD' ||
			$fiatCurrency === 'CHF' ||
			$fiatCurrency === 'CLP' ||
			$fiatCurrency === 'CNY' ||
			$fiatCurrency === 'CNY' ||
			$fiatCurrency === 'DKK' ||
			$fiatCurrency === 'EUR' ||
			$fiatCurrency === 'GBP' ||
			$fiatCurrency === 'HKD' ||
			$fiatCurrency === 'INR' ||
			$fiatCurrency === 'ISK' ||
			$fiatCurrency === 'JPY' ||
			$fiatCurrency === 'KRW' ||
			$fiatCurrency === 'NZD' ||
			$fiatCurrency === 'PLN' ||
			$fiatCurrency === 'RUB' ||
			$fiatCurrency === 'SEK' ||
			$fiatCurrency === 'SGD' ||
			$fiatCurrency === 'THB' ||
			$fiatCurrency === 'TWD' ||
			$fiatCurrency === 'USD'
		)){
			return null;
		}
	
	
		$fiatPrice = file_get_contents('https://blockchain.info/tobtc?currency='.$fiatCurrency.'&value=10000');
		if ($fiatPrice === false) {
			$this->log->add('masari_gateway', '[ERROR] Unable to get the market price of Masari');
			return null;
		}
		
		$btcPerFiat = $fiatPrice/10000;
    	
        $msr_price = file_get_contents('https://www.southxchange.com/api/price/MSR/BTC');
        $price = json_decode($msr_price, TRUE);
        if (!isset($price)) {
            $this->log->add('masari_gateway', '[ERROR] Unable to get the market price of Masari');
            return null;
        }
        return round($price["Last"]/$btcPerFiat, 8);
    }
    
    private function on_verified($payment_id, $amount_atomic_units, $order_id)
    {
        $this->log->add('masari_gateway', '[SUCCESS] Payment has been recorded. Congratulations!');
        $this->confirmed = true;
        $order = wc_get_order($order_id);
        
        if($this->is_virtual_in_cart($order_id) == true){
            $order->update_status('completed', __('Payment has been received.', 'masari_gateway'));
        }
        else{
            $order->update_status('processing', __('Payment has been received.', 'masari_gateway')); // Show payment id used for order
        }
        
        global $wpdb;
		$wpdb->query("UPDATE ".$wpdb->prefix."masari_gateway_payments_rate SET payed=true WHERE payment_id='".$payment_id."'");
	
		setcookie('payment_id', null, -1, COOKIEPATH, COOKIE_DOMAIN );
        $this->reloadTime = 3000000000000; // Greatly increase the reload time as it is no longer needed
    }
    
    public function verify_payment($payment_id, $amount, $order_id)
    {
        /*
         * function for verifying payments
         * Check if a payment has been made with this payment id then notify the merchant
         */
        
        $pool_txs = $this->masari_daemon->get_transfers_in_mempool();
        $this->mempool_tx_found = false;
        $i = 1;
        $correct_tx;
        while($i <= count($pool_txs))
        {
           if($pool_txs[$i-1]["payment_id"] == $payment_id)
           {
               $this->mempool_tx_found = true;
               $tx_index = $i - 1;
           }
           $i++;
        }
        
        $amount_atomic_units = $amount * 1000000000000;
        
        if($this->confirmations_wait == 0)
        {
            if($pool_txs[$tx_index]["amount"] >= $amount_atomic_units)
            {
               $this->on_verified($payment_id, $amount_atomic_units, $order_id);
            }
        }
        
        $get_payments_method = $this->masari_daemon->get_payments($payment_id);
        if (isset($get_payments_method["payments"][0]["amount"])) {
			$totalPayed = $get_payments_method["payments"][0]["amount"];
			$outputs_count = count($get_payments_method["payments"]); // number of outputs recieved with this payment id
			$output_counter = 1;

			while($output_counter < $outputs_count){
				$totalPayed += $get_payments_method["payments"][$output_counter]["amount"];
				$output_counter++;
			}
			if($totalPayed >= $amount_atomic_units){
				$tx_height = $get_payments_method["payments"][$outputs_count-1]["block_height"];
				$get_height = $this->masari_daemon->getheight();
          			$bc_height = $get_height["height"] - 1;
				$this->confirmations = ($bc_height - $tx_height) + 1;
				if($this->confirmations >= $this->confirmations_wait)
				{
				   $this->on_verified($payment_id, $amount_atomic_units, $order_id);
				}
			}
        }
    }
    public function last_block_seen($height) // sometimes 2 blocks are mined within a few seconds of eacher. Make sure we don't miss one
    {
        if (!isset($_COOKIE['last_seen_block']))
        {
            setcookie('last_seen_block', $height, time() + 2700, COOKIEPATH, COOKIE_DOMAIN);
            return 0;
        }
        else{
            $cookie_block = $_COOKIE['last_seen_block'];
            $difference = $height - $cookie_block;
            setcookie('last_seen_block', $height, time() + 2700, COOKIEPATH, COOKIE_DOMAIN);
            return $difference;
        }
    }
    public function verify_non_rpc($payment_id, $amount, $order_id)
    {
        $bc_height = $this->msr_tools->get_last_block_height();

        $block_difference = $this->last_block_seen($bc_height);
        
        $txs_from_block = $this->msr_tools->get_txs_from_block($bc_height);
        $tx_count = count($txs_from_block) - 1; // The tx at index 0 is a coinbase tx so it can be ignored
        
        $output_found = null;
        $block_index = null;
        
        if($block_difference != 0)
        {
            if($block_difference >= 2){
                $this->log->add('[WARNING] Block difference is greater or equal to 2');
            }
            
            $txs_from_block_2 = $this->msr_tools->get_txs_from_block($bc_height - 1);
            $tx_count_2 = count($txs_from_block_2) - 1;
            
            $i = 1;
            while($i <= $tx_count_2)
            {
                $tx_hash = $txs_from_block_2[$i]['tx_hash'];
                if(strlen($txs_from_block_2[$i]['payment_id']) != 0)
                {
                    $result = $this->msr_tools->check_tx($tx_hash, $this->address, $this->viewKey);
                    if($result)
                    {
                        $output_found = $result;
                        $block_index = $i;
                        $i = $tx_count_2; // finish loop
                    }
                }
                $i++;
            }
        }

        $i = 1;
        while($i <= $tx_count)
        {
            $tx_hash = $txs_from_block[$i]['tx_hash'];
            if(strlen($txs_from_block[$i]['payment_id']) != 0)
            {
                $result = $this->msr_tools->check_tx($tx_hash, $this->address, $this->viewKey);
                if($result)
                {
                    $output_found = $result;
                    $block_index = $i;
                    $i = $tx_count; // finish loop
                }
            }
            $i++;
        }
        
        if(isset($output_found))
        {
            $amount_atomic_units = $amount * 1000000000000;
            
            if($txs_from_block[$block_index]['payment_id'] == $payment_id && $output_found['amount'] >= $amount_atomic_units)
            {
                $this->on_verified($payment_id, $amount_atomic_units, $order_id);
            }
            if($txs_from_block_2[$block_index]['payment_id'] == $payment_id && $output_found['amount'] >= $amount_atomic_units)
            {
                $this->on_verified($payment_id, $amount_atomic_units, $order_id);
            }
            
            return true;
        }
            return false;
    }
    
    public function verify_zero_conf($payment_id, $amount, $order_id)
    {
        $txs_from_mempool = $this->msr_tools->get_mempool_txs();;
        $tx_count = count($txs_from_mempool['data']['txs']);
        $i = 0;
        $output_found = null;
        
        while($i <= $tx_count)
        {
            $tx_hash = $txs_from_mempool['data']['txs'][$i]['tx_hash'];
            if(strlen($txs_from_mempool['data']['txs'][$i]['payment_id']) != 0)
            {
                $result = $this->msr_tools->check_tx($tx_hash, $this->address, $this->viewKey);
                if($result)
                {
                    $output_found = $result;
                    $tx_i = $i;
                    $i = $tx_count; // finish loop
                }
            }
            $i++;
        }
        if(isset($output_found))
        {
            $amount_atomic_units = $amount * 1000000000000;
            if($txs_from_mempool['data']['txs'][$tx_i]['payment_id'] == $payment_id && $output_found['amount'] >= $amount_atomic_units)
            {
                $this->on_verified($payment_id, $amount_atomic_units, $order_id);
            }
            return true;
        }
        else
            return false;
    }

    public function do_ssl_check()
    {
        if ($this->enabled == "yes" && !$this->get_option('onion_service')) {
            if (get_option('woocommerce_force_ssl_checkout') == "no") {
                echo "<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>";
            }
        }
    }

    public function connect_daemon()
    {
        $host = $this->settings['daemon_host'];
        $port = $this->settings['daemon_port'];
        $masariLibrary = new Masari_Library($host, $port);
        if ($masariLibrary->works() == true) {
            echo "<div class=\"notice notice-success is-dismissible\"><p>Everything works! Congratulations and welcome to Masari. <button type=\"button\" class=\"notice-dismiss\">
						<span class=\"screen-reader-text\">Dismiss this notice.</span>
						</button></p></div>";
        } else {
            $this->log->add('masari_gateway', '[ERROR] Plugin can not reach wallet rpc.');
            echo "<div class=\" notice notice-error\"><p>Error with connection of daemon, see documentation!</p></div>";
        }
    }
}
