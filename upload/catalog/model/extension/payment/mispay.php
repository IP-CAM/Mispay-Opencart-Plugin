<?php

// Prevent direct access
if (!defined('DIR_APPLICATION')) {
    exit;
}

require_once(DIR_SYSTEM . 'library/MispayController.php');

class ModelExtensionPaymentMispay extends Model
{
    private $MisPayController;

    public function getMethod($address, $total)
    {
        $this->load->language('extension/payment/mispay');

        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_mispay_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");

        if ($this->config->get('payment_mispay_total') > 0 && $this->config->get('payment_mispay_total') > $total) {
            $status = false;
        } elseif (!$this->config->get('payment_mispay_geo_zone_id')) {
            $status = true;
        } elseif ($query->num_rows) {
            $status = true;
        } else {
            $status = false;
        }

        $method_data = array();

        if ($status) {
            // Get the current session language instead of admin config language
            $current_language = isset($this->session->data['language']) ? $this->session->data['language'] : $this->config->get('config_language');
            
            // Improved language detection - check for Arabic language codes
            $is_arabic = (strpos($current_language, 'ar') === 0); // Checks if language starts with 'ar'
            
            // Get title and description based on language
            if ($is_arabic) {
                $title = $this->config->get('payment_mispay_title_ar') ?: $this->language->get('text_title');
                $description = $this->config->get('payment_mispay_description_ar') ?: $this->language->get('text_description');
            } else {
                $title = $this->config->get('payment_mispay_title_en') ?: $this->language->get('text_title');
                $description = $this->config->get('payment_mispay_description_en') ?: $this->language->get('text_description');
            }

            // Check if logo should be shown
            $show_logo = $this->config->get('payment_mispay_show_icon');
            $logo_html = '';
            
            if ($show_logo) {
                $logo_html = '<img src="'.HTTPS_SERVER.'image/catalog/logo.svg" style="height: 20px;"/>';
            }

            $method_data = array(
                'code'       => 'mispay',
                'title'      =>  $logo_html .$title,
                'terms'      => $description,
                'sort_order' => $this->config->get('payment_mispay_sort_order')
            );
        }

        return $method_data;
    }

    /**
     * Initialize MisPayController
     */
    private function initMisPayController()
    {
        if (!$this->MisPayController) {
            $this->MisPayController = new MisPayController(
                $this->registry,
                $this->config->get('payment_mispay_test'),
                $this->config->get('payment_mispay_app_id'),
                $this->config->get('payment_mispay_app_secret')
            );
        }
        return $this->MisPayController;
    }

