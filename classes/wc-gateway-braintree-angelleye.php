<?php

/**
 * WC_Gateway_Braintree_AngellEYE class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Braintree_AngellEYE extends WC_Payment_Gateway {

    /**
     * Constuctor
     */
    function __construct() {
        $this->id = 'braintree';
        $this->icon = apply_filters('woocommerce_braintree_icon', plugins_url('assets/images/cards.png', __DIR__));
        $this->has_fields = true;
        $this->method_title = 'Braintree';
        $this->method_description = 'Braintree Payment Gateway authorizes credit card payments and processes them securely with your merchant account.';
        $this->supports = array(
            'products',
            'refunds'
        );
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->sandbox = $this->get_option('sandbox');
        $this->environment = $this->sandbox == 'no' ? 'production' : 'sandbox';
        $this->merchant_id = $this->sandbox == 'no' ? $this->get_option('merchant_id') : $this->get_option('sandbox_merchant_id');
        $this->private_key = $this->sandbox == 'no' ? $this->get_option('private_key') : $this->get_option('sandbox_private_key');
        $this->public_key = $this->sandbox == 'no' ? $this->get_option('public_key') : $this->get_option('sandbox_public_key');
        $this->enable_braintree_drop_in = $this->get_option('enable_braintree_drop_in') === "yes" ? true : false;
        $this->debug = isset($this->settings['debug']) && $this->settings['debug'] == 'yes' ? true : false;
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        $this->response = '';
        if ($this->enable_braintree_drop_in) {
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'), 0);
        }
        add_action('admin_notices', array($this, 'checks'));
        $this->merchant_access_token = get_option('wc_paypal_braintree_merchant_access_token', '');
        $this->merchant_id = get_option('wc_paypal_braintree_merchant_id', '');
        $just_connected = $this->possibly_save_access_token();
        $just_disconnected = $this->possibly_discard_access_token();
        // Now that $this->debug is set, we can use logging
        if ($just_connected) {
            $this->add_log("Info: Connected to PayPal Braintree successfully. Merchant ID = {$this->merchant_id}");
        }
        if ($just_disconnected) {
            $this->add_log("Info: Disconnected from PayPal Braintree.");
        }
        if (!$this->is_valid_for_use()) {
            $this->enabled = 'no';
            return;
        }
    }

    /**
     * Admin Panel Options
     */
    public function admin_options() {
        ?>
        <h3><?php _e('Braintree', 'paypal-for-woocommerce'); ?></h3>
        <p><?php _e($this->method_description, 'paypal-for-woocommerce'); ?></p>
        <table class="form-table">
            <?php $this->admin_options_header(); ?>
        </table>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <script type="text/javascript">
            jQuery('#woocommerce_braintree_sandbox').change(function () {
                var sandbox = jQuery('#woocommerce_braintree_sandbox_public_key, #woocommerce_braintree_sandbox_private_key, #woocommerce_braintree_sandbox_merchant_id').closest('tr'),
                        production = jQuery('#woocommerce_braintree_public_key, #woocommerce_braintree_private_key, #woocommerce_braintree_merchant_id').closest('tr');
                if (jQuery(this).is(':checked')) {
                    sandbox.show();
                    production.hide();
                } else {
                    sandbox.hide();
                    production.show();
                }
            }).change();
        </script> <?php
    }

    public function admin_options_header() {
        $current_user = wp_get_current_user();
        $section_slug = strtolower(get_class($this));
        $production_connect_url = 'https://connect.woocommerce.com/login/braintree';
        $sandbox_connect_url = 'https://connect.woocommerce.com/login/braintreesandbox';
        $redirect_url = add_query_arg(
                array(
            'page' => 'wc-settings',
            'tab' => 'checkout',
            'section' => $section_slug
                ), admin_url('admin.php')
        );
        $redirect_url = wp_nonce_url($redirect_url, 'connect_paypal_braintree', 'wc_paypal_braintree_admin_nonce');
        $query_args = array(
            'redirect' => urlencode(urlencode($redirect_url)),
            'scopes' => 'read_write'
        );
        $production_connect_url = add_query_arg($query_args, $production_connect_url);
        $sandbox_connect_url = add_query_arg($query_args, $sandbox_connect_url);
        $disconnect_url = add_query_arg(
                array(
            'page' => 'wc-settings',
            'tab' => 'checkout',
            'section' => $section_slug,
            'disconnect_paypal_braintree' => 1
                ), admin_url('admin.php')
        );
        $disconnect_url = wp_nonce_url($disconnect_url, 'disconnect_paypal_braintree', 'wc_paypal_braintree_admin_nonce');
        ?>
        <div class='paypal-braintree-admin-header'>
            <div class='paypal-braintree-admin-brand'>
                <img alt="paypal braintree" src="<?php echo plugins_url('../assets/images/branding/paypal-braintree-horizontal.png', __FILE__); ?>" />
            </div>
            <div class='paypal-braintree-admin-payment-methods'>
                <img alt="visa" src="<?php echo plugins_url('../assets/images/payments/visa.png', __FILE__); ?>" />
                <img alt="master card" src="<?php echo plugins_url('../assets/images/payments/master-card.png', __FILE__); ?>" />
                <img alt="discover" src="<?php echo plugins_url('../assets/images/payments/discover.png', __FILE__); ?>" />
                <img alt="american express" src="<?php echo plugins_url('../assets/images/payments/american-express.png', __FILE__); ?>" />
                <img alt="PayPal" src="<?php echo plugins_url('../assets/images/payments/paypal.png', __FILE__); ?>" />
            </div>
        </div>
        <?php if (empty($this->merchant_access_token)) { ?>
            <p class='paypal-braintree-admin-connect-prompt'>
                <?php echo esc_html('Connect with Braintree to start accepting credit and debit card payments in your checkout.', 'woocommerce-gateway-paypal-braintree'); ?>
                <br/>
                <a href="https://www.braintreepayments.com/partners/learn-more" target="_blank">
                    <?php echo esc_html('Learn more', 'woocommerce-gateway-paypal-braintree'); ?>
                </a>
            </p>
        <?php } ?>

        <table class="form-table">
            <tbody>
                <tr>
                    <th>
                        <?php _e('Connect/Disconnect', 'woocommerce-gateway-paypal-braintree'); ?>
                    </th>
                    <td>
                        <?php if (!empty($this->merchant_access_token)) { ?>
                            <a href="<?php echo esc_attr($disconnect_url); ?>" class='button-primary'>
                                <?php echo esc_html__('Disconnect from PayPal Powered by Braintree', 'woocommerce-gateway-paypal-braintree'); ?>
                            </a>
                        <?php } else { ?>
                            <a href="<?php echo esc_attr($production_connect_url); ?>">
                                <img src="<?php echo plugins_url('../assets/images/button/connect-braintree.png', __FILE__); ?>"/>
                            </a>
                            <br/>
                            <br/>
                            <a href="<?php echo esc_attr($sandbox_connect_url); ?>">
                                <?php echo esc_html__('Not ready to accept live payments? Click here to connect using sandbox mode.', 'woocommerce-gateway-paypal-braintree'); ?>
                            </a>
                        <?php } ?>
                    </td>
                </tr>
            </tbody>
        </table>


        <?php
    }

    /**
     * Check if SSL is enabled and notify the user
     */
    public function checks() {
        if ($this->enabled == 'no') {
            return;
        }
        if (version_compare(phpversion(), '5.2.1', '<')) {
            echo '<div class="error"><p>' . sprintf(__('Braintree Error: Braintree requires PHP 5.2.1 and above. You are using version %s.', 'woocommerce'), phpversion()) . '</p></div>';
        }
        if ('no' == get_option('woocommerce_force_ssl_checkout') && !class_exists('WordPressHTTPS') && $this->enable_braintree_drop_in == false && $this->sandbox == 'no') {
            echo '<div class="error"><p>' . sprintf(__('Braintree is enabled, but the <a href="%s">force SSL option</a> is disabled; your checkout may not be secure! Please enable SSL and ensure your server has a valid SSL certificate - Braintree custome credit card UI will only work in sandbox mode.', 'paypal-for-woocommerce'), admin_url('admin.php?page=wc-settings&tab=checkout')) . '</p></div>';
        }
        $this->add_dependencies_admin_notices();
    }

