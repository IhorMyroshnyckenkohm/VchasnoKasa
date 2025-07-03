<?php

class ControllerExtensionModuleRemberVchkasa extends Controller
{
    public $response_message = '';

    public $error = false;
    private $model;

    public function index()
    {
        $this->load();

        $this->document->setTitle($this->language->get('heading_title'));

        if ($this->request->server['REQUEST_METHOD'] == 'POST' &&
            key_exists('devices', $this->request->post)) {
            $this->updateDeviceSettings($this->request->post);
        }

        $this->response->setOutput(
            $this->load->view('extension/module/rember_vchkasa', $this->translationsData())
        );
    }

    public function install()
    {
        $this->load->model('extension/module/rember_vchkasa');
        $this->model_extension_module_rember_vchkasa->install();
    }

    public function uninstall()
    {
        $this->load->model('extension/module/rember_vchkasa');
        $this->model_extension_module_rember_vchkasa->uninstall();
    }

    private function updateDeviceSettings(array $postData): void
    {
        $this->model_setting_setting->editSetting('rember_vchkasa', $postData);

        foreach ($postData['devices'] as $device_id => $device_name) {
            if (!empty($postData['remove_device'])) {
                $this->model->removeDevice((int)$postData['remove_device']);
                unset($postData['remove_device']);
                break;
            }
            if (empty($device_name)) continue;
            $this->model->saveOrUpdateDevice($device_id, $device_name);
        }
    }

    public function getOrders(): array
    {
        $orders = [];
        if (isset($this->request->post['selected'])) {
            $orders[] = $this->request->post['selected'];
        } elseif (isset($this->request->get['order_id'])) {
            $orders[0][] = $this->request->get['order_id'];
        } elseif (isset($this->session->data['orders'])) {
            $orders[] = $this->session->data['orders'];
        }

        return $orders;
    }

    public function receipt(): void
    {
        $this->load();
        $device_id = (int)$this->session->data['device_id'];
        $settings_info = $this->model->getDeviceDataById($device_id);
        $current_device_name = '';
        $throw_error = true;
        if (key_exists('name', $settings_info)){
            $current_device_name = $settings_info['name'];
            $throw_error = false;
        }
        $orders = $this->getOrders()[0];

        $data = array_merge($this->translationsData(),
            [
                'orders' => [],
                'device_name' => $current_device_name,
                'device_id' => $device_id,
                'no_orders_found' => empty($orders),
                'cannot_connect_to_device' => false,
                'devices' => $this->model->getAllDevices(),
            ]);

        $shift = $this->sendCurl($this->openShift($current_device_name));

        if($throw_error){
            $shift['errortxt'] = 'не вибрано девайс';
        }
        if (!empty($shift['errortxt']) || (!empty($shift['response']['errortxt']) && $shift['response']['res'] != 1096)) {
            $this->error = true;
            $this->response_message .= '&nbsp;' . $shift['errortxt'];
        } else {
            foreach ($orders as $order_id) {
                $data['orders'][$order_id] = $this->processOrder($order_id, $settings_info);
            }
        }

        $data['is_error'] = $this->error;
        $data['error'] = $this->response_message;

        $this->response->setOutput(
            $this->load->view('extension/module/rember_vchkasa_receipt', $data)
        );
    }

    private function openShift($device_name): array
    {
        return [
            "ver" => 6,
            "source" => "DM_API",
            "device" => $device_name,
            "type" => "1",
            "fiscal" => [
                "task" => 0,
            ]
        ];
    }

