<?php

include_once 'admin/model/sale/order.php';
include_once 'admin/model/localisation/tax_class.php';
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
    private $dbPrefix = DB_PREFIX;
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
        $this->data['error'] = true;
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
            $taxes = (new ModelLocalisationTaxClass($this->registry))->getTaxClasses();
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
                $aorder['products'] = array_map(function ($product) use ($order) {
                    $aproduct = $product;
                    $aproduct['options'] = $order->getOrderOptions($product['order_id'], $product['order_product_id']);
                    return $aproduct;
                }, $order->getOrderProducts($aorder['order_id']));
                $aorder['totals'] = $this->getOrderTotals($aorder['order_id'], $aorder['shipping_code'], $aorder['payment_country_id']);
                $aorder['coupon'] = $this->getOrderCoupon($aorder['order_id']);
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

            $sql = 'SELECT * FROM ' . DB_PREFIX . 'category c LEFT JOIN ' . DB_PREFIX .
                'category_description cd ON (c.category_id = cd.category_id) LEFT JOIN ' . DB_PREFIX .
                "category_to_store c2s ON (c.category_id = c2s.category_id) WHERE cd.language_id = '" .
                (int)$this->config->get('config_language_id') . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') ."' ";
            if (isset($_GET['status'])) {
                $sql .= " AND c.status = '". $_GET['status'] ."' ";
            }
            $sql .= " ORDER BY c.sort_order, LCASE(cd.name)";

            $query = $this->db->query($sql);
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

    private function getOrderCoupon($order_id)
    {
        $query = $this->db->query('SELECT * FROM oc_coupon_history ch
                JOIN oc_coupon c ON ch.coupon_id = c.coupon_id
                WHERE ch.order_id = '. $order_id );

        return $query->rows;
    }

    /**
     * Calculate tax rates
     * @param $key
     * @param $countryID
     * @return mixed
     */
    private function getTotalsTaxRate($key, $countryID)
    {
        $prefix = $this->dbPrefix;
        $query = $this->db->query("select {$prefix}tax_rate.* from {$prefix}tax_rate
                inner join {$prefix}setting on {$prefix}setting.`key` = '{$key}_tax_class_id'
                INNER JOIN {$prefix}tax_class on {$prefix}tax_class.tax_class_id = {$prefix}setting.value
                INNER JOIN {$prefix}zone_to_geo_zone on {$prefix}zone_to_geo_zone.country_id = {$countryID}
                left JOIN {$prefix}tax_rule on {$prefix}tax_rule.tax_class_id =  {$prefix}tax_class.tax_class_id
            where {$prefix}tax_rate.geo_zone_id = {$prefix}zone_to_geo_zone.geo_zone_id and {$prefix}tax_rule.tax_rate_id = {$prefix}tax_rate.tax_rate_id
            group by {$prefix}tax_rate.tax_rate_id");

        return $query->rows;
    }

    /**
     * Product list
     */
    public function product()
    {
        if ($this->auth()) {
            $page = isset($_GET['page']) ? $_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : self::limit;
            $start = ($page - 1) * $limit;
            $languageId = $this->config->get('config_language_id');
            $prefix = $this->dbPrefix;
            $stockCode = $_GET['sku'] ?? null;
            $queryWhere = '';
            if ($stockCode) {
                $queryWhere = " where sku ='$stockCode'";
            }
            // get products
            $query = $this->db->query(
                "SELECT (select cp.category_id
                        from {$prefix}product_to_category ptc2
                                 INNER JOIN {$prefix}category_path cp on (cp.category_id = ptc2.category_id)
                        where ptc2.product_id = p.product_id order by cp.level desc limit 1) as category_id,
                    pd.*, p.*,  m.name AS manufacturer, wcd.unit as weight_unit
                from {$prefix}product as p
                        inner join {$prefix}product_description as pd on pd.product_id = p.product_id and pd.language_id = $languageId
                        LEFT JOIN {$prefix}manufacturer m ON (p.manufacturer_id = m.manufacturer_id)
                        LEFT JOIN {$prefix}weight_class wc on (p.weight_class_id = wc.weight_class_id)
                        LEFT JOIN {$prefix}weight_class_description wcd on (wc.weight_class_id = wcd.weight_class_id)
                $queryWhere
                order by pd.name, p.model, p.price, p.quantity, p.status, p.sort_order
                limit $start, $limit"
            );
            $products = [];
            $taxes = (new ModelLocalisationTaxClass($this->registry))->getTaxClasses();
            foreach ($query->rows as $row) {
                $images = $this->db->query("SELECT * FROM {$this->dbPrefix}product_image WHERE product_id = {$row['product_id']}");
                $row['images'] = $images->rows;
                $options = $this->db->query(
                    "SELECT opv.option_id,
                       opv.option_value_id,
                       opv.product_option_value_id,
                       opv.price_prefix,
                       opv.price,
                       opv.quantity,
                       opv.subtract,
                       ovd.name as option_value_label,
                       od.name  as option_label
                    FROM {$this->dbPrefix}product_option_value as opv
                             inner join {$this->dbPrefix}option_value_description ovd on opv.option_value_id = ovd.option_value_id
                             inner join {$this->dbPrefix}option_description od on opv.option_id = od.option_id
                    WHERE opv.product_id = {$row['product_id']}
                      and ovd.language_id = {$languageId} LIMIT 50"
                );
                $row['options'] = $options->rows;
                $row['tax_rate'] = $this->getTaxRate($taxes, $row['tax_class_id']);
                $products[] = $row;
            }

            $this->setResponseData($products);
        }
    }

    private function getTaxRate($taxes, $taxClassId) {
        $taxes = array_filter($taxes, function ($tax) use ($taxClassId) {
            return $tax['tax_class_id'] == $taxClassId;
        });
        if (count($taxes) < 1) {
            return null;
        }
        $tax = reset($taxes);

        preg_match('/(\d+)%|%(\d+)/', $tax['title'], $match);

        return array_pop($match);
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

    /**
     * Order List
     */
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
            $sql .= " AND DATE(o.date_modified) >= DATE('" . $this->db->escape($data['filter_date_modified']) . "')";
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
            $sql .= ' ORDER BY o.date_modified';
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
        if(!$this->response->getOutput()) {
            $this->response->setOutput(json_encode($this->data));
        }
    }

    /**
     * Return response
     */
    public function __destruct()
    {
        $this->response();
    }

    /**
     * Order Status
     */
    public function orderStatus()
    {
        $request = json_decode(file_get_contents('php://input'), true);
        $orderId = $request['order_id'];
        $orderStatusId = $request['order_status_id'];
        (new ModelCheckoutOrder($this->registry))->addOrderHistory($orderId, $orderStatusId, '', true);
    }

    /**
     * Manufacturer List
     */
    public function manufacturer()
    {
        if ($this->auth()) {

            $sql = "SELECT * FROM " . DB_PREFIX . "manufacturer m LEFT JOIN " . DB_PREFIX . "manufacturer_to_store m2s ON (m.manufacturer_id = m2s.manufacturer_id) WHERE m2s.store_id = '" . (int)$this->config->get('config_store_id') . "' ORDER BY name ASC";

            $query = $this->db->query($sql);
            $manufacturers = $this->paginate($query->rows);
            $this->setResponseData($manufacturers);
        }
    }

    /**
     * lengthCLass List
     */
    public function lengthCLass()
    {
        if ($this->auth()) {

            $sql = "select lc.length_class_id as id, lcd.title, lcd.unit from " . DB_PREFIX . "length_class lc
                left join " . DB_PREFIX . "length_class_description lcd on lc.length_class_id = lcd.length_class_id
            Where lcd.language_id = " . (int)$this->config->get('config_language_id');

            $query = $this->db->query($sql);
            $this->setResponseData($query->rows);
        }
    }

    /**
     * weightClass List
     */
    public function weightClass()
    {
        if ($this->auth()) {

            $sql = "select wd.unit, wd.title, w.weight_class_id from " . DB_PREFIX . "weight_class w
                left join " . DB_PREFIX . "weight_class_description wd on w.weight_class_id = wd.weight_class_id
            Where wd.language_id = " . (int)$this->config->get('config_language_id');

            $query = $this->db->query($sql);
            $this->setResponseData($query->rows);
        }
    }

    /**
     * Product Create
     */
    public function createProduct()
    {
        if (!$this->auth()) {
            return $this->error('Geçersiz AccessToken.');
        }

        $data = $this->request->post;

        if(!$data['model'] || !$data['name'] || !$data['meta_title']) {
            return $this->error('Model, product title, and SEO title are required fields.');
        }
        $model              = $this->db->escape($data['model']);
        $sku                = $this->db->escape($data['sku'] ?? null);
        $quantity           = $data['quantity'] ?? null;
        $manufacturerId     = $data['manufacturer_id'] ?? null;
        $price              = $data['price'] ?? null;
        $weight             = $data['weight'] ?? null;
        $weightClassId      = $data['weight_class_id'] ?? null;
        $length             = $data['length'] ?? null;
        $width              = $data['width'] ?? null;
        $height             = $data['height'] ?? null;
        $lengthClassId      = $data['length_class_id'] ?? null;
        $status             = $data['status'] ?? null;
        $taxClassId         = $data['tax_class_id'] ?? null;
        $name               = $this->db->escape($data['name'] ?? null);
        $description        = $this->db->escape($data['description'] ?? null);
        $tag                = $this->db->escape($data['tag'] ?? null);
        $metaTitle          = $this->db->escape($data['meta_title'] ?? null);
        $metaDescription    = $this->db->escape($data['meta_description'] ?? null);
        $metaKeyword        = $this->db->escape($data['meta_keyword'] ?? null);
        $categoryId         = $this->db->escape($data['category_id'] ?? null);
        $this->db->query(
            "INSERT INTO {$this->dbPrefix}product SET 
                model = '{$model}',
                sku = ' {$sku}',
                quantity = '{$quantity}',
                manufacturer_id = '{$manufacturerId} ',
                price = '{$price}',
                weight = '{$weight}',
                weight_class_id = '{$weightClassId}',
                length = '{$length}',
                width = '{$width}',
                height = '{$height}',
                length_class_id = '{$lengthClassId}',
                status = '{$status}',
                tax_class_id = '{$taxClassId}',
                date_added = NOW(), date_modified = NOW();"
        );

        $productId = $this->db->getLastId();
        $this->db->query("INSERT INTO  {$this->dbPrefix}product_to_store SET product_id = '{$productId}',store_id = '{$this->config->get('config_store_id')}';");

        $this->db->query(
            "INSERT INTO {$this->dbPrefix}product_description SET 
                product_id = '{$productId}',
                language_id = '{$this->config->get('config_language_id')}',
                name = '{$name}',
                description = '{$description}',
                tag = '{$tag}',
                meta_title = '{$metaTitle}',
                meta_description = '{$metaDescription}',
                meta_keyword = '{$metaKeyword}';"
        );

        $this->db->query("INSERT INTO {$this->dbPrefix}product_to_category SET product_id = '{$productId}', category_id = '{$categoryId}';");

        foreach ($data['product_images'] ?? [] as $product_image) {
            if ($path = $this->imageUpload($product_image, $model)) {
                $this->db->query("INSERT INTO {$this->dbPrefix}product_image SET product_id = '{$productId}', image = '{$this->db->escape($path)}';");
            }
        }

        $this->setResponseData($this->getProductQuery($productId));
    }

    /**
     * Product Update
     */
    public function updateProduct()
    {
        if (!$this->auth()) {
            return false;
        }
        $productId = $_GET['product_id'];
        $data = $this->request->post;

        $model              = $this->db->escape($data['model']);
        $sku                = $this->db->escape($data['sku']) ?? null;
        $manufacturerId     = $data['manufacturer_id'] ?? null;
        $weight             = $data['weight'] ?? null;
        $weightClassId      = $data['weight_class_id'] ?? null;
        $length             = $data['length'] ?? null;
        $width              = $data['width'] ?? null;
        $height             = $data['height'] ?? null;
        $lengthClassId      = $data['length_class_id'] ?? null;
        $status             = $data['status'] ?? null;
        $taxClassId         = $data['tax_class_id'] ?? null;
        $name               = $this->db->escape($data['name'] ?? null);
        $description        = $this->db->escape($data['description'] ?? null);
        $tag                = $this->db->escape($data['tag'] ?? null);
        $metaTitle          = $this->db->escape($data['meta_title'] ?? null);
        $metaDescription    = $this->db->escape($data['meta_description'] ?? null);
        $metaKeyword        = $this->db->escape($data['meta_keyword'] ?? null);
        $categoryId         = $this->db->escape($data['category_id'] ?? null);

        $this->db->query(
            "UPDATE {$this->dbPrefix}product SET model = '{$this->db->escape($model)}',
                sku = '{$this->db->escape($sku)}',
                manufacturer_id = '{$manufacturerId}',
                weight = '{$weight}',
                weight_class_id = '{$weightClassId}',
                length = '{$length}',
                width = '{$width}',
                height = '{$height}',
                length_class_id = '{$lengthClassId}',
                status = '{$status}',
                tax_class_id = '{$taxClassId}',
                date_modified = NOW() WHERE product_id = '{$productId}';"
        );

        $this->db->query(
            "UPDATE {$this->dbPrefix}product_description SET 
                name = '{$this->db->escape($name)}',
                description = '{$this->db->escape($description)}',
                tag = '{$tag}',
                meta_title = '{$metaTitle}',
                meta_description = '{$metaDescription}',
                meta_keyword = '{$metaKeyword}'
            WHERE product_id = '{$productId}' AND language_id = '{$this->config->get('config_language_id')}';"
        );

        $this->db->query("DELETE FROM {$this->dbPrefix}product_to_category WHERE product_id = '{$productId}';");


        $this->db->query("INSERT INTO {$this->dbPrefix}product_to_category SET product_id = '{$productId}', category_id = '{$categoryId}';");

        $this->setResponseData($this->getProductQuery($productId));
    }

    /**
     * Image Upload
     */
    public function imageUpload()
    {
        if (!$this->auth()) {
            return false;
        }
        $data = $this->request->post;
        $url = $data['url'];
        $productId = $data['product_id'];
        $order = $data['sort_order'];

        $image = file_get_contents($url);
        if (empty($image) || !$url) {
            return null;
        }
        $filename = basename($url);

        if (!is_dir(DIR_IMAGE . 'catalog/' . $productId)) {
            mkdir(DIR_IMAGE . 'catalog/' . $productId, 0700);
        }
        file_put_contents(DIR_IMAGE . 'catalog/' . $productId . '/' . $filename, file_get_contents($url));

        $path = 'catalog/' . $productId . '/' . $filename;

        if ($order == 1) {
            $this->db->query(
                "UPDATE {$this->dbPrefix}product SET
                    image = '{$this->db->escape($path)}',
                    date_modified = NOW() WHERE product_id = '{$productId}';"
            );
        } else {
            $this->db->query(
                "INSERT INTO {$this->dbPrefix}product_image SET 
                product_id = '{$productId} ',
                image = '{$this->db->escape($path)}',
                sort_order = {$order};"
            );
        }

        $this->setResponseData(
            [
                'path' => $path,
                'product_id' => $productId
            ]
        );
    }

    public function updateProductImages()
    {
        $data = $this->request->post;
        $productId = $data['product_id'];

        $this->db->query("DELETE FROM {$this->dbPrefix}product_image WHERE product_id = '{$productId}';");
        foreach ($data['images'] ?? [] as $key => $image) {
            if ($key == 0) {
                $this->db->query(
                    "UPDATE {$this->dbPrefix}product SET
                    image = '{$this->db->escape($image['path'])}',
                    date_modified = NOW() WHERE product_id = '{$productId}';"
                );
            } else {
                $this->db->query(
                    "INSERT INTO {$this->dbPrefix}product_image SET
                    product_id = '{$productId}',
                    image = '{$this->db->escape($image['path'])}',
                    sort_order = {$image['sort_order']};");
            }
        }
    }

    public function updateStockAndPrice()
    {
        if (!$this->auth()) {
            return false;
        }

        $productId = $_GET['product_id'];
        $data = $this->request->post;
        $sql = "UPDATE  {$this->dbPrefix}product SET ";
        if (isset($data['quantity'])) {
            $sql .= "quantity = '{$data['quantity']}',";
        }
        if (isset($data['price'])) {
            $sql .= " price = '{$data['price']}',";
        }
        if (isset($data['status'])) {
            $sql .= " status = '{$data['status']}',";
        }

        $sql .= "date_modified = NOW() WHERE product_id = '{$productId}';";
        $this->db->query($sql);

        $this->setResponseData($this->getProductQuery($productId));
    }

    /**
     * Get Product
     */
    public function getProduct()
    {
        if ($this->auth()) {
            $productId = $_GET['product_id'];

            $this->setResponseData($this->getProductQuery($productId));
        }
    }

    private function getProductQuery($productId) {
        $languageId = $this->config->get('config_language_id');

        $query = $this->db->query(
            "SELECT (select cp.category_id
                        from {$this->dbPrefix}product_to_category ptc2
                                 INNER JOIN {$this->dbPrefix}category_path cp on (cp.category_id = ptc2.category_id)
                        where ptc2.product_id = p.product_id order by cp.level desc limit 1) as category_id,
                    pd.*, p.*,  m.name AS manufacturer, wcd.unit as weight_unit
                from {$this->dbPrefix}product as p
                        inner join {$this->dbPrefix}product_description as pd on pd.product_id = p.product_id and pd.language_id = $languageId
                        LEFT JOIN {$this->dbPrefix}manufacturer m ON (p.manufacturer_id = m.manufacturer_id)
                        LEFT JOIN {$this->dbPrefix}weight_class wc on (p.weight_class_id = wc.weight_class_id)
                        LEFT JOIN {$this->dbPrefix}weight_class_description wcd on (wc.weight_class_id = wcd.weight_class_id)
                where p.product_id = '" . $productId . "' 
                order by pd.name, p.model, p.price, p.quantity, p.status, p.sort_order limit 1"
        );

        $data = $query->row;
        $images = $this->db->query("SELECT * FROM {$this->dbPrefix}product_image WHERE product_id = $productId");
        $taxes = (new ModelLocalisationTaxClass($this->registry))->getTaxClasses();
        $data['tax_rate'] = $this->getTaxRate($taxes, $data['tax_class_id']);
        $data['images'] = $images->rows;
        $options = $this->db->query(
            "SELECT opv.option_id,
                       opv.option_value_id,
                       opv.product_option_value_id,
                       opv.price_prefix,
                       opv.price,
                       opv.quantity,
                       opv.subtract,
                       ovd.name as option_value_label,
                       od.name  as option_label
                    FROM {$this->dbPrefix}product_option_value as opv
                             inner join {$this->dbPrefix}option_value_description ovd on opv.option_value_id = ovd.option_value_id
                             inner join {$this->dbPrefix}option_description od on opv.option_id = od.option_id
                    WHERE opv.product_id = {$productId}
                      and ovd.language_id = {$languageId} LIMIT 50"
        );
        $data['options'] = $options->rows;

        return $data;
    }

    public function updateProductOptionPrice()
    {
        if (!$this->auth()) {
            return false;
        }
        $data = $this->request->post;

        $this->db->query(
            "UPDATE  {$this->dbPrefix}product_option_value SET 
                 price = '{$data['price']}',
                 price_prefix = '{$data['price_prefix']}'
                 WHERE product_option_value_id = '{$_GET['product_option_value_id']}';"
        );
    }

    public function updateProductOptionQuantity()
    {
        if (!$this->auth()) {
            return false;
        }
        $data = $this->request->post;

        $this->db->query(
            "UPDATE  {$this->dbPrefix}product_option_value SET 
                 quantity = '{$data['quantity']}',
                 subtract = '1'
                 WHERE product_option_value_id = '{$_GET['product_option_value_id']}';"
        );
    }

    public function currencies()
    {
        if ($this->auth()) {
            $defaultCurrencyCode = $this->config->get('config_currency');

            $query = $this->db->query(
                "SELECT currency_id, title, code, symbol_left, symbol_right, value, status 
             FROM {$this->dbPrefix}currency"
            );
            $currencies = array_map(function ($currency) use ($defaultCurrencyCode) {
                $currency['is_default'] = ($currency['code'] === $defaultCurrencyCode) ? true : false;
                return $currency;
            }, $query->rows);

            $this->setResponseData($currencies);
        }
    }

    public function setOrderInvoice() {
        if (!$this->auth()) {
            return false;
        }

        if (empty($this->request->post['order_id']) || empty($this->request->post['invoice_url'])) {
            return $this->error('Missing parameter (order_id or invoice_url) was sent.');
        }

        $this->load->model('checkout/order');

        $order_id = $this->request->post['order_id'];
        $invoice_url = $this->request->post['invoice_url'];

        // Check Order Exists
        $query = $this->db->query("SELECT comment FROM `" . DB_PREFIX . "order` WHERE order_id = '" . $order_id . "'");
        if (!$query->num_rows) {
            return $this->error('Order not found.');
        }

        $existing_comment = $query->row['comment'];

        // Remove old invoice link
        $updated_comment = preg_replace('/<a href="[^"]+" target="_blank">Faturayı indirmek için tıklayın<\/a>/', '', $existing_comment);

        // Add invoice link
        $new_comment = trim($updated_comment . ' <a href="' . $invoice_url . '" target="_blank">Faturayı indirmek için tıklayın.</a>');

        // Update comment with invoice link
        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET comment = '" . $this->db->escape($new_comment) . "' WHERE order_id = '" . (int)$order_id . "'");

        return true;
    }
}