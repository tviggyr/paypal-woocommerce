<?php

class WC_Gateway_PayPal_Website_Payment extends WC_Payment_Gateway {

    /**
     * __construct function.
     *
     * @access public
     * @return void
     */
    public function __construct() {
        $this->id = 'paypal_website_payment';
        $this->method_title = __('PayPal Website Payments Pro Hosted', 'paypal-for-woocommerce');
        $this->method_description = __('Website Payments Pro Hosted Solutions offers merchants a way to securely accept credit and debit card or PayPal payments without capturing or storing card information on their website. Payment information is collected by PayPal using an inline frame.', 'paypal-for-woocommerce');
        $this->has_fields = false;
        $this->supports = array(
            'products',
            'refunds'
        );

        // Load the form fields
        $this->init_form_fields();
        // Load the settings.
        $this->init_settings();
        // Get setting values
        $this->enabled = $this->settings['enabled'];
        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];
        $this->testmode = $this->settings['testmode'];
        if ($this->testmode == 'yes') {
            $this->API_Endpoint = "https://api-3t.sandbox.paypal.com/nvp";
            $this->PAYPAL_URL = "https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&token=";
            $this->api_username = $this->settings['sandbox_api_username'];
            $this->api_password = $this->settings['sandbox_api_password'];
            $this->api_signature = $this->settings['sandbox_api_signature'];
        } else {
            $this->API_Endpoint = "https://api-3t.paypal.com/nvp";
            $this->PAYPAL_URL = "https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=";
        }
        $this->version = "64";  // PayPal SetExpressCheckout API version

