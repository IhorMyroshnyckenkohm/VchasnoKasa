<?php

class ModelExtensionModuleRemberVchkasa extends Model
{

    public function saveReceiptRecord(int $order_id, int $device_id)
    {
        try {

            $this->db->query("
            INSERT INTO " . DB_PREFIX . "rember_vchkasa_orders (order_id, device_id) 
            VALUES ($order_id, $device_id)
        ");
            return ['error' => false, 'response' => ''];
        } catch (Exception $e) {
            return ['error' => true, 'response' => $e->getMessage()];
        }

    }

    public function getDeviceDataById($device_id, $needle = '*')
    {
        $result = $this->db->query('SELECT ' . $needle . ' FROM ' . DB_PREFIX . 'rember_vchkasa_devices WHERE id = ' . (int)$device_id)->row;
        if ($needle != '*') {
            return $result[$needle];
        }else{
            return $result;
        }
    }

    public function getAllDevices()
    {
        $return = [];
        try {
            $data = $this->db->query('SELECT * FROM ' . DB_PREFIX . 'rember_vchkasa_devices');
            foreach ($data->rows as $device) {
                $return[$device['name']] = $device;
            }
            return $return;
        } catch (Exception $exception) {
            die($exception->getMessage());
        }
    }

    public function getOrderReceiptById(int $order_id)
    {
        return $this->db->query('SELECT * FROM ' . DB_PREFIX . 'rember_vchkasa_orders WHERE order_id = ' . $order_id);
    }

    public function removeDevice(int $device_id): void
    {
        if ($this->session->data['device_id'] == $device_id) {
            unset($this->session->data['device_id']);
        }
        $this->db->query("DELETE FROM " . DB_PREFIX . "rember_vchkasa_devices WHERE id = " . $device_id);
    }

    public function saveOrUpdateDevice(int $device_id, string $device_name): void
    {
        $deviceData = $this->db->query("SELECT * FROM " . DB_PREFIX . "rember_vchkasa_devices WHERE id = " . $device_id);

        if (!empty($deviceData->row)) {
            $this->db->query("
                UPDATE " . DB_PREFIX . "rember_vchkasa_devices 
                SET name = '" . $this->db->escape($device_name) . "', 
                last_change = NOW() 
                WHERE id = " . $device_id
            );
        } else {
            $this->db->query("
                INSERT INTO " . DB_PREFIX . "rember_vchkasa_devices (id, name, created_at) 
                VALUES (" . $device_id . ", '" . $this->db->escape($device_name) . "', NOW())"
            );
        }
    }

    public function uninstall()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('btn_order_list');
        $this->model_setting_event->deleteEventByCode('btn_order_info');
        $this->model_setting_event->deleteEventByCode('unset_device_on_logout');
        $this->db->query("DROP TABLE IF EXISTS " . DB_PREFIX . "rember_vchkasa_devices");
        unset($this->session->data['device_id']);
    }

    public function install()
    {
        $this->load->model('setting/event');

        $this->model_setting_event->addEvent(
            'btn_order_list',
            'admin/view/sale/order_list/after',
            'extension/module/rember_vchkasa/btnCreateReceipt'
        );

        $this->model_setting_event->addEvent(
            'btn_order_info',
            'admin/view/sale/order_info/after',
            'extension/module/rember_vchkasa/btnCreateReceipt'
        );

        $this->db->query("
            CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "rember_vchkasa_devices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(56) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_change TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "rember_vchkasa_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                device_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                order_id INT NOT NULL
            )
        ");

        $this->db->query("
            INSERT INTO " . DB_PREFIX . "rember_vchkasa_devices (name) 
            VALUES ('example_device')
        ");
    }

    public function getOrderWithReceipt()
    {
        return $this->db->query('SELECT * FROM ' . DB_PREFIX . 'rember_vchkasa_orders');
    }
}
