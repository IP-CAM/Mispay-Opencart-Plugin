<?php
class ControllerExtensionPaymentMispay extends Controller {
    private $error = array();

    public function index() {
        $this->load->language('extension/payment/mispay');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_mispay', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->error['app_id'])) {
            $data['error_app_id'] = $this->error['app_id'];
        } else {
            $data['error_app_id'] = '';
        }

        if (isset($this->error['app_secret'])) {
            $data['error_app_secret'] = $this->error['app_secret'];
        } else {
            $data['error_app_secret'] = '';
        }

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/mispay', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/payment/mispay', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        // API Credentials
        if (isset($this->request->post['payment_mispay_app_id'])) {
            $data['payment_mispay_app_id'] = $this->request->post['payment_mispay_app_id'];
        } else {
            $data['payment_mispay_app_id'] = $this->config->get('payment_mispay_app_id');
        }

        if (isset($this->request->post['payment_mispay_app_secret'])) {
            $data['payment_mispay_app_secret'] = $this->request->post['payment_mispay_app_secret'];
        } else {
            $data['payment_mispay_app_secret'] = $this->config->get('payment_mispay_app_secret');
        }

        // General Settings
        if (isset($this->request->post['payment_mispay_status'])) {
            $data['payment_mispay_status'] = $this->request->post['payment_mispay_status'];
        } else {
            $data['payment_mispay_status'] = $this->config->get('payment_mispay_status');
        }

        if (isset($this->request->post['payment_mispay_test'])) {
            $data['payment_mispay_test'] = $this->request->post['payment_mispay_test'];
        } else {
            $data['payment_mispay_test'] = $this->config->get('payment_mispay_test');
        }

        if (isset($this->request->post['payment_mispay_show_icon'])) {
            $data['payment_mispay_show_icon'] = $this->request->post['payment_mispay_show_icon'];
        } else {
            $data['payment_mispay_show_icon'] = $this->config->get('payment_mispay_show_icon');
        }

        // Widget Settings
        if (isset($this->request->post['payment_mispay_enable_widget'])) {
            $data['payment_mispay_enable_widget'] = $this->request->post['payment_mispay_enable_widget'];
        } else {
            $data['payment_mispay_enable_widget'] = $this->config->get('payment_mispay_enable_widget');
        }

        if (isset($this->request->post['payment_mispay_access_key'])) {
            $data['payment_mispay_access_key'] = $this->request->post['payment_mispay_access_key'];
        } else {
            $data['payment_mispay_access_key'] = $this->config->get('payment_mispay_access_key');
        }

        // Title and Description Settings
        if (isset($this->request->post['payment_mispay_title_en'])) {
            $data['payment_mispay_title_en'] = $this->request->post['payment_mispay_title_en'];
        } else {
            $data['payment_mispay_title_en'] = $this->config->get('payment_mispay_title_en') ?: 'Buy now then pay it later with MISpay';
        }

        if (isset($this->request->post['payment_mispay_title_ar'])) {
            $data['payment_mispay_title_ar'] = $this->request->post['payment_mispay_title_ar'];
        } else {
            $data['payment_mispay_title_ar'] = $this->config->get('payment_mispay_title_ar') ?: 'اشتر الان وقسطها لاحقا مع MISpay';
        }

        if (isset($this->request->post['payment_mispay_description_en'])) {
            $data['payment_mispay_description_en'] = $this->request->post['payment_mispay_description_en'];
        } else {
            $data['payment_mispay_description_en'] = $this->config->get('payment_mispay_description_en') ?: 'Split your purchase into 3 interest-free payments, No late fees. sharia-compliant';
        }

        if (isset($this->request->post['payment_mispay_description_ar'])) {
            $data['payment_mispay_description_ar'] = $this->request->post['payment_mispay_description_ar'];
        } else {
            $data['payment_mispay_description_ar'] = $this->config->get('payment_mispay_description_ar') ?: 'قسم مشترياتك إلى 3 دفعات بدون فوائد، بدون رسوم تأخير متوافقة مع أحكام الشريعة الإسلامية';
        }

        // Order Status
        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        if (isset($this->request->post['payment_mispay_order_status_id'])) {
            $data['payment_mispay_order_status_id'] = $this->request->post['payment_mispay_order_status_id'];
        } else {
            $data['payment_mispay_order_status_id'] = $this->config->get('payment_mispay_order_status_id') ?: 2;
        }

        // Geo Zone
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        if (isset($this->request->post['payment_mispay_geo_zone_id'])) {
            $data['payment_mispay_geo_zone_id'] = $this->request->post['payment_mispay_geo_zone_id'];
        } else {
            $data['payment_mispay_geo_zone_id'] = $this->config->get('payment_mispay_geo_zone_id');
        }

        // Sort Order
        if (isset($this->request->post['payment_mispay_sort_order'])) {
            $data['payment_mispay_sort_order'] = $this->request->post['payment_mispay_sort_order'];
        } else {
            $data['payment_mispay_sort_order'] = $this->config->get('payment_mispay_sort_order');
        }

        // Total
        if (isset($this->request->post['payment_mispay_total'])) {
            $data['payment_mispay_total'] = $this->request->post['payment_mispay_total'];
        } else {
            $data['payment_mispay_total'] = $this->config->get('payment_mispay_total');
        }