        //Save settings
        add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

    }

    function is_available() {
        if ($this->enabled == 'yes' && ( $this->show_on_checkout == 'regular' || $this->show_on_checkout == 'both'))
            return true;
        return false;
    }

    /**
     * Use WooCommerce logger if debug is enabled.
     */
    function add_log($message) {
        if ($this->debug == 'yes') {
            if (empty($this->log))
                $this->log = new WC_Logger();
            $this->log->add('paypal_website_payment', $message);
        }
    }



    /**
     * Initialize Gateway Settings Form Fields
     */
    function init_form_fields() {

        $require_ssl = '';
        if (!AngellEYE_Gateway_Paypal::is_ssl()) {
            $require_ssl = __('This image requires an SSL host.  Please upload your image to <a target="_blank" href="http://www.sslpic.com">www.sslpic.com</a> and enter the image URL here.', 'paypal-for-woocommerce');
        }

        $woocommerce_enable_guest_checkout = get_option('woocommerce_enable_guest_checkout');
        if (isset($woocommerce_enable_guest_checkout) && ( $woocommerce_enable_guest_checkout === "no" )) {
            $skip_final_review_option_not_allowed = ' (This is not available because your WooCommerce orders require an account.)';
        } else {
            $skip_final_review_option_not_allowed = '';
        }

        $args = array(
            'sort_order' => 'ASC',
            'sort_column' => 'post_title',
            'hierarchical' => 1,
            'exclude' => '',
            'include' => '',
            'meta_key' => '',
            'meta_value' => '',
            'authors' => '',
            'child_of' => 0,
            'parent' => -1,
            'exclude_tree' => '',
            'number' => '',
            'offset' => 0,
            'post_type' => 'page',
            'post_status' => 'publish'
        );
        $pages = get_pages($args);
        $cancel_page = array();
        foreach ($pages as $p) {
            $cancel_page[$p->ID] = $p->post_title;
        }
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'paypal-for-woocommerce'),
                'label' => __('Enable PayPal Website Payments Pro Hosted', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => '',
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'paypal-for-woocommerce'),
                'default' => __('PayPal Website Payments Pro Hosted', 'paypal-for-woocommerce')
            ),
            'description' => array(
                'title' => __('Description', 'paypal-for-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'paypal-for-woocommerce'),
                'default' => __("Pay via PayPal; you can pay with your credit card if you don't have a PayPal account", 'paypal-for-woocommerce')
            ),
            'sandbox_api_username' => array(
                'title' => __('Sandbox API User Name', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('Create sandbox accounts and obtain API credentials from within your <a href="http://developer.paypal.com">PayPal developer account</a>.', 'paypal-for-woocommerce'),
                'default' => ''
            ),
            'sandbox_api_password' => array(
                'title' => __('Sandbox API Password', 'paypal-for-woocommerce'),
                'type' => 'password',
                'default' => ''
            ),
            'sandbox_api_signature' => array(
                'title' => __('Sandbox API Signature', 'paypal-for-woocommerce'),
                'type' => 'password',
                'default' => ''
            ),
            'api_username' => array(
                'title' => __('Live API User Name', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('Get your live account API credentials from your PayPal account profile under the API Access section <br />or by using <a target="_blank" href="https://www.paypal.com/us/cgi-bin/webscr?cmd=_login-api-run">this tool</a>.', 'paypal-for-woocommerce'),
                'default' => ''
            ),
            'api_password' => array(
                'title' => __('Live API Password', 'paypal-for-woocommerce'),
                'type' => 'password',
                'default' => ''
            ),
            'api_signature' => array(
                'title' => __('Live API Signature', 'paypal-for-woocommerce'),
                'type' => 'password',
                'default' => ''
            ),
            'testmode' => array(
                'title' => __('PayPal Sandbox', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable PayPal Sandbox', 'paypal-for-woocommerce'),
                'default' => 'yes',
                'description' => __('The sandbox is PayPal\'s test environment and is only for use with sandbox accounts created within your <a href="http://developer.paypal.com" target="_blank">PayPal developer account</a>.', 'paypal-for-woocommerce')
            ),
            'debug' => array(
                'title' => __('Debug', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable logging <code>/wp-content/uploads/wc-logs/paypal_website_payment-{tag}.log</code>', 'paypal-for-woocommerce'),
                'default' => 'no'
            ),
            'error_email_notify' => array(
                'title' => __('Error Email Notifications', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable admin email notifications for errors.', 'paypal-for-woocommerce'),
                'default' => 'yes',
                'description' => __('This will send a detailed error email to the WordPress site administrator if a PayPal API error occurs.', 'paypal-for-woocommerce')
            ),
            'invoice_id_prefix' => array(
                'title' => __('Invoice ID Prefix', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('Add a prefix to the invoice ID sent to PayPal. This can resolve duplicate invoice problems when working with multiple websites on the same PayPal account.', 'paypal-for-woocommerce'),
            ),
            'brand_name' => array(
                'title' => __('Brand Name', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls what users see as the brand / company name on PayPal review pages.', 'paypal-for-woocommerce'),
                'default' => __(get_bloginfo('name'), 'paypal-for-woocommerce')
            ),
            'checkout_logo' => array(
                'title' => __('PayPal Checkout Logo (190x90px)', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls what users see as the logo on PayPal review pages. ', 'paypal-for-woocommerce') . $require_ssl,
                'default' => ''
            ),
            'checkout_logo_hdrimg' => array(
                'title' => __('PayPal Checkout Banner (750x90px)', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls what users see as the header banner on PayPal review pages. ', 'paypal-for-woocommerce') . $require_ssl,
                'default' => ''
            ),
            'customer_service_number' => array(
                'title' => __('Customer Service Number', 'paypal-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls what users see for your customer service phone number on PayPal review pages.', 'paypal-for-woocommerce'),
                'default' => ''
            ),
            'payment_action' => array(
                'title' => __('Payment Action', 'paypal-for-woocommerce'),
                'label' => __('Whether to process as a Sale or Authorization.', 'paypal-for-woocommerce'),
                'description' => __('Sale will capture the funds immediately when the order is placed.  Authorization will authorize the payment but will not capture the funds.  You would need to capture funds through your PayPal account when you are ready to deliver.'),
                'type' => 'select',
                'options' => array(
                    'Sale' => 'Sale',
                    'Authorization' => 'Authorization',
                ),
                'default' => 'Sale'
            ),
            'billing_address' => array(
                'title' => __('Billing Address', 'paypal-for-woocommerce'),
                'label' => __('Set billing address in WooCommerce using the address returned by PayPal.', 'paypal-for-woocommerce'),
                'description' => __('PayPal only returns a shipping address back to the website.  Enable this option if you would like to use this address for both billing and shipping in WooCommerce.'),
                'type' => 'checkbox',
                'default' => 'no'
            ),
            'cancel_page' => array(
                'title' => __('Cancel Page', 'paypal-for-woocommerce'),
                'description' => __('Sets the page users will be returned to if they click the Cancel link on the PayPal checkout pages.'),
                'type' => 'select',
                'options' => $cancel_page,
            ),
            'send_items' => array(
                'title' => __('Send Item Details', 'paypal-for-woocommerce'),
                'label' => __('Send line item details to PayPal.', 'paypal-for-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Include all line item details in the payment request to PayPal so that they can be seen from the PayPal transaction details page.', 'paypal-for-woocommerce'),
                'default' => 'yes'
            )
        );
        $this->form_fields = apply_filters('angelleye_ec_form_fields', $this->form_fields);
    }
}