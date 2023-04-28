<?php

/**
 * Plugin Name: Clover Basic Pay
 * Plugin URI: https://pixelmain.com/basic-pay-for-wordpress
 * Text Domain: clover-basic-pay
 * Description: Accept payments using the Clover payment gateway.
 * Version: 1.1.1
 * Author: Pixel Main
 * Author URI: https://pixelmain.com
 * License: GPL v2 or later
 */

namespace CBPPlugin;



if (!defined("ABSPATH")) {
    exit;
}

class CBPPlugin
{
    private $license;
    private $token;
    private $production = false;

    private $errorCode = '<div class="cbp-error-icon"></div>
<div class="cbp-error-text">%error</div>';

    private $receiptCode = '<div class="cbp-paid-circle"><div class="cbp-paid-checkmark"></div></div>
<div class="cbp-paid-amount">$%amount</div>
<div class="cbp-paid-title">Your payment is completed</div>
<div class="cbp-paid-verification-code">Verification code</div>

<div class="cbp-paid-verification-code-value">%id</div>

<div class="cbp-paid-link"><a href="%link" target="_blank">View Receipt</a></div>';

    private $sandbox_url =  "https://checkout.sandbox.dev.clover.com/sdk.js";
    private $production_url = "https://checkout.clover.com/sdk.js";

    private $cloverFields = array(
        'card-number' => array(
            'label' => 'Card Number',
            'class' => 'cbp-clover-field cbp-card-number',
            'error_class' => 'cbp-input-errors',
            'error_id' => 'card-number-errors',
        ),
        'card-date' => array(
            'label' => 'Card Expiration',
            'class' => 'cbp-clover-field cbp-card-date',
            'error_class' => 'cbp-input-errors',
            'error_id' => 'card-date-errors',
        ),
        'card-cvv' => array(
            'label' => 'CVV',
            'class' => 'cbp-clover-field cbp-card-cvv',
            'error_class' => 'cbp-input-errors',
            'error_id' => 'card-cvv-errors',
        ),
        'card-postal-code' => array(
            'label' => 'Postal Code',
            'class' => 'cbp-clover-field cbp-card-postal-code',
            'error_class' => 'cbp-input-errors',
            'error_id' => 'card-postal-code-errors',
        ),
    );

    private $fields = array(
        'name' => array(
            'label' => 'Name of cardholder',
            'placeholder' => 'Name',
            'required' => "true",
            'showLabel' => "true",
            'display' => "true",
            'class' => 'cbp-input cbp-name',
        ),
        'email' => array(
            'label' => 'E-mail',
            'placeholder' => 'your@email.com',
            'required' => "true",
            'showLabel' => "true",
            'display' => "true",
            'class' => 'cbp-input cbp-email',

        ),
        'address' => array(
            'label' => 'Address',
            'placeholder' => 'Address',
            'required' => "true",
            'showLabel' => "true",
            'display' => "true",
            'class' => 'cbp-input cbp-address',

        ),
        'phone' => array(
            'label' => 'Phone Number',
            'placeholder' => '(XXX) XXX-XXXX',
            'required' => "true",
            'showLabel' => "true",
            'display' => "true",
            'class' => 'cbp-input cbp-phone',

        ),
        'invoice' => array(
            'label' => 'Invoice Number',
            'placeholder' => 'Optional',
            'required' => "false",
            'showLabel' => "true",
            'display' => "true",
            'class' => 'cbp-input cbp-invoice',

        ),
        'amount' => array(
            'label' => 'Payment Amount',
            'placeholder' => '$0.00',
            'required' => "true",
            'showLabel' => "true",
            'display' => "true",
            'class' => 'cbp-input cbp-amount',

        ),
    );

    public function __construct()
    {
        $this->license = get_option('cbp_license');
        $this->token = get_option('cbp_token');
        $this->production = get_option('cbp_production');
    }

    public function load()
    {
        add_action('admin_init', [$this, 'add_plugin_settings']);
        add_action('admin_menu', [$this, 'add_plugin_options_page']);
        wp_enqueue_script('jquery');
    }



    function init_your_gateway_class()
    {
        require_once(plugin_dir_path(__FILE__) . 'woo.php');
    }

    function add_your_gateway_class($methods)
    {
        $methods[] = 'WC_Gateway_Clover_Basic_Pay';
        return $methods;
    }