        // Webhook URL
        $data['webhook_url'] = HTTPS_CATALOG . 'index.php?route=extension/payment/mispay/webhook';

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/mispay', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/mispay')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (empty($this->request->post['payment_mispay_app_id'])) {
            $this->error['app_id'] = $this->language->get('error_app_id');
        }

        if (empty($this->request->post['payment_mispay_app_secret'])) {
            $this->error['app_secret'] = $this->language->get('error_app_secret');
        }

        return !$this->error;
    }

    public function install() {
        $this->load->model('extension/payment/mispay');
        $this->model_extension_payment_mispay->install();
    }

    public function uninstall() {
        $this->load->model('extension/payment/mispay');
        $this->model_extension_payment_mispay->uninstall();
    }

    /**
     * View transaction logs for an order
     */
    public function viewLogs() {
        $this->load->language('extension/payment/mispay');
        $this->load->model('extension/payment/mispay');
        $this->load->model('sale/order');

        $order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
        
        if (!$order_id) {
            $this->response->redirect($this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'], true));
        }

        $order_info = $this->model_sale_order->getOrder($order_id);
        if (!$order_info) {
            $this->response->redirect($this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'], true));
        }

        $data['heading_title'] = $this->language->get('heading_title') . ' - Transaction Logs';
        $data['order_id'] = $order_id;
        $data['order_info'] = $order_info;
        
        // Get transaction logs
        $logs = $this->model_extension_payment_mispay->getOrderMeta($order_id, 'mispay_transaction_logs');
        $data['logs'] = is_array($logs) ? $logs : array();
        
        // Get webhook data
        $webhook_data = $this->model_extension_payment_mispay->getOrderMeta($order_id, 'mispay_webhook_data');
        $data['webhook_data'] = $webhook_data;
        
        // Get track ID
        $track_id = $this->model_extension_payment_mispay->getOrderMeta($order_id, 'mispay_track_id');
        $data['track_id'] = $track_id;

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => 'Sales',
            'href' => $this->url->link('sale/order', 'user_token=' . $this->session->data['user_token'], true)
        );
        $data['breadcrumbs'][] = array(
            'text' => 'Order #' . $order_id,
            'href' => $this->url->link('sale/order/info', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id, true)
        );
        $data['breadcrumbs'][] = array(
            'text' => 'MISPay Logs',
            'href' => $this->url->link('extension/payment/mispay/viewLogs', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id, true)
        );

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/mispay_logs', $data));
    }

    /**
     * Manual tracking for a specific order
     */
    public function trackOrder() {
        $this->load->language('extension/payment/mispay');
        $this->load->model('extension/payment/mispay');
        $this->load->model('sale/order');

        $json = array();

        if (isset($this->request->get['order_id'])) {
            $order_id = (int)$this->request->get['order_id'];
            
            $order_info = $this->model_sale_order->getOrder($order_id);
            if ($order_info) {
                $result = $this->model_extension_payment_mispay->trackCheckout($order_id);
                
                if ($result) {
                    switch($result['status']) {
                        case 'success':
                            $this->model_sale_order->addOrderHistory($order_id, $this->config->get('payment_mispay_order_status_id'), 'MISPay payment completed successfully via manual tracking');
                            break;
                        case 'canceled':
                            $this->model_sale_order->addOrderHistory($order_id, $this->config->get('config_canceled_status_id'), 'MISPay payment was canceled - detected via manual tracking');
                            break;
                        case 'error':
                            $this->model_sale_order->addOrderHistory($order_id, $this->config->get('config_failed_status_id'), 'MISPay payment verification failed - detected via manual tracking');
                            break;
                        case 'timeout':
                            $this->model_sale_order->addOrderHistory($order_id, $this->config->get('config_failed_status_id'), 'MISPay payment tracking timeout - detected via manual tracking');
                            break;
                    }
                    
                    $json['success'] = true;
                    $json['status'] = $result['status'];
                    $json['message'] = 'Payment status updated successfully';
                } else {
                    $json['error'] = 'Failed to track payment';
                }
            } else {
                $json['error'] = 'Order not found';
            }
        } else {
            $json['error'] = 'Invalid order ID';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Bulk tracking for multiple orders
     */
    public function bulkTrack() {
        $this->load->language('extension/payment/mispay');
        $this->load->model('extension/payment/mispay');
        $this->load->model('sale/order');

        $json = array();

        if (isset($this->request->post['selected']) && is_array($this->request->post['selected'])) {
            $processed = 0;
            $errors = array();
            
            foreach ($this->request->post['selected'] as $order_id) {
                $order_info = $this->model_sale_order->getOrder($order_id);
                if ($order_info && $order_info['payment_method'] === 'MISPay') {
                    $result = $this->model_extension_payment_mispay->trackCheckout($order_id);
                    if ($result) {
                        $processed++;
                    } else {
                        $errors[] = 'Order #' . $order_id . ': Failed to track';
                    }
                }
            }
            
            $json['success'] = true;
            $json['processed'] = $processed;
            $json['errors'] = $errors;
            $json['message'] = 'Processed ' . $processed . ' orders successfully';
        } else {
            $json['error'] = 'No orders selected';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
} 