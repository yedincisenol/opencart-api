<?php
namespace Opencart\Catalog\Controller\Custom;

use Exception;
use Opencart\System\Engine\Controller;

class Api extends Controller
{
    const LIMIT = 15;

    private $dbColumnName = 'username';

    private $data = [
        'data' => [],
        'meta' => []
    ];
    private $dbPrefix;

    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->dbPrefix = DB_PREFIX;
    }

    public function __destruct()
    {
        $this->response();
    }

    /**
     * Authenticate user
     *
     * @return bool
     */
    public function auth(): bool
    {
        if (!isset($this->request->get['token'])) {
            return $this->error();
        }

        $token = (string) $this->request->get['token'];

        try {
            $data = $this->tryParseToken($token);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }

        $username = $data['username'];
        $key = $data['key'];

        $sqlFormat = "SELECT * 
                      FROM %sapi 
                      WHERE status = 1 
                        AND `%s` = '%s' 
                        AND `key` = '%s'";
        $sql = sprintf($sqlFormat, $this->dbPrefix, $this->dbColumnName, $this->db->escape($username), $this->db->escape($key));

        $query = $this->db->query($sql);

        if (isset($query->row) && isset($query->row['api_id'])) {
            return true;
        }

        return $this->error();
    }

    /**
     * @param string $token
     *
     * @return array
     * @throws Exception
     */
    private function tryParseToken(string $token): array
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
     * Login
     */
    public function login(): void
    {
        $json = [];

        if (!isset($this->request->post['username'])) {
            $this->error('username not found');
            return;
        }

        if (!isset($this->request->post['key'])) {
            $this->error('key not found');
            return;
        }

        $username = $this->request->post['username'];
        $key = $this->request->post['key'];

        $sqlFormat = "SELECT * 
                      FROM %sapi 
                      WHERE status = 1 
                        AND `%s` = '%s' 
                        AND `key` = '%s'";
        $sql = sprintf($sqlFormat, $this->dbPrefix, $this->dbColumnName, $this->db->escape($username), $this->db->escape($key));

        $query = $this->db->query($sql);

        $row = $query->row;
        if (!isset($row[$this->dbColumnName]) || !isset($row['key'])) {
            $this->error();
            return;
        }

        $username = $row[$this->dbColumnName];
        $key = $row['key'];

        $json['success'] = $this->language->get('text_success');
        $json['token'] = base64_encode(json_encode(['username' => $username, 'key' => $key]));
        unset($this->data['data'], $this->data['meta']);

        $this->data = $json;
        $this->response();
    }

    /**
     * @param string|null $msg
     *
     * @return bool
     */
    private function error(?string $msg = null): bool
    {
        if (!$msg) {
            $msg = $this->language->get('error_permission');
        }

        unset($this->data['data'], $this->data['meta']);
        $this->data['error'] = true;
        $this->data['message'] = $msg;

        return false;
    }

    private function response(?array $data = null): void
    {
        if (isset($this->request->server['HTTP_ORIGIN'])) {
            $this->response->addHeader('Access-Control-Allow-Origin: ' . $this->request->server['HTTP_ORIGIN']);
            $this->response->addHeader('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
            $this->response->addHeader('Access-Control-Max-Age: 1000');
            $this->response->addHeader('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        }

        if ($data !== null) {
            $this->data['data'] = $data;
        }

        $this->response->addHeader('X-Opencart-Version: ' . VERSION);
        $this->response->addHeader('Content-Type: application/json');

        $this->response->setOutput(json_encode($this->data));
    }



    private function paginate(array $results, ?int $page = null, ?int $limit = null): array
    {
        if ($page == null) {
            $page = max($this->request->get['page'] ?? 1, 1);
        }
        if ($limit == null) {
            $limit = isset($this->request->get['limit']) ? $this->request->get['limit'] : self::LIMIT;
        }

        $page = (int) $page;
        $limit = (int) $limit;

        $paginate['total'] = count($results);
        $paginate['current_page'] = $page;
        $paginate['per_page'] = $limit;
        $paginate['total_pages'] = ceil(count($results) / $limit);

        $this->data['meta']['pagination'] = $paginate;

        return array_slice($results, ($page - 1) * $limit, $limit);
    }

    /**
     * Helper to build SET part of SQL update
     */
    private function buildUpdateSet(array $data, array $schema): string
    {
        $updates = [];
        foreach ($schema as $field => $type) {
            if (isset($data[$field])) {
                $value = $data[$field];
                switch ($type) {
                    case 'int':
                        $cleanValue = (int) $value;
                        break;
                    case 'float':
                        $cleanValue = (float) $value;
                        break;
                    case 'escape':
                    default:
                        $cleanValue = $this->db->escape((string) $value);
                        break;
                }
                $updates[] = "`{$field}` = '{$cleanValue}'";
            }
        }
        return implode(', ', $updates);
    }

    /**
     * Helper to load admin models from catalog side
     */
    private function loadAdminModel(string $route): ?object
    {
        $file = DIR_OPENCART . 'admin/model/' . $route . '.php';
        if (file_exists($file)) {
            include_once($file);

            $parts = explode('/', $route);
            $class = 'Opencart\\Admin\\Model';
            foreach ($parts as $part) {
                $class .= '\\' . ucfirst(preg_replace('/[^a-zA-Z0-9]/', '', $part));
            }
            if (class_exists($class)) {
                return new $class($this->registry);
            }
        }
        return null;
    }

    private function decode(string $string): mixed
    {
        if ($this->jsonValidate($string)) {
            return json_decode($string, true);
        } else {
            return unserialize($string);
        }
    }

    private function jsonValidate(mixed $string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);

        return (json_last_error() === JSON_ERROR_NONE);
    }

    // =========================================================================
    // REFERENCE DATA METHODS
    // =========================================================================

    /**
     * Category List
     */
    public function category(): void
    {
        if ($this->auth()) {
            $languageId = (int) $this->config->get('config_language_id');
            $storeId = (int) $this->config->get('config_store_id');
            $sql = "SELECT * 
                    FROM {$this->dbPrefix}category c 
                    LEFT JOIN {$this->dbPrefix}category_description cd ON (c.category_id = cd.category_id) 
                    LEFT JOIN {$this->dbPrefix}category_to_store c2s ON (c.category_id = c2s.category_id) 
                    WHERE cd.language_id = '{$languageId}' 
                      AND c2s.store_id = '{$storeId}' ";

            if (isset($this->request->get['status'])) {
                $sql .= " AND c.status = '" . $this->request->get['status'] . "' ";
            }
            $sql .= " ORDER BY c.sort_order, LCASE(cd.name)";

            $query = $this->db->query($sql);
            $categories = $this->paginate($query->rows);
            $this->response($categories);
        }
    }

    public function manufacturer(): void
    {
        if ($this->auth()) {
            $storeId = (int) $this->config->get('config_store_id');
            $sql = "SELECT * 
                    FROM {$this->dbPrefix}manufacturer m 
                    LEFT JOIN {$this->dbPrefix}manufacturer_to_store m2s ON (m.manufacturer_id = m2s.manufacturer_id) 
                    WHERE m2s.store_id = '{$storeId}' 
                    ORDER BY name ASC";

            $query = $this->db->query($sql);
            $manufacturers = $this->paginate($query->rows);
            $this->response($manufacturers);
        }
    }

    /**
     * Tax list
     */
    public function tax(): void
    {
        if ($this->auth()) {
            $model = $this->loadAdminModel('localisation/tax_class');
            if ($model) {
                $taxes = $model->getTaxClasses();
                $this->response($taxes);
                return;
            }
            $this->response();
        }
    }

    public function lengthClass(): void
    {
        if ($this->auth()) {
            $languageId = (int) $this->config->get('config_language_id');
            $sql = "SELECT lc.length_class_id as id, lcd.title, lcd.unit 
                    FROM {$this->dbPrefix}length_class lc
                    LEFT JOIN {$this->dbPrefix}length_class_description lcd ON lc.length_class_id = lcd.length_class_id
                    WHERE lcd.language_id = {$languageId}";
            $query = $this->db->query($sql);
            $this->response($query->rows);
        }
    }

    public function weightClass(): void
    {
        if ($this->auth()) {
            $languageId = (int) $this->config->get('config_language_id');
            $sql = "SELECT wd.unit, wd.title, w.weight_class_id 
                    FROM {$this->dbPrefix}weight_class w
                    LEFT JOIN {$this->dbPrefix}weight_class_description wd ON w.weight_class_id = wd.weight_class_id
                    WHERE wd.language_id = {$languageId}";

            $query = $this->db->query($sql);
            $this->response($query->rows);
        }
    }

    public function currencies(): void
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

            $this->response($currencies);
        }
    }

    /**
     * Customers custom fields
     */
    public function customeroption(): void
    {
        if ($this->auth()) {
            $this->load->model('account/custom_field');
            $this->load->model('localisation/order_status');
            $data['custom_fields'] = $this->model_account_custom_field->getCustomFields();
            $data['customer_groups'] = $this->getCustomerGroup();
            $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();


            $this->response($data);
        }
    }

    /**
     * Get Customer Group
     *
     * @return array
     */
    public function getCustomerGroup(): array
    {
        $this->load->model('account/customer_group');
        return $this->model_account_customer_group->getCustomerGroups();
    }

    // =========================================================================
    // PRODUCT METHODS
    // =========================================================================

    /**
     * Product List
     */
    public function product(): void
    {
        if ($this->auth()) {
            $languageId = (int) $this->config->get('config_language_id');
            $stockCode = $this->request->get['sku'] ?? null;
            $queryWhere = '';
            if ($stockCode) {
                $escapedSku = $this->db->escape($stockCode);
                $queryWhere = " where sku ='{$escapedSku}'";
            }

            $query = $this->db->query(
                "SELECT 
                    (SELECT cp.category_id
                     FROM {$this->dbPrefix}product_to_category ptc2
                     INNER JOIN {$this->dbPrefix}category_path cp ON (cp.category_id = ptc2.category_id)
                     WHERE ptc2.product_id = p.product_id 
                     ORDER BY cp.level DESC LIMIT 1) as category_id,
                    pd.*, p.*, m.name AS manufacturer, wcd.unit as weight_unit
                FROM {$this->dbPrefix}product as p
                INNER JOIN {$this->dbPrefix}product_description as pd ON pd.product_id = p.product_id AND pd.language_id = '{$languageId}'
                LEFT JOIN {$this->dbPrefix}manufacturer m ON (p.manufacturer_id = m.manufacturer_id)
                LEFT JOIN {$this->dbPrefix}weight_class wc ON (p.weight_class_id = wc.weight_class_id)
                LEFT JOIN {$this->dbPrefix}weight_class_description wcd ON (wc.weight_class_id = wcd.weight_class_id)
                {$queryWhere}
                ORDER BY pd.name, p.model, p.price, p.quantity, p.status, p.sort_order"
            );

            $productRows = $this->paginate($query->rows);
            $products = [];
            $modelTax = $this->loadAdminModel('localisation/tax_class');
            $taxes = $modelTax ? $modelTax->getTaxClasses() : [];
            foreach ($productRows as $row) {
                // Images
                $productId = (int) $row['product_id'];
                $images = $this->db->query("SELECT * FROM {$this->dbPrefix}product_image WHERE product_id = '{$productId}'");
                $row['images'] = $images->rows;

                // Options
                $options = $this->db->query(
                    "SELECT 
                       opv.option_id,
                       opv.option_value_id,
                       opv.product_option_value_id,
                       opv.price_prefix,
                       opv.price,
                       opv.quantity,
                       opv.subtract,
                       ovd.name as option_value_label,
                       od.name as option_label
                    FROM {$this->dbPrefix}product_option_value as opv
                    INNER JOIN {$this->dbPrefix}option_value_description ovd ON opv.option_value_id = ovd.option_value_id
                    INNER JOIN {$this->dbPrefix}option_description od ON opv.option_id = od.option_id
                    WHERE opv.product_id = '{$productId}'
                      AND ovd.language_id = '{$languageId}' 
                    LIMIT 50"
                );
                $row['options'] = $options->rows;
                $row['tax_rate'] = $this->getTaxRate($taxes, $row['tax_class_id']);
                $products[] = $row;
            }

            $this->response($products);
        }
    }

    public function getProduct(): void
    {
        if ($this->auth()) {
            $productId = (int) ($this->request->get['product_id'] ?? 0);
            $this->response($this->getProductQuery($productId));
        }
    }

    private function getProductQuery(int $productId): array
    {
        $languageId = (int) $this->config->get('config_language_id');

        $query = $this->db->query(
            "SELECT 
                (SELECT cp.category_id
                 FROM {$this->dbPrefix}product_to_category ptc2
                 INNER JOIN {$this->dbPrefix}category_path cp ON (cp.category_id = ptc2.category_id)
                 WHERE ptc2.product_id = p.product_id 
                 ORDER BY cp.level DESC LIMIT 1) as category_id,
                pd.*, p.*, m.name AS manufacturer, wcd.unit as weight_unit
            FROM {$this->dbPrefix}product as p
            INNER JOIN {$this->dbPrefix}product_description as pd ON pd.product_id = p.product_id AND pd.language_id = '{$languageId}'
            LEFT JOIN {$this->dbPrefix}manufacturer m ON (p.manufacturer_id = m.manufacturer_id)
            LEFT JOIN {$this->dbPrefix}weight_class wc ON (p.weight_class_id = wc.weight_class_id)
            LEFT JOIN {$this->dbPrefix}weight_class_description wcd ON (wc.weight_class_id = wcd.weight_class_id)
            WHERE p.product_id = '{$productId}' 
            ORDER BY pd.name, p.model, p.price, p.quantity, p.status, p.sort_order LIMIT 1"
        );

        $data = $query->row;
        if (!$data) {
            return [];
        }

        $images = $this->db->query("SELECT * FROM " . $this->dbPrefix . "product_image WHERE product_id = '" . (int) $productId . "'");

        $modelTax = $this->loadAdminModel('localisation/tax_class');
        $taxes = $modelTax ? $modelTax->getTaxClasses() : [];

        $data['tax_rate'] = $this->getTaxRate($taxes, $data['tax_class_id']);
        $data['images'] = $images->rows;

        $options = $this->db->query(
            "SELECT 
               opv.option_id,
               opv.option_value_id,
               opv.product_option_value_id,
               opv.price_prefix,
               opv.price,
               opv.quantity,
               opv.subtract,
               ovd.name as option_value_label,
               od.name as option_label
            FROM {$this->dbPrefix}product_option_value as opv
            INNER JOIN {$this->dbPrefix}option_value_description ovd ON opv.option_value_id = ovd.option_value_id
            INNER JOIN {$this->dbPrefix}option_description od ON opv.option_id = od.option_id
            WHERE opv.product_id = '" . (int) $productId . "'
              AND ovd.language_id = {$languageId} 
            LIMIT 50"
        );
        $data['options'] = $options->rows;

        return $data;
    }

    private function getTaxRate(array $taxes, int $taxClassId): ?string
    {
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

    public function createProduct(): void
    {
        if (!$this->auth()) {
            $this->error('Geçersiz AccessToken.');
            return;
        }

        $data = $this->request->post;

        if (empty($data['model']) || empty($data['name']) || empty($data['meta_title'])) {
            $this->error('Model, product title, and SEO title are required fields.');
            return;
        }

        $model = $this->db->escape($data['model']);
        $sku = $this->db->escape($data['sku'] ?? '');
        $quantity = (int) ($data['quantity'] ?? 0);
        $manufacturerId = (int) ($data['manufacturer_id'] ?? 0);
        $price = (float) ($data['price'] ?? 0);
        $weight = (float) ($data['weight'] ?? 0);
        $weightClassId = (int) ($data['weight_class_id'] ?? 0);
        $length = (float) ($data['length'] ?? 0);
        $width = (float) ($data['width'] ?? 0);
        $height = (float) ($data['height'] ?? 0);
        $lengthClassId = (int) ($data['length_class_id'] ?? 0);
        $status = (int) ($data['status'] ?? 0);
        $taxClassId = (int) ($data['tax_class_id'] ?? 0);
        $name = $this->db->escape($data['name'] ?? '');
        $description = $this->db->escape($data['description'] ?? '');
        $tag = $this->db->escape($data['tag'] ?? '');
        $metaTitle = $this->db->escape($data['meta_title'] ?? '');
        $metaDescription = $this->db->escape($data['meta_description'] ?? '');
        $metaKeyword = $this->db->escape($data['meta_keyword'] ?? '');
        $categoryId = (int) ($data['category_id'] ?? 0);

        $this->db->query(
            "INSERT INTO {$this->dbPrefix}product SET 
                model = '{$model}',
                sku = '{$sku}',
                quantity = '{$quantity}',
                manufacturer_id = '{$manufacturerId}',
                price = '{$price}',
                weight = '{$weight}',
                weight_class_id = '{$weightClassId}',
                length = '{$length}',
                width = '{$width}',
                height = '{$height}',
                length_class_id = '{$lengthClassId}',
                status = '{$status}',
                tax_class_id = '{$taxClassId}',
                date_added = NOW(), date_modified = NOW()"
        );

        $productId = $this->db->getLastId();

        $storeId = (int) $this->config->get('config_store_id');
        $this->db->query(
            "INSERT INTO {$this->dbPrefix}product_to_store 
             SET product_id = '{$productId}', 
                 store_id = '{$storeId}'"
        );

        $languageId = (int) $this->config->get('config_language_id');
        $this->db->query(
            "INSERT INTO {$this->dbPrefix}product_description SET 
                product_id = '{$productId}',
                language_id = '{$languageId}',
                name = '{$name}',
                description = '{$description}',
                tag = '{$tag}',
                meta_title = '{$metaTitle}',
                meta_description = '{$metaDescription}',
                meta_keyword = '{$metaKeyword}'"
        );

        $categoryId = (int) $categoryId;
        if ($categoryId) {
            $this->db->query(
                "INSERT INTO {$this->dbPrefix}product_to_category 
                 SET product_id = '{$productId}', 
                     category_id = '{$categoryId}'"
            );
        }

        if (isset($data['product_images']) && is_array($data['product_images'])) {
            foreach ($data['product_images'] as $productImage) {
                if ($path = $this->imageUploadLogic($productImage, $productId)) {
                    $escapedPath = $this->db->escape($path);
                    $this->db->query(
                        "INSERT INTO {$this->dbPrefix}product_image 
                         SET product_id = '{$productId}', 
                             image = '{$escapedPath}'"
                    );
                }
            }
        }

        $this->response($this->getProductQuery($productId));
    }

    public function updateProduct(): void
    {
        if (!$this->auth()) {
            return;
        }

        $productId = (int) ($this->request->get['product_id'] ?? 0);
        $data = $this->request->post;

        $productSchema = [
            'model' => 'escape',
            'sku' => 'escape',
            'quantity' => 'int',
            'manufacturer_id' => 'int',
            'price' => 'float',
            'weight' => 'float',
            'weight_class_id' => 'int',
            'length' => 'float',
            'width' => 'float',
            'height' => 'float',
            'length_class_id' => 'int',
            'status' => 'int',
            'tax_class_id' => 'int',
            'sort_order' => 'int'
        ];

        $setClause = $this->buildUpdateSet($data, $productSchema);
        if ($setClause) {
            $this->db->query("UPDATE {$this->dbPrefix}product SET {$setClause}, date_modified = NOW() WHERE product_id = '{$productId}'");
        }

        // Dynamic update for 'product_description' table
        $descriptionSchema = [
            'name' => 'escape',
            'description' => 'escape',
            'tag' => 'escape',
            'meta_title' => 'escape',
            'meta_description' => 'escape',
            'meta_keyword' => 'escape'
        ];

        $setClauseDesc = $this->buildUpdateSet($data, $descriptionSchema);
        if ($setClauseDesc) {
            $sql = "UPDATE {$this->dbPrefix}product_description SET {$setClauseDesc} WHERE product_id = '{$productId}' AND language_id = '" . (int) $this->config->get('config_language_id') . "'";
            $this->db->query($sql);
        }

        if (isset($data['category_id'])) {
            $this->db->query("DELETE FROM {$this->dbPrefix}product_to_category WHERE product_id = '" . $productId . "'");
            if ($data['category_id']) {
                $this->db->query(
                    "INSERT INTO {$this->dbPrefix}product_to_category 
                     SET product_id = '" . $productId . "', 
                         category_id = '" . (int) $data['category_id'] . "'"
                );
            }
        }

        $this->response($this->getProductQuery($productId));
    }

    public function updateStockAndPrice(): void
    {
        if (!$this->auth()) {
            return;
        }

        $productId = (int) ($this->request->get['product_id'] ?? 0);
        $data = $this->request->post;

        $schema = [
            'quantity' => 'int',
            'price' => 'float',
            'status' => 'int'
        ];

        $setClause = $this->buildUpdateSet($data, $schema);
        if ($setClause) {
            $this->db->query("UPDATE {$this->dbPrefix}product SET {$setClause}, date_modified = NOW() WHERE product_id = '{$productId}'");
        }

        $this->response($this->getProductQuery($productId));
    }

    public function updateProductOptionPrice(): void
    {
        if (!$this->auth()) {
            return;
        }
        $data = $this->request->post;
        $productOptionValueId = (int) ($this->request->get['product_option_value_id'] ?? 0);

        $price = (float) $data['price'];
        $pricePrefix = $this->db->escape($data['price_prefix']);
        $this->db->query(
            "UPDATE {$this->dbPrefix}product_option_value SET 
                 price = '{$price}',
                 price_prefix = '{$pricePrefix}'
                 WHERE product_option_value_id = '{$productOptionValueId}'"
        );
        $this->response();
    }

    public function updateProductOptionQuantity(): void
    {
        if (!$this->auth()) {
            return;
        }
        $data = $this->request->post;
        $productOptionValueId = (int) ($this->request->get['product_option_value_id'] ?? 0);

        $quantity = (int) $data['quantity'];
        $this->db->query(
            "UPDATE {$this->dbPrefix}product_option_value SET 
                 quantity = '{$quantity}',
                 subtract = '1'
                 WHERE product_option_value_id = '{$productOptionValueId}'"
        );
        $this->response();
    }

    public function imageUpload(): void
    {
        if (!$this->auth()) {
            return;
        }

        $data = $this->request->post;
        $url = $data['url'] ?? '';
        $productId = (int) ($data['product_id'] ?? 0);
        $order = (int) ($data['sort_order'] ?? 0);

        if ($path = $this->imageUploadLogic($url, $productId)) {
            $escapedPath = $this->db->escape($path);
            if ($order == 1) {
                $this->db->query(
                    "UPDATE {$this->dbPrefix}product SET
                        image = '{$escapedPath}',
                        date_modified = NOW() WHERE product_id = '{$productId}'"
                );
            } else {
                $this->db->query(
                    "INSERT INTO {$this->dbPrefix}product_image SET 
                    product_id = '{$productId}',
                    image = '{$escapedPath}',
                    sort_order = '{$order}'"
                );
            }


            $this->response([
                'path' => $path,
                'product_id' => $productId
            ]);
        }
    }

    private function imageUploadLogic(string $url, int $productId): ?string
    {
        if (empty($url) || !$productId) {
            return null;
        }

        $imageContent = @file_get_contents($url);
        if (empty($imageContent)) {
            return null;
        }

        $filename = basename($url);
        $filename = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', $filename);

        $directory = DIR_IMAGE . 'catalog/' . $productId;
        if (!is_dir($directory)) {
            mkdir($directory, 0700);
        }

        file_put_contents($directory . '/' . $filename, $imageContent);

        return 'catalog/' . $productId . '/' . $filename;
    }

    public function updateProductImages(): void
    {
        $data = $this->request->post;
        $productId = (int) ($data['product_id'] ?? 0);

        $this->db->query("DELETE FROM {$this->dbPrefix}product_image WHERE product_id = '" . $productId . "'");
        foreach ($data['images'] ?? [] as $key => $image) {
            $path = $this->db->escape($image['path']);
            if ($key == 0) {
                $this->db->query(
                    "UPDATE {$this->dbPrefix}product SET
                    image = '{$path}',
                    date_modified = NOW() WHERE product_id = '{$productId}'"
                );
            } else {
                $sortOrder = (int) $image['sort_order'];
                $this->db->query(
                    "INSERT INTO {$this->dbPrefix}product_image SET
                    product_id = '{$productId}',
                    image = '{$path}',
                    sort_order = '{$sortOrder}'"
                );
            }
        }
        $this->response();
    }

    // =========================================================================
    // ORDER METHODS
    // =========================================================================

    /**
     * Order List
     */
    public function order(): void
    {
        if ($this->auth()) {
            $modelSaleOrder = $this->loadAdminModel('sale/order');

            if (!$modelSaleOrder) {
                return;
            }

            $orders = $this->getOrders($this->request->get);
            $orders = $this->paginate($orders);

            $orderIds = array_column($orders, 'order_id');
            $returnsMap = $this->getReturnsByOrderIds($orderIds);

            $orders = array_map(function ($orderResult) use ($modelSaleOrder, $returnsMap) {
                $orderResult['custom_field'] = $this->decode($orderResult['custom_field']);
                $orderResult['payment_custom_field'] = $this->decode($orderResult['payment_custom_field']);

                if (isset($orderResult['customer_custom_field'])) {
                    $orderResult['customer_custom_field'] = $this->decode($orderResult['customer_custom_field']);
                }

                $orderResult['products'] = array_map(function ($product) use ($modelSaleOrder, $orderResult, $returnsMap) {
                    $productResult = $product;
                    // Check if getOptions exists, else try getOrderOptions (OC version variance)
                    if (method_exists($modelSaleOrder, 'getOptions')) {
                        $productResult['options'] = $modelSaleOrder->getOptions($orderResult['order_id'], $product['order_product_id']);
                    } elseif (method_exists($modelSaleOrder, 'getOrderOptions')) {
                        $productResult['options'] = $modelSaleOrder->getOrderOptions($orderResult['order_id'], $product['order_product_id']);
                    }
                    $productResult['returns'] = $returnsMap[$product['order_id']][$product['product_id']] ?? [];
                    return $productResult;
                }, $modelSaleOrder->getProducts($orderResult['order_id']));

                $orderResult['totals'] = $this->getOrderTotals($orderResult['order_id'], $orderResult['shipping_code'], $orderResult['payment_country_id']);
                $orderResult['coupon'] = $this->getOrderCoupon($orderResult['order_id']);
                return $orderResult;
            }, $orders);


            $this->response($orders);
        }
    }

    private function getOrders(array $data = array()): array
    {
        $sql = "SELECT o.*, 
                    (SELECT os.name FROM {$this->dbPrefix}order_status os WHERE os.order_status_id = o.order_status_id AND os.language_id = '" . (int) $this->config->get('config_language_id') . "') AS status, 
                    o.total, 
                    o.currency_code, 
                    o.currency_value, 
                    o.date_added, 
                    o.date_modified, 
                    (SELECT custom_field FROM {$this->dbPrefix}customer where customer_id = o.customer_id) as customer_custom_field 
                FROM `{$this->dbPrefix}order` o";

        if (isset($data['filter_order_status'])) {
            $implode = array();
            $orderStatuses = explode(',', $data['filter_order_status']);
            foreach ($orderStatuses as $orderStatusId) {
                $implode[] = "o.order_status_id = '" . (int) $orderStatusId . "'";
            }
            if ($implode) {
                $sql .= ' WHERE (' . implode(' OR ', $implode) . ')';
            }
        } else {
            $sql .= " WHERE o.order_status_id > '0'";
        }
        if (!empty($data['filter_order_id'])) {
            $filterOrderId = (int) $data['filter_order_id'];
            $sql .= " AND o.order_id = '{$filterOrderId}'";
        }
        if (!empty($data['filter_customer'])) {
            $escapedCustomer = $this->db->escape($data['filter_customer']);
            $sql .= " AND CONCAT(o.firstname, ' ', o.lastname) LIKE '%{$escapedCustomer}%'";
        }
        if (!empty($data['filter_date_added'])) {
            $escapedDateAdded = $this->db->escape($data['filter_date_added']);
            $sql .= " AND DATE(o.date_added) >= DATE('{$escapedDateAdded}')";
        }
        if (!empty($data['filter_date_modified'])) {
            $escapedDateModified = $this->db->escape($data['filter_date_modified']);
            $sql .= " AND DATE(o.date_modified) >= DATE('{$escapedDateModified}')";
        }
        if (!empty($data['filter_total'])) {
            $filterTotal = (float) $data['filter_total'];
            $sql .= " AND o.total = '{$filterTotal}'";
        }

        $sortData = ['o.order_id', 'customer', 'status', 'o.date_added', 'o.date_modified', 'o.total'];
        if (isset($data['sort']) && in_array($data['sort'], $sortData)) {
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

        $results = [];
        foreach ($query->rows as $row) {
            $shippingMethod = json_decode($row['shipping_method'] ?? '', true);
            $row['shipping_code'] = $shippingMethod['code'] ?? '';
            $row['shipping_method'] = $shippingMethod['name'] ?? '';

            $paymentMethod = json_decode($row['payment_method'] ?? '', true);
            $row['payment_code'] = $paymentMethod['code'] ?? '';
            $row['payment_method'] = $paymentMethod['name'] ?? '';

            if (empty($row['payment_firstname'])) {
                $row['payment_firstname'] = $row['shipping_firstname'];
                $row['payment_lastname'] = $row['shipping_lastname'];
            }

            $results[] = $row;
        }

        return $results;
    }

    /**
     * Get order totals
     */
    private function getOrderTotals(int $orderId, ?string $shippingCode, int $countryID): array
    {
        $orderId = (int) $orderId;
        $query = $this->db->query(
            "SELECT * 
             FROM {$this->dbPrefix}order_total 
             WHERE order_id = '{$orderId}' 
             ORDER BY sort_order"
        );

        $totals = array_map(function ($row) use ($countryID, $shippingCode) {
            $code = $row['code'];
            if ($code == 'shipping' && $shippingCode) {
                list($code) = explode('.', $shippingCode);
            }

            $row['tax'] = $this->getTotalsTaxRate($code, $countryID);
            return $row;
        }, $query->rows);

        return $totals;
    }

    private function getOrderCoupon(int $orderId): array
    {
        $orderId = (int) $orderId;
        $query = $this->db->query(
            "SELECT * 
             FROM {$this->dbPrefix}coupon_history ch 
             JOIN {$this->dbPrefix}coupon c ON ch.coupon_id = c.coupon_id 
             WHERE ch.order_id = '{$orderId}'"
        );
        return $query->rows;
    }

    private function getTotalsTaxRate(string $key, int $countryID): array
    {
        $query = $this->db->query(
            "SELECT {$this->dbPrefix}tax_rate.* 
             FROM {$this->dbPrefix}tax_rate
             INNER JOIN {$this->dbPrefix}setting ON {$this->dbPrefix}setting.`key` = '{$key}_tax_class_id'
             INNER JOIN {$this->dbPrefix}tax_class ON {$this->dbPrefix}tax_class.tax_class_id = {$this->dbPrefix}setting.value
             INNER JOIN {$this->dbPrefix}zone_to_geo_zone ON {$this->dbPrefix}zone_to_geo_zone.country_id = {$countryID}
             LEFT JOIN {$this->dbPrefix}tax_rule ON {$this->dbPrefix}tax_rule.tax_class_id = {$this->dbPrefix}tax_class.tax_class_id
             WHERE {$this->dbPrefix}tax_rate.geo_zone_id = {$this->dbPrefix}zone_to_geo_zone.geo_zone_id 
               AND {$this->dbPrefix}tax_rule.tax_rate_id = {$this->dbPrefix}tax_rate.tax_rate_id
             GROUP BY {$this->dbPrefix}tax_rate.tax_rate_id"
        );

        return $query->rows;
    }

    public function orderStatus(): void
    {
        $json = file_get_contents('php://input');
        if ($json) {
            $request = json_decode($json, true);
            if (isset($request['order_id'])) {
                $orderId = $request['order_id'];
                $orderStatusId = $request['order_status_id'] ?? 0;

                $this->load->model('checkout/order');
                $this->model_checkout_order->addHistory($orderId, $orderStatusId, '', true);
            }
        }

        $this->response([]);
    }

    public function setOrderInvoice(): void
    {
        if (!$this->auth()) {
            return;
        }

        if (empty($this->request->post['order_id']) || empty($this->request->post['invoice_url'])) {
            $this->error('Missing parameter (order_id or invoice_url) was sent.');
            return;
        }

        $this->load->model('checkout/order');

        $orderId = $this->request->post['order_id'];
        $invoiceUrl = $this->request->post['invoice_url'];

        $query = $this->db->query(
            "SELECT comment 
             FROM `" . $this->dbPrefix . "order` 
             WHERE order_id = '" . (int) $orderId . "'"
        );
        if (!$query->num_rows) {
            $this->error('Order not found.');
            return;
        }

        $existingComment = $query->row['comment'];
        $updatedComment = preg_replace('/<a href="[^"]+" target="_blank">Faturayı indirmek için tıklayın<\/a>/', '', $existingComment);
        $newComment = trim($updatedComment . ' <a href="' . $invoiceUrl . '" target="_blank">Faturayı indirmek için tıklayın.</a>');

        $escapedComment = $this->db->escape($newComment);
        $orderId = (int) $orderId;
        $this->db->query(
            "UPDATE `{$this->dbPrefix}order` 
             SET comment = '{$escapedComment}' 
             WHERE order_id = '{$orderId}'"
        );

        $this->response(['success' => true]);
    }

    private function getReturnsByOrderIds(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $ids = implode(',', array_map('intval', $orderIds));

        $query = $this->db->query("
            SELECT return_id, order_id, product_id, product, model, quantity, opened, comment,
                   return_reason_id, return_action_id, return_status_id,
                   date_ordered, date_added, date_modified
            FROM {$this->dbPrefix}return
            WHERE order_id IN ({$ids})
        ");

        $map = [];
        foreach ($query->rows as $row) {
            $map[$row['order_id']][$row['product_id']][] = $row;
        }

        return $map;
    }

    public function returnStatus(): void
    {
        if ($this->auth()) {
            $languageId = (int) $this->config->get('config_language_id');
            $query = $this->db->query(
                "SELECT return_status_id, name
                 FROM {$this->dbPrefix}return_status
                 WHERE language_id = '{$languageId}'
                 ORDER BY name ASC"
            );
            $this->response($query->rows);
        }
    }
}
