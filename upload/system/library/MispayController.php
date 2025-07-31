<?php

// Prevent direct access
if (!defined('DIR_APPLICATION')) {
    exit;
}

class MisPayController
{
    private $SANDBOX_API_URL = 'https://api-sandbox.mispay.dev/v1/api/';
    private $PROD_API_URL = 'https://api.mispay.co/v1/api/';
    private $API_URL;
    private $APP_ID;
    private $APP_SECRET;
    private $ACCESS_TOKEN;
    private $TOKEN_EXPIRY;
    private $registry;

    public function __construct($registry, $isSandbox, $appId, $appSecret)
    {
        $this->registry = $registry;
        $this->API_URL = $isSandbox ? $this->SANDBOX_API_URL : $this->PROD_API_URL;
        $this->APP_ID = $appId;
        $this->APP_SECRET = $appSecret;
        $this->ACCESS_TOKEN = null;
        $this->TOKEN_EXPIRY = null;
    }

    /**
     * Get a fresh access token, ensuring it's valid and not expired
     */
    function get_access_token()
    { 
        // Check if we have a valid token that hasn't expired
        if ($this->is_token_valid()) {
            return $this->ACCESS_TOKEN;
        }
       

        // Clear any existing token
        $this->ACCESS_TOKEN = null;
        $this->TOKEN_EXPIRY = null;

        $api_url = $this->API_URL . 'token';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'x-app-id: ' . $this->APP_ID,
            'x-app-secret: ' . $this->APP_SECRET,
            'accept: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log('MISPay Error: cURL request failed');
            return false;
        }

        if ($http_code !== 200) {
            error_log('MISPay Error: Invalid response code ' . $http_code);
            return false;
        }

        $data = json_decode($response, true);
       

