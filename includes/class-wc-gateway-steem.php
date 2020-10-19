<?php
/**
 * WC_Gateway_Steem
 *
 * @package WooCommerce Steem Payment Method
 * @category Class
 * @author ReCrypto
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Gateway_Steem extends WC_Payment_Gateway {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id                 = 'wc_steem';
		$this->has_fields         = true;
		$this->order_button_text  = __('Proceed to Steem', 'wc-steem');
		$this->method_title       = __('Steem', 'wc-steem' );
		$this->method_description = sprintf(__('Process payments via Steem.', 'wc-steem'), '<a href="' . admin_url('admin.php?page=wc-status') . '">', '</a>');
		$this->supports           = array(
			'products',
			'refunds'
		);

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title          = $this->get_option('title');
		$this->description    = $this->get_option('description');
		$this->payee          = $this->get_option('payee');

		// WordPress hooks
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		add_action('ywsbs_renew_subscription', array($this, 'yith_renew_subscription'), 20, 2);
	}


	# Backend

	/**
	 * Backend form settings
	 *
	 * @since 1.0.0
	 */
	public function init_form_fields() {

		if ($accepted_currencies = wc_steem_get_currencies()) {
			foreach ($accepted_currencies as $accepted_currency_key => $accepted_currency) {
				$accepted_currencies[$accepted_currency_key] = sprintf('%1$s (%2$s)', $accepted_currency, $accepted_currency_key);
			}
		}

		$this->form_fields = array(
			'enabled' => array(
				'title'   => __('Enable/Disable', 'wc-steem'),
				'type'    => 'checkbox',
				'label'   => __('Enable WooCommerce Steem', 'wc-steem'),
				'default' => 'yes'
			),
			'title' => array(
				'title'       => __('Title', 'wc-steem'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'wc-steem'),
				'default'     => __('Steem', 'wc-steem' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __('Description', 'wc-steem'),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => __('This controls the description which the user sees during checkout.', 'wc-steem'),
				'default'     => __('Pay via Steem', 'wc-steem')
			),
			'payee' => array(
				'title'       => __('Payee', 'wc-steem'),
				'type'        => 'text',
				'description' => __('This is your Steem username where your customers will pay you.', 'wc-steem'),
				'default'     => '',
				'desc_tip'    => true,
			),
			'accepted_currencies' => array(
				'title'       => __('Accepted Currencies', 'wc-steem'),
				'type'        => 'multiselect',
				'description' => __('Select the Steem currencies you will accept.', 'wc-steem'),
				'default'     => '',
				'desc_tip'    => true,
				'options'     => $accepted_currencies,
				'select_buttons' => true,
			),
			'show_insightful' => array(
				'title'   => __('Enable insightful prices on products', 'wc-steem'),
				'type'    => 'checkbox',
				'label'   => __('Shows an insightful prices on products that displays the accepted currencies such as SBD and/or STEEM rates converted from the product price.', 'wc-steem'),
				'default' => 'no'
			),
			'show_discounted_price' => array(
				'title'   => __('Show Discounted Price', 'wc-steem'),
				'type'    => 'checkbox',
				'label'   => __('If enabled, products that are on sale will display the original price in STEEM/SBD with strikethrough. Only operational when "Enable insightful prices on products" is enabled.', 'wc-steem'),
				'default' => 'no'
			),			
		);
	}


	# Frontend

	/**
	 * Frontend payment method fields
	 *
	 * @since 1.0.0
	 */
	public function payment_fields() {

		if ( ! $this->payee) {
			if (is_super_admin()) {
				_e('Please set your Steem username at the WooCommerce Settings to get paid via Steem.', 'wc-steem');
			}
			else {
				_e('Sorry, Steem payments is not available right now.', 'wc-steem');
			}
		}
		elseif ( ! wc_steem_get_accepted_currencies()) {
			if (is_super_admin()) {
				_e('Please set one or more accepted currencies at the WooCommerce Settings to get paid via Steem.', 'wc-steem');
			}
			else {
				_e('Sorry, Steem payments is not available right now.', 'wc-steem');
			}
		} else {
			$description = $this->get_description();

			if ($description) {
				echo wpautop(wptexturize(trim($description)));
			}

			if ( $this->supports( 'tokenization' ) && is_checkout() ) {
				$this->tokenization_script();
				$this->saved_payment_methods();
				$this->form();
				$this->save_payment_method_checkbox();
			} else {
				$this->form();
			}
		}
	}
	
	/**
	 * Frontend payment method form
	 *
	 * @since 1.0.0
	 */
	public function form() {

		$amount_currencies_html = '';

		if ($currencies = wc_steem_get_currencies()) {
			foreach ($currencies as $currency_symbol => $currency) {
				if (wc_steem_is_accepted_currency($currency_symbol)) {
					$amount_currencies_html .= sprintf('<option value="%s">%s</option>', $currency_symbol, $currency);
				}
			}
		}

		$default_fields = array(
			'amount' => '<p class="form-row form-row-wide">
				<label for="' . $this->field_id('amount') . '">' . esc_html__( 'Amount', 'wc-steem' ) . '</label>
				<span id="' . $this->field_id('amount') . '">' . WC_Steem::get_amount() . ' ' .  WC_Steem::get_amount_currency() . '</span>
			</p>',
			'amount_currency' => '<p class="form-row form-row-wide">
				<label for="' . $this->field_id('amount-currency') . '">' . esc_html__( 'Currency', 'wc-steem' ) . '</label>
				<select id="' . $this->field_id('amount-currency') . '"' . $this->field_name('amount_currency') . '>' . $amount_currencies_html . '</select>
			</p>',
		);

		$fields = wp_parse_args($default_fields, apply_filters('wc_steem_form_fields', $default_fields, $this->id)); ?>

		<fieldset id="<?php echo esc_attr($this->id); ?>-steem-form" class='wc-steem-form wc-payment-form'>
			<?php do_action('wc_steem_form_start', $this->id); ?>

			<?php foreach ($fields as $field) : ?>
					<?php echo $field; ?>
			<?php endforeach; ?>

			<?php do_action('wc_steem_form_end', $this->id); ?>

			<div class="clear"></div>
		</fieldset><?php
	}


	# Helpers

	/**
	 * Output field name HTML
	 *
	 * Gateways which support tokenization do not require names - we don't want the data to post to the server.
	 *
	 * @since 1.0.0
	 * @param string $name
	 * @return string
	 */
	public function field_name($name) {
		return $this->supports('tokenization') ? '' : ' name="' . $this->field_id($name) . '" ';
	}

	/**
	 * Construct field identifier
	 *
	 * @since 1.0.0
	 * @param string $key
	 * @return string
	 */
	public function field_id($key) {
		return esc_attr(sprintf('%s-%s', $this->id, $key));
	}


	/**
	 * Get gateway icon.
	 * @return string
	 */
	public function get_icon() {
		$icon_html = '';
		$icon      = apply_filters('wc_steem_icon', WC_STEEM_DIR_URL . '/assets/img/steem-64.png');

		$icon_html .= '<img src="' . esc_attr($icon) . '" alt="' . esc_attr__('Steem acceptance mark', 'wc-steem') . '" />';

		return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
	}


	# Handlers

	/**
	 * Process payment
	 *
	 * Validation takes place by querying transactions to Steemful API
	 *
	 * @since 1.0.0
	 * @param int $order_id
	 * @return array $response
	 */
	public function process_payment($order_id) {
		$response = null;

		// Reduce stock levels
		wc_reduce_stock_levels($order_id);

		if (WC()->cart !== null) {
			// Remove cart
			WC()->cart->empty_cart();
		}

		// Set order meta for payee, memo, amount, amount_currency, etc.
		$order = $this->prepare_order_for_payment($order_id);

		// Retrieve meta info from order to pass to SteemConnect
		$payee = get_post_meta($order_id, '_wc_steem_payee', true);
		$memo = get_post_meta($order_id, '_wc_steem_memo', true);		
		$amount = get_post_meta($order_id, '_wc_steem_amount', true);
		$amount_currency = get_post_meta($order_id, '_wc_steem_amount_currency', true);
		$from_amount = get_post_meta($order_id, '_wc_steem_from_amount', true);	
		$from_currency = get_post_meta($order_id, '_wc_steem_from_currency', true);	
		$exchange_rate = get_post_meta($order_id, '_wc_steem_exchange_rate', true);	

		update_post_meta($order->get_id(), '_wc_steem_status', 'pending');
			
		$exchange_rate_note = sprintf('1 %s = %s %s; 1 %s = %s %s',
			$from_currency,
			$exchange_rate,
			$amount_currency,
			$amount_currency,
			round((float)1 / (float)$exchange_rate, 3, PHP_ROUND_HALF_UP),
			$from_currency
		);
		
		// Add order note indicating details of payment request
		$order->add_order_note(
			sprintf(
				__('Steem payment <strong>Initiated</strong>:<br />Payee: %s<br />Amount Due: %s %s<br />Converted From: %s %s<br />Exchange Rate: %s<br />Memo: %s', 'wc-steem'), 
				$payee, 
				$amount,
				$amount_currency,
				$from_amount,
				$from_currency,
				$exchange_rate_note,
				$memo
			)				
		);		
		
		$steemConnectUrl = "https://steemlogin.com/sign/transfer?to=" . $payee . "&memo=" . $memo . "&amount=" . $amount . "%20" . $amount_currency ."&redirect_uri=" . urlencode($this->get_return_url($order));
		
		$response = array(
			'result' => 'success',
			'redirect' => $steemConnectUrl
		);

		return $response;
	}

	/**
	 * Process payment
	 *
	 * Validation takes place by querying transactions to Steemful API
	 *
	 * @since 1.1.3
	 * @param int $order_id
	 */
	public function prepare_order_for_payment($order_id) {
		$order = new WC_Order($order_id);
		
		$payee = get_post_meta($order_id, '_wc_steem_payee', true);
		$amount = get_post_meta($order_id, '_wc_steem_amount', true);
		$amount_currency = get_post_meta($order_id, '_wc_steem_amount_currency', true);
		$memo = get_post_meta($order_id, '_wc_steem_memo', true);		
		$from_amount = get_post_meta($order_id, '_wc_steem_from_amount', true);	
		$from_currency = get_post_meta($order_id, '_wc_steem_from_currency', true);	
		$exchange_rate = get_post_meta($order_id, '_wc_steem_exchange_rate', true);	
		
		if (empty($memo)) {
			$payee = WC_Steem::get_payee();
			$amount = WC_Steem::get_amount();
			$amount_currency = WC_Steem::get_amount_currency();
			$memo = WC_Steem::get_memo();
			$from_amount = WC_Steem::get_from_amount();
			$from_currency = WC_Steem::get_from_currency();
			$exchange_rate = WC_Steem::get_exchange_rate();

			// Allow overriding payee on a per order basis
			$payee = apply_filters('woocommerce_gateway_steem_steemconnect_payee', $payee, $order );			
			$memo = apply_filters('woocommerce_gateway_steem_steemconnect_memo', $memo, $order );			
			
			update_post_meta($order_id, '_wc_steem_payee', $payee);
			update_post_meta($order_id, '_wc_steem_amount', $amount);
			update_post_meta($order_id, '_wc_steem_amount_currency', $amount_currency);
			update_post_meta($order_id, '_wc_steem_memo', $memo);
			update_post_meta($order_id, '_wc_steem_from_amount', $from_amount);
			update_post_meta($order_id, '_wc_steem_from_currency', $from_currency);
			update_post_meta($order_id, '_wc_steem_exchange_rate', $exchange_rate);

			WC_Steem::reset();
		}

		return $order;
	}


	/**
	 * Validate frontend fields
	 *
	 * @since 1.0.0
	 * @return boolean
	 */
	public function validate_fields() {

		$amount_currency = isset($_POST[$this->field_id('amount_currency')]) ? $_POST[$this->field_id('amount_currency')] : 'STEEM';
		$from_currency_symbol = wc_steem_get_base_fiat_currency();
		
		WC_Steem::set_from_currency($from_currency_symbol);
		
		if (wc_steem_is_accepted_currency($amount_currency)) {
			WC_Steem::set_amount_currency($amount_currency);

			if ($amounts = WC_Steem::get_amounts()) {
				if (isset($amounts[WC_Steem::get_amount_currency() . '_' . $from_currency_symbol])) {
					WC_Steem::set_amount($amounts[WC_Steem::get_amount_currency() . '_' . $from_currency_symbol]);
					
					// Set exchange rate based off 1 unit of the base fiat currency
					WC_Steem::set_exchange_rate(wc_steem_rate_convert(1, $from_currency_symbol, WC_Steem::get_amount_currency()));
				}
			}
		}

		if (empty(WC_Steem::get_memo())) {
			WC_Steem::set_memo();
		}

		WC_Steem::set_payee($this->payee);
		
		return true;
	}

	/**
	 * Cannot be refunded
	 *
	 * @since 1.0.0
	 * @param WC_Order $order
	 * @return boolean
	 */
	public function can_refund_order($order) {
		return $order->get_payment_method() == 'wc_steem' && false;
	}

	/**
	 * Handle YITH Subscription Renewal
	 *
	 * @since 1.1.3
	 * @param int $order_id
	 * @return boolean
	 */
	public function yith_renew_subscription($order_id, $subscription_id) {
		if (empty($subscription_id) || !class_exists('YWSBS_Subscription'))
			return;

		$subscription = new YWSBS_Subscription( $subscription_id );
		$parent_order_id = $subscription->order_id;

		// Get meta values from parent order
		// Don't copy amount from parent order, it will be set below by new conversion
		// Don't copy exchange_rate from parent order, it will be set below by new query
		$payee = get_post_meta($parent_order_id, '_wc_steem_payee', true);
		$amount_currency = get_post_meta($parent_order_id, '_wc_steem_amount_currency', true);
		$memo = get_post_meta($parent_order_id, '_wc_steem_memo', true);		
		$from_amount = get_post_meta($parent_order_id, '_wc_steem_from_amount', true);	
		$from_currency = get_post_meta($parent_order_id, '_wc_steem_from_currency', true);	

		update_post_meta($order_id, '_wc_steem_payee', $payee);
		update_post_meta($order_id, '_wc_steem_amount_currency', $amount_currency);
		update_post_meta($order_id, '_wc_steem_memo', $memo);
		update_post_meta($order_id, '_wc_steem_from_amount', $from_amount);
		update_post_meta($order_id, '_wc_steem_from_currency', $from_currency);

		// Get fresh exchange rate and amount for this order
		WC_Gateway_Steem::update_order_exchange_rate_and_amount($order_id);

		$this->prepare_order_for_payment($order_id);
	}

	/**
	 * Get fresh exchange rate and amount for the specified order. This is currently used for refreshing the rate
	 * for subscription orders at the time of checkout in case they were stale or not initialized yet.
	 *
	 * @since 1.1.3
	 * @param int $order_id
	 * @return boolean
	 */
	public static function update_order_exchange_rate_and_amount($order_id) {
		// Get meta values from parent order
		// Don't copy amount from parent order, it will be set below by new conversion
		// Don't copy exchange_rate from parent order, it will be set below by new query
		$amount_currency = get_post_meta($order_id, '_wc_steem_amount_currency', true);
		$from_amount = get_post_meta($order_id, '_wc_steem_from_amount', true);	
		$from_currency = get_post_meta($order_id, '_wc_steem_from_currency', true);	

		// Get from fiat symbol
		$from_currency_symbol = wc_steem_get_base_fiat_currency();

		$rates_handler = new WC_Steem_Rates_Handler();

		// Get fresh exchange rate
		$exchange_rate = $rates_handler->get_fiat_to_steem_exchange_rate($from_currency_symbol, $amount_currency);

		// Convert the fiat amount from parent order to steem using latest exchange rate
		$amount = wc_steem_rate_convert($from_amount, $from_currency_symbol, $amount_currency);

		// Set meta values to this order
		update_post_meta($order_id, '_wc_steem_amount', $amount);
		update_post_meta($order_id, '_wc_steem_exchange_rate', $exchange_rate);		
	}
}