    public function processPayment($order_id)
    {
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($order_id);

        if (!$order) {
            error_log('MISPay Error: Invalid order ID in process_payment: ' . $order_id);
            return array(
                'result' => 'failure',
                'redirect' => $this->url->link('checkout/checkout', '', true)
            );
        }

        // Update order status to pending
        $this->model_checkout_order->addOrderHistory($order_id, 1, 'Pending MISPay payment');
        
        // Add payment method to order
        $this->addOrderMeta($order_id, 'payment_method', 'MISPay');
        
        $mispay_controller = $this->initMisPayController();
        $startCheckout = $mispay_controller->start_checkout($order_id);
        if ($startCheckout) {
            return array(
                'result' => 'success',
                'redirect' => $startCheckout,
            );
        } else {
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('config_order_status_id'), 'Failed to start MISPay checkout process');

            return array(
                'result' => 'failure',
                'redirect' => $this->url->link('checkout/checkout', '', true)
            );
        }
    }

    public function handleCallback()
    {
        $response = $this->request->get['_'];
        
        if (empty($response)) {
            return array(
                'result' => 'failed',
                'orderId' => null
            );
        }
        
        $mispay_controller = $this->initMisPayController();
        $decodeResponse = $mispay_controller->decrypt(base64_decode($response));
        
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($decodeResponse['orderId']);

        if (!$order) {
            error_log('MISPay Error: Invalid order ID in handle_callback: ' . $decodeResponse['orderId']);
            return array(
                'result' => 'failed',
                'orderId' => null,
                'message' => $this->language->get('error_invalid_order')
            );
        }

        // Log the callback received
        $this->log_callback_response($order['order_id'], $decodeResponse);
        //print_r($decodeResponse);
        if (!isset($decodeResponse['code']) || $decodeResponse['code'] !== 'MP00') {
            $this->model_checkout_order->addOrderHistory($order['order_id'], 7, 'MISPay payment canceled');
            return array(
                'result' => 'failed',
                'orderId' => $order['order_id'],
                'message' => $decodeResponse['code'] === 'MP02' ? $this->language->get('error_payment_canceled') : $this->language->get('error_payment_timeout')
            );
        } else {
            // Update order status to 'Processing' for successful MISPay payments
            $this->model_checkout_order->addOrderHistory($order['order_id'], $this->config->get('payment_mispay_order_status_id'), 'MISPay payment completed successfully');
            return array(
                'result' => 'success',
                'orderId' => $order['order_id'],
                'checkoutId' => $decodeResponse['checkoutId']
            );
        }
    }

    /**
     * Get widget for cart page
     */
    public function getWidget()
    {   
        if (!$this->config->get('payment_mispay_enable_widget')) {
            return '';
        }
       
        $cart_total = 0;
       
        $products = $this->cart->getProducts();
        
        if (count($products) > 0) {
            foreach ($this->cart->getProducts() as $key => $product) {
                $query = $this->db->query("SELECT price FROM " . DB_PREFIX . "product WHERE product_id = '" . (int)$product['product_id'] . "'");
                if ($query->num_rows) {
                    $cart_total += $query->row['price'] * $product['quantity'];
                }
            }
        }
        
        if ($cart_total <= 0) {
            return '';
        }
        
        // Improved language detection - check for Arabic language codes
        $current_language = isset($this->session->data['language']) ? $this->session->data['language'] : $this->config->get('config_language');
        $is_arabic = (strpos($current_language, 'ar') === 0); // Checks if language starts with 'ar'
        $lang = $is_arabic ? 'ar' : 'en';
        
        $access_key = $this->config->get('payment_mispay_access_key');
        
        // Validate access key
        if (empty($access_key)) {
            error_log('MISPay Widget Error: Access key is missing for cart widget');
            return '';
        }
        
        // Ensure amount is a valid number
        $amount = (float)$cart_total;
        if ($amount <= 0) {
            return '';
        }
        
        // Determine SDK URL based on test mode
        $is_test = $this->config->get('payment_mispay_test');
        $sdk_url = $is_test ? 'https://widget-sandbox.mispay.dev/v1/sdk.js' : 'https://widget.mispay.co/v1/sdk.js';
        
        return '<div class="mispay-widget-container">
            <mispay-widget amount="' . number_format($amount, 2, '.', '') . '" lang="' . $lang . '"></mispay-widget>
        </div>
        <script defer src="' . $sdk_url . '?authorize=' . htmlspecialchars($access_key) . '"></script>';
    }

    /**
     * Get widget for product page
     */
    public function getProductWidget($product_id)
    { 
        if (!$this->config->get('payment_mispay_enable_widget')) {
            return '';
        }

        $query = $this->db->query("SELECT price FROM " . DB_PREFIX . "product WHERE product_id = '" . (int)$product_id . "'");
        
        if (!$query->num_rows || $query->row['price'] <= 0) {
            return '';
        }
        
        // Improved language detection - check for Arabic language codes
        $current_language = isset($this->session->data['language']) ? $this->session->data['language'] : $this->config->get('config_language');
        $is_arabic = (strpos($current_language, 'ar') === 0); // Checks if language starts with 'ar'
        $lang = $is_arabic ? 'ar' : 'en';
        
        $price = $query->row['price'];
        $access_key = $this->config->get('payment_mispay_access_key');
        
        // Validate access key
        if (empty($access_key)) {
            error_log('MISPay Widget Error: Access key is missing for product widget');
            return '';
        }
        
        // Ensure amount is a valid number
        $amount = (float)$price;
        if ($amount <= 0) {
            return '';
        }
        
        // Determine SDK URL based on test mode
        $is_test = $this->config->get('payment_mispay_test');
        $sdk_url = $is_test ? 'https://widget-sandbox.mispay.dev/v1/sdk.js' : 'https://widget.mispay.co/v1/sdk.js';
        
        $widget_html = '<div class="mispay-widget-container">
            <mispay-widget amount="' . number_format($amount, 2, '.', '') . '" lang="' . $lang . '"></mispay-widget>
        </div>
        <script defer src="' . $sdk_url . '?authorize=' . htmlspecialchars($access_key) . '"></script>';
        
        error_log('MISPay Debug: Widget HTML generated successfully');
        return $widget_html;
    }

    /**
     * Log callback response to order meta
     */
    private function log_callback_response($order_id, $callback_data)
    {
        $log_entry = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => 'callback_received',
            'message' => 'Callback received from MISPay',
            'details' => array(
                'code' => $callback_data['code'] ?? 'unknown',
                'order_id' => $callback_data['orderId'] ?? 'unknown',
                'checkout_id' => $callback_data['checkoutId'] ?? 'unknown',
                'raw_data' => $callback_data
            )
        );
        
        // Get existing logs
        $existing_logs = $this->getOrderMeta($order_id, 'mispay_transaction_logs');
        $logs = is_array($existing_logs) ? $existing_logs : array();
        
        // Add new log entry
        $logs[] = $log_entry;
        
        // Keep only last 20 entries
        if (count($logs) > 20) {
            $logs = array_slice($logs, -20);
        }
        
        $this->addOrderMeta($order_id, 'mispay_transaction_logs', $logs);
    }

    /**
     * Add order meta data
     */
    public function addOrderMeta($order_id, $key, $value)
    {
        $this->db->query("INSERT INTO " . DB_PREFIX . "order_meta SET order_id = '" . (int)$order_id . "', meta_key = '" . $this->db->escape($key) . "', meta_value = '" . $this->db->escape(serialize($value)) . "' ON DUPLICATE KEY UPDATE meta_value = '" . $this->db->escape(serialize($value)) . "'");
    }

    /**
     * Get order meta data
     */
    public function getOrderMeta($order_id, $key)
    {
        $query = $this->db->query("SELECT meta_value FROM " . DB_PREFIX . "order_meta WHERE order_id = '" . (int)$order_id . "' AND meta_key = '" . $this->db->escape($key) . "'");
        
        if ($query->num_rows) {
            return unserialize($query->row['meta_value']);
        }
        
        return null;
    }

    /**
     * Check if tracking is scheduled for an order
     */
    public function isTrackingScheduled($order_id)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "cron WHERE code = 'mispay_track_checkout' AND parameter = '" . (int)$order_id . "' AND status = '1'");
        return $query->num_rows > 0;
    }

    /**
     * Schedule tracking for an order
     */
    public function scheduleTracking($order_id, $time)
    {
        $this->db->query("INSERT INTO " . DB_PREFIX . "cron SET code = 'mispay_track_checkout', parameter = '" . (int)$order_id . "', cycle = 'once', action = 'catalog/extension/payment/mispay/trackCheckout', status = '1', date_added = NOW(), date_modified = NOW()");
    }

    /**
     * Clear scheduled tracking for an order
     */
    public function clearScheduledTracking($order_id)
    {
        $this->db->query("DELETE FROM " . DB_PREFIX . "cron WHERE code = 'mispay_track_checkout' AND parameter = '" . (int)$order_id . "'");
    }

    /**
     * Track checkout status
     */
    public function trackCheckout($order_id)
    {
        $mispay_controller = $this->initMisPayController();
        return $mispay_controller->track_checkout($order_id);
    }

    /**
     * Install extension
     */
    public function install() {
        // Create order_meta table if it doesn't exist
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "order_meta` (
            `meta_id` int(11) NOT NULL AUTO_INCREMENT,
            `order_id` int(11) NOT NULL,
            `meta_key` varchar(255) NOT NULL,
            `meta_value` text NOT NULL,
            PRIMARY KEY (`meta_id`),
            KEY `order_id` (`order_id`),
            KEY `meta_key` (`meta_key`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");
        
        // Add default settings
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_status', `value` = '0'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_test', `value` = '1'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_app_id', `value` = ''");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_app_secret', `value` = ''");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_order_status_id', `value` = '2'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_geo_zone_id', `value` = '0'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_sort_order', `value` = '0'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_total', `value` = '0'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_show_icon', `value` = '1'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_enable_widget', `value` = '1'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_access_key', `value` = ''");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_title_en', `value` = 'Buy now then pay it later with MISpay'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_title_ar', `value` = 'اشتر الان وقسطها لاحقا مع MISpay'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_description_en', `value` = 'Split your purchase into 3 interest-free payments, No late fees. sharia-compliant'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_description_ar', `value` = 'قسم مشترياتك إلى 3 دفعات بدون فوائد، بدون رسوم تأخير متوافقة مع أحكام الشريعة الإسلامية'");
    }

    /**
     * Uninstall extension
     */
    public function uninstall() {
        // Remove settings
        $this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE `code` = 'payment_mispay'");
        
        // Clear any scheduled tracking
        $this->db->query("DELETE FROM " . DB_PREFIX . "cron WHERE `code` = 'mispay_track_checkout'");
    }
} 