    public function loadPublic()
    {
        add_action('plugins_loaded', [$this, 'init_your_gateway_class']);
        add_filter('woocommerce_payment_gateways', [$this, 'add_your_gateway_class']);

        wp_enqueue_style('cbp-styles', plugin_dir_url(__FILE__) . 'pay.css');

        add_action('wp_ajax_cbp_submitted', [$this, 'post_payment']);
        add_action('wp_ajax_nopriv_cbp_submitted', [$this, 'post_payment']);

        add_shortcode('cbp-payment-button', [$this, 'cbp_payment_button_shortcode']);
        add_shortcode('cloverbasicpay', [$this, 'cloverbasicpay_shortcode']);
        add_shortcode('cbp-response', [$this, 'cbp_card_response_shortcode']);
        add_shortcode('cbp-error', [$this, 'cbp_card_error_shortcode']);


        foreach ($this->fields as $field_name => $field) {
            add_shortcode('cbp-' . $field_name, function ($attrs) use ($field_name, $field) {
                $option_name =  'cbp' . $field_name;

                $options = get_option($option_name);

                $required = $options['required'] ? ($options['required'] === "1" ? "required" : "") : ($field['required'] === "true" ? "required" : "");
                $showLabel = $options['showLabel'] ? ($options['showLabel'] === "1") : ($field['showLabel'] === "true");


                $display = $attrs['display'] === "false" ? false : ($options['display'] ? ($options['display'] === "1") : ($field['display']));

                if (!$display) return "";

                $type = $display ? "text" : "hidden";

                if ($field_name === "email" && $type !== "hidden") {
                    $type = "email";
                }

                $atts = shortcode_atts(array(
                    'required' => $required,
                    'placeholder' => $options['placeholder'] ?: $field['placeholder'],
                    'label' => $options['label'] ?: $field['label'],
                    'class' => $options['class'] ?: $field['class'],
                ), $attrs);

                $output = '';

                if ($display) {
                    $output .= '<div class="cbp-field">';
                    if ($showLabel && esc_html($atts['label'])) $output .= '<label for="cbp-' . $field_name . '-field">' . esc_html($atts['label']) . '</label>';
                }

                $output .= '<input type="' . $type . '" id="cbp-' . $field_name . '-field" name="' . $field_name . '"';

                if ($atts['required']) {
                    $output .= ' required';
                }

                if ($attrs['value']) $output .= ' value="' . esc_attr($attrs['value']) . '"';

                if (($field_name === "invoice" || $field_name === "amount") && $_REQUEST[$field_name]) $output .= ' value="' . esc_attr($_REQUEST[$field_name]) . '"';


                $output .= ' class="' . $atts['class'] . '"';
                $output .= ' placeholder="' . $atts['placeholder'] . '">';

                if ($display) $output .= '</div>';

                return $output;
            });

            foreach ($this->cloverFields as $name => $field) {
                add_shortcode('cbp-' . $name, function () use ($name, $field) {
                    return $this->render_clover_field($name, $field);
                });
            }
        }
    }


    function render_clover_field($name, $field)
    {
        $output = '<div id="cbp-clover-' . esc_attr($name) . '" class="' . esc_attr($field['class']) . '">';
        if (esc_html($field['label'])) $output .= '<label for="' . esc_attr($name) . '-field">' . esc_html($field['label']) . '</label>';
        $output .= '</div>';
        $output .= '<div class="' . esc_attr($field['error_class']) . '" id="' . esc_attr($field['error_id']) . '" role="alert"></div>';
        return $output;
    }

    public function cbp_card_response_shortcode()
    {
        $cbp_onPayValue = wp_kses_post(get_option('cbp_onPayValue'));

        if (!$cbp_onPayValue) {
            $cbp_onPayValue = $this->receiptCode;
        }

        return "<div id=\"cbp-card-response\" class=\"cbp-card-response\" role=\"alert\" >{$cbp_onPayValue}</div>";
    }

    public function cbp_card_error_shortcode()
    {
        $cbp_onErrorValue = wp_kses_post(get_option('cbp_onErrorValue'));

        if (!$cbp_onErrorValue) {
            $cbp_onErrorValue = $this->errorCode;
        }

        return "<div id=\"cbp-card-error\" class=\"cbp-card-error\" role=\"alert\" >{$cbp_onErrorValue}</div>";
    }

    function cbp_payment_button_shortcode($atts)
    {
        $options = get_option("cbpsubmit");

        $button_text = $options ?: wp_kses_post($atts["label"]) ?:  'Pay';

        $output = '<div class="cbp-button-block">';
        $output .= '<button id="cbp-submit" class="cbp-button">' . stripslashes(esc_html($button_text)) . '</button>';
        $output .= '</div>';

        return $output;
    }


    function convertToCents($input)
    {
        $regex = '/^(\$)?(\d+(\.\d{1,2})?)$/';
        if (preg_match($regex, $input, $matches)) {
            $value = floatval($matches[2]) * 100;
            return round($value);
        } else {
            return null;
        }
    }


