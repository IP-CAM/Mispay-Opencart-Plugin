<?php
class ModelExtensionPaymentMispay extends Model {
    
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
     * Add order meta data
     */
    public function addOrderMeta($order_id, $key, $value)
    {
        $this->db->query("INSERT INTO " . DB_PREFIX . "order_meta SET order_id = '" . (int)$order_id . "', meta_key = '" . $this->db->escape($key) . "', meta_value = '" . $this->db->escape(serialize($value)) . "' ON DUPLICATE KEY UPDATE meta_value = '" . $this->db->escape(serialize($value)) . "'");
    }

    /**
     * Track checkout status
     */
    public function trackCheckout($order_id)
    {
        // Include the MisPayController
        require_once(DIR_CATALOG . 'MispayController.php');
        
        // Create MisPayController instance
        $mispay_controller = new MisPayController(
            $this->registry,
            $this->config->get('payment_mispay_test'),
            $this->config->get('payment_mispay_app_id'),
            $this->config->get('payment_mispay_app_secret')
        );
        
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
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_description_en', `value` = 'Split your purchase into 4 interest-free payments, No late fees. sharia-compliant'");
        $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'payment_mispay', `key` = 'payment_mispay_description_ar', `value` = 'قسم مشترياتك إلى 4 دفعات بدون فوائد، بدون رسوم تأخير متوافقة مع أحكام الشريعة الإسلامية'");
    }

    /**
     * Uninstall extension
     */
    public function uninstall() {
        // Remove settings
        $this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = 'payment_mispay'");
        
        // Clear any scheduled tracking
        //$this->db->query("DELETE FROM " . DB_PREFIX . "cron WHERE `code` = 'mispay_track_checkout'");
    }
} 
