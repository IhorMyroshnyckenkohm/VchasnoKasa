<?php
#todo потрібно додати кнопку щоб завжди мати змогу поміняти девайс та зробити всі сторінки в одному стилі і додати переклади, перейменувати всі custom_module
#todo і додати переклади
class ControllerExtensionModuleCustomModule extends Controller
{
    public function index()
    {
        unset($this->session->data['redirect_setting']);
        $this->load->language('extension/module/custom_module');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') /*&& $this->validate()*/) {
            $this->model_setting_setting->editSetting('custom_module', $this->request->post);
            try {
                foreach ($this->request->post['devices'] as $device_id => $device_name) {
                    if (!empty($this->request->post['remove_device'])) {
                        $this->db->query(
                            'DELETE FROM ' . DB_PREFIX . 'custom_module_devices WHERE id = ' . (int)$this->request->post['remove_device']);
                        unset($this->request->post['remove_device']);
                        break;
                    }
                    if (empty($device_name)) continue;
                    $data = $this->db->query('SELECT * FROM ' . DB_PREFIX . 'custom_module_devices WHERE id = ' . $device_id);
                    if (!empty($data->row) && (int)$device_id === (int)$data->row['id']) {
                        $this->db->query(
                            'UPDATE ' . DB_PREFIX . 'custom_module_devices 
                                SET 
                                name = "' . $this->db->escape($device_name) . '", 
                                last_change = "' . date("Y-m-d H:i:s") . '" 
                                WHERE id = ' . (int)$device_id
                        );
                    } else {
                        $this->db->query(
                            'INSERT INTO ' . DB_PREFIX . 'custom_module_devices (id, name, created_at) 
                                 VALUES (' . $device_id . ', "' . $device_name . '", "' . date("Y-m-d H:i:s") . '")'
                        );
                    }
                }
            } catch (Exception $e) {
                echo $e->getMessage();
            };
        }

        $this->response->setOutput($this->load->view('extension/module/custom_module', $this->translations_data()));
    }

    public function install()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->addEvent(
            'btn_order_list',
            'admin/view/sale/order_list/after',
            'extension/module/custom_module/btn_create_receipt'
        );
        $this->model_setting_event->addEvent(
            'btn_order_info',
            'admin/view/sale/order_info/after',
            'extension/module/custom_module/btn_create_receipt'
        );

        $this->load->language('extension/module/custom_module');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "custom_module_devices (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(56) NOT NULL UNIQUE,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `last_change` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "custom_module_orders (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `device_id` VARCHAR(56) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `order_id` INT(11) NOT NULL
        )"
        );

        $this->db->query(
            'INSERT INTO ' . DB_PREFIX . 'custom_module_devices (id, name) 
            VALUES (1, "' . $this->db->escape($this->language->get('example_device')) . '")'
        );
    }

    public function uninstall()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode('btn_order_list');
        $this->model_setting_event->deleteEventByCode('btn_order_info');
        $this->db->query("DROP TABLE IF EXISTS " . DB_PREFIX . "custom_module_devices");
