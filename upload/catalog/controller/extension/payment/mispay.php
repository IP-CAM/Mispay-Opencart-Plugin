<?php
class ControllerExtensionPaymentMispay extends Controller {
    public function index() {
        $this->load->language('extension/payment/mispay');

        $data['button_confirm'] = $this->language->get('button_confirm');

        $data['text_loading'] = $this->language->get('text_loading');

        $data['continue'] = $this->url->link('checkout/success');

        return $this->load->view('extension/payment/mispay', $data);
    }

    public function confirm() {
        $json = array();

        // Debug: Log the payment method code
        error_log('MISPay Debug: Payment method code = ' . ($this->session->data['payment_method']['code'] ?? 'not set'));

        if (isset($this->session->data['payment_method']['code']) && $this->session->data['payment_method']['code'] == 'mispay') {
            $this->load->model('checkout/order');
            $this->load->model('extension/payment/mispay');

            $order_id = $this->session->data['order_id'];
            $order_info = $this->model_checkout_order->getOrder($order_id);
           

            if ($order_info) {
                $result = $this->model_extension_payment_mispay->processPayment($order_id);

                if ($result['result'] == 'success') {
                    $json['redirect'] = $result['redirect'];
                } else {
                    $json['error'] = $this->language->get('error_payment');
                }
            } else {
                $json['error'] = $this->language->get('error_order');
            }
        } else {
            $json['error'] = 'Invalid payment method: ' . ($this->session->data['payment_method']['code'] ?? 'not set');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function callback() { 
        $this->load->model('extension/payment/mispay');
        
        $result = $this->model_extension_payment_mispay->handleCallback();
       
        
        if ($result['result'] == 'success') {
            $this->response->redirect($this->url->link('checkout/success'));
        } else {
            $this->response->redirect($this->url->link('checkout/checkout'));
        }
    }

    public function webhook() {
       
        $this->load->model('extension/payment/mispay');
        
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (isset($data['orderId']) && isset($data['code'])) {
            $this->load->model('checkout/order');
            
            // Log webhook data first
            $this->model_extension_payment_mispay->addOrderMeta($data['orderId'], 'mispay_webhook_data', $data);
            
            // Get current order status to avoid duplicate processing
            $order = $this->model_checkout_order->getOrder($data['orderId']);
            if (!$order) {
                error_log('MISPay Error: Order not found in webhook: ' . $data['orderId']);
                http_response_code(404);
                $this->response->setOutput('Order not found');
                return;
            }
            
            // Check if order is already in final status to prevent duplicate processing
            $final_statuses = array(
                $this->config->get('payment_mispay_order_status_id'), // Processing
                7,//$this->config->get('config_canceled_status_id'),      // Cancelled
                10//$this->config->get('config_failed_status_id')         // Failed
            );
            
            if (in_array($order['order_status_id'], $final_statuses)) {
                error_log('MISPay Info: Order ' . $data['orderId'] . ' already in final status, skipping webhook processing');
                $this->response->setOutput('OK');
                return;
            }
            
            switch ($data['code']) {
                case 'MP00':
                    $this->model_checkout_order->addOrderHistory($data['orderId'], $this->config->get('payment_mispay_order_status_id'), 'MISPay webhook: Payment successful');
                    break;
                case 'MP02':
                    $this->model_checkout_order->addOrderHistory($data['orderId'], 7, 'MISPay webhook: Payment cancelled');
                    break;
                default:
                    $this->model_checkout_order->addOrderHistory($data['orderId'], 10, 'MISPay webhook: Payment failed or unknown status');
                    break;
            }
        } else {
            error_log('MISPay Error: Invalid webhook data received');
            http_response_code(400);
            $this->response->setOutput('Invalid data');
            return;
        }
        
        $this->response->setOutput('OK');
    }

    public function trackCheckout() {
        $this->load->model('extension/payment/mispay');
        
        $order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
        
        if ($order_id) {
            $result = $this->model_extension_payment_mispay->trackCheckout($order_id);
            
            if ($result) {
                $this->load->model('checkout/order');
                
                switch($result['status']) {
                    case 'success':
                        $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_mispay_order_status_id'), 'MISPay payment completed successfully via manual tracking');
                        break;
                    case 'canceled':
                        $this->model_checkout_order->addOrderHistory($order_id, 7, 'MISPay payment was canceled - detected via manual tracking');
                        break;
                    case 'error':
                        $this->model_checkout_order->addOrderHistory($order_id, 10, 'MISPay payment verification failed - detected via manual tracking');
                        break;
                    case 'timeout':
                        $this->model_checkout_order->addOrderHistory($order_id, 10, 'MISPay payment tracking timeout - detected via manual tracking');
                        break;
                }
                
                $json['success'] = true;
                $json['status'] = $result['status'];
            } else {
                $json['error'] = 'Failed to track payment';
            }
        } else {
            $json['error'] = 'Invalid order ID';
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function getWidget() {
        $this->load->model('extension/payment/mispay');
        $widget_html = $this->model_extension_payment_mispay->getWidget();
        $this->response->setOutput($widget_html);
    }

    public function getProductWidget() {
        $this->load->model('extension/payment/mispay');
        $product_id = isset($this->request->get['product_id']) ? (int)$this->request->get['product_id'] : 0;
        $widget_html = $this->model_extension_payment_mispay->getProductWidget($product_id);
        $this->response->setOutput($widget_html);
    }
} 