    private function processOrder(int $order_id, array $settings_info): array
    {
        $exists_record = $this->model->getOrderReceiptById($order_id);

        if (!empty($exists_record->row)) {

            $device_name = $this->model->getDeviceDataById($exists_record->row['device_id'], 'name');

            if (in_array($device_name, ['Приклад пристрою', 'example_device'])) {
                $device_name = $this->language->get('device_was_deleted');
            }

            return [
                'error' => true,
                'response' => $this->language->get('already_done_receipt_for_order'),
                'created_time' => $exists_record->row['created_at'],
                'device_name' => $device_name,
                'href' => 'index.php?route=sale/order/info&user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id,
            ];
        }

        $order_info = $this->model_sale_order->getOrder($order_id);

        if (!isset($order_info['payment_status']) || $order_info['payment_status'] !== 'P') {
            return [
                'response' => $this->language->get('status_must_be_paid'),
                'error' => true,
                'href' => 'index.php?route=sale/order/info&user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id,
            ];
        }

        $request_data = $this->prepareData($order_id, $settings_info['name'], $order_info);
        $receipt = $this->sendCurl($request_data);
        $responce = $receipt['response'];
        $receipt_error = $receipt['errortxt'];

        if (!empty($receipt_error)) {
            return [
                'response' => '&nbsp;' . $receipt_error,
                'error' => true,
                'href' => 'index.php?route=sale/order/info&user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id,
            ];
        }

        $saved = $this->model->saveReceiptRecord($order_id, $settings_info['id']);

        if ($saved['error']) {
            return [
                'response' => $saved['response'],
                'error' => $saved['response'],
                'href' => 'index.php?route=sale/order/info&user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id,
            ];
        }

        return [
            'receipt_created' => true,
            'response' => $this->language->get('success') . ' ' . $responce['info']['printinfo']['fisn'],
            'error' => false,
            'link' => 'https://kasa.vchasno.ua/check-viewer/' . $responce['info']['printinfo']['fisn'],
            'href' => 'index.php?route=sale/order/info&user_token=' . $this->session->data['user_token'] . '&order_id=' . $order_id,
        ];

    }

    private function sendCurl($request_data): array
    {
        try {
            $api_url = $this->getApiUrl();

            if ($api_url === 'url_empty') {
                return ['errortxt' => $this->language->get('url_is_empty'), 'response' => null];
            }

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                return ['errortxt' => "cURL Error: $error", 'response' => null];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
            curl_close($ch);
        } catch (Exception $e) {
            return ['errortxt' => $e->getMessage(), 'response' => null];
        }

        if ($response == false) {
            return ['errortxt' => 'помилка', 'response' => null];
        }

        $json_decode = json_decode($response, true);

        if (!empty($json_decode['errortxt']) && $json_decode['res'] != 1096) {
            return ['errortxt' => $json_decode['errortxt'], 'response' => null];
        }

        if (is_null($json_decode)) {
            return ['errortxt' => $response, 'response' => null];
        }

        return ['errortxt' => null, 'response' => $json_decode];
    }

    /*** @throws Exception */
    public function getApiUrl()
    {
        try {

            $url = $this->model_setting_setting->getSetting('rember_vchkasa');

            if (method_exists($this, 'urlValidation')) {

                return $this->urlValidation($url['rember_vchkasa_api_url']);

            } else {

                if (empty($url['rember_vchkasa_api_url'])) {
                    return 'url_empty';
                }

                return $url['rember_vchkasa_api_url'] . 'dm/execute';

            }
        } catch (Exception $exception) {

            throw new Exception($exception->getMessage());

        }
    }