    function post_payment()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error([
                'message' => 'Invalid method.'
            ]);
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['cbp_nonce']) || !wp_verify_nonce($data['cbp_nonce'], 'cbp_save_settings')) {
            wp_send_json_error([
                'message' => 'Invalid nonce.'
            ]);

            exit();
        }

        if (!isset($data['name']) || !isset($data['amount']) || !isset($data['source'])) {
            wp_send_json_error([
                'data' => $data,
                'message' => 'Missing fields.'
            ]);

            exit();
        }

        $name = sanitize_text_field($data['name']);
        $address = sanitize_text_field($data['address']);
        $phone = sanitize_text_field($data['phone']);
        $email = sanitize_email($data['email']);
        $invoice = sanitize_text_field($data['invoice']);
        $amount = sanitize_text_field($data['amount']);
        $uuid = sanitize_text_field($data['uuid']);
        $source = sanitize_text_field($data['source']);

        $url = $this->production ? 'https://scl.clover.com' : 'https://scl-sandbox.dev.clover.com';

        $headers = array(
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'idempotency-key' => $uuid
        );
        
                

        $data = array(
            'amount' => $this->convertToCents($amount),
            'currency' => 'usd',
            'source' => $source,
            'external_reference_id' => $invoice,
            'description' => "Name: {$name}, Address: {$address}, Phone: {$phone}, Email: {$email}"
        );

        if ($email) {
            $data['receipt_email'] = $email;
        }

        $response = wp_remote_post($url . '/v1/charges', array(
            'headers' => $headers,
            'body' => json_encode($data),
        ));


        if ( is_wp_error( $response ) ) {
            return wp_send_json( array(
                'success' => false,
                'data' => ['error' => ['message' => $response->get_error_message()]],
            ) );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code === 401 ||  $response_code === 400 ) {
            $response_body = wp_remote_retrieve_body( $response );
            $response_data = json_decode( $response_body, true );
            $error_message = isset( $response_data['error']['message'] ) ? $response_data['error']['message'] : 'Unauthorized';
            return wp_send_json( array(
                'success' => false,
                'data' => ['error' => ['message' => $error_message]],
                "tmp" => [$this->token, $headers]
            ) );
        }
        
        $response_data = json_decode(wp_remote_retrieve_body($response), true);

        $response = array(
            'success' =>  true,
            'data' => $response_data
        );

        if (!$response_data['error']) {

            $to = wp_kses_post(get_option('admin_email'));
            $subject = 'You have received a new order!';
            $message = "
Dear Admin,

A new payment has been made. Here are the details:

- Amount: " . number_format($data['amount'] / 100, 2) . " USD
- Invoice Number: " . $data['external_reference_id'] . "
- Payment Description: Name: " . $data['description']['Name'] . ", Address: " . $data['description']['Address'] . ", Phone: " . $data['description']['Phone'] . "

Best regards,
- Clover Basic Pay
";
            $headers = array('Content-Type: text/html; charset=UTF-8');

            wp_mail($to, $subject, $message, $headers);
        }



        wp_send_json($response);

        exit();
    }


    function clover_scripts()
    {
        if (!$this->license) return null;

        wp_enqueue_script('clover-polyfill', 'https://cdn.polyfill.io/v3/polyfill.min.js');
        wp_enqueue_script('clover', $this->production === "1" ? $this->production_url : $this->sandbox_url);
        wp_enqueue_script('clover-pay',  plugin_dir_url(__FILE__) . 'pay.js', array(), '1.3');

        $uuid = wp_generate_uuid4();

        $cloverStyle = wp_kses_post(get_option('cbp_style'));

        if (!$cloverStyle) {
            ob_start();
            include __DIR__ . '/cloverStyle.json';
            $cloverStyle = ob_get_clean();
        }

        $data = array(
            'apiAccessKey' => $this->license,
            'apiProduction' => $this->production,
            'apiStyles' => $cloverStyle,
            'buttonText' => wp_kses_post(get_option("cbpsubmit", "Pay")) ?: "Pay",
            'submittedText' => wp_kses_post(get_option("cbpsubmit-submitted", "Processing...")) ?: "Processing...",
            'onPay' => wp_kses_post(get_option('cbp_onPay', "0")),
            'uuid' => $uuid,
        );
        wp_localize_script('clover-pay', 'cbpData', $data);
    }

    function setHidden($options)
    {
        if (!is_array($options)) {
            $options = array($options);
        }

        foreach ($options as $option) {
            $option_name = 'cbp' . $option;
            $option_value = get_option($option_name);

            if ($option_value['display'] !== "2") {
                return "";
            }
        }

        return "style=\"display: none\"";
    }


    function cloverbasicpay_shortcode($atts, $content)
    {
        $layout = get_option('cbp_display', 'basic');

        $this->clover_scripts();

        $code = isset($atts['code']) ? $atts['code'] : '';

        $output =  htmlspecialchars($code);

        $output .= '<form action="" method="post" id="cbp-payment-form">';
        $output .=  wp_nonce_field('cbp_save_settings', 'cbp_nonce');

        $layouts = [
            "basic" => '
            <div class="cbp-row" ' . $this->setHidden(["amount", "invoice"]) . '>
                    <div class="cbp-col">[cbp-amount]</div>
                    <div class="cbp-col">[cbp-invoice]</div>
                </div>
    
                <div class="cbp-row" ' . $this->setHidden("name") . '>
                    <div class="cbp-col-full">[cbp-name]</div>
                </div>
    
                <div class="cbp-row" ' . $this->setHidden("email") . '>
                    <div class="cbp-col-full">[cbp-email]</div>
                </div>
    
                <div class="cbp-row" ' . $this->setHidden("address") . '>
                    <div class="cbp-col-full">[cbp-address]</div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col-full">[cbp-card-postal-code]</div>
                </div>
    
                <div class="cbp-row" ' . $this->setHidden("phone") . '>
                    <div class="cbp-col-full">[cbp-phone]</div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col-full">[cbp-card-number]</div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col">[cbp-card-date]</div>
                    <div class="cbp-col">[cbp-card-cvv]</div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col-full">
                        [cbp-error]
                        [cbp-payment-button]  
                    </div>
                </div>
    
                [cbp-response]
            ',
            "option2" => '
                <div class="cbp-row" ' . $this->setHidden(["amount", "invoice"]) . '>
                    <div class="cbp-col">
                        [cbp-amount]
                    </div>
                    <div class="cbp-col">
                        [cbp-invoice]
                    </div>
                </div>
    
                <div class="cbp-row" ' . $this->setHidden(["name", "email"]) . '>
                    <div class="cbp-col">
                        [cbp-name]
                    </div>
                    <div class="cbp-col">
                        [cbp-email]
                    </div>
                </div>
                
                <div class="cbp-row" ' . $this->setHidden("phone") . '>
                    <div class="cbp-col-full">
                        [cbp-phone]
                    </div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col">
                        [cbp-address]
                    </div>
                    <div class="cbp-col">
                        [cbp-card-postal-code]
                    </div>
                </div>
    
    
                <div class="cbp-row">
                    <div class="cbp-col-full">
                        [cbp-card-number]
                    </div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col">
                        [cbp-card-date]
                    </div>
                    <div class="cbp-col">
                        [cbp-card-cvv]
                    </div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col-full">
                        [cbp-error]
                        [cbp-payment-button]
                    </div>
                </div>
                
                [cbp-response]
            ',
            "option3" => '
                <div class="cbp-row" ' . $this->setHidden(["amount", "email"]) . '>
                    <div class="cbp-col">
                        [cbp-amount]
                    </div>
                    <div class="cbp-col">
                        [cbp-email]
                    </div>
                </div>
                
                <div class="cbp-row">
                    <div class="cbp-col">
                        [cbp-card-number]
                    </div>
                    <div class="cbp-col">
                        [cbp-card-postal-code]
                    </div>
                </div>
    
    
                <div class="cbp-row">
                    <div class="cbp-col">
                        [cbp-card-date]
                    </div>
                    <div class="cbp-col">
                        [cbp-card-cvv]
                    </div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col-full">
                        [cbp-error]
                        [cbp-payment-button]
                    </div>
                </div>
                
                [cbp-response]
            ',
            "woo" => '
                <div class="cbp-row">
                    <div class="cbp-col">
                        [cbp-card-number]
                    </div>
                    <div class="cbp-col">
                        [cbp-card-postal-code]
                    </div>
                </div>
    
    
                <div class="cbp-row">
                    <div class="cbp-col">
                        [cbp-card-date]
                    </div>
                    <div class="cbp-col">
                        [cbp-card-cvv]
                    </div>
                </div>
    
                <div class="cbp-row">
                    <div class="cbp-col-full">
                        [cbp-error]
                       
                    </div>
                </div>
                
                [cbp-response]
            '
        ];


        if (!$content) {
            $output .= do_shortcode($layouts[$layout]);
        } else {
            $output .= do_shortcode($content);
        }

        $output .= '</form>';


        if ($layout === "woo") $output .= "<script>typeof clover === 'function' && clover()</script>";
        if ($layout !== "woo") $output .= '<script>window.onload = () => clover()</script>';


        return $output;
    }


    public function add_plugin_options_page()
    {
        add_menu_page(
            'Clover Basic Pay',
            'Clover Basic Pay',
            'manage_options',
            'cloverbasicpay',
            [$this, 'render_admin_page'],
            'dashicons-cart',
            30
        );
    }

    public function render_admin_page()
    {
?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Clover Basic Pay</h1>
            <h2 style="font-size: 12px">Created by: <a href="https://pixelmain.com" target="_blank">Pixel Main</a></h2>
            <form method="post" action="options.php">
                <?php
                wp_nonce_field('cbp_save_settings', 'cbp_nonce');
                settings_fields('cbp');
                settings_errors();
                $plugin_dir_path = plugin_dir_url(__FILE__);

                ?>

                <h2 class="nav-tab-wrapper">
                    <a href="#settings" class="nav-tab">Settings</a>
                    <a href="#display" class="nav-tab">Display</a>
                    <a href="#style" class="nav-tab">Style</a>
                    <a href="#fields" class="nav-tab">Fields</a>
                    <a href="#usage" class="nav-tab">Usage</a>
                    <a href="#information" class="nav-tab">Information</a>
                </h2>

                <div class="tab-content">
                    <div class="tab-pane active" id="settings">
                        <?php do_settings_sections('cbp_settings'); ?>
                        <?php submit_button(); ?>
                    </div>
                    <div class="tab-pane" id="display">
                        <?php do_settings_sections('cbp_display'); ?>
                        <?php submit_button(); ?>
                    </div>
                    <div class="tab-pane" id="style">
                        <h2>Clover Styles</h2>
                        <p>Each element is loaded in an iframe from Clover. In order to style it you can follow their <a href="https://docs.clover.com/docs/customizing-iframe-elements-with-css" target="_blank">Style Guide</a> and define the options below as a JSON document.</p>
                        <p>Copy the default <a href="<?php echo $plugin_dir_path . '/cloverStyle.json'; ?>" target="_blank">cloverStyle.json</a> to use here. Once defined the defaults will not be loaded.</p>

                        <label for="cbp_style">JSON:</label><br>
                        <textarea placeholder="Default in use." id="cbp_style" style="width:100%;min-height: 420px" name="cbp_style"><?php echo stripslashes(get_option('cbp_style')); ?></textarea>

                        <?php submit_button(); ?>
                    </div>
                    <div class="tab-pane" id="fields">
                        <?php do_settings_sections('cbp_fields'); ?>
                        <?php submit_button(); ?>
                    </div>
                    <div class="tab-pane" id="usage">
                        <?php do_settings_sections('cbp_usage'); ?>
                    </div>
                    <div class="tab-pane" id="information">
                        <?php do_settings_sections('cbp_information'); ?>
                    </div>
                </div>

            </form>
        </div>
        <style>
            .tab-content>.tab-pane {
                display: none;
            }

            .tab-content {
                background-color: #f1f1f1;
                padding: 10px;
            }

            .tab-content .form-table {
                background-color: #fff;
                border: 1px solid #ddd;
                padding: 10px;
                margin-bottom: 20px;
            }

            .tab-content .form-table th,
            .tab-content .form-table td {
                padding: 10px;
                line-height: 1.5;
            }

            .tab-content .form-table td textarea {
                width: 100%;
                min-height: 100px;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                var hash = window.location.hash;
                var activeTabId = null;

                // Check if there is a saved active tab in localStorage
                if (localStorage.getItem('activeTabId')) {
                    activeTabId = localStorage.getItem('activeTabId');
                }

                if (hash && $(hash).length) {
                    activeTabId = hash;
                }

                if (activeTabId) {
                    $('.nav-tab-wrapper a[href="' + activeTabId + '"]').addClass('nav-tab-active');
                    $('.tab-content .tab-pane').hide();
                    $(activeTabId).fadeIn();
                } else {
                    // Otherwise, set the first tab as active
                    $('.nav-tab-wrapper a:first').addClass('nav-tab-active');
                    $('.tab-content .tab-pane').hide();
                    $($('.nav-tab-wrapper a:first').attr('href')).fadeIn();
                }

                $('.nav-tab-wrapper a').click(function(event) {
                    $('.nav-tab-wrapper a').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.tab-content .tab-pane').hide();
                    $($(this).attr('href')).fadeIn();
                    $('html, body').animate({
                        scrollTop: 0
                    }, "fast");

                    // Save the active tab in localStorage
                    var activeTabId = $(this).attr('href');
                    localStorage.setItem('activeTabId', activeTabId);
                });
            });
        </script>
    <?php
    }

    public function usage_callback()
    {
    ?>
        <p>Clover Basic Pay allows you to use pre-configured layouts or short codes to design your own custom layout. Currently limited to 1 form per page.</p>

        <h4>Pre-configured Layouts</h4>
        <p>Using pre-configured layouts is simple. Define your Layout, Settings, and Display, then include the <code>[cloverbasicpay]</code> shortcode in any page to show the payment form.

        <h4>Custom Layouts</h4>

        <p>You can use each component to create your own custom layout within a <code>[cloverbasicpay][/cloverbasicpay]</code>.</p>
        <pre><code>[cloverbasicpay]
    [cbp-email]
    [cbp-address]
    [cbp-phone]
    [cbp-invoice]
    [cbp-amount]

    [cbp-card-number]
    [cbp-card-date]
    [cbp-card-cvv]
    [cbp-card-postal-code]

    [cbp-response]
    [cbp-error]

    [cbp-payment-button]
[/cloverbasicpay]</code></pre>

        <h4>Custom Fields</h4>
        <p>Fields can be customized inline when using a Custom Layout. For example you can hide the Amount field and define the value yourself.<br />This payment form will charge $1.00:
        <pre><code>[cbp-amount value="1.00" display="false"]</code></pre>
        </p>

        <h5>Field Options</h5>
        <ul>
            <li><code>label</code>: String</li>
            <li><code>placeholder</code>: String</li>
            <li><code>required</code>: Boolean</li>
            <li><code>showLabel</code>: Boolean</li>
            <li><code>display</code>: Boolean</li>
            <li><code>class</code>: String</li>
            <li><code>value</code>: String</li>
        </ul>

        <h5>Invoice &amp; Amount Values</h5>
        <p>
            These fields can be set by a <code>$_REQUEST</code> variable. If you <code>POST</code> or <code>GET</code> your payment page you can assign values.

        <pre><code>https://host.tld/pay/?invoice=10&amount=1.0</code></pre>
        </p>

        <h4>Custom Success &amp; Error Responses</h4>
        <p>You can use each component to create your own custom layout within a <code>[cloverbasicpay][/cloverbasicpay]</code>.</p>

        <h5>Success Message Code</h5>
        <pre><code><?php echo htmlspecialchars($this->receiptCode); ?></code></pre>

        <h5>Error Message Code</h5>
        <pre><code><?php echo htmlspecialchars($this->errorCode); ?></code></pre>

        <h4>Test Credit Cards</h4>
        <p>Use any future date, zip code, and CVV with the following cards:</p>
        <ul>
            <li>4111111111111111</li>
        </ul>


        <style>
            pre {
                background-color: #f6f8fa;
                color: #2d2d2d;
                font-family: Consolas, Monaco, "Andale Mono", "Ubuntu Mono", monospace;
                font-size: 14px;
                line-height: 1.4;
                margin-bottom: 1.5em;
                padding: 1em;
                overflow: auto;
                border-radius: 0.3em;
            }

            code {
                background-color: #f6f8fa;
                color: #2d2d2d;
                font-family: Consolas, Monaco, "Andale Mono", "Ubuntu Mono", monospace;
                font-size: 14px;
                padding: 0.2em 0.4em;
                border-radius: 0.3em;
            }
        </style>
<?php
    }

    public function information_callback()
    {
        echo '<p>This plugin is intended to be a very basic way to accept payments in your WordPress application using Clover.</p>';

        echo '<p>You can view more information on the <a href="https://pixelmain.com/clover-basic-pay-wordpress" target="_blank">Clover Basic Pay Website</a>.</p>';
        echo '<p>This plugin uses the <a href="https://docs.clover.com/docs/clover-iframe-integrations" target="_blank">Clover Iframe Integration</a> features.</p>';
    }

    public function add_plugin_settings()
    {
        register_setting('cbp', 'cbp', [$this, 'post']);

        register_setting('cbp_style', 'cbp_style', array(
            'type' => 'string',
        ));



        register_setting('cbp_fields', 'cbp_fields');
        register_setting('cbp_display', 'cbp_display');
        register_setting('cbp_production', 'cbp_production');

        register_setting('cbp_onPay', 'cbp_onPay', 0);
        register_setting('cbp_onPayValue', 'cbp_onPayValue', 'Payment Completed.');
        register_setting('cbp_onErrorValue', 'cbp_onErrorValue', 'Payment Completed.');

        add_settings_section('cbp_fields', 'Fields', array($this, 'cbp_display_callback'), 'cbp_fields');
        add_settings_section(
            'cbp_display',
            'Display',
            function () {
                $display_option = get_option('cbp_display', 'basic');
                $plugin_dir_path = plugin_dir_url(__FILE__);


                echo '<p>Use the <code>[cloverbasicpay]</code> shortcode in your page or post to display the layout selected.</p>';

                echo '<p>To create your own custom layout view Usage for an example.</p>';
                echo '<h4>Layout Style:</h4>';

                echo '<label class="display-option"><input type="radio" name="cbp_display" value="basic" ' . checked($display_option, 'basic', false) . '><img src="' . $plugin_dir_path . 'layouts/option1.png' . '"></label>';
                echo '<label class="display-option"><input type="radio" name="cbp_display" value="option2" ' . checked($display_option, 'option2', false) . '><img src="' . $plugin_dir_path . 'layouts/option2.png' . '"></label>';
                echo '<label class="display-option"><input type="radio" name="cbp_display" value="option3" ' . checked($display_option, 'option3', false) . '><img src="' . $plugin_dir_path . 'layouts/option3.png' . '"></label>';
                echo '<label class="display-option"><input type="radio" name="cbp_display" value="woo" ' . checked($display_option, 'option3', false) . '><img src="' . $plugin_dir_path . 'layouts/option3.png' . '"></label>';

                echo '<style>.display-option input[type="radio"] {
                    position: absolute;
                    opacity: 0;
                    pointer-events: none;
                }
                
                .display-option img {
                    cursor: pointer;
                    border: 2px solid transparent;
                    width: 70%;
                    height: auto;
                }
                
                .display-option input[type="radio"]:checked + img {
                    border-color: blue;
                }
                </style>';
            },
            'cbp_display'
        );

        add_settings_section('cbp_usage', 'Usage', array($this, 'usage_callback'), 'cbp_usage');
        add_settings_section('cbp_information', 'Information', array($this, 'information_callback'), 'cbp_information');

        add_settings_section(
            'cbp_settings',
            'Settings',
            function () {
                echo '<p>To enable this plugin you must have a Clover API Key and Access Token. These will be generated after you authorize our application in the Clover Marketplace by clicking a link below.</p>';


                echo '<p><a href="https://sandbox.dev.clover.com/oauth/authorize?client_id=3PGV50N6C8WNP" target="_blank">Generate Staging Credentials</a></p>';
                echo '<p><a href="https://clover.com/appmarket/apps/Z539P424VSMVW" target="_blank">Generate Production Credentials</a></p>';
                echo '<p><a href="https://pixelmain.com/clover-basic-pay-wordpress" target="_blank">View the Clover Basic Pay website for more information.</a></p>';


                echo '<p>Once you\'ve received your Clover API Key and Access Token enter it below and save your changes.</p>';
            },
            'cbp_settings'
        );

        add_settings_field(
            'cbp_license',
            'Clover API Key',
            [$this, 'render_license_key_field'],
            'cbp_settings',
            'cbp_settings'
        );

        add_settings_field(
            'cbp_token',
            'Clover Access Token',
            [$this, 'render_token_field'],
            'cbp_settings',
            'cbp_settings'
        );

        add_settings_field(
            'cbp_production',
            'Production Mode',
            [$this, 'render_production_field'],
            'cbp_settings',
            'cbp_settings'
        );

        add_settings_section(
            'cbp_fields',
            'Fields',
            function () {
                echo '<p>Define the default settings for your fields. You can also define these parameters inline, view the Usage tab for more information.</p>';
                echo '<h4>On Success Options</h4>';
                echo '<p>When a payment is made you can have one of the following actions performed:</p>';
                echo '<p><strong>Show Value:</strong>  The Success Code will be rendered where it is displayed.</p>';
                echo '<p><strong>Show Value &amp; Hide Form:</strong> The Success Code will be rendered at the top of the form and the form elements will be hidden.</p>';
                echo '<p><strong>Redirect:</strong> The window will be redirect to the URL you provide in Success Code.</p>';
                echo '<p><strong>Show Custom Div &amp; Hide Form:</strong> Provide an elements HTML ID in the Success Code and that element will be set to <code>display: flex</code> and the form elements will be hidden.</p>';
                echo '<p></p>';
            },
            'cbp_fields'
        );

        add_settings_field(
            'cbp_onPay',
            'On Success',
            [$this, 'render_onPay'],
            'cbp_fields',
            'cbp_fields'
        );

        add_settings_field(
            'cbp_onPayValue',
            'Success Code',
            [$this, 'render_onPayValue'],
            'cbp_fields',
            'cbp_fields'
        );

        add_settings_field(
            'cbp_onErrorValue',
            'Error Code',
            [$this, 'render_onErrorValue'],
            'cbp_fields',
            'cbp_fields'
        );

        add_settings_field(
            'cbpsubmit',
            "Submit Button Text",
            array($this, 'render_text_field'),
            'cbp_fields',
            'cbp_fields',
        );

        add_settings_field(
            'cbpsubmit-submitting',
            "Submitted Button Text",
            array($this, 'render_text2_field'),
            'cbp_fields',
            'cbp_fields',
        );


        foreach ($this->fields as $field_key => $field) {
            add_settings_field(
                'cbp' . $field_key,
                $field['label'],
                array($this, 'render_setting_field'),
                'cbp_fields',
                'cbp_fields',
                array(
                    'field_key' => $field_key,
                    'label' => $field['label'],
                    'placeholder' => $field['placeholder'],
                    'required' => $field['required'],
                    'showLabel' => $field['showLabel'],
                    'display' => $field['display'],
                    'label' => $field['label'],
                )
            );
        }
    }

    public function render_text_field()
    {
        $option_name = 'cbpsubmit';
        $field_name = $option_name;
        $field_placeholder = "Pay";
        $options = get_option($option_name);

        echo '<input type="text" id="' . $field_name . '_placeholder" name="' . $option_name . '" value="' . wp_kses_post($options) . '" placeholder="' . $field_placeholder . '"></p>';
    }

    public function render_text2_field()
    {
        $option_name = 'cbpsubmit-submitting';
        $field_name = $option_name;
        $field_placeholder = "Processing...";
        $options = get_option($option_name);

        echo '<input type="text" id="' . $field_name . '_placeholder" name="' . $option_name . '" value="' . wp_kses_post($options) . '" placeholder="' . $field_placeholder . '"></p>';
    }


    public function render_setting_field($args)
    {
        $option_name = 'cbp' . $args['field_key'];
        $field_name = $args['name'];
        $field_placeholder = $args['placeholder'];
        $options = get_option($option_name);
        $field_label = $options['label'] ?: $args['label'];

        $required = $options['required'] ? ($options['required'] === "1" ? 'checked' : "") : ($args["required"] === "true" ? 'checked' : '');
        $showLabel = $options['showLabel'] ? ($options['showLabel'] === "1" ? 'checked' : "") : ($args["showLabel"] === "true" ? 'checked' : '');
        $display = $options['display'] ? ($options['display'] === "1" ? 'checked' : "") : ($args["display"] === "true" ? 'checked' : '');

        echo '<p><input type="checkbox" id="' . $option_name . '_display" name="' . $option_name . '[display]" value="1" ' . $display . '> <label for="' . $option_name . '_display">Display</label> </p>';

        echo '<p><input type="checkbox" id="' . $option_name . '_required" name="' . $option_name . '[required]" value="1" ' . $required . '> <label for="' . $option_name . '_required">Required</label></p>';

        echo '<p><input type="checkbox" id="' . $option_name . '_showLabel" name="' . $option_name . '[showLabel]" value="1" ' . $showLabel . '> <label for="' . $option_name . '_showLabel">Show Label</label></p>';

        echo '<p><label for="' . $field_name . '_label">Label</label><br>';
        echo '<input type="text" id="' . $field_name . '_label" name="' . $option_name . '[label]" value="' . wp_kses_post($options['label']) . '" placeholder="' . $field_label . '"></p>';

        echo '<p><label for="' . $field_name . '_placeholder">Placeholder</label><br>';
        echo '<input type="text" id="' . $field_name . '_placeholder" name="' . $option_name . '[placeholder]" value="' . wp_kses_post($options['placeholder']) . '" placeholder="' . $field_placeholder . '"></p>';
    }

    public function render_onPay()
    {
        $onPay = get_option('cbp_onPay');

        printf(
            '<select id="cbp_onPay" name="cbp_onPay"><option value="0" %s>Show Value</option><option value="1" %s>Show Value &amp; Hide Form</option><option value="2" %s>Redirect</option><option value="3" %s>Show Custom Div &amp; Hide Form</option></select>',
            $onPay == '0' ? 'selected' : '',
            $onPay == '1' ? 'selected' : '',
            $onPay == '2' ? 'selected' : '',
            $onPay == '3' ? 'selected' : ''
        );
    }



    public function render_onErrorValue()
    {
        $onPayValue = get_option('cbp_onErrorValue');

        printf(
            '<textarea id="cbp_onErrorValue" name="cbp_onErrorValue" placeholder="Default in use. View Usage for code example.">%s</textarea>',
            isset($onPayValue) ? wp_kses_post($onPayValue) : ''
        );
    }


    public function render_onPayValue()
    {
        $onPayValue = get_option('cbp_onPayValue', "Payment Completed.");

        printf(
            '<textarea id="cbp_onPayValue" name="cbp_onPayValue" placeholder="Default in use. View Usage for code example.">%s</textarea>',
            isset($onPayValue) ? wp_kses_post($onPayValue) : ''
        );
    }

    public function render_token_field()
    {
        printf(
            '<input type="text" id="token" name="cbp_token" value="%s" />',
            isset($this->token) ? esc_attr($this->token) : ''
        );
    }

    public function render_license_key_field()
    {
        printf(
            '<input type="text" id="key" name="cbp_license" value="%s" />',
            isset($this->license) ? esc_attr($this->license) : ''
        );
    }

    public function render_production_field()
    {
        printf(
            '<input type="checkbox" id="production" name="cbp_production" %s value="1" />',
            $this->production === "1" ? "checked" : ""
        );
    }

    public function post()
    {
        if (!isset($_POST['cbp_nonce']) || !wp_verify_nonce($_POST['cbp_nonce'], 'cbp_save_settings')) {
            add_settings_error('cbp_nonce', esc_attr('settings_updated'), 'Invalid nonce.', 'error');
            return false;
        }

        foreach ($this->fields as $field_key => $field) {
            if (isset($_POST['cbp' . $field_key])) {
                $value = $_POST['cbp' . $field_key];

                if (!isset($value['display'])) {
                    $value['display'] = "2";
                }

                if (!isset($value['required'])) {
                    $value['required'] = "2";
                }

                if (!isset($value['showLabel'])) {
                    $value['showLabel'] = "2";
                }

                update_option('cbp' . $field_key, sanitize_text_field($value));
            }
        }


        update_option('cbpsubmit', sanitize_text_field($_POST['cbpsubmit']));
        update_option('cbpsubmit-submitting', sanitize_text_field($_POST['cbpsubmit-submitting']));
        update_option('cbp_style', wp_kses_post($_POST['cbp_style']));
        update_option('cbp_display', sanitize_text_field($_POST['cbp_display']));
        update_option('cbp_license', sanitize_text_field($_POST['cbp_license']));
        update_option('cbp_token', sanitize_text_field($_POST['cbp_token']));
        update_option('cbp_onPay', sanitize_text_field($_POST['cbp_onPay']));
        update_option('cbp_onPayValue', wp_kses_post($_POST['cbp_onPayValue']));
        update_option('cbp_onErrorValue', wp_kses_post($_POST['cbp_onErrorValue']));


        update_option('cbp_production', sanitize_text_field(isset($_POST['cbp_production']) ? $_POST['cbp_production'] : ""));
    }
}

$plugin = new CBPPlugin();

if (is_admin()) {
    $plugin->load();
}

$plugin->loadPublic();
