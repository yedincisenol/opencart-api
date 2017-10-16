<?php

include_once 'admin/model/sale/order.php';
include_once 'admin/model/localisation/tax_rate.php';

class ControllerApiCustom extends Controller
{

    const limit = 15;

    private $data = [
        'data' => [],
        'meta' => []
    ];

    public function auth()
    {
        // load model
        $this->load->model('catalog/product');

        if (!isset($this->session->data['api_id'])) {
            unset($this->data['data'], $this->data['meta']);
            $this->data['message'] = $this->language->get('error_permission');

            return false;
        }

        return true;

    }

    /**
     * Tax list
     */
    public function tax()
    {
        if ($this->auth()) {
            $taxes = (new ModelLocalisationTaxRate($this->registry))->getTaxRates();
            $this->setData($taxes);
        }
    }

    public function order()
    {
        if ($this->auth()) {
            $order  =   new ModelSaleOrder($this->registry);
            $orders =   $order->getOrders($_GET);
            $orders =   $this->paginate($orders);
            $orders =   array_map(function($aorder) use ($order) {
                $aorder['products'] =   $order->getOrderProducts($aorder['order_id']);
                $aorder['totals']   =   $order->getOrderTotals($aorder['order_id']);
                return $aorder;
            }, $orders);
            $this->setData($orders);
        }

    }

    /**
     * Product list
     */
    public function product()
    {
        if ($this->auth()) {
            // get products
            $products = $this->model_catalog_product->getProducts();
            $products = $this->paginate($products);
            //Costoomize start
            $products = array_map(function ($data) {
                unset($data['description']);
                return $data;
            }, $products);
            //Customize end
            $this->setData($products);
        }
    }

    /**
     * Add data to response
     * @param $data
     */
    private function setData($data)
    {
        $this->data['data'] = $data;
    }

    /**
     * Paginate result set
     * @param $results
     * @param null $page
     * @param null $limit
     * @return array
     */
    private function paginate($results, $page = null, $limit = null)
    {
        if ($page == null) $page = max(@$_GET['page'], 1);
        if ($limit == null) $limit = isset($_GET['limit']) ?: self::limit;

        $paginate['total']          =   count($results);
        $paginate['current_page']   =   $page;
        $paginate['per_page']       =   $limit;
        $paginate['total_pages']    =   ceil(count($results) / $limit);

        $this->data['meta']['pagination'] = $paginate;

        return array_slice($results, ($page - 1) * $limit, $limit);
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

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($this->data));
    }

    public function __destruct()
    {
        $this->response();
    }
}