//    /**
//     * Check if this gateway is enabled
//     */
//    public function is_available() {
//        if ('yes' != $this->enabled) {
//            return false;
//        }
//        if (!$this->merchant_id || !$this->public_key || !$this->private_key) {
//            return false;
//        }
//        return true;
//    }

    public function validate_fields() {
        if (!$this->enable_braintree_drop_in) {
            try {
                $card = $this->get_posted_card();
                if (empty($card->exp_month) || empty($card->exp_year)) {
                    throw new Exception(__('Card expiration date is invalid', 'paypal-for-woocommerce'));
                }
                if (!ctype_digit($card->cvc)) {
                    throw new Exception(__('Card security code is invalid (only digits are allowed)', 'paypal-for-woocommerce'));
                }
                if (!ctype_digit($card->exp_month) || !ctype_digit($card->exp_year) || $card->exp_month > 12 || $card->exp_month < 1 || $card->exp_year < date('y')) {
                    throw new Exception(__('Card expiration date is invalid', 'paypal-for-woocommerce'));
                }
                if (empty($card->number) || !ctype_digit($card->number)) {
                    throw new Exception(__('Card number is invalid', 'paypal-for-woocommerce'));
                }
                return true;
            } catch (Exception $e) {
                wc_add_notice($e->getMessage(), 'error');
                return false;
            }
            return true;
        } else {
            try {
                if (isset($_POST['braintree_token']) && !empty($_POST['braintree_token'])) {
                    return true;
                } else {
                    throw new Exception(__('Braintree payment method nonce is empty', 'paypal-for-woocommerce'));
                }
            } catch (Exception $e) {
                wc_add_notice($e->getMessage(), 'error');
                return false;
            }
            return true;
        }
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'paypal-for-woocommerce'),
                'label' => __('Enable Braintree Payment Gateway', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'paypal-for-woocommerce'),
                'default' => __('Braintree Credit card', 'paypal-for-woocommerce'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'paypal-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'paypal-for-woocommerce'),
                'default' => 'Pay securely with your credit card.',
                'desc_tip' => true
            ),
            'enable_braintree_drop_in' => array(
                'title' => __('Enable Drop-in Payment UI', 'paypal-for-woocommerce'),
                'label' => __('Enable Drop-in Payment UI', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Rather than showing a credit card form on your checkout, this shows the form on it\'s own page, thus making the process more secure and more PCI friendly.', 'paypal-for-woocommerce'),
                'default' => 'no'
            ),
            'sandbox' => array(
                'title' => __('Sandbox', 'paypal-for-woocommerce'),
                'label' => __('Enable Sandbox Mode', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).', 'paypal-for-woocommerce'),
                'default' => 'yes'
            ),
            'sandbox_merchant_id' => array(
                'title' => __('Sandbox Merchant ID', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'sandbox_public_key' => array(
                'title' => __('Sandbox Public Key', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'sandbox_private_key' => array(
                'title' => __('Sandbox Private Key', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'merchant_id' => array(
                'title' => __('Live Merchant ID', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'public_key' => array(
                'title' => __('Live Public Key', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'private_key' => array(
                'title' => __('Live Private Key', 'paypal-for-woocommerce'),
                'type' => 'password',
                'description' => __('Get your API keys from your Braintree account.', 'paypal-for-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'debug' => array(
                'title' => __('Debug Log', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable logging', 'woocommerce'),
                'default' => 'no',
                'description' => __('Log PayPal/Braintree events inside <code>/wp-content/uploads/wc-logs/braintree-{tag}.log</code>'
                )
            )
        );
    }

    public function payment_fields() {
        $this->angelleye_braintree_lib();
        $this->add_log('Begin Braintree_ClientToken::generate Request');
        //$clientToken = Braintree_ClientToken::generate();
        $braintree_gateway = new Braintree_Gateway(array(
            'accessToken' => $this->merchant_access_token,
        ));
        $clientToken = $braintree_gateway->clientToken()->generate();
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        if ($this->enable_braintree_drop_in) {
            ?>
            <div id="braintree-cc-form">
                <fieldset>
                    <div id="braintree-payment-form"></div>
                </fieldset>
            </div>
            <script>
                var $form = jQuery('form.checkout');
                var ccForm = jQuery('form.checkout');
                var clientToken = "<?php echo $clientToken; ?>";
                braintree.setup(clientToken, "dropin", {
                    container: "braintree-payment-form",
                    onError: function (a) {
                        if ("VALIDATION" === a.type) {
                            if (is_angelleye_braintree_selected()) {
                                console.log("configuration error " + a.message);
                                jQuery('.woocommerce-error, .braintree-token', ccForm).remove();
                                ccForm.prepend('<ul class="woocommerce-error"><li>' + a.message + '</li></ul>');
                                return $form.unblock();
                            }
                        } else {
                            console.log("configuration error " + a.message);
                            return $form.unblock();
                        }
                    },
                    onPaymentMethodReceived: function (obj) {
                        braintreeResponseHandler(obj);
                    }
                });

                function is_angelleye_braintree_selected() {
                    if (jQuery('#payment_method_braintree').is(':checked')) {
                        return true;
                    } else {
                        return false;
                    }
                }
                function braintreeResponseHandler(obj) {
                    var $form = jQuery('form.checkout'),
                            ccForm = jQuery('#braintree-cc-form');
                    if (obj.nonce) {
                        ccForm.append('<input type="hidden" class="braintree-token" name="braintree_token" value="' + obj.nonce + '"/>');
                        $form.submit();
                    }
                }
                jQuery('form.checkout').on('checkout_place_order_braintree', function () {
                    return braintreeFormHandler();
                });
                function braintreeFormHandler() {
                    if (jQuery('#payment_method_braintree').is(':checked')) {
                        if (0 === jQuery('input.braintree-token').size()) {
                            return false;
                        }
                    }
                    return true;
                }
            </script>
            <?php
        } else {
            $this->credit_card_form();
        }
    }

    private function get_posted_card() {
        $card_number = isset($_POST['braintree-card-number']) ? wc_clean($_POST['braintree-card-number']) : '';
        $card_cvc = isset($_POST['braintree-card-cvc']) ? wc_clean($_POST['braintree-card-cvc']) : '';
        $card_expiry = isset($_POST['braintree-card-expiry']) ? wc_clean($_POST['braintree-card-expiry']) : '';
        $card_number = str_replace(array(' ', '-'), '', $card_number);
        $card_expiry = array_map('trim', explode('/', $card_expiry));
        $card_exp_month = str_pad($card_expiry[0], 2, "0", STR_PAD_LEFT);
        $card_exp_year = isset($card_expiry[1]) ? $card_expiry[1] : '';
        if (strlen($card_exp_year) == 2) {
            $card_exp_year += 2000;
        }
        return (object) array(
                    'number' => $card_number,
                    'type' => '',
                    'cvc' => $card_cvc,
                    'exp_month' => $card_exp_month,
                    'exp_year' => $card_exp_year,
        );
    }

    /**
     * Process the payment
     */
    public function process_payment($order_id) {
        $order = new WC_Order($order_id);
        $this->angelleye_do_payment($order);
        if (is_ajax()) {
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        } else {
            wp_redirect($this->get_return_url($order));
            exit();
        }
    }

    public function angelleye_do_payment($order) {
        try {
            if (isset($_POST['braintree_token']) && !empty($_POST['braintree_token'])) {
                $payment_method_nonce = $_POST['braintree_token'];
            } else {
                $payment_method_nonce = null;
            }
            $request_data = array();
            $this->angelleye_braintree_lib();
            $card = $this->get_posted_card();
            $request_data['billing'] = array(
                'firstName' => $order->billing_first_name,
                'lastName' => $order->billing_last_name,
                'company' => $order->billing_company,
                'streetAddress' => $order->billing_address_1,
                'extendedAddress' => $order->billing_address_2,
                'locality' => $order->billing_city,
                'region' => $order->billing_state,
                'postalCode' => $order->billing_postcode,
                'countryCodeAlpha2' => $order->billing_country,
            );
            $request_data['shipping'] = array(
                'firstName' => $order->shipping_first_name,
                'lastName' => $order->shipping_last_name,
                'company' => $order->shipping_company,
                'streetAddress' => $order->shipping_address_1,
                'extendedAddress' => $order->shipping_address_2,
                'locality' => $order->shipping_city,
                'region' => $order->shipping_state,
                'postalCode' => $order->shipping_postcode,
                'countryCodeAlpha2' => $order->shipping_country,
            );
            if (is_null($payment_method_nonce)) {
                $request_data['creditCard'] = array(
                    'number' => $card->number,
                    'expirationDate' => $card->exp_month . '/' . $card->exp_year,
                    'cvv' => $card->cvc,
                    'cardholderName' => $order->billing_first_name . ' ' . $order->billing_last_name
                );
            } else {
                $request_data['paymentMethodNonce'] = $payment_method_nonce;
            }
            $request_data['customer'] = array(
                'firstName' => $order->billing_first_name,
                'lastName' => $order->billing_last_name,
                'company' => $order->billing_company,
                'phone' => $this->str_truncate(preg_replace('/[^\d-().]/', '', $order->billing_phone), 14, ''),
                'email' => $order->billing_email,
            );
            $request_data['amount'] = number_format($order->get_total(), 2, '.', '');
            $request_data['orderId'] = $order->get_order_number();
            $request_data['options'] = $this->get_braintree_options();
            $request_data['channel'] = 'AngellEYEPayPalforWoo_BT';
            if ($this->debug) {
                $this->add_log('Begin Braintree_Transaction::sale request');
                $this->add_log('Order: ' . print_r($order->get_order_number(), true));
                $log = $request_data;
                if (is_null($payment_method_nonce)) {
                    $log['creditCard'] = array(
                        'number' => '**** **** **** ****',
                        'expirationDate' => '**' . '/' . '****',
                        'cvv' => '***'
                    );
                } else {
                    $log['paymentMethodNonce'] = '*********************';
                }
                $this->add_log('Braintree_Transaction::sale Reuest Data ' . print_r($log, true));
            }
//            $this->response = Braintree_Transaction::sale($request_data);
            $gateway = new Braintree_Gateway(array(
                'accessToken' => $this->merchant_access_token,
            ));
            $this->response = $gateway->transaction()->sale($request_data);
            $this->add_log('Braintree_Transaction::sale Response code: ' . print_r($this->get_status_code(), true));
            $this->add_log('Braintree_Transaction::sale Response message: ' . print_r($this->get_status_message(), true));
            if ($this->response->success) {
                $order->payment_complete($this->response->transaction->id);

                $order->add_order_note(sprintf(__('%s payment approved! Trnsaction ID: %s', 'paypal-for-woocommerce'), $this->title, $this->response->transaction->id));
                WC()->cart->empty_cart();
            } else if ($this->response->transaction) {
                $order->add_order_note(sprintf(__('%s payment declined.<br />Code: %s', 'paypal-for-woocommerce'), $this->title, $this->response->transaction->processorResponseCode));
            } else {
                if ($this->has_validation_errors()) {
                    $this->add_log('Braintree_Transaction::sale Response: ' . print_r($this->response, true));
                    wc_add_notice('Braintree Error ' . $this->get_message(), 'error');
                    wp_redirect($order->get_checkout_payment_url(true));
                    exit;
                }
            }
        } catch (Exception $ex) {
            wc_add_notice($ex->getMessage(), 'error');
            wp_redirect($order->get_checkout_payment_url(true));
            exit;
        }
    }

    public function str_truncate($string, $length, $omission = '...') {
        if (self::multibyte_loaded()) {
            if (mb_strlen($string) <= $length) {
                return $string;
            }
            $length -= mb_strlen($omission);
            return mb_substr($string, 0, $length) . $omission;
        } else {
            $string = self::str_to_ascii($string);
            if (strlen($string) <= $length) {
                return $string;
            }
            $length -= strlen($omission);
            return substr($string, 0, $length) . $omission;
        }
    }

    public function get_braintree_options() {
        return array('submitForSettlement' => true, 'storeInVaultOnSuccess' => '');
    }

    public static function multibyte_loaded() {
        return extension_loaded('mbstring');
    }

    public function process_refund($order_id, $refund_amount = null, $reason = '') {
        $this->add_log("Beginning processing refund/void for order $order_id");
        $this->add_log("Merchant ID = {$this->merchant_id}");
        $order = wc_get_order($order_id);
        if (!$this->can_refund_order($order)) {
            $this->add_log("Error: Unable to refund/void order {$order_id}. Order has no transaction ID.");
            return false;
        }
        if (!$refund_amount) {
            $refund_amount = floatval($order->get_total());
        }
        $this->add_log("Amount = {$refund_amount}");
        $transaction_id = $order->get_transaction_id();
        $this->angelleye_braintree_lib();
        $gateway = new Braintree_Gateway(array(
            'accessToken' => $this->merchant_access_token,
        ));
        try {
            $transaction = $gateway->transaction()->find($transaction_id);
        } catch (Exception $e) {
            $this->add_log("Error: Unable to find transaction with transaction ID {$transaction_id}");
            return false;
        }
        $this->add_log("Order {$order_id} with transaction ID {$transaction_id} has status {$transaction->status}");
        $action_to_take = '';
        switch ($transaction->status) {
            case Braintree_Transaction::AUTHORIZED :
            case Braintree_Transaction::SUBMITTED_FOR_SETTLEMENT :
            case Braintree_Transaction::SETTLEMENT_PENDING :
                $action_to_take = "void";
                break;
            case Braintree_Transaction::SETTLED :
            case Braintree_Transaction::SETTLING :
                $action_to_take = "refund";
                break;
        }
        if (empty($action_to_take)) {
            $this->add_log("Error: The transaction cannot be voided nor refunded in its current state: state = {$transaction->status}");
            return false;
        }
        if ("void" === $action_to_take) {
            $result = $gateway->transaction()->void($transaction_id);
        } else {
            $result = $gateway->transaction()->refund($transaction_id, $refund_amount);
        }
        if (!$result->success) {
            $this->add_log("Error: The transaction cannot be voided nor refunded - reason: = {$result->message}");
            return false;
        }
        $latest_transaction_id = $result->transaction->id;
        if ("void" === $action_to_take) {
            $order->add_order_note(
                    sprintf(
                            __('Voided - Void ID: %s - Reason: %s', 'woocommerce-gateway-paypal-braintree'), $latest_transaction_id, $reason
                    )
            );
            $this->add_log("Successfully voided order {$order_id}");
        } else {
            $order->add_order_note(
                    sprintf(
                            __('Refunded %s - Refund ID: %s - Reason: %s', 'woocommerce-gateway-paypal-braintree'), wc_price($refund_amount), $latest_transaction_id, $reason
                    )
            );
            $this->add_log(__FUNCTION__, "Info: Successfully refunded {$refund_amount} for order {$order_id}");
        }
        return true;
    }

    public function angelleye_braintree_lib() {
        require_once( 'lib/Braintree/Braintree.php' );
    }

    public function add_dependencies_admin_notices() {
        $missing_extensions = $this->get_missing_dependencies();
        if (count($missing_extensions) > 0) {
            $message = sprintf(
                    _n(
                            '%s requires the %s PHP extension to function.  Contact your host or server administrator to configure and install the missing extension.', '%s requires the following PHP extensions to function: %s.  Contact your host or server administrator to configure and install the missing extensions.', count($missing_extensions), 'paypal-for-woocommerce'
                    ), "PayPal For WooCoomerce - Braintree", '<strong>' . implode(', ', $missing_extensions) . '</strong>'
            );
            echo '<div class="error"><p>' . $message . '</p></div>';
        }
    }

    public function get_missing_dependencies() {
        $missing_extensions = array();
        foreach ($this->get_dependencies() as $ext) {
            if (!extension_loaded($ext)) {
                $missing_extensions[] = $ext;
            }
        }
        return $missing_extensions;
    }

    public function get_dependencies() {
        return array('curl', 'dom', 'hash', 'openssl', 'SimpleXML', 'xmlwriter');
    }

    public function add_log($message) {
        if ($this->debug == 'yes') {
            if (empty($this->log))
                $this->log = new WC_Logger();
            $this->log->add('braintree', $message);
        }
    }

    public function get_status_code() {
        if ($this->response->success) {
            return $this->get_success_status_info('code');
        } else {
            return $this->get_failure_status_info('code');
        }
    }

    public function get_status_message() {
        if ($this->response->success) {
            return $this->get_success_status_info('message');
        } else {
            return $this->get_failure_status_info('message');
        }
    }

    public function get_success_status_info($type) {
        $transaction = !empty($this->response->transaction) ? $this->response->transaction : $this->response->creditCardVerification;
        if (isset($transaction->processorSettlementResponseCode) && !empty($transaction->processorSettlementResponseCode)) {
            $status = array(
                'code' => $transaction->processorSettlementResponseCode,
                'message' => $transaction->processorSettlementResponseText,
            );
        } else {
            $status = array(
                'code' => $transaction->processorResponseCode,
                'message' => $transaction->processorResponseText,
            );
        }
        return isset($status[$type]) ? $status[$type] : null;
    }

    public function get_failure_status_info($type) {
        if ($this->has_validation_errors()) {
            $errors = $this->get_validation_errors();
            return implode(', ', ( 'code' === $type ? array_keys($errors) : array_values($errors)));
        }
        $transaction = !empty($this->response->transaction) ? $this->response->transaction : $this->response->creditCardVerification;
        switch ($transaction->status) {
            case 'gateway_rejected':
                $status = array(
                    'code' => $transaction->gatewayRejectionReason,
                    'message' => $this->response->message,
                );
                break;
            case 'processor_declined':
                $status = array(
                    'code' => $transaction->processorResponseCode,
                    'message' => $transaction->processorResponseText . (!empty($transaction->additionalProcessorResponse) ? ' (' . $transaction->additionalProcessorResponse . ')' : '' ),
                );
                break;
            case 'settlement_declined':
                $status = array(
                    'code' => $transaction->processorSettlementResponseCode,
                    'message' => $transaction->processorSettlementResponseText,
                );
                break;
            default:
                $status = array(
                    'code' => $transaction->status,
                    'message' => $this->response->message,
                );
        }
        return isset($status[$type]) ? $status[$type] : null;
    }

    public function has_validation_errors() {
        return isset($this->response->errors) && $this->response->errors->deepSize();
    }

    public function get_validation_errors() {
        $errors = array();
        if ($this->has_validation_errors()) {
            foreach ($this->response->errors->deepAll() as $error) {
                $errors[$error->code] = $error->message;
            }
        }
        return $errors;
    }

    public function get_user_message($message_id) {
        $message = null;
        switch ($message_id) {
            case 'error': $message = __('An error occurred, please try again or try an alternate form of payment', 'paypal-for-woocommerce');
                break;
            case 'decline': $message = __('We cannot process your order with the payment information that you provided. Please use a different payment account or an alternate payment method.', 'paypal-for-woocommerce');
                break;
            case 'held_for_review': $message = __('This order is being placed on hold for review. Please contact us to complete the transaction.', 'paypal-for-woocommerce');
                break;
            case 'held_for_incorrect_csc': $message = __('This order is being placed on hold for review due to an incorrect card verification number.  You may contact the store to complete the transaction.', 'paypal-for-woocommerce');
                break;
            case 'csc_invalid': $message = __('The card verification number is invalid, please try again.', 'paypal-for-woocommerce');
                break;
            case 'csc_missing': $message = __('Please enter your card verification number and try again.', 'paypal-for-woocommerce');
                break;
            case 'card_type_not_accepted': $message = __('That card type is not accepted, please use an alternate card or other form of payment.', 'paypal-for-woocommerce');
                break;
            case 'card_type_invalid': $message = __('The card type is invalid or does not correlate with the credit card number.  Please try again or use an alternate card or other form of payment.', 'paypal-for-woocommerce');
                break;
            case 'card_type_missing': $message = __('Please select the card type and try again.', 'paypal-for-woocommerce');
                break;
            case 'card_number_type_invalid': $message = __('The card type is invalid or does not correlate with the credit card number.  Please try again or use an alternate card or other form of payment.', 'paypal-for-woocommerce');
                break;
            case 'card_number_invalid': $message = __('The card number is invalid, please re-enter and try again.', 'paypal-for-woocommerce');
                break;
            case 'card_number_missing': $message = __('Please enter your card number and try again.', 'paypal-for-woocommerce');
                break;
            case 'card_expiry_invalid': $message = __('The card expiration date is invalid, please re-enter and try again.', 'paypal-for-woocommerce');
                break;
            case 'card_expiry_month_invalid': $message = __('The card expiration month is invalid, please re-enter and try again.', 'paypal-for-woocommerce');
                break;
            case 'card_expiry_year_invalid': $message = __('The card expiration year is invalid, please re-enter and try again.', 'paypal-for-woocommerce');
                break;
            case 'card_expiry_missing': $message = __('Please enter your card expiration date and try again.', 'paypal-for-woocommerce');
                break;
            case 'bank_aba_invalid': $message_id = __('The bank routing number is invalid, please re-enter and try again.', 'paypal-for-woocommerce');
                break;
            case 'bank_account_number_invalid': $message_id = __('The bank account number is invalid, please re-enter and try again.', 'paypal-for-woocommerce');
                break;
            case 'card_expired': $message = __('The provided card is expired, please use an alternate card or other form of payment.', 'paypal-for-woocommerce');
                break;
            case 'card_declined': $message = __('The provided card was declined, please use an alternate card or other form of payment.', 'paypal-for-woocommerce');
                break;
            case 'insufficient_funds': $message = __('Insufficient funds in account, please use an alternate card or other form of payment.', 'paypal-for-woocommerce');
                break;
            case 'card_inactive': $message = __('The card is inactivate or not authorized for card-not-present transactions, please use an alternate card or other form of payment.', 'paypal-for-woocommerce');
                break;
            case 'credit_limit_reached': $message = __('The credit limit for the card has been reached, please use an alternate card or other form of payment.', 'paypal-for-woocommerce');
                break;
            case 'csc_mismatch': $message = __('The card verification number does not match. Please re-enter and try again.', 'paypal-for-woocommerce');
                break;
            case 'avs_mismatch': $message = __('The provided address does not match the billing address for cardholder. Please verify the address and try again.', 'paypal-for-woocommerce');
                break;
        }
        return apply_filters('wc_payment_gateway_transaction_response_user_message', $message, $message_id, $this);
    }

    public function get_message() {
        $messages = array();
        $message_id = array();
        $decline_codes = array(
            'cvv' => 'csc_mismatch',
            'avs' => 'avs_mismatch',
            '2000' => 'card_declined',
            '2001' => 'insufficient_funds',
            '2002' => 'credit_limit_reached',
            '2003' => 'card_declined',
            '2004' => 'card_expired',
            '2005' => 'card_number_invalid',
            '2006' => 'card_expiry_invalid',
            '2007' => 'card_type_invalid',
            '2008' => 'card_number_invalid',
            '2010' => 'csc_mismatch',
            '2012' => 'card_declined',
            '2013' => 'card_declined',
            '2014' => 'card_declined',
            '2016' => 'error',
            '2017' => 'card_declined',
            '2018' => 'card_declined',
            '2023' => 'card_type_not_accepted',
            '2024' => 'card_type_not_accepted',
            '2038' => 'card_declined',
            '2046' => 'card_declined',
            '2056' => 'credit_limit_reached',
            '2059' => 'avs_mismatch',
            '2060' => 'avs_mismatch',
            '2075' => 'paypal_closed',
        );
        $response_codes = $this->get_validation_errors();
        if (isset($response_codes) && !empty($response_codes) && is_array($response_codes)) {
            foreach ($response_codes as $key => $value) {
                $messages[] = isset($decline_codes[$key]) ? $this->get_user_message($key) : $value;
            }
        }
        return implode(' ', $messages);
    }

    public function payment_scripts() {
        if (!is_checkout() || !$this->is_available()) {
            return;
        }
        wp_enqueue_script('braintree-gateway', 'https://js.braintreegateway.com/v2/braintree.js', array(), WC_VERSION, false);
    }

    public function possibly_save_access_token() {
        if (!is_admin() || !is_user_logged_in()) {
            return false;
        }
        if (!isset($_GET['braintree_access_token'])) {
            return false;
        }
        if (!isset($_GET['wc_paypal_braintree_admin_nonce'])) {
            return false;
        }
        if (!wp_verify_nonce($_GET['wc_paypal_braintree_admin_nonce'], 'connect_paypal_braintree')) {
            wp_die(__('Invalid connection request', 'woocommerce-gateway-paypal-braintree'));
        }
        $access_token = isset($_GET['braintree_access_token']) ? sanitize_text_field(urldecode($_GET['braintree_access_token'])) : '';
        if (empty($access_token)) {
            return false;
        }
        $existing_access_token = get_option('wc_paypal_braintree_merchant_access_token', '');
        if (!empty($existing_access_token)) {
            return false;
        }
        update_option('wc_paypal_braintree_merchant_access_token', $access_token);
        $this->angelleye_braintree_lib();
        $gateway = new Braintree_Gateway(array(
            'accessToken' => $access_token,
        ));
        $merchant_id = $gateway->config->getMerchantId();
        update_option('wc_paypal_braintree_merchant_id', $merchant_id);
        $environment = $gateway->config->getEnvironment(); // sandbox or production
        update_option('wc_paypal_braintree_environment', $environment);
        //wc_add_notice(__('Connected successfully.', 'woocommerce-gateway-paypal-braintree'));
        return true;
    }

    public function possibly_discard_access_token() {
        if (!is_user_logged_in()) {
            return false;
        }
        $disconnect_paypal_braintree = isset($_GET['disconnect_paypal_braintree']);
        if (!$disconnect_paypal_braintree) {
            return false;
        }
        if (!isset($_GET['wc_paypal_braintree_admin_nonce'])) {
            return false;
        }
        if (!wp_verify_nonce($_GET['wc_paypal_braintree_admin_nonce'], 'disconnect_paypal_braintree')) {
            wp_die(__('Invalid disconnection request', 'woocommerce-gateway-paypal-braintree'));
        }
        $existing_access_token = get_option('wc_paypal_braintree_merchant_access_token', '');
        if (empty($existing_access_token)) {
            return false;
        }
        delete_option('wc_paypal_braintree_merchant_access_token');
        delete_option('wc_paypal_braintree_merchant_id');
        //wc_add_notice(__('Disconnected successfully.', 'woocommerce-gateway-paypal-braintree'));
        return true;
    }

    /**
     * Don't allow use of this extension if the currency is not supported or if setup is incomplete
     *
     * @since 1.0.0
     */
    function is_valid_for_use() {
        if (!is_ssl() && !$this->sandbox) {
            return false;
        }

        if (!$this->is_shop_currency_supported()) {
            return false;
        }

        if (empty($this->merchant_access_token)) {
            return false;
        }

        return true;
    }

    public function is_shop_currency_supported() {

        $supported_currencies = array(
            'AED', // United Arab Emirates Dirham
            'AFN', // Afghan Afghani
            'ALL', // Albanian Lek
            'AMD', // Armenian Dram
            'ANG', // Netherlands Antillean Gulden
            'AOA', // Angolan Kwanza
            'ARS', // Argentine Peso
            'AUD', // Australian Dollar
            'AWG', // Aruban Florin
            'AZN', // Azerbaijani Manat
            'BAM', // Bosnia and Herzegovina Convertible Mark
            'BBD', // Barbadian Dollar
            'BDT', // Bangladeshi Taka
            'BGN', // Bulgarian Lev
            'BHD', // Bahraini Dinar
            'BIF', // Burundian Franc
            'BMD', // Bermudian Dollar
            'BND', // Brunei Dollar
            'BOB', // Bolivian Boliviano
            'BRL', // Brazilian Real
            'BSD', // Bahamian Dollar
            'BTN', // Bhutanese Ngultrum
            'BWP', // Botswana Pula
            'BYR', // Belarusian Ruble
            'BZD', // Belize Dollar
            'CAD', // Canadian Dollar
            'CDF', // Congolese Franc
            'CHF', // Swiss Franc
            'CLP', // Chilean Peso
            'CNY', // Chinese Renminbi Yuan
            'COP', // Colombian Peso
            'CRC', // Costa Rican Colón
            'CUC', // Cuban Convertible Peso
            'CUP', // Cuban Peso
            'CVE', // Cape Verdean Escudo
            'CZK', // Czech Koruna
            'DJF', // Djiboutian Franc
            'DKK', // Danish Krone
            'DOP', // Dominican Peso
            'DZD', // Algerian Dinar
            'EEK', // Estonian Kroon
            'EGP', // Egyptian Pound
            'ERN', // Eritrean Nakfa
            'ETB', // Ethiopian Birr
            'EUR', // Euro
            'FJD', // Fijian Dollar
            'FKP', // Falkland Pound
            'GBP', // British Pound
            'GEL', // Georgian Lari
            'GHS', // Ghanaian Cedi
            'GIP', // Gibraltar Pound
            'GMD', // Gambian Dalasi
            'GNF', // Guinean Franc
            'GTQ', // Guatemalan Quetzal
            'GYD', // Guyanese Dollar
            'HKD', // Hong Kong Dollar
            'HNL', // Honduran Lempira
            'HRK', // Croatian Kuna
            'HTG', // Haitian Gourde
            'HUF', // Hungarian Forint
            'IDR', // Indonesian Rupiah
            'ILS', // Israeli New Sheqel
            'INR', // Indian Rupee
            'IQD', // Iraqi Dinar
            'IRR', // Iranian Rial
            'ISK', // Icelandic Króna
            'JMD', // Jamaican Dollar
            'JOD', // Jordanian Dinar
            'JPY', // Japanese Yen
            'KES', // Kenyan Shilling
            'KGS', // Kyrgyzstani Som
            'KHR', // Cambodian Riel
            'KMF', // Comorian Franc
            'KPW', // North Korean Won
            'KRW', // South Korean Won
            'KWD', // Kuwaiti Dinar
            'KYD', // Cayman Islands Dollar
            'KZT', // Kazakhstani Tenge
            'LAK', // Lao Kip
            'LBP', // Lebanese Lira
            'LKR', // Sri Lankan Rupee
            'LRD', // Liberian Dollar
            'LSL', // Lesotho Loti
            'LTL', // Lithuanian Litas
            'LVL', // Latvian Lats
            'LYD', // Libyan Dinar
            'MAD', // Moroccan Dirham
            'MDL', // Moldovan Leu
            'MGA', // Malagasy Ariary
            'MKD', // Macedonian Denar
            'MMK', // Myanmar Kyat
            'MNT', // Mongolian Tögrög
            'MOP', // Macanese Pataca
            'MRO', // Mauritanian Ouguiya
            'MUR', // Mauritian Rupee
            'MVR', // Maldivian Rufiyaa
            'MWK', // Malawian Kwacha
            'MXN', // Mexican Peso
            'MYR', // Malaysian Ringgit
            'MZN', // Mozambican Metical
            'NAD', // Namibian Dollar
            'NGN', // Nigerian Naira
            'NIO', // Nicaraguan Córdoba
            'NOK', // Norwegian Krone
            'NPR', // Nepalese Rupee
            'NZD', // New Zealand Dollar
            'OMR', // Omani Rial
            'PAB', // Panamanian Balboa
            'PEN', // Peruvian Nuevo Sol
            'PGK', // Papua New Guinean Kina
            'PHP', // Philippine Peso
            'PKR', // Pakistani Rupee
            'PLN', // Polish Złoty
            'PYG', // Paraguayan Guaraní
            'QAR', // Qatari Riyal
            'RON', // Romanian Leu
            'RSD', // Serbian Dinar
            'RUB', // Russian Ruble
            'RWF', // Rwandan Franc
            'SAR', // Saudi Riyal
            'SBD', // Solomon Islands Dollar
            'SCR', // Seychellois Rupee
            'SDG', // Sudanese Pound
            'SEK', // Swedish Krona
            'SGD', // Singapore Dollar
            'SHP', // Saint Helenian Pound
            'SKK', // Slovak Koruna
            'SLL', // Sierra Leonean Leone
            'SOS', // Somali Shilling
            'SRD', // Surinamese Dollar
            'STD', // São Tomé and Príncipe Dobra
            'SVC', // Salvadoran Colón
            'SYP', // Syrian Pound
            'SZL', // Swazi Lilangeni
            'THB', // Thai Baht
            'TJS', // Tajikistani Somoni
            'TMM', // Turkmenistani Manat
            'TMT', // Turkmenistani Manat
            'TND', // Tunisian Dinar
            'TOP', // Tongan Paʻanga
            'TRY', // Turkish New Lira
            'TTD', // Trinidad and Tobago Dollar
            'TWD', // New Taiwan Dollar
            'TZS', // Tanzanian Shilling
            'UAH', // Ukrainian Hryvnia
            'UGX', // Ugandan Shilling
            'USD', // United States Dollar
            'UYU', // Uruguayan Peso
            'UZS', // Uzbekistani Som
            'VEF', // Venezuelan Bolívar
            'VND', // Vietnamese Đồng
            'VUV', // Vanuatu Vatu
            'WST', // Samoan Tala
            'XAF', // Central African Cfa Franc
            'XCD', // East Caribbean Dollar
            'XOF', // West African Cfa Franc
            'XPF', // Cfp Franc
            'YER', // Yemeni Rial
            'ZAR', // South African Rand
            'ZMK', // Zambian Kwacha
            'ZWD'  // Zimbabwean Dollar
        );

        return ( in_array(get_woocommerce_currency(), $supported_currencies) );
    }

}