//        $this->db->query("DROP TABLE IF EXISTS " . DB_PREFIX . "custom_module_orders");
//        don`t delete because when we install again we can create duplicate orders receipt
    }

    public function btn_create_receipt(&$route, &$data, &$output)
    {
        $this->load->language('extension/module/custom_module');
        $button = '
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<script>
    $(document).ready(function() {
        $(\'input[name^="selected"]\').on(\'change\', function () {
            var selected = $(\'input[name^="selected"]:checked\');

            if (selected.length) {
                $(\'#button-receipt\').prop(\'disabled\', false);
            } else {
                $(\'#button-receipt\').prop(\'disabled\', true);
            }
        });
    });
</script>';

        if ($route === 'sale/order_info') {
            $receipt_url = $this->url->link('extension/module/custom_module/receipt', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$data['order_id'], true);
            $button .= "
            <a 
            href=" . $receipt_url . "
            data-toggle='tooltip' 
            title=\"" . htmlspecialchars($this->language->get('button_create_receipt'), ENT_QUOTES, 'UTF-8') . "\"
            class='btn btn-info'>
            <i class='bi bi-receipt'></i>
            </a> ";
        } else {
            $button .= "
        <button 
            type='submit' 
            id='button-receipt'  
            form='form-order' 
            formaction=" . $this->url->link('extension/module/custom_module/receipt', 'user_token=' . $this->session->data['user_token'], true) . " 
            data-toggle='tooltip' 
            title=\"" . htmlspecialchars($this->language->get('button_create_receipt'), ENT_QUOTES, 'UTF-8') . "\"
            class='btn btn-info' disabled>
            <i class='bi bi-receipt'></i>
        </button>";

        }
        $output = str_replace('<div class="pull-right">', '<div class="pull-right">' . $button, $output);
    }

    public function receipt()
    {
        $orders = $data['orders'] = array();
        if (isset($this->request->post['selected'])) {
            $orders[] = $this->request->post['selected'];
        } elseif (isset($this->request->get['order_id'])) {
            $orders[] = $this->request->get['order_id'];
        } elseif (isset($this->session->data['orders'])) {
            $orders = $this->session->data['orders'];
        }


        if (empty($this->session->data['device_id'])) {
            $this->session->data['orders'] = $orders;
            $this->select_device();
        } else {
            $this->load->model('setting/setting');
            $this->load();
            $device_id = (int)$this->session->data['device_id'];
            $settings_info = $this->get_device_by_id($device_id);
            $data['device_name'] = $settings_info['name'];
            $data['device_id'] = $device_id;
            $api_url = $this->get_url() . 'dm/execute';
            $data['title'] = $this->language->get('text_shipping');

            foreach ((array)$orders[0] as $order_id) {
                $order_exist = $this->db->query('SELECT * FROM ' . DB_PREFIX . 'custom_module_orders WHERE order_id = ' . $order_id);
                $data['orders'][$order_id]['url'] = 'index.php?route=sale/order/info&user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id;
                $data['orders'][$order_id]['created_time'] = $order_exist->row['created_at'];
                $data['orders'][$order_id]['device_name'] = $this->get_device_by_id($order_exist->row['device_id'])['name'];

                if (!empty($order_exist->row)) {
                    $data['orders'][$order_id]['response'] = $this->language->get('already_done_receipt_for_order');
                    continue;
                }

                $order_info = $this->model_sale_order->getOrder($order_id);
                $order_products = $this->model_sale_order->getOrderProducts($order_id);
                $order_totals = $this->model_sale_order->getOrderTotals($order_id);
                $open_shift = [
                    "ver" => 6,
                    "source" => "DM_API",
                    "device" => $settings_info['name'],
                    "type" => "1",
                    "fiscal" => [
                        "task" => 0,
                    ]
                ];
                try {
                    $ch = curl_init($api_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($open_shift));

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                } catch (Exception $e) {
                    echo $e->getMessage();
                }

                $response = json_decode($response, true);
                if ($response['res'] == 0 || $response['res'] == 1096) {
                    $data = [
                        "ver" => 6,
//                    "source" => "DM_API",
                        "device" => $settings_info['name'],
//                    "need_pf_img" => "1",
//                    "need_pf_pdf" => "1",
//                    "need_pf_txt" => "1",
//                    "need_pf_doccmd" => "1",
                        "type" => "1",
                        /*"userinfo" => [
                            "email" => '',
                            "phone" => '',
                        ],*/
                        "fiscal" => [
                            "task" => 1,
//                        "cashier" => "API",
                            "receipt" => [
                                "sum" => $order_info['total'],
                                "round" => 0.00,
                                "comment_up" => '',
                                "comment_down" => '',
                                "rows" => [],
                                "pays" => []
                            ]
                        ]
                    ];
                    $flat_rate = 0;
                    foreach ($order_totals as $total) {
                        if ($total['code'] === 'total') {
                            $data['fiscal']['receipt']['pays'][] = [
//                            "type" => 0,
                                "sum" => $total['value'],
//                            "change" => 0.00,
//                            "comment" => "Payment comment for cash"
                            ];
                        } elseif ($total['code'] === 'card') {
                            $data['fiscal']['receipt']['pays'][] = [
//                            "type" => 2,
                                "sum" => $total['value'],
//                            "paysys" => "VISA",
//                            "rrn" => "123",
//                            "cardmask" => "1223******1111",
//                            "term_id" => "123456888",
//                            "bank_id" => "BANK123",
//                            "auth_code" => "AA12345678",
//                            "comment" => "Payment comment for card"
                            ];
                        } elseif ($total['code'] === 'shipping') {
                            $flat_rate += $total['value'];
                        }
                    }

                    foreach ($order_products as $product) {
                        if (isset($product['price']) && isset($product['quantity']) && isset($product['total'])) {
                            $data['fiscal']['receipt']['rows'][] = [
                                "price" => $product['price'],
                                "name" => $product['name'],
                                "cost" => $product['total'] + $flat_rate,
                                "taxgrp" => 2,
                            ];
                        } else {
                            var_dump("Помилка: Немає ціни або кількості для товару з ID " . $product['product_id']);
                            die();
                        }
                    }

                    try {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                    } catch (Exception $e) {
                        echo $e->getMessage();
                    }

                    $response = json_decode($response, true);

                    if (isset($response['info']['printinfo']['fisn'])) {
                        $this->db->query(
                            'INSERT INTO ' . DB_PREFIX . 'custom_module_orders (order_id, device_id) 
                         VALUES (' . $order_id . ', ' . $settings_info['id'] . ')'
                        );
                        $data['orders'][$order_id]['response'] = $this->language->get('success') . ' ' . $response['info']['printinfo']['fisn'] . ' ' . $order_id . '<br>';
                        $data['orders'][$order_id]['receipt_created'] = $this->language->get('success') . ' ' . $response['info']['printinfo']['fisn'] . ' ' . $order_id . '<br>';
                    } else {
                        echo 'httpCode : ' . $httpCode . ' <br> ' . $this->language->get('responce') . ' : ' . (empty($response['errortxt']) ? $this->language->get('select_a_product') : $response['errortxt']);
                    }
                } else {
                    $data['orders'][$order_id]['response'] = $response['errortxt'];
                }

            }

            $this->response->setOutput($this->load->view('extension/module/custom_module_receipt', array_merge($this->translations_data(), $data)));
        }
    }


    public
    function translations_data()
    {
        $data['tr_back'] = $this->language->get('tr_back');;
        $data['back_href'] = $_SERVER['HTTP_REFERER'];
        $data['select_device_href'] = $this->url->link('extension/module/custom_module/save_redirect', 'user_token=' . $this->session->data['user_token'], true);
        $data['tr_select_devise'] = $this->language->get('tr_select_devise');
        $data['tr_save'] = $this->language->get('tr_save');
        $data['devices'] = $this->get_all_devices();
        $data['current_device_id'] = $this->get_current_device_id();
        if (is_array($this->get_device_by_id($data['current_device_id']))){
            $device_name = $this->get_device_by_id($data['current_device_id'])['name'];
        }else{
            $device_name = $this->language->get('device_not_selected');
        }
        $data['current_device_name'] = $device_name;
        $data['custom_module_api_url'] = $this->model_setting_setting->getSetting('custom_module')['custom_module_api_url'];
        $data['breadcrumbs'] = array();
        $data['user_token'] = $this->session->data['user_token'];
        $data['by'] = $this->language->get('by');
        $data['tr_current_device'] = $this->language->get('current_device');
        $data['tr_device_name'] = $this->language->get('tr_device_name');
        $data['tr_device_id'] = $this->language->get('tr_device_id');

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/custom_module', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/module/custom_module', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        if (isset($this->request->post['module_custom_module_status'])) {
            $data['module_custom_module_status'] = $this->request->post['module_custom_module_status'];
        } else {
            $data['module_custom_module_status'] = $this->config->get('module_custom_module_status');
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        return $data;
    }

    public
    function load()
    {
        $this->load->language('sale/order');

        $this->load->language('extension/module/custom_module');

        $this->load->model('sale/order');

        $this->load->model('catalog/product');

        $this->load->model('setting/setting');
    }

    public
    function get_url()
    {
        try {
            $url = $this->model_setting_setting->getSetting('custom_module');
            return $url['custom_module_api_url'];
        } catch (Exception $exception) {
            return 'http://localhost:3939/';
        }
    }

    public
    function get_all_devices()
    {
        $return = [];
        try {
            $data = $this->db->query('SELECT * FROM ' . DB_PREFIX . 'custom_module_devices');
            foreach ($data->rows as $device) {
                $return[$device['name']] = $device;
            }
            return $return;
        } catch (Exception $exception) {
            die($exception->getMessage());
        }
    }

    public
    function get_device_by_id($device_id)
    {
        return $this->db->query('SELECT * FROM ' . DB_PREFIX . 'custom_module_devices WHERE id = ' . (int)$device_id)->row;
    }
    public
    function get_current_device_id()
    {
        if (key_exists('device_id', $this->session->data)){
            return $this->session->data['device_id'];
        }else{
            return $this->language->get('device_not_selected');
        }
    }

    public function device_save()
    {
        if ($this->request->server['REQUEST_METHOD'] === 'POST') {
            if (!empty($this->request->post['device_id'])) {
                $this->session->data['device_id'] = $this->request->post['device_id'];
                if ($this->session->data['redirect_setting']){
                    $this->response->redirect($this->url->link('extension/module/custom_module', 'user_token=' . $this->session->data['user_token'], true));
                    unset($this->session->data['redirect_setting']);
                }
                $this->response->redirect($this->url->link('extension/module/custom_module/receipt', 'user_token=' . $this->session->data['user_token'], true));
            } else {
                $this->response->addHeader('HTTP/1.1 400 Bad Request');
                $this->response->setOutput(json_encode(['error' => 'Device ID is missing']));
            }
        }
    }

    public function save_redirect(){
        $this->session->data['redirect_setting'] = true;
        $this->select_device();
    }
    public function select_device()
    {
        $this->load->model('setting/setting');
        $this->load();
        $this->response->setOutput($this->load->view('extension/module/custom_module_select_device', ($this->translations_data())));
    }

}