    private function prepareData($order_id, $device_name, $order_info): array
    {
        $order_products = $this->model_sale_order->getOrderProducts($order_id);
        $order_totals = $this->model_sale_order->getOrderTotals($order_id);
        $flat_rate = $coupon = 0;

        $request_data = [
            "ver" => 6,
//          "source" =>
            "device" => $device_name,
//          "tag" => '',
//          "need_pf_img" => "1",
//          "need_pf_pdf" => "1",
//          "need_pf_txt" => "1",
//          "need_pf_doccmd" => "1",
            "type" => "1",
            "userinfo" => [
                "email" => $order_info['email'],
//              "phone" => "+380",
            ],
            "fiscal" => [
                "task" => 1,
//              "cashier" => "API",
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

        foreach ($order_totals as $total) {
            switch ($total['code']) {
                case 'total':
                    $request_data['fiscal']['receipt']['pays'][] = [
                        "type" => 17,
                        "sum" => $total['value'],
//                  "change" => 0.00,
//                  "comment" => "Payment comment for cash"
                    ];
                    break;
                case 'card':
                    $request_data['fiscal']['receipt']['pays'][] = [
                        "type" => 17,
                        "sum" => $total['value'],
//                  "paysys" => "VISA",
//                  "rrn" => "123",
//                   "cardmask" => "1223******1111",
//                   "term_id" => "123456888",
//                   "bank_id" => "BANK123",
//                   "auth_code" => "AA12345678",
//                   "comment" => "Payment comment for card"
                    ];
                    break;
                case 'shipping':
                    $flat_rate += $total['value'];
                    break;
                case 'coupon':
                    $coupon += $total['value'];
                    break;
            }
        }

        foreach ($order_products as $product) {
            if (isset($product['price']) && isset($product['quantity']) && isset($product['total'])) {
                $request_data['fiscal']['receipt']['rows'][] = [
                    "price" => $product['price'],
                    "name" => html_entity_decode($product['name']),
                    "cost" => $product['total'] + $flat_rate,
                    "cnt" => $product['quantity'],
                    "taxgrp" => 2,
                ];
            }
        }
        if($coupon != 0){
            $request_data['fiscal']['receipt']['rows'][] = [
                "price" => $coupon,
                "name" => 'купон',
                "cost" => $coupon,
                "cnt" => 1,
                "taxgrp" => 2,
            ];
        }

        return $request_data;
    }

    public function load()
    {
        $this->load->model('extension/module/rember_vchkasa');

        $this->model = $this->model_extension_module_rember_vchkasa;

        $this->load->language('sale/order');

        $this->load->language('extension/module/rember_vchkasa');

        $this->load->model('sale/order');

        $this->load->model('catalog/product');

        $this->load->model('setting/setting');

        $this->document->addStyle('view/stylesheet/rember_vchkasa/rember_vchkasa.css');
        $this->document->addScript('view/javascript/rember_vchkasa/rember_vchkasa.js');
    }

    /*** @throws Exception */
    public function getCurrentDeviceId()
    {
        if (key_exists('device_id', $this->session->data)) {
            return $this->session->data['device_id'];
        } else {
            return 0;
        }
    }

    /*** @throws Exception */
    public function translationsData()
    {
        $this->load->model('extension/module/rember_vchkasa');

        $this->model = $this->model_extension_module_rember_vchkasa;

        foreach (['sale/order', 'extension/module/rember_vchkasa'] as $route) {
            $this->load->language($route);
        }

        $current_url =
            (
            isset($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] === 'on' ? "https" : "http"
            )
            . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        if (isset($_SERVER['HTTP_REFERER'])) {
            if ($current_url === $_SERVER['HTTP_REFERER']) {
                $back_href = 'index.php?route=sale/order&user_token=' . $this->session->data['user_token'];
            } else {
                $back_href = $_SERVER['HTTP_REFERER'];
            }
        } else {
            $back_href = 'index.php?route=sale/order&user_token=' . $this->session->data['user_token'];
        }

        $device = $this->model->getDeviceDataById($this->getCurrentDeviceId());

        if (!empty($device)) {
            $device_name = $device['name'];
        } else {
            $device_name = $this->language->get('device_not_selected');
        }
        if (key_exists('rember_vchkasa_api_url', $this->model_setting_setting->getSetting('rember_vchkasa'))) {
            $api_url = $this->model_setting_setting->getSetting('rember_vchkasa')['rember_vchkasa_api_url'];
        } else {
            $api_url = '';
        }

        return [
            'title' => $this->language->get('text_shipping'),
            'module_rember_vchkasa_status' => $this->config->get('module_rember_vchkasa_status'),
            'tr_no_orders_found' => $this->language->get('tr_no_orders_found'),
            'tr_back' => $this->language->get('tr_back'),
            'back_href' => $back_href,
            'tr_select_devise' => $this->language->get('tr_select_devise'),
            'tr_save' => $this->language->get('tr_save'),
            'current_device_id' => $this->getCurrentDeviceId(),
            'current_device_name' => $device_name,
            'rember_vchkasa_api_url' => $api_url,
            'user_token' => $this->session->data['user_token'],
            'tr_current_device' => $this->language->get('current_device'),
            'tr_device_name' => $this->language->get('tr_device_name'),
            'tr_device_id' => $this->language->get('tr_device_id'),
            'action' => $this->url->link('extension/module/rember_vchkasa', 'user_token=' . $this->session->data['user_token'], true),
            'cancel' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true),
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
            'devices' => $this->model->getAllDevices(),
            'order_id' => $this->language->get('order_id'),
            'order_link' => $this->language->get('order_link'),
            'breadcrumbs' => [
                [
                    'text' => $this->language->get('text_home'),
                    'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
                ],
                [
                    'text' => $this->language->get('text_extension'),
                    'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
                ],
                [
                    'text' => $this->language->get('heading_title'),
                    'href' => $this->url->link('extension/module/rember_vchkasa', 'user_token=' . $this->session->data['user_token'], true)
                ]
            ],

        ];
    }

    public function btnCreateReceipt(&$route, &$data, &$output)
    {
        $this->load();

        $need_select_device = '';
        $receipt_ids = [];

        $already_created_receipt = $this->model->getOrderWithReceipt();

        if (!empty($already_created_receipt->rows)) {
            foreach ($already_created_receipt->rows as $receipt) {
                $receipt_ids[] = $receipt['order_id'];
            }
        }


        if (empty($this->session->data['device_id'])) {
            $need_select_device = 'select_device';
        }

        $button = '
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
        <link href="view/stylesheet/rember_vchkasa/rember_vchkasa.css" rel="stylesheet">
        <script>const ids = ' . json_encode($receipt_ids) . '</script>
        <script src="view/javascript/rember_vchkasa/rember_vchkasa.js"></script>
        ';

        if ($route === 'sale/order_info') {
            $receipt_url = $this->url->link('extension/module/rember_vchkasa/receipt', 'user_token=' . $this->session->data['user_token'] . '&order_id=' . (int)$data['order_id'], true);
            $button .= "
            <a 
            id='button-receipt'
            href=" . $receipt_url . "
            data-toggle='tooltip' 
            title=\"" . htmlspecialchars($this->language->get('button_create_receipt'), ENT_QUOTES, 'UTF-8') . "\"
            class='btn btn-info $need_select_device'>
            <i class='bi bi-receipt'></i>
            </a> ";
        } else {
            $button .= "
        <button 
            id='button-receipt'  
            form='form-order' 
            formaction=" . $this->url->link('extension/module/rember_vchkasa/receipt', 'user_token=' . $this->session->data['user_token'], true) . " 
            data-toggle='tooltip' 
            title=\"" . htmlspecialchars($this->language->get('button_create_receipt'), ENT_QUOTES, 'UTF-8') . "\"
            class='btn btn-info $need_select_device' disabled>
            <i class='bi bi-receipt'></i>
        </button>";

        }
        $button .= $this->selectDevice();
        $output = str_replace('<div class="pull-right">', '<div class="pull-right">' . $button, $output);
    }

    public function selectDevice(): string
    {
        $html = "
    <div class='modal fade' id='customModal'>
        <div class='modal-dialog'>
            <div class='modal-content'>
                <div class='modal-header'>
                    <h5 class='modal-title'>" . $this->language->get('tr_select_devise') . "</h5>
                    <button type='button' class='close' data-dismiss='modal' aria-label='Close'>
                        <span>&times;</span>
                    </button>
                </div>
                <div class='modal-body'>
                    <select class='form-select deviceSelect' id='deviceSelect' name='device_id'>
    ";

        foreach ($this->model_extension_module_rember_vchkasa->getAllDevices() as $device) {
            $html .= "<option value='" . htmlspecialchars($device['id'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($device['name'], ENT_QUOTES, 'UTF-8') . "</option>";
        }

        $html .= "
                    </select>
                </div>
                <div class='modal-footer'>
                    <button type='button' class='btn btn-secondary' data-dismiss='modal'>" . $this->language->get('tr_cancel') . "</button>
                    <button type='button' class='btn btn-primary' id='saveDevice'>" . $this->language->get('tr_save') . "</button>
                </div>
            </div>
        </div>
    </div>
    ";


        return $html;
    }


    public function setDevice()
    {
        $json = [];
        if ($this->request->server['REQUEST_METHOD'] == 'POST' && isset($this->request->post['device_id'])) {
            $this->session->data['device_id'] = $this->request->post['device_id'];
            $json['success'] = true;
        } else {
            $json['success'] = false;
        }

        $this->load->language('extension/module/rember_vchkasa');
        $this->session->data['success'] = $this->language->get('text_device_set_success');
        $this->response->setOutput(json_encode($json));
    }

    public function unsetDevice()
    {
        unset($this->session->data['device_id']);
    }
}