        if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'success') {
            error_log('MISPay Error: Invalid response data');
            return false;
        }

        $encryptedAccessToken = $data['result']['token'];
        $decryptedToken = $this->decrypt($encryptedAccessToken);

        if (!empty($decryptedToken['token'])) {
            $this->ACCESS_TOKEN = $decryptedToken['token'];
            // Set token expiry to 1 hour from now (assuming tokens expire in 1 hour)
            $this->TOKEN_EXPIRY = time() + (60 * 60);
            return $decryptedToken['token'];
        } else {
            error_log('MISPay Error: Failed to decrypt token');
            return false;
        }
    }

    /**
     * Check if the current token is valid and not expired
     */
    private function is_token_valid()
    {
        if (empty($this->ACCESS_TOKEN) || empty($this->TOKEN_EXPIRY)) {
            return false;
        }

        // Check if token has expired (with 5 minute buffer)
        if (time() >= ($this->TOKEN_EXPIRY - 300)) {
            return false;
        }

        return true;
    }

    /**
     * Ensure we have a valid token before making API requests
     */
    private function ensure_valid_token()
    {
        $token = $this->get_access_token();
        if (!$token) {
            // Try one more time with fresh token request
            $this->ACCESS_TOKEN = null;
            $this->TOKEN_EXPIRY = null;
            $token = $this->get_access_token();
            
            if (!$token) {
                throw new Exception('Failed to obtain valid access token after retry');
            }
        }
        return $token;
    }

    /**
     * Refresh token
     */
    private function refresh_token()
    {
        error_log('MISPay: Attempting to refresh token');
            
            $token = $this->get_access_token();
            if ($token) {
                error_log('MISPay: Token refreshed successfully');
                return $token;
            }
            
        error_log('MISPay Error: Failed to refresh token');
        return false;
    }

    /**
     * Handle API response and check for token-related errors
     */
    private function handle_api_response($response, $http_code, $operation = 'API request')
    {
        if ($response === false) {
            error_log('MISPay Error: cURL request failed for ' . $operation);
            return false;
        }
        
        // Handle token-related errors
        if ($http_code === 401) {
            error_log('MISPay Error: Token expired or invalid, attempting to refresh token');
            // Clear current token and try to get a new one
            $this->ACCESS_TOKEN = null;
            $this->TOKEN_EXPIRY = null;
            
            // Try to refresh token
            $new_token = $this->refresh_token();
            if (!$new_token) {
                error_log('MISPay Error: Failed to refresh token for ' . $operation);
                return false;
            }
            
            // Return false to indicate the calling method should retry the request
            return false;
        }
        
        // Define success codes. 201 is valid for start-checkout.
        $success_codes = [200];
        if ($operation === 'start-checkout') {
            $success_codes[] = 201;
        }
        
        if (!in_array($http_code, $success_codes)) {
            error_log('MISPay Error: Invalid response code ' . $http_code . ' for ' . $operation);
            return false;
        }

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'success') {
            error_log('MISPay Error: Invalid response data for ' . $operation);
            return false;
        }

        return $data;
    }

    function start_checkout($orderId)
    {
            try {
                // Ensure we have a fresh token
                $this->ensure_valid_token();
            } catch (Exception $e) {
                error_log('MISPay Error: ' . $e->getMessage());
                    return false;
            }
            
            // Get OpenCart order object
            $this->registry->get('load')->model('checkout/order');
            $order = $this->registry->get('model_checkout_order')->getOrder($orderId);
            if (!$order) {
                error_log('MISPay Error: Invalid order ID ' . $orderId);
                return false;
            }

            $api_url = $this->API_URL . 'start-checkout';
           

            // Get order items
            $this->registry->get('load')->model('account/order');
            $order_products = $this->registry->get('model_account_order')->getOrderProducts($orderId);
            $items = array();
            foreach ($order_products as $product) {
                $items[] = array(
                    'quantity' => (string) $product['quantity'],
                    'unitPrice' => (string) $product['price'],
                    'nameEnglish' => $product['name']
                );
            }

            // Calculate components for purchaseAmount
            $totalPrice = (float) $order['total'];
            $shippingAmount = 0; // OpenCart doesn't separate shipping in the same way
            $vat = 0; // OpenCart tax handling may differ
            $discount = 0; // OpenCart discount handling may differ

            // Calculate purchaseAmount according to formula
            $purchaseAmount = $totalPrice + $shippingAmount + $vat - $discount;

            // Get customer phone from order
            $billing_phone = $order['telephone'];
            // Format phone number if needed
            $formatted_phone = preg_match('/^(966|\+966|0)?[5][0-9]{8}$/', $billing_phone) ? $billing_phone : '';
            $billing_email = $order['email'];

            $payload = [
                'orderId' => (string)$orderId,
                'totalPrice' => (string)$totalPrice,
                'shippingAmount' => (string)$shippingAmount,
                'invoiceId' => (string)$orderId, // Using orderId as invoiceId
                'vat' => (string)$vat,
                'purchaseAmount' => (string)$purchaseAmount,
                'purchaseCurrency' => $order['currency_code'],
                'discount' => [
                    'amount' => (string)$discount
                ],
                'lang' => $this->registry->get('config')->get('config_language') === 'ar' ? 'ar' : 'en',
                'version' => 'v1.2',
                'customerDetails' => [
                    'mobileNumber' => $formatted_phone,
                    'email' => $billing_email
                ],
                'orderDetails' => [
                    'items' => $items
                ]
            ];
            
            $payload = json_encode($payload);
            
           
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'x-app-id: ' . $this->APP_ID,
                'Authorization: Bearer ' . $this->ACCESS_TOKEN,
                'Content-Type: application/json',
                'accept: application/json'
            ));

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = $this->handle_api_response($response, $http_code, 'start-checkout');
        
        // If the first call fails, it might be a token issue. handle_api_response attempts a refresh.
        // We will try the call one more time with the (potentially) new token.
            if (!$data) {
            error_log('MISPay: Retrying start-checkout once after potential token refresh.');
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'x-app-id: ' . $this->APP_ID,
                'Authorization: Bearer ' . $this->ACCESS_TOKEN,
                'Content-Type: application/json',
                'accept: application/json'
            ));

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = $this->handle_api_response($response, $http_code, 'start-checkout');
        }

        if (!$data) {
                return false;
            }

            // Save trackId to order meta
            $this->registry->get('load')->model('extension/payment/mispay');
            $this->registry->get('model_extension_payment_mispay')->addOrderMeta($orderId, 'mispay_track_id', $data['result']['trackId']);

            // Log the initial transaction creation
            $this->log_transaction_status($orderId, 'created', array(
                'track_id' => $data['result']['trackId'],
                'redirect_url' => $data['result']['url'],
                'message' => 'Checkout session created successfully'
            ));

        // Clean up the URL by removing escaped slashes
        $redirect_url = str_replace('\\/', '/', $data['result']['url']);
        
        return $redirect_url;
    }

    function track_checkout($orderId)
    {
            try {
                // Ensure we have a fresh token
                $this->ensure_valid_token();
            } catch (Exception $e) {
                error_log('MISPay Error: ' . $e->getMessage());
                    return false;
            }
            
            // Get OpenCart order object
            $this->registry->get('load')->model('checkout/order');
            $order = $this->registry->get('model_checkout_order')->getOrder($orderId);
            if (!$order) {
                error_log('MISPay Error: Invalid order ID ' . $orderId);
                return false;
            }

            // Get the trackId from order meta
            $this->registry->get('load')->model('extension/payment/mispay');
            $track_id = $this->registry->get('model_extension_payment_mispay')->getOrderMeta($orderId, 'mispay_track_id');
            if (empty($track_id)) {
                error_log('MISPay Error: No track ID found for order ' . $orderId);
                return false;
            }

            $api_url = $this->API_URL . 'track-checkout/' . $track_id;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'x-app-id: ' . $this->APP_ID,
                'Authorization: Bearer ' . $this->ACCESS_TOKEN,
                'Content-Type: application/json',
                'accept: application/json'
            ));

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $data = $this->handle_api_response($response, $http_code, 'track-checkout');
        
        // If the first call fails, it might be a token issue. handle_api_response attempts a refresh.
        // We will try the call one more time with the (potentially) new token.
        if (!$data) {
            error_log('MISPay: Retrying track-checkout once after potential token refresh.');
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'x-app-id: ' . $this->APP_ID,
                'Authorization: Bearer ' . $this->ACCESS_TOKEN,
                'Content-Type: application/json',
                'accept: application/json'
            ));

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = $this->handle_api_response($response, $http_code, 'track-checkout');

            if (!$data) {
                return false;
            }
            }

            $checkout_status = $data['result']['status'];

            // Log the transaction status update
            $this->log_transaction_status($orderId, $checkout_status, $data['result']);

            // If we get a final status, return the result
            if (in_array($checkout_status, ['success', 'canceled', 'error'])) {
                // Clear the scheduled cron job if it exists
                $this->registry->get('load')->model('extension/payment/mispay');
                $this->registry->get('model_extension_payment_mispay')->clearScheduledTracking($orderId);
                return $data['result'];
            }

            // If status is still pending or in-progress, schedule next check if not already scheduled
            if (in_array($checkout_status, ['pending', 'in-progress'])) {
                $start_time = $this->registry->get('model_extension_payment_mispay')->getOrderMeta($orderId, 'mispay_tracking_start_time');
                if (empty($start_time)) {
                    $start_time = time();
                    $this->registry->get('model_extension_payment_mispay')->addOrderMeta($orderId, 'mispay_tracking_start_time', $start_time);
                }

                // Check if we've exceeded 24 hours
                if ((time() - $start_time) > (24 * 60 * 60)) {
                    $this->registry->get('model_extension_payment_mispay')->clearScheduledTracking($orderId);
                    error_log('MISPay Error: Tracking timeout exceeded 24 hours for order ' . $orderId);
                    
                    // Log timeout error
                    $this->log_transaction_status($orderId, 'timeout', array(
                        'message' => 'Tracking timeout exceeded 24 hours',
                        'track_id' => $track_id
                    ));
                    
                    return array(
                        'status' => 'error',
                        'message' => 'Tracking timeout exceeded 24 hours'
                    );
                }

                // Schedule next check in 3 minutes if not already scheduled
                if (!$this->registry->get('model_extension_payment_mispay')->isTrackingScheduled($orderId)) {
                    $this->registry->get('model_extension_payment_mispay')->scheduleTracking($orderId, time() + (3 * 60));
                }
            }

            return $data['result'];
    }

    /**
     * Log transaction status updates to order meta
     */
    private function log_transaction_status($orderId, $status, $details = array())
    {
        $log_entry = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $status,
            'message' => $this->get_status_message($status),
            'details' => $details
        );
        
        // Get existing logs
        $this->registry->get('load')->model('extension/payment/mispay');
        $existing_logs = $this->registry->get('model_extension_payment_mispay')->getOrderMeta($orderId, 'mispay_transaction_logs');
        $logs = is_array($existing_logs) ? $existing_logs : array();
        
        // Add new log entry
        $logs[] = $log_entry;
        
        // Keep only last 20 entries to prevent excessive storage
        if (count($logs) > 20) {
            $logs = array_slice($logs, -20);
        }
        
        $this->registry->get('model_extension_payment_mispay')->addOrderMeta($orderId, 'mispay_transaction_logs', $logs);
    }
    
    /**
     * Get human-readable status message
     */
    private function get_status_message($status)
    {
        $messages = array(
            'created' => 'Checkout session created',
            'callback_received' => 'Callback received from MISPay',
            'status_change_trigger' => 'Order status change triggered tracking',
            'cron_executed' => 'Cron job executed for payment tracking',
            'pending' => 'Payment is pending',
            'in-progress' => 'Payment is being processed',
            'success' => 'Payment completed successfully',
            'canceled' => 'Payment was canceled',
            'error' => 'Payment failed',
            'timeout' => 'Payment tracking timeout'
        );
        
        return isset($messages[$status]) ? $messages[$status] : 'Status: ' . $status;
    }

    function decrypt(string $token)
    {
        $input = base64_decode($token);
        if ($input === false) {
            return false;
        }
        $salt = substr($input, 0, 16);
        $nonce = substr($input, 16, 12);
        $ciphertext = substr($input, 28, -16);
        $tag = substr($input, -16);
        $key = hash_pbkdf2("sha256", $this->APP_SECRET, $salt, 40000, 32, true);
        $decryptToken = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, 1, $nonce, $tag);
        if ($decryptToken === false) {
            return false;
        }
        $jsonResponse = json_decode($decryptToken, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        return $jsonResponse;
    }
}
