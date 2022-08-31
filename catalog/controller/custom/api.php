<?php

include_once 'admin/model/sale/order.php';
include_once 'catalog/model/catalog/category.php';
include_once 'admin/model/localisation/tax_rate.php';
include_once 'catalog/model/account/custom_field.php';
include_once 'catalog/model/account/customer_group.php';
include_once 'admin/model/localisation/order_status.php';
include_once 'catalog/model/checkout/order.php';

class Controllercustomapi extends Controller
{

    const limit = 15;

    private $dbColumnName = 'username';

    private $data = [
        'data' => [],
        'meta' => []
    ];


    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->load->model('catalog/product');

        if ($this->startsWith(VERSION, '2.')) {
            $this->dbColumnName = 'name';
        }
    }


    /**
     * Authenticate user
     *
     * @return bool
     */
    public function auth()
    {
        if (!isset($this->request->get['token'])) {
            return $this->error();
        }

        $token = $this->request->get['token'];

        try {
            $data = $this->tryParseToken($token);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }

        $username = $data['username'];
        $key = $data['key'];

        $sqlFormat = "SELECT * FROM %sapi WHERE status = 1 and `%s`='%s' and `key`='%s'";
        $sql = sprintf($sqlFormat, DB_PREFIX, $this->dbColumnName, $username, $key);

        $query = $this->db->query($sql);

        if (isset($query->row) && isset($query->row['api_id'])) {
            return true;
        }

        return $this->error();
    }


    /**
     * @param $token
     *
     * @return bool|array
     * @throws Exception
     */
    private function tryParseToken($token)
    {
        $encodedJson = base64_decode($token);
        if (!$encodedJson) {
            throw new Exception('token can not decoded');
        }

        $decodedJson = json_decode($encodedJson, true);
        if (!isset($decodedJson['username'])) {
            throw new Exception('username not found');
        }

        if (!isset($decodedJson['key'])) {
            throw new Exception('key not found');
        }
        return $decodedJson;
    }


    /**
     * @return bool|void
     */
    public function login()
    {
        $json = [];

        if (!isset($this->request->post['username'])) {
            return $this->error('username not found');
        }

        if (!isset($this->request->post['key'])) {
            return $this->error('key not found');
        }

        $username = $this->request->post['username'];
        $key = $this->request->post['key'];

        $sqlFormat = "SELECT * FROM %sapi WHERE status = 1 and `%s`='%s' and `key`='%s'";
        $sql = sprintf($sqlFormat, DB_PREFIX, $this->dbColumnName, $username, $key);

        $query = $this->db->query($sql);

        $row = $query->row;
        if (!isset($row[$this->dbColumnName]) || !isset($row['key'])) {
            return $this->error();
        }

        $username = $row[$this->dbColumnName];
        $key = $row['key'];

        $json['success'] = $this->language->get('text_success');
        $json['token'] = base64_encode(json_encode(['username' => $username, 'key' => $key]));
        unset($this->data['data'], $this->data['meta']);

        $this->data = $json;
    }


    /**
     * @param null $msg
     *
     * @return bool
     */
    private function error($msg = null)
    {
        if (!$msg) {
            $msg = $this->language->get('error_permission');
        }

        unset($this->data['data'], $this->data['meta']);
        $this->data['message'] = $msg;

        return false;
    }

    /**
     * Customers custom fields
     */
    public function customeroption()
    {
        if ($this->auth()) {
            $data['custom_fields'] = (new ModelAccountCustomField($this->registry))->getCustomFields();
            $data['customer_groups'] = $this->getCustomerGroup();
            $data['order_statuses'] = (new ModelLocalisationOrderStatus($this->registry))->getOrderStatuses();

            $this->setResponseData($data);
        }
    }

    public function getCustomerGroup()
    {
        return (new ModelAccountCustomerGroup($this->registry))->getCustomerGroups();
    }

    /**
     * Tax list
     */
    public function tax()
    {
        if ($this->auth()) {
            $taxes = (new ModelLocalisationTaxRate($this->registry))->getTaxRates();
            $this->setResponseData($taxes);
        }
    }


    /**
     * Order List
     */
    public function order()
    {
        if ($this->auth()) {
            $order = new ModelSaleOrder($this->registry);
            $orders = $this->getOrders($_GET);
            $orders = $this->paginate($orders);
            $orders = array_map(function ($aorder) use ($order) {
                $aorder['custom_field'] = $this->decode($aorder['custom_field']);
                $aorder['payment_custom_field'] = $this->decode($aorder['payment_custom_field']);
                $aorder['customer_custom_field'] = $this->decode($aorder['customer_custom_field']);
                $aorder['products'] = $order->getOrderProducts($aorder['order_id']);
                $aorder['totals'] = $this->getOrderTotals($aorder['order_id'], $aorder['shipping_code'], $aorder['payment_country_id']);
                return $aorder;
            }, $orders);
            $this->setResponseData($orders);
        }
    }

    /**
     * Category List
     */
    public function category()
    {
        if ($this->auth()) {
            $query = $this->db->query('SELECT * FROM ' . DB_PREFIX . 'category c LEFT JOIN ' . DB_PREFIX . 'category_description cd ON (c.category_id = cd.category_id) LEFT JOIN ' . DB_PREFIX . "category_to_store c2s ON (c.category_id = c2s.category_id) WHERE cd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "'  AND c.status = '1' ORDER BY c.sort_order, LCASE(cd.name)");
            $categories = $this->paginate($query->rows);
            $this->setResponseData($categories);
        }
    }

    private function decode($string)
    {
        if ($this->json_validate($string)) {
            return json_decode($string, true);
        } else {
            return unserialize($string);
        }
    }

    function json_validate($string)
    {
        // decode the JSON data
        $result = json_decode($string);

        // switch and check possible JSON errors
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                $error = ''; // JSON is valid // No error has occurred
                break;
            case JSON_ERROR_DEPTH:
                $error = 'The maximum stack depth has been exceeded.';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $error = 'Invalid or malformed JSON.';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $error = 'Control character error, possibly incorrectly encoded.';
                break;
            case JSON_ERROR_SYNTAX:
                $error = 'Syntax error, malformed JSON.';
                break;
            // PHP >= 5.3.3
            case JSON_ERROR_UTF8:
                $error = 'Malformed UTF-8 characters, possibly incorrectly encoded.';
                break;
            // PHP >= 5.5.0
            case JSON_ERROR_RECURSION:
                $error = 'One or more recursive references in the value to be encoded.';
                break;
            // PHP >= 5.5.0
            case JSON_ERROR_INF_OR_NAN:
                $error = 'One or more NAN or INF values in the value to be encoded.';
                break;
            case JSON_ERROR_UNSUPPORTED_TYPE:
                $error = 'A value of a type that cannot be encoded was given.';
                break;
            default:
                $error = 'Unknown JSON error occured.';
                break;
        }

        if ($error !== '') {
            // throw the Exception or exit // or whatever :)
            return false;
        }

        // everything is OK
        return true;
    }

    /**
     * Ger orders details
     *
     * @param $order_id
     * @param $shippingCode
     * @param $countryID
     *
     * @return array
     */
    private function getOrderTotals($order_id, $shippingCode, $countryID)
    {
        $query = $this->db->query('SELECT * FROM ' . DB_PREFIX . "order_total
		  WHERE order_id = '" . (int)$order_id . "' ORDER BY sort_order");

        $totals = array_map(function ($row) use ($countryID, $shippingCode) {
            $code = $row['code'];
            if ($code == 'shipping')
                list($code) = explode('.', $shippingCode);

            $row['tax'] = $this->getTotalsTaxRate($code, $countryID);
            return $row;
        }, $query->rows);

        return $totals;
    }

    /**
     * Calculate tax rates
     * @param $key
     * @param $countryID
     * @return mixed
     */
    private function getTotalsTaxRate($key, $countryID)
    {
        $DBPREFIX = DB_PREFIX;
        $query = $this->db->query("select {$DBPREFIX}tax_rate.* from {$DBPREFIX}tax_rate
              inner join {$DBPREFIX}setting on {$DBPREFIX}setting.`key` = '{$key}_tax_class_id'
                INNER JOIN {$DBPREFIX}tax_class on {$DBPREFIX}tax_class.tax_class_id = {$DBPREFIX}setting.value
              INNER JOIN {$DBPREFIX}zone_to_geo_zone on {$DBPREFIX}zone_to_geo_zone.country_id = {$countryID}
              left JOIN {$DBPREFIX}tax_rule on {$DBPREFIX}tax_rule.tax_class_id =  {$DBPREFIX}tax_class.tax_class_id
            where {$DBPREFIX}tax_rate.geo_zone_id = {$DBPREFIX}zone_to_geo_zone.geo_zone_id and {$DBPREFIX}tax_rule.tax_rate_id = {$DBPREFIX}tax_rate.tax_rate_id
            group by {$DBPREFIX}tax_rate.tax_rate_id");

        return $query->rows;
    }

    /**
     * Product list
     */
    public function product()
    {
        if ($this->auth()) {
		$page = (int) $_GET['page'] ?? 1;
            $limit = (int) ($_GET['limit'] ?? self::limit);
            $start = ($page - 1) * $limit;

            $DBPREFIX = DB_PREFIX;
            $languageId = $this->config->get('config_language_id');
            // get products
            $query = $this->db->query(
                "SELECT p.product_id, p.quantity, p.price, pd.name, p.sku, p.model from {$DBPREFIX}product as p
                        inner join {$DBPREFIX}product_description as pd on pd.product_id = p.product_id and pd.language_id = $languageId
                        where p.status = 1
                        order by pd.name, p.model, p.price, p.quantity, p.status, p.sort_order
                        limit $start, $limit"
            );
            $products = $query->rows;
            $this->setResponseData($products);
        }
    }

    /**
     * Add data to response
     *
     * @param $data
     */
    private function setResponseData($data)
    {
        $this->data['data'] = $data;
    }

    /**
     * Paginate result set
     *
     * @param $results
     * @param null $page
     * @param null $limit
     *
     * @return array
     */
    private function paginate($results, $page = null, $limit = null)
    {
        if ($page == null) $page = max(@$_GET['page'], 1);
        if ($limit == null) $limit = isset($_GET['limit']) ? $_GET['limit'] : self::limit;

        $paginate['total'] = count($results);
        $paginate['current_page'] = $page;
        $paginate['per_page'] = $limit;
        $paginate['total_pages'] = ceil(count($results) / $limit);

        $this->data['meta']['pagination'] = $paginate;

        return array_slice($results, ($page - 1) * $limit, $limit);
    }

    private function getOrders($data = array())
    {

        $sql = 'SELECT o.*, (SELECT os.name FROM ' . DB_PREFIX . "order_status os WHERE os.order_status_id = o.order_status_id AND os.language_id = '" . (int)$this->config->get('config_language_id') . "') AS status, o.shipping_code, o.total, o.currency_code, o.currency_value, o.date_added, o.date_modified, (SELECT custom_field FROM " . DB_PREFIX . 'customer where customer_id = o.customer_id) as customer_custom_field FROM `' . DB_PREFIX . 'order` o';

        if (isset($data['filter_order_status'])) {
            $implode = array();

            $order_statuses = explode(',', $data['filter_order_status']);

            foreach ($order_statuses as $order_status_id) {
                $implode[] = "o.order_status_id = '" . (int)$order_status_id . "'";
            }

            if ($implode) {
                $sql .= ' WHERE (' . implode(' OR ', $implode) . ')';
            } else {

            }
        } else {
            $sql .= " WHERE o.order_status_id > '0'";
        }

        if (!empty($data['filter_order_id'])) {
            $sql .= " AND o.order_id = '" . (int)$data['filter_order_id'] . "'";
        }

        if (!empty($data['filter_customer'])) {
            $sql .= " AND CONCAT(o.firstname, ' ', o.lastname) LIKE '%" . $this->db->escape($data['filter_customer']) . "%'";
        }

        if (!empty($data['filter_date_added'])) {
            $sql .= " AND DATE(o.date_added) >= DATE('" . $this->db->escape($data['filter_date_added']) . "')";
        }

        if (!empty($data['filter_date_modified'])) {
            $sql .= " AND DATE(o.date_modified) = DATE('" . $this->db->escape($data['filter_date_modified']) . "')";
        }

        if (!empty($data['filter_total'])) {
            $sql .= " AND o.total = '" . (float)$data['filter_total'] . "'";
        }

        $sort_data = array(
            'o.order_id',
            'customer',
            'status',
            'o.date_added',
            'o.date_modified',
            'o.total'
        );

        if (isset($data['sort']) && in_array($data['sort'], $sort_data)) {
            $sql .= ' ORDER BY ' . $data['sort'];
        } else {
            $sql .= ' ORDER BY o.order_id';
        }

        if (isset($data['order']) && ($data['order'] == 'DESC')) {
            $sql .= ' DESC';
        } else {
            $sql .= ' ASC';
        }

        $query = $this->db->query($sql);

        return $query->rows;
    }


    /**
     * @param $string
     * @param $startString
     *
     * @return bool
     */
    function startsWith($string, $startString)
    {
        $len = strlen($startString);

        return (substr($string, 0, $len) === $startString);
    }


    /**
     * Echo response
     */
    private function response()
    {
        if (isset($this->request->server['HTTP_ORIGIN'])) {
            $this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
            $this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
            $this->response->addHeader('Access-Control-Max-Age: 1000');
            $this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        }

        $this->response->addHeader('X-Opencart-Version: ' . VERSION);
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($this->data));
    }

    /**
     * Return response
     */
    public function __destruct()
    {
        $this->response();
    }

    public function orderStatus()
    {
        $request = json_decode(file_get_contents('php://input'), true);
        $orderId = $request['order_id'];
        $orderStatusId = $request['order_status_id'];
        (new ModelCheckoutOrder($this->registry))->addOrderHistory($orderId, $orderStatusId);
    }
}
