<?php

if (class_exists("WC_Payment_Gateway")) {
    class WC_Gateway_Clover_Basic_Pay extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->has_fields = true;
            $this->id = 'clover-basic-pay';
            $this->icon = '';
            $this->has_fields = true;
            $this->method_title = 'Clover Basic Pay';
            $this->method_description = 'Adds a payment system to WooCommerce using the Clover payment gateway.';
            $this->supports = array(
                'products',
                'add_payment_method'
            );

            $this->init_form_fields();
            $this->init_settings();

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_admin_order_data_after_order_details', array($this, 'order_details'), 10, 1);
            add_filter('woocommerce_get_order_item_totals', array($this, 'account_order'), 10, 3);
        }

        public function get_card_details($order): string
        {
            return get_post_meta($order->id, '_card_details', true);
        }

        public function account_order($total_rows, $order)
        {
            $card_details = $this->get_card_details($order);

            if ($card_details) {
                $total_rows['card_details'] = [
                    'label' => __('Card details:', 'woocommerce'),
                    'value' => esc_html($card_details)
                ];

                // Reorder items totals
                [$orderTotalKey, $orderTotalValue] = array_splice($total_rows, array_search('order_total', array_keys($total_rows)), 1);
                $total_rows['order_total'] = $orderTotalValue;
            }

            return $total_rows;
        }

        public function order_details($order)
        {
            $card_details = $this->get_card_details($order);

            if ($card_details) {
                echo '<br class="clear"/><h4>Payment Information </h4><br class="clear"/>' . $card_details;
            }
        }


        /**
         * Initialize the payment gateway form fields.
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Clover Basic Pay', 'woocommerce'),
                    'default' => 'yes',
                ),

            );
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

        public function process_payment($order_id)
        {
            $order = new \WC_Order($order_id);
            $customer_data = $this->get_customer_data($order_id);
            $currency = $order->get_currency();
            $amount = $this->convertToCents($order->get_total());
            $source = sanitize_text_field(wp_unslash($_POST['cloverToken']));
            $uuid = sanitize_text_field(wp_unslash($_POST['cloverUuid']));
            $email = $customer_data['email'];

            $token  = get_option('cbp_token');
            $url = get_option('cbp_production') ? 'https://scl.clover.com' : 'https://scl-sandbox.dev.clover.com';

            $headers = array(
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'idempotency-key' => $uuid
            );

            $data = array(
                'amount' => $amount,
                'currency' => $currency,
                'source' => $source,
                'external_reference_id' => $order_id,
                'description' => "Name: {$customer_data['first_name']} {$customer_data['last_name']}, Phone: {$customer_data['phone']}, Email: {$email}"
            );

            if ($email) {
                $data['receipt_email'] = $email;
            }

            $response = $this->send_payment_request($url, $headers, $data);

            $success = $response['success'];
            $response_data = $response['data'];

            if (!$success) {
                $failure_message = $response_data["error"]["message"];
                $order->update_status('failed');
                wc_add_notice($failure_message, 'error');
                return array(
                    'result' => 'failed',
                    'message' => $failure_message,
                    'error_code' => $response_data['error_code'],
                );
            }

            $order->payment_complete($response_data['id']);
            $order->set_transaction_id($response_data['id']);

            $brand = $this->get_card_brand_name($response_data['source']['brand']);
            $card_details = $brand . ' ending in ' . $response_data['source']['last4'];

            add_post_meta($order_id, '_brand', $response_data->source->brand);
            add_post_meta($order_id, '_last4', $response_data->source->last4);
            add_post_meta($order_id, '_card_details', $card_details);

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }

        private function send_payment_request($url, $headers, $data)
        {
            $response = wp_remote_post($url . '/v1/charges', array(
                'headers' => $headers,
                'body' => json_encode($data),
            ));


            if (is_wp_error($response)) {
                return wp_send_json(array(
                    'success' => false,
                    'data' => ['error' => ['message' => $response->get_error_message()]],
                ));
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 401 ||  $response_code === 400) {
                $response_body = wp_remote_retrieve_body($response);
                $response_data = json_decode($response_body, true);
                $error_message = isset($response_data['error']['message']) ? $response_data['error']['message'] : 'Unauthorized';
                return wp_send_json(array(
                    'success' => false,
                    'data' => ['error' => ['message' => $error_message]],
                ));
            }

            $response_data = json_decode(wp_remote_retrieve_body($response), true);


            return array(
                'success' => !$response_data['error'],
                'data' => $response_data,
            );
        }

        private function get_card_brand_name($brand_code)
        {
            if (strcasecmp($brand_code, 'MC') == 0) {
                return 'MasterCard';
            } else {
                return $brand_code;
            }
        }

        public function validate_fields()
        {
            return true;
        }

        private function get_customer_data($order_id)
        {
            $customer_data = [];

            $user_id = get_post_meta($order_id, '_customer_user', true);
            if ($user_id) {
                $customer = new WC_Customer($user_id);
                $customer_data = [
                    'first_name' => $customer->get_first_name(),
                    'last_name' => $customer->get_last_name(),
                    'phone' => $customer->get_billing_phone(),
                ];
            } else {
                $customer_data = [
                    'first_name' => get_post_meta($order_id, '_billing_first_name', true),
                    'last_name' => get_post_meta($order_id, '_billing_last_name', true),
                    'phone' => get_post_meta($order_id, '_billing_phone', true),
                ];
            }
            $customer_data['email'] = get_post_meta($order_id, '_billing_email', true);

            return $customer_data;
        }


        public function payment_fields()
        {
            echo '<fieldset id="wc-' . esc_attr($this->id) . '-cc-form" class="wc-credit-card-form wc-payment-form">';
            echo do_shortcode("[cloverbasicpay]");
            echo '<input type="hidden" name="cloverToken" id="cbp-clover-token"/>';
            echo '<input type="hidden" name="cloverUuid" id="cbp-clover-uuid"/>';
            echo '</fieldset>';
        }
    }
}
