<?php
/**
 * 개선된 쿠팡 API 연동 클래스
 * 경로: /plugin/coupang/lib/coupang_api_class.php
 * 용도: 통합된 쿠팡 API 연동 및 동기화 처리
 */

if (!defined('_GNUBOARD_')) exit; // 직접 접근 금지

class CoupangAPI {
    private $access_key;
    private $secret_key;
    private $vendor_id;
    private $base_url;

    public function __construct($config = array()) {
        $this->access_key = isset($config['access_key']) ? $config['access_key'] : '';
        $this->secret_key = isset($config['secret_key']) ? $config['secret_key'] : '';
        $this->vendor_id  = isset($config['vendor_id']) ? $config['vendor_id'] : '';
        $this->base_url   = 'https://api-gateway.coupang.com';
    }

    public static function log($level, $message, $context = array()) {
        $log_levels = array('DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3);

        if (!isset($log_levels[$level]) || !isset($log_levels[COUPANG_LOG_LEVEL])) {
            return;
        }

        if ($log_levels[$level] < $log_levels[COUPANG_LOG_LEVEL]) {
            return;
        }

        $log_dir = COUPANG_PLUGIN_PATH . '/logs';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $log_file = isset($context['log_file']) ? $context['log_file'] : 'general.log';
        unset($context['log_file']);

        $log_message = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message;
        if (!empty($context)) {
            $log_message .= ' Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        file_put_contents($log_dir . '/' . $log_file, $log_message . PHP_EOL, FILE_APPEND);
    }

    public static function monitorCronExecution($cron_type, $status, $message = '', $execution_duration = null) {
        global $g5;
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_cron_log SET"
             . " cron_type = '" . addslashes($cron_type) . "',"
             . " status = '" . addslashes($status) . "',"
             . " message = '" . addslashes($message) . "'";
        if ($execution_duration !== null) {
            $sql .= ", execution_duration = " . (float)$execution_duration;
        }
        return sql_query($sql);
    }

    public static function runCron($sync_type) {
        $start_time = microtime(true);
        $log_prefix = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($sync_type) . ': ';

        try {
            echo $log_prefix . "시작\n";

            $config_check = validate_coupang_config();
            if (!$config_check['valid']) {
                throw new Exception('API 설정 오류: ' . implode(', ', $config_check['errors']));
            }

            self::monitorCronExecution($sync_type, 'start', '동기화 시작');

            $coupang_api = new self(array(
                'access_key' => COUPANG_ACCESS_KEY,
                'secret_key' => COUPANG_SECRET_KEY,
                'vendor_id'  => COUPANG_VENDOR_ID
            ));
            $result = array('success' => false, 'stats' => array());

            switch ($sync_type) {
                case 'orders':
                    echo $log_prefix . "주문 동기화 실행\n";
                    $result = $coupang_api->syncOrdersFromCoupang(1);
                    break;
                case 'cancelled_orders':
                    echo $log_prefix . "취소 주문 동기화 실행\n";
                    $result = $coupang_api->syncCancelledOrdersFromCoupang(1);
                    break;
                case 'order_status':
                    echo $log_prefix . "주문 상태 동기화 실행\n";
                    $result = $coupang_api->syncOrderStatusToCoupang();
                    break;
                case 'products':
                    echo $log_prefix . "상품 동기화 실행\n";
                    $result = $coupang_api->syncProductsToCoupang();
                    break;
                case 'product_status':
                    echo $log_prefix . "상품 상태 동기화 실행\n";
                    $result = $coupang_api->syncProductStatusToCoupang();
                    break;
                case 'stock':
                    echo $log_prefix . "재고/가격 동기화 실행\n";
                    $result = $coupang_api->syncStockAndPrice();
                    break;
                default:
                    throw new Exception('알 수 없는 동기화 타입: ' . $sync_type);
            }

            $end_time = microtime(true);
            $execution_time = round(($end_time - $start_time), 2);

            if ($result['success']) {
                $stats = isset($result['stats']) ? $result['stats'] : array();
                $stats['execution_time'] = $execution_time;

                $summary = "완료";
                if (isset($stats['total'])) $summary .= " - 전체:{$stats['total']}";
                if (isset($stats['success'])) $summary .= ", 성공:{$stats['success']}";
                if (isset($stats['new'])) $summary .= ", 신규:{$stats['new']}";
                if (isset($stats['update'])) $summary .= ", 업데이트:{$stats['update']}";
                if (isset($stats['skip'])) $summary .= ", 스킵:{$stats['skip']}";
                if (isset($stats['error'])) $summary .= ", 실패:{$stats['error']}";
                if (isset($stats['stock_success'])) $summary .= ", 재고성공:{$stats['stock_success']}";
                if (isset($stats['price_success'])) $summary .= ", 가격성공:{$stats['price_success']}";
                $summary .= ", 실행시간:{$execution_time}초";

                echo $log_prefix . $summary . "\n";
                self::monitorCronExecution($sync_type, 'success', $summary, $execution_time);
                return 0;
            } else {
                $error_msg = isset($result['message']) ? $result['message'] : '알 수 없는 오류';
                throw new Exception($error_msg);
            }

        } catch (Exception $e) {
            $end_time = microtime(true);
            $execution_time = round(($end_time - $start_time), 2);
            $error_msg = "오류: " . $e->getMessage() . " (실행시간: {$execution_time}초)";
            echo $log_prefix . $error_msg . "\n";
            self::log('ERROR', $sync_type . ' 동기화 오류', array(
                'error' => $e->getMessage(),
                'execution_time' => $execution_time,
                'log_file' => 'general.log'
            ));
            self::monitorCronExecution($sync_type, 'error', $error_msg, $execution_time);
            return 1;
        }
    }

    private function getDeliveryCode($company_name) {
        global $COUPANG_DELIVERY_COMPANIES;
        $company_name = trim($company_name);
        if (isset($COUPANG_DELIVERY_COMPANIES[$company_name])) {
            return $COUPANG_DELIVERY_COMPANIES[$company_name];
        }
        foreach ($COUPANG_DELIVERY_COMPANIES as $name => $code) {
            if (strpos($company_name, $name) !== false || strpos($name, $company_name) !== false) {
                return $code;
            }
        }
        return 'ETC';
    }

    /**
     * API 요청 헤더 생성 (HMAC)
     */
    private function generateHeaders($method, $path, $query = '') {
        $datetime = gmdate('Y-m-d\TH:i:s\Z');
        $message  = $datetime . $method . $path . $query;
        $signature = base64_encode(hash_hmac('sha256', $message, $this->secret_key, true));
        return array(
            'Content-Type: application/json;charset=UTF-8',
            'Authorization: CEA algorithm=HmacSHA256, access-key=' . $this->access_key . ', signed-date=' . $datetime . ', signature=' . $signature
        );
    }

    /**
     * HTTP 요청 실행
     */
    public function makeRequest($method, $endpoint, $data = null) {
        $url   = $this->base_url . $endpoint;
        $path  = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $headers = $this->generateHeaders($method, $path, $query ? '?' . $query : '');

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => COUPANG_TIMEOUT
        ));
        
        if ($data && ($method === 'POST' || $method === 'PUT')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response   = curl_exec($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error      = curl_error($ch);
        curl_close($ch);

        if ($error) {
            self::log('ERROR', 'HTTP 요청 오류: ' . $error, array('log_file' => 'general.log'));
            return array('success' => false, 'error' => $error);
        }
        
        if ($http_code >= 400) {
            self::log('ERROR', 'HTTP 오류 응답', array(
                'http_code' => $http_code,
                'response'  => $response,
                'log_file'  => 'general.log'
            ));
        }

        $result = json_decode($response, true);
        return array(
            'success'   => ($http_code >= 200 && $http_code < 300),
            'http_code' => $http_code,
            'data'      => $result,
            'message'   => isset($result['message']) ? $result['message'] : ''
        );
    }

    // ===================== 기본 API 메서드들 =====================

    /**
     * 주문 목록 조회
     */
    public function getOrders($created_at_from = '', $created_at_to = '', $status = '') {
        $params = array();
        if ($created_at_from) $params['createdAtFrom'] = $created_at_from;
        if ($created_at_to)   $params['createdAtTo']   = $created_at_to;
        if ($status)          $params['status']        = $status;
        
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/orders' . ($params ? '?' . http_build_query($params) : '');
        return $this->makeRequest('GET', $endpoint);
    }

    /**
     * 취소된 주문 조회
     */
    public function getCancelledOrders($created_at_from = '', $created_at_to = '') {
        $params = array('status' => 'CANCELED');
        if ($created_at_from) $params['createdAtFrom'] = $created_at_from;
        if ($created_at_to)   $params['createdAtTo']   = $created_at_to;
        
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/orders?' . http_build_query($params);
        return $this->makeRequest('GET', $endpoint);
    }

    /**
     * 배송 처리
     */
    public function dispatchOrder($vendor_order_id, $tracking_number = '', $delivery_company = '') {
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/orders/' . $vendor_order_id . '/dispatch';
        $data = array('vendorOrderId' => $vendor_order_id);
        if ($tracking_number)  $data['trackingNumber']  = $tracking_number;
        if ($delivery_company) $data['deliveryCompany'] = $this->getDeliveryCode($delivery_company);
        
        return $this->makeRequest('POST', $endpoint, $data);
    }

    /**
     * 주문 취소
     */
    public function cancelOrder($vendor_order_id, $cancel_reason = '') {
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/orders/' . $vendor_order_id . '/cancel';
        $data = array('vendorOrderId' => $vendor_order_id);
        if ($cancel_reason) $data['cancelReason'] = $cancel_reason;
        
        return $this->makeRequest('PUT', $endpoint, $data);
    }

    /**
     * 상품 생성
     */
    public function createProduct($product_data) {
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/items';
        $data = $this->buildProductData($product_data);
        return $this->makeRequest('POST', $endpoint, $data);
    }

    /**
     * 상품 업데이트
     */
    public function updateProduct($vendor_item_id, $product_data) {
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/items/' . $vendor_item_id;
        $data = array();
        if (isset($product_data['it_name']))       $data['displayName']   = $product_data['it_name'];
        if (isset($product_data['it_price']))      { 
            $data['salePrice']   = (int)$product_data['it_price']; 
            $data['originalPrice'] = (int)$product_data['it_price']; 
        }
        if (isset($product_data['it_stock_qty']))  $data['maximumBuyCount'] = (int)$product_data['it_stock_qty'];
        
        return $this->makeRequest('PUT', $endpoint, $data);
    }

    /**
     * 재고 업데이트
     */
    public function updateStock($vendor_item_id, $quantity) {
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/items/' . $vendor_item_id . '/quantities';
        $data = array('quantities' => array(array('itemId' => $vendor_item_id, 'quantity' => (int)$quantity)));
        return $this->makeRequest('PUT', $endpoint, $data);
    }

    /**
     * 가격 업데이트
     */
    public function updatePrice($vendor_item_id, $price) {
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/items/' . $vendor_item_id . '/prices';
        $data = array('items' => array(array('itemId' => $vendor_item_id, 'salePrice' => (int)$price, 'originalPrice' => (int)$price)));
        return $this->makeRequest('PUT', $endpoint, $data);
    }

    /**
     * 판매 중단
     */
    public function stopSelling($vendor_item_id) {
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/items/' . $vendor_item_id . '/sales';
        return $this->makeRequest('PUT', $endpoint, array('sellingType' => 'STOP_SELLING'));
    }

    /**
     * 판매 재개
     */
    public function startSelling($vendor_item_id) {
        $endpoint = '/v2/providers/' . $this->vendor_id . '/vendor/items/' . $vendor_item_id . '/sales';
        return $this->makeRequest('PUT', $endpoint, array('sellingType' => 'ON_SALE'));
    }

    // ===================== 통합 동기화 메서드들 =====================

    /**
     * 쿠팡 주문 동기화 (메인)
     */
    public function syncOrdersFromCoupang($time_range_hours = 1) {
        $start_time = microtime(true);
        
        try {
            $from_date = date('Y-m-d\TH:i:s\Z', strtotime('-' . $time_range_hours . ' hour'));
            $to_date = date('Y-m-d\TH:i:s\Z');
            
            self::log('INFO', '주문 동기화 시작', array('from' => $from_date, 'to' => $to_date, 'log_file' => 'orders.log'));
            
            $result = $this->getOrders($from_date, $to_date);
            
            if (!$result['success']) {
                throw new Exception('쿠팡 주문 조회 실패: ' . $result['message']);
            }
            
            $orders = isset($result['data']['data']) ? $result['data']['data'] : array();
            $stats = array('total' => count($orders), 'success' => 0, 'skip' => 0, 'error' => 0);
            
            foreach ($orders as $order) {
                try {
                    $save_result = $this->saveOrderToYoungcart($order);
                    
                    if ($save_result['success']) {
                        $stats['success']++;
                    } else {
                        if (strpos($save_result['message'], '이미 등록된') !== false) {
                            $stats['skip']++;
                        } else {
                            $stats['error']++;
                            self::log('ERROR', '주문 저장 실패', array(
                                'order_id' => $order['vendorOrderId'],
                                'error' => $save_result['message'],
                                'log_file' => 'orders.log'
                            ));
                        }
                    }
                    
                    if (COUPANG_API_DELAY > 0) sleep(COUPANG_API_DELAY);
                    
                } catch (Exception $e) {
                    $stats['error']++;
                    self::log('ERROR', '주문 처리 예외', array(
                        'order_id' => $order['vendorOrderId'],
                        'exception' => $e->getMessage(),
                        'log_file' => 'orders.log'
                    ));
                }
            }
            
            $execution_time = round((microtime(true) - $start_time), 2);
            $stats['execution_time'] = $execution_time;
            
            self::log('INFO', '주문 동기화 완료', array_merge($stats, array('log_file' => 'orders.log')));
            return array('success' => true, 'stats' => $stats);
            
        } catch (Exception $e) {
            $execution_time = round((microtime(true) - $start_time), 2);
            self::log('ERROR', '주문 동기화 오류', array(
                'error' => $e->getMessage(),
                'execution_time' => $execution_time,
                'log_file' => 'orders.log'
            ));
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * 취소 주문 동기화
     */
    public function syncCancelledOrdersFromCoupang($time_range_hours = 1) {
        $start_time = microtime(true);
        
        try {
            $from_date = date('Y-m-d\TH:i:s\Z', strtotime('-' . $time_range_hours . ' hour'));
            $to_date = date('Y-m-d\TH:i:s\Z');
            
            self::log('INFO', '취소 주문 동기화 시작', array('log_file' => 'cancelled.log'));
            
            $result = $this->getCancelledOrders($from_date, $to_date);
            
            if (!$result['success']) {
                throw new Exception('쿠팡 취소 주문 조회 실패: ' . $result['message']);
            }
            
            $orders = isset($result['data']['data']) ? $result['data']['data'] : array();
            $stats = array('total' => count($orders), 'success' => 0, 'skip' => 0, 'error' => 0);
            
            global $g5;
            foreach ($orders as $order) {
                $vendor_order_id = $order['vendorOrderId'];
                
                try {
                    // 영카트에서 해당 주문 확인
                    $sql = "SELECT od_id, od_status FROM {$g5['g5_shop_order_table']} 
                           WHERE od_id = '" . addslashes($vendor_order_id) . "' AND od_coupang_yn = 'Y'";
                    $youngcart_order = sql_fetch($sql);
                    
                    if (!$youngcart_order) {
                        $stats['skip']++;
                        continue;
                    }
                    
                    if ($youngcart_order['od_status'] === '취소') {
                        $stats['skip']++;
                        continue;
                    }
                    
                    // 주문 취소 처리
                    if ($this->processCancelledOrder($vendor_order_id, $order)) {
                        $stats['success']++;
                    } else {
                        $stats['error']++;
                    }
                    
                } catch (Exception $e) {
                    $stats['error']++;
                    self::log('ERROR', '취소 주문 처리 예외', array(
                        'order_id' => $vendor_order_id,
                        'exception' => $e->getMessage(),
                        'log_file' => 'cancelled.log'
                    ));
                }
            }
            
            $execution_time = round((microtime(true) - $start_time), 2);
            $stats['execution_time'] = $execution_time;
            
            self::log('INFO', '취소 주문 동기화 완료', array_merge($stats, array('log_file' => 'cancelled.log')));
            return array('success' => true, 'stats' => $stats);
            
        } catch (Exception $e) {
            $execution_time = round((microtime(true) - $start_time), 2);
            self::log('ERROR', '취소 주문 동기화 오류', array(
                'error' => $e->getMessage(),
                'execution_time' => $execution_time,
                'log_file' => 'cancelled.log'
            ));
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * 상품 동기화 (영카트 → 쿠팡)
     */
    public function syncProductsToCoupang($batch_size = null) {
        if ($batch_size === null) $batch_size = COUPANG_PRODUCT_BATCH_SIZE;
        
        $start_time = microtime(true);
        
        try {
            global $g5;
            
            // 동기화 대상 상품 조회
            $sql = "SELECT i.*, m.coupang_item_id, m.last_sync_date, m.sync_status,
                           c.ca_name
                    FROM {$g5['g5_shop_item_table']} i
                    LEFT JOIN " . G5_TABLE_PREFIX . "coupang_item_map m ON i.it_id = m.youngcart_it_id
                    LEFT JOIN {$g5['g5_shop_category_table']} c ON i.ca_id = c.ca_id
                    WHERE i.it_use = '1' AND i.it_soldout = '0'
                    AND (
                        m.youngcart_it_id IS NULL OR
                        (m.sync_status = 'active' AND i.it_update_time > m.last_sync_date)
                    )
                    ORDER BY i.it_update_time DESC 
                    LIMIT " . (int)$batch_size;

            $result = sql_query($sql);
            $total_products = sql_num_rows($result);
            $stats = array('total' => $total_products, 'new' => 0, 'update' => 0, 'error' => 0);
            
            while ($row = sql_fetch_array($result)) {
                $is_new_product = empty($row['coupang_item_id']);
                
                try {
                    if ($is_new_product) {
                        // 신규 상품 등록
                        $sync_result = $this->createProductFromYoungcart($row['it_id']);
                        if ($sync_result['success']) {
                            $stats['new']++;
                        } else {
                            $stats['error']++;
                            $this->logProductError($row['it_id'], $sync_result['message']);
                        }
                    } else {
                        // 기존 상품 업데이트
                        $update_results = $this->updateProductFromYoungcart($row['it_id'], array('info', 'price', 'stock'));
                        $success_count = 0;
                        
                        foreach ($update_results as $result) {
                            if (isset($result['success']) && $result['success']) {
                                $success_count++;
                            }
                        }
                        
                        if ($success_count > 0) {
                            $stats['update']++;
                            $this->updateItemMapping($row['it_id'], 'active');
                        } else {
                            $stats['error']++;
                        }
                    }
                    
                    if (COUPANG_API_DELAY > 0) sleep(COUPANG_API_DELAY);
                    
                } catch (Exception $e) {
                    $stats['error']++;
                    self::log('ERROR', '상품 동기화 예외', array(
                        'it_id' => $row['it_id'],
                        'exception' => $e->getMessage(),
                        'log_file' => 'products.log'
                    ));
                }
            }
            
            $execution_time = round((microtime(true) - $start_time), 2);
            $stats['execution_time'] = $execution_time;
            
            self::log('INFO', '상품 동기화 완료', array_merge($stats, array('log_file' => 'products.log')));
            return array('success' => true, 'stats' => $stats);
            
        } catch (Exception $e) {
            $execution_time = round((microtime(true) - $start_time), 2);
            self::log('ERROR', '상품 동기화 오류', array(
                'error' => $e->getMessage(),
                'execution_time' => $execution_time,
                'log_file' => 'products.log'
            ));
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    public function syncProductStatusToCoupang() {
        global $g5;
        $start_time = microtime(true);
        $sql = "SELECT i.it_id, i.it_use, i.it_soldout, i.it_update_time,"
             . " m.coupang_item_id, m.sync_status"
             . " FROM {$g5['g5_shop_item_table']} i"
             . " INNER JOIN " . G5_TABLE_PREFIX . "coupang_item_map m ON i.it_id = m.youngcart_it_id"
             . " WHERE ((i.it_use = '0' AND m.sync_status = 'active')"
             . " OR (i.it_soldout = '1' AND m.sync_status = 'active')"
             . " OR (i.it_use = '1' AND i.it_soldout = '0' AND m.sync_status = 'inactive'))"
             . " AND i.it_update_time > m.last_sync_date"
             . " ORDER BY i.it_update_time DESC"
             . " LIMIT " . COUPANG_PRODUCT_BATCH_SIZE;

        $result = sql_query($sql);
        $sync_count = 0;
        $error_count = 0;

        while ($row = sql_fetch_array($result)) {
            try {
                $sync_result = false;
                $new_status = '';

                if ($row['it_use'] == '0' || $row['it_soldout'] == '1') {
                    $sync_result = $this->stopSelling($row['coupang_item_id']);
                    $new_status = 'inactive';
                } else if ($row['it_use'] == '1' && $row['it_soldout'] == '0') {
                    $sync_result = $this->startSelling($row['coupang_item_id']);
                    $new_status = 'active';
                }

                if ($sync_result && $sync_result['success']) {
                    $sync_count++;
                    $update_sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_item_map"
                                . " SET sync_status = '{$new_status}',"
                                . "     last_sync_date = NOW(),"
                                . "     error_message = NULL"
                                . " WHERE youngcart_it_id = '" . addslashes($row['it_id']) . "'";
                    sql_query($update_sql);
                } else {
                    $error_count++;
                    $error_message = isset($sync_result['message']) ? $sync_result['message'] : '알 수 없는 오류';
                    $update_sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_item_map"
                                . " SET error_message = '" . addslashes($error_message) . "',"
                                . "     last_sync_date = NOW()"
                                . " WHERE youngcart_it_id = '" . addslashes($row['it_id']) . "'";
                    sql_query($update_sql);
                }

                if (COUPANG_API_DELAY > 0) sleep(COUPANG_API_DELAY);
            } catch (Exception $e) {
                $error_count++;
                self::log('ERROR', '상품 상태 동기화 예외', array(
                    'it_id' => $row['it_id'],
                    'exception' => $e->getMessage(),
                    'log_file' => 'product_status.log'
                ));
            }
        }

        $execution_time = round((microtime(true) - $start_time), 2);
        self::log('INFO', '상품 상태 동기화 완료', array(
            'success' => $sync_count,
            'error' => $error_count,
            'execution_time' => $execution_time,
            'log_file' => 'product_status.log'
        ));

        return array(
            'success' => ($sync_count > 0 || $error_count == 0),
            'stats' => array('success' => $sync_count, 'error' => $error_count)
        );
    }

    /**
     * 재고/가격 동기화
     */
    public function syncStockAndPrice($batch_size = null) {
        if ($batch_size === null) $batch_size = COUPANG_STOCK_BATCH_SIZE;
        
        $start_time = microtime(true);
        
        try {
            global $g5;
            
            $sql = "SELECT i.it_id, i.it_name, i.it_stock_qty, i.it_price, i.it_update_time, 
                           m.coupang_item_id, m.last_sync_date
                    FROM {$g5['g5_shop_item_table']} i
                    INNER JOIN " . G5_TABLE_PREFIX . "coupang_item_map m ON i.it_id = m.youngcart_it_id
                    WHERE i.it_use = '1' AND m.sync_status = 'active'
                    AND (
                        m.last_sync_date IS NULL OR 
                        i.it_update_time > m.last_sync_date OR
                        TIMESTAMPDIFF(HOUR, m.last_sync_date, NOW()) > 12
                    )
                    ORDER BY i.it_update_time DESC
                    LIMIT " . (int)$batch_size;
            
            $result = sql_query($sql);
            $total_products = sql_num_rows($result);
            $stats = array('total' => $total_products, 'stock_success' => 0, 'price_success' => 0, 'error' => 0);
            
            while ($row = sql_fetch_array($result)) {
                try {
                    $errors = array();
                    
                    // 재고 업데이트
                    $stock_result = $this->updateStock($row['coupang_item_id'], $row['it_stock_qty']);
                    if ($stock_result['success']) {
                        $stats['stock_success']++;
                    } else {
                        $errors[] = 'Stock: ' . $stock_result['message'];
                    }
                    
                    if (COUPANG_API_DELAY > 0) usleep(COUPANG_API_DELAY * 500000);
                    
                    // 가격 업데이트
                    $price_result = $this->updatePrice($row['coupang_item_id'], $row['it_price']);
                    if ($price_result['success']) {
                        $stats['price_success']++;
                    } else {
                        $errors[] = 'Price: ' . $price_result['message'];
                    }
                    
                    // 매핑 테이블 업데이트
                    if ($stock_result['success'] || $price_result['success']) {
                        $this->updateItemMapping($row['it_id'], 'active', empty($errors) ? null : implode(', ', $errors));
                    } else {
                        $stats['error']++;
                        $this->updateItemMapping($row['it_id'], 'error', implode(', ', $errors));
                    }
                    
                    if (COUPANG_API_DELAY > 0) sleep(COUPANG_API_DELAY);
                    
                } catch (Exception $e) {
                    $stats['error']++;
                    self::log('ERROR', '재고/가격 동기화 예외', array(
                        'it_id' => $row['it_id'],
                        'exception' => $e->getMessage(),
                        'log_file' => 'stock.log'
                    ));
                }
            }
            
            $execution_time = round((microtime(true) - $start_time), 2);
            $stats['execution_time'] = $execution_time;
            
            self::log('INFO', '재고/가격 동기화 완료', array_merge($stats, array('log_file' => 'stock.log')));
            return array('success' => true, 'stats' => $stats);
            
        } catch (Exception $e) {
            $execution_time = round((microtime(true) - $start_time), 2);
            self::log('ERROR', '재고/가격 동기화 오류', array(
                'error' => $e->getMessage(),
                'execution_time' => $execution_time,
                'log_file' => 'stock.log'
            ));
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * 주문 상태 동기화 (영카트 → 쿠팡)
     */
    public function syncOrderStatusToCoupang($batch_size = null) {
        if ($batch_size === null) $batch_size = COUPANG_ORDER_BATCH_SIZE;
        
        $start_time = microtime(true);
        
        try {
            global $g5;
            
            $sql = "SELECT o.od_id, o.od_status, o.od_invoice, o.od_delivery_company, o.od_time
                    FROM {$g5['g5_shop_order_table']} o
                    LEFT JOIN " . G5_TABLE_PREFIX . "coupang_order_log l 
                        ON o.od_id = l.od_id AND l.action_type = 'status_update'
                    WHERE o.od_coupang_yn = 'Y' 
                    AND (
                        (o.od_status = '배송' AND o.od_invoice != '' AND o.od_invoice IS NOT NULL) OR
                        (o.od_status = '취소') OR
                        (o.od_status = '반품')
                    )
                    AND (l.od_id IS NULL OR l.created_date < o.od_time)
                    ORDER BY o.od_time DESC 
                    LIMIT " . (int)$batch_size;
            
            $result = sql_query($sql);
            $total_orders = sql_num_rows($result);
            $stats = array('total' => $total_orders, 'success' => 0, 'skip' => 0, 'error' => 0);
            
            while ($row = sql_fetch_array($result)) {
                try {
                    $sync_result = $this->processOrderStatusUpdate($row);
                    
                    if ($sync_result['success']) {
                        $stats['success']++;
                    } else {
                        if ($sync_result['skip']) {
                            $stats['skip']++;
                        } else {
                            $stats['error']++;
                        }
                    }
                    
                    if (COUPANG_API_DELAY > 0) sleep(COUPANG_API_DELAY);
                    
                } catch (Exception $e) {
                    $stats['error']++;
                    self::log('ERROR', '주문 상태 동기화 예외', array(
                        'order_id' => $row['od_id'],
                        'exception' => $e->getMessage(),
                        'log_file' => 'status.log'
                    ));
                }
            }
            
            $execution_time = round((microtime(true) - $start_time), 2);
            $stats['execution_time'] = $execution_time;
            
            self::log('INFO', '주문 상태 동기화 완료', array_merge($stats, array('log_file' => 'status.log')));
            return array('success' => true, 'stats' => $stats);
            
        } catch (Exception $e) {
            $execution_time = round((microtime(true) - $start_time), 2);
            self::log('ERROR', '주문 상태 동기화 오류', array(
                'error' => $e->getMessage(),
                'execution_time' => $execution_time,
                'log_file' => 'status.log'
            ));
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    // ===================== 헬퍼 메서드들 =====================

    /**
     * 쿠팡 주문을 영카트에 저장 (개선된 버전)
     */
    public function saveOrderToYoungcart($coupang_order) {
        global $g5;

        // 중복 체크
        $sql = "SELECT COUNT(*) AS cnt FROM {$g5['g5_shop_order_table']} WHERE od_id = '" . addslashes($coupang_order['vendorOrderId']) . "'";
        $row = sql_fetch($sql);
        if ($row && $row['cnt'] > 0) {
            return array('success' => false, 'message' => '이미 등록된 주문입니다.');
        }

        $receiver = isset($coupang_order['receiver']) ? $coupang_order['receiver'] : array();
        $orderer  = isset($coupang_order['orderer'])  ? $coupang_order['orderer']  : array();

        $od_b_name  = !empty($orderer['ordererName']) ? $orderer['ordererName'] : '';
        $od_b_hp    = !empty($orderer['phone']) ? $orderer['phone'] : '';
        $od_b_email = !empty($orderer['email']) ? $orderer['email'] : '';

        $od_name = !empty($receiver['receiverName']) ? $receiver['receiverName'] : $od_b_name;
        $od_hp   = !empty($receiver['phone']) ? $receiver['phone'] : $od_b_hp;

        $od_zip1 = ''; $od_zip2 = '';
        if (!empty($receiver['postCode'])) {
            if (strlen($receiver['postCode']) == 6) {
                $od_zip1 = substr($receiver['postCode'], 0, 3);
                $od_zip2 = substr($receiver['postCode'], 3, 3);
            } else {
                $od_zip1 = $receiver['postCode'];
            }
        }

        $shipping_fee       = isset($coupang_order['shippingFee']) ? (int)$coupang_order['shippingFee'] : 0;
        $total_item_price   = isset($coupang_order['totalItemPrice']) ? (int)$coupang_order['totalItemPrice'] : 0;
        $total_price        = isset($coupang_order['totalPrice']) ? (int)$coupang_order['totalPrice'] : 0;
        $discount_amount    = isset($coupang_order['totalDiscountPrice']) ? (int)$coupang_order['totalDiscountPrice'] : 0;

        $order_data = array(
            'od_id'          => $coupang_order['vendorOrderId'],
            'mb_id'          => 'coupang_' . preg_replace('/[^a-zA-Z0-9]/', '', $od_b_name),
            'od_name'        => $od_name,
            'od_password'    => '',
            'od_email'       => $od_b_email,
            'od_tel'         => isset($receiver['tel']) ? $receiver['tel'] : '',
            'od_hp'          => $od_hp,
            'od_zip1'        => $od_zip1,
            'od_zip2'        => $od_zip2,
            'od_addr1'       => !empty($receiver['address1']) ? $receiver['address1'] : '',
            'od_addr2'       => !empty($receiver['address2']) ? $receiver['address2'] : '',
            'od_addr3'       => !empty($receiver['address3']) ? $receiver['address3'] : '',
            'od_addr_jibeon' => !empty($receiver['jibeonAddress']) ? $receiver['jibeonAddress'] : '',

            'od_b_name'      => $od_b_name,
            'od_b_tel'       => !empty($orderer['tel']) ? $orderer['tel'] : '',
            'od_b_hp'        => $od_b_hp,
            'od_b_email'     => $od_b_email,
            'od_b_addr1'     => $od_b_name,
            'od_b_addr2'     => '',
            'od_b_addr3'     => '',
            'od_b_addr_jibeon' => '',

            'od_delivery_company' => '',
            'od_invoice'          => '',
            'od_invoice_time'     => '0000-00-00 00:00:00',

            'od_settle_case' => 'coupang',
            'od_bank_account'=> '',
            'od_deposit_name'=> $od_b_name,
            'od_pg'          => 'coupang',

            'od_cash'            => (!empty($coupang_order['paidAt'])) ? $total_price : 0,
            'od_receipt_price'   => $total_price,
            'od_receipt_point'   => 0,
            'od_receipt_coupon'  => $discount_amount,
            'od_cart_price'      => $total_item_price,
            'od_cart_coupon'     => $discount_amount,
            'od_send_cost'       => $shipping_fee,
            'od_send_cost2'      => 0,
            'od_send_coupon'     => 0,

            'od_status'      => $this->mapCoupangOrderStatus($coupang_order['status']),
            'od_hope_date'   => !empty($receiver['requestedDeliveryDate']) ? $receiver['requestedDeliveryDate'] : date('Y-m-d'),
            'od_memo'        => !empty($receiver['deliveryMessage']) ? $receiver['deliveryMessage'] : '',
            'od_shop_memo'   => 'Coupang Order - Original ID: ' . $coupang_order['orderId'] . ' / Vendor Order ID: ' . $coupang_order['vendorOrderId'],

            'od_tax_flag'    => '과세',
            'od_tax_mny'     => (int)($total_price / 11),
            'od_vat_mny'     => (int)($total_price - ($total_price / 11)),

            'od_receipt_time' => !empty($coupang_order['paidAt']) ? date('Y-m-d H:i:s', strtotime($coupang_order['paidAt'])) : '0000-00-00 00:00:00',
            'od_cancel_time'  => '0000-00-00 00:00:00',
            'od_time'         => date('Y-m-d H:i:s'),

            // 쿠팡 구분 필드
            'od_coupang_yn'             => 'Y',
            'od_coupang_order_id'       => $coupang_order['orderId'],
            'od_coupang_vendor_order_id'=> $coupang_order['vendorOrderId'],

            // od_misu는 원래 용도로 사용 (미수금액)
            'od_misu' => $total_price - (isset($coupang_order['paidAt']) && !empty($coupang_order['paidAt']) ? $total_price : 0)
        );

        // 주문 저장
        $sql = "INSERT INTO {$g5['g5_shop_order_table']} SET ";
        foreach ($order_data as $key => $value) {
            $sql .= " {$key} = '" . addslashes($value) . "',";
        }
        $sql = rtrim($sql, ',');
        $result = sql_query($sql);

        if (!$result) {
            return array('success' => false, 'message' => 'DB 저장 실패: ' . sql_error());
        }

        // 주문 상품(카트) 저장
        if (isset($coupang_order['vendorItems']) && is_array($coupang_order['vendorItems'])) {
            foreach ($coupang_order['vendorItems'] as $idx => $item) {
                $this->saveOrderItem($coupang_order['vendorOrderId'], $item, $idx);
            }
        }

        // 쿠팡 주문 로그 저장 (상세 데이터)
        $this->logOrderAction($coupang_order['vendorOrderId'], $coupang_order['orderId'], 'order_import', $coupang_order);

        return array('success' => true, 'message' => '주문이 성공적으로 등록되었습니다.');
    }

    /**
     * 주문 상품 저장 (개선된 버전)
     */
    private function saveOrderItem($vendor_order_id, $item, $idx) {
        global $g5;

        $youngcart_item_id = $this->findYoungcartItemId($item['vendorItemId']);

        $item_info = array();
        if ($youngcart_item_id) {
            $item_sql = "SELECT * FROM {$g5['g5_shop_item_table']} WHERE it_id = '" . addslashes($youngcart_item_id) . "'";
            $item_info = sql_fetch($item_sql);
        }

        $cart_data = array(
            'od_id'       => $vendor_order_id,
            'it_id'       => $youngcart_item_id ? $youngcart_item_id : $item['vendorItemId'],
            'it_name'     => $item['vendorItemName'],

            'it_sc_type'    => $item_info ? $item_info['it_sc_type']    : '1',
            'it_sc_method'  => $item_info ? $item_info['it_sc_method']  : '1',
            'it_sc_price'   => $item_info ? $item_info['it_sc_price']   : 0,
            'it_sc_minimum' => $item_info ? $item_info['it_sc_minimum'] : 0,
            'it_sc_qty'     => $item_info ? $item_info['it_sc_qty']     : 0,

            'ct_id'     => $vendor_order_id . '_' . ($idx + 1),
            'ct_qty'    => (int)$item['quantity'],
            'ct_price'  => (int)$item['unitPrice'],
            'ct_point'  => 0,
            'ct_point_use' => 0,
            'ct_stock_use' => 1,

            'ct_option'     => '',
            'ct_select'     => 0,
            'ct_select_time'=> date('Y-m-d H:i:s'),

            'ct_status'  => $this->mapCoupangItemStatus($item['status']),
            // ct_memo 대신 ct_history 사용 (수정됨)
            'ct_history' => json_encode(array(
                'coupang_vendor_item_id' => $item['vendorItemId'],
                'coupang_product_id'     => isset($item['productId']) ? $item['productId'] : '',
                'coupang_order_item_id'  => isset($item['orderItemId']) ? $item['orderItemId'] : '',
                'unit_price'             => $item['unitPrice'],
                'quantity'               => $item['quantity'],
                'shipping_fee'           => isset($item['shippingFee']) ? $item['shippingFee'] : 0,
                'discount_price'         => isset($item['discountPrice']) ? $item['discountPrice'] : 0,
                'original_data'          => $item
            ), JSON_UNESCAPED_UNICODE),
            'ct_time'    => date('Y-m-d H:i:s'),
            'ct_ip'      => 'coupang.com',
            'ct_send_cost' => isset($item['shippingFee']) ? (int)$item['shippingFee'] : 0,

            'io_type'  => 'coupang',
            'io_id'    => $vendor_order_id . '_' . ($idx + 1),
            'io_price' => (int)$item['unitPrice']
        );

        // 옵션 텍스트
        if (isset($item['options']) && is_array($item['options'])) {
            $option_texts  = array();
            $option_values = array();
            foreach ($item['options'] as $opt) {
                $option_texts[]  = $opt['optionName'] . ': ' . $opt['optionValue'];
                $option_values[] = $opt['optionValue'];
            }
            $cart_data['ct_option'] = implode(', ', $option_texts);
        }

        $cart_sql = "INSERT INTO {$g5['g5_shop_cart_table']} SET ";
        foreach ($cart_data as $key => $value) {
            $cart_sql .= " {$key} = '" . addslashes($value) . "',";
        }
        $cart_sql = rtrim($cart_sql, ',');
        $cart_result = sql_query($cart_sql);

        if (!$cart_result) {
            self::log('ERROR', 'Cart 저장 실패', array(
                'error' => sql_error(),
                'item_id' => $item['vendorItemId'],
                'log_file' => 'orders.log'
            ));
        } else {
            // 재고 차감
            if ($youngcart_item_id) {
                $this->updateYoungcartStock($youngcart_item_id, -(int)$item['quantity']);
            }
        }
    }

    /**
     * 취소된 주문 처리
     */
    private function processCancelledOrder($vendor_order_id, $order) {
        global $g5;

        $update_sql = "UPDATE {$g5['g5_shop_order_table']} SET 
                       od_status = '취소',
                       od_cancel_time = NOW(),
                       od_shop_memo = CONCAT(IFNULL(od_shop_memo, ''), ' / 쿠팡에서 취소됨: " . date('Y-m-d H:i:s') . "')
                       WHERE od_id = '" . addslashes($vendor_order_id) . "'";

        if (!sql_query($update_sql)) {
            return false;
        }

        // 장바구니 상태도 업데이트
        $cart_update_sql = "UPDATE {$g5['g5_shop_cart_table']} 
                           SET ct_status = '취소' 
                           WHERE od_id = '" . addslashes($vendor_order_id) . "'";
        sql_query($cart_update_sql);

        // 재고 복구
        $cart_sql = "SELECT it_id, ct_qty FROM {$g5['g5_shop_cart_table']} 
                    WHERE od_id = '" . addslashes($vendor_order_id) . "'";
        $cart_result = sql_query($cart_sql);

        while ($cart_row = sql_fetch_array($cart_result)) {
            if (!empty($cart_row['it_id'])) {
                $this->updateYoungcartStock($cart_row['it_id'], (int)$cart_row['ct_qty']);
            }
        }

        // 로그 기록
        $this->logOrderAction($vendor_order_id, $order['orderId'], 'cancel_from_coupang', $order);

        return true;
    }

    /**
     * 주문 상태 업데이트 처리
     */
    private function processOrderStatusUpdate($order_row) {
        $od_id = $order_row['od_id'];
        $sync_result = array('success' => false, 'skip' => false);
        $action_type = '';

        try {
            switch ($order_row['od_status']) {
                case '배송':
                    if (!empty($order_row['od_invoice'])) {
                        $delivery_company_code = $this->getDeliveryCode($order_row['od_delivery_company']);
                        $result = $this->dispatchOrder($od_id, $order_row['od_invoice'], $delivery_company_code);
                        $action_type = 'dispatch';

                        if ($result['success']) {
                            // 영카트 배송 정보 업데이트
                            global $g5;
                            $update_sql = "UPDATE {$g5['g5_shop_order_table']} SET 
                                           od_invoice_time = NOW()
                                           WHERE od_id = '" . addslashes($od_id) . "' AND od_invoice_time = '0000-00-00 00:00:00'";
                            sql_query($update_sql);
                            $sync_result['success'] = true;
                        }
                    } else {
                        $sync_result['skip'] = true;
                    }
                    break;

                case '취소':
                    $result = $this->cancelOrder($od_id, '판매자 취소');
                    $action_type = 'cancel';
                    $sync_result['success'] = $result['success'];
                    break;

                case '반품':
                    $result = $this->cancelOrder($od_id, '반품 처리');
                    $action_type = 'return';
                    $sync_result['success'] = $result['success'];
                    break;

                default:
                    $sync_result['skip'] = true;
                    break;
            }

            if ($sync_result['success']) {
                // 로그 기록
                $this->logOrderAction($od_id, '', 'status_update', array(
                    'status' => $order_row['od_status'],
                    'invoice' => $order_row['od_invoice'],
                    'delivery_company' => $order_row['od_delivery_company'],
                    'action_type' => $action_type
                ));
            }

        } catch (Exception $e) {
            self::log('ERROR', '주문 상태 업데이트 예외', array(
                'order_id' => $od_id,
                'exception' => $e->getMessage(),
                'log_file' => 'status.log'
            ));
        }

        return $sync_result;
    }

    /**
     * 영카트 상품 정보로 쿠팡 상품 생성
     */
    public function createProductFromYoungcart($it_id) {
        global $g5;
        
        $sql = "SELECT * FROM {$g5['g5_shop_item_table']} WHERE it_id = '" . addslashes($it_id) . "'";
        $item = sql_fetch($sql);
        if (!$item) {
            return array('success' => false, 'message' => '상품을 찾을 수 없습니다.');
        }

        $ca_sql = "SELECT * FROM {$g5['g5_shop_category_table']} WHERE ca_id = '" . addslashes($item['ca_id']) . "'";
        $category = sql_fetch($ca_sql);

        $product_data = $this->buildProductDataFromYoungcart($item, $category);
        $result = $this->createProduct($product_data);

        if ($result['success'] && isset($result['data']['vendorItemId'])) {
            // 매핑 테이블에 저장
            $mapping_sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_item_map SET 
                            youngcart_it_id = '" . addslashes($it_id) . "',
                            coupang_item_id = '" . addslashes($result['data']['vendorItemId']) . "',
                            sync_date = NOW(),
                            sync_status = 'active'
                            ON DUPLICATE KEY UPDATE 
                            coupang_item_id = VALUES(coupang_item_id),
                            sync_date = NOW(),
                            sync_status = 'active'";
            sql_query($mapping_sql);
        }

        return $result;
    }

    /**
     * 영카트 상품으로 쿠팡 상품 업데이트
     */
    public function updateProductFromYoungcart($it_id, $update_fields = array()) {
        global $g5;
        
        $sql = "SELECT coupang_item_id FROM " . G5_TABLE_PREFIX . "coupang_item_map WHERE youngcart_it_id = '" . addslashes($it_id) . "'";
        $map_row = sql_fetch($sql);
        if (!$map_row || empty($map_row['coupang_item_id'])) {
            return array('error' => array('success' => false, 'message' => '쿠팡에 등록되지 않은 상품입니다.'));
        }

        $coupang_item_id = $map_row['coupang_item_id'];

        $sql = "SELECT * FROM {$g5['g5_shop_item_table']} WHERE it_id = '" . addslashes($it_id) . "'";
        $item = sql_fetch($sql);
        if (!$item) {
            return array('error' => array('success' => false, 'message' => '상품을 찾을 수 없습니다.'));
        }

        $results = array();

        if (empty($update_fields) || in_array('price', $update_fields)) {
            $results['price'] = $this->updatePrice($coupang_item_id, $item['it_price']);
        }
        if (empty($update_fields) || in_array('stock', $update_fields)) {
            $results['stock'] = $this->updateStock($coupang_item_id, $item['it_stock_qty']);
        }
        if (empty($update_fields) || in_array('info', $update_fields)) {
            $product_data = array(
                'it_name'  => $item['it_name'],
                'it_price' => $item['it_price'],
                'it_stock_qty' => $item['it_stock_qty']
            );
            $results['info'] = $this->updateProduct($coupang_item_id, $product_data);
        }

        return $results;
    }

    /**
     * 상품 데이터 구성 (영카트 → 쿠팡)
     */
    private function buildProductDataFromYoungcart($item, $category = null) {
        $data = array(
            'displayName'             => $item['it_name'],
            'vendorItemName'          => $item['it_name'],
            'brand'                   => !empty($item['it_maker']) ? $item['it_maker'] : '기타',
            'manufacturerName'        => !empty($item['it_maker']) ? $item['it_maker'] : '기타',
            'salePrice'               => (int)$item['it_price'],
            'originalPrice'           => !empty($item['it_cust_price']) ? (int)$item['it_cust_price'] : (int)$item['it_price'],
            'maximumBuyCount'         => (int)$item['it_stock_qty'],
            'outboundShippingTimeDay' => 1,
            'vendorUserId'            => $this->vendor_id,
            'requested'               => true,
            'adult'                   => false,
            'taxType'                 => 'TAX',
            'parallelImported'        => false,
            'overseasPurchased'       => false,
            'pccNeeded'               => false,
            'externalVendorSku'       => $item['it_id'],
            'modelName'               => !empty($item['it_model']) ? $item['it_model'] : $item['it_name']
        );

        // 카테고리 매핑
        if (!empty($item['ca_id'])) {
            $data['categoryId'] = $this->mapYoungcartCategoryToCoupang($item['ca_id']);
        }

        // 설명
        $description = '';
        if (!empty($item['it_basic']))   $description .= strip_tags($item['it_basic']) . "\n\n";
        if (!empty($item['it_explan']))  $description .= strip_tags($item['it_explan']) . "\n\n";
        $data['itemDescription'] = trim($description);

        // 이미지
        $data['images'] = array();
        $image_order = 1;
        for ($i = 1; $i <= 10; $i++) {
            if (!empty($item['it_img' . $i])) {
                $data['images'][] = array(
                    'imageOrder' => $image_order++,
                    'imageType'  => ($i == 1) ? 'REPRESENTATION' : 'DETAIL',
                    'cdnPath'    => G5_DOMAIN . G5_DATA_URL . '/item/' . $item['it_img' . $i]
                );
            }
        }

        return $data;
    }

    // ===================== 기타 헬퍼 메서드들 =====================

    /**
     * 쿠팡 주문 상태 → 영카트 주문 상태
     */
    private function mapCoupangOrderStatus($coupang_status) {
        $status_map = array(
            'ACCEPT'           => '입금',
            'INSTRUCT'         => '준비',
            'DEPARTURE'        => '배송',
            'DELIVERED'        => '완료',
            'PURCHASE_DECIDED' => '완료',
            'CANCELED'         => '취소',
            'RETURNED'         => '반품',
            'EXCHANGED'        => '교환'
        );
        return isset($status_map[$coupang_status]) ? $status_map[$coupang_status] : '대기';
    }

    /**
     * 쿠팡 아이템 상태 → 영카트 카트 상태
     */
    private function mapCoupangItemStatus($coupang_status) {
        $status_map = array(
            'ACCEPT'           => '주문',
            'INSTRUCT'         => '준비',
            'DEPARTURE'        => '배송',
            'DELIVERED'        => '완료',
            'PURCHASE_DECIDED' => '완료',
            'CANCELED'         => '취소',
            'RETURNED'         => '반품',
            'EXCHANGED'        => '교환'
        );
        return isset($status_map[$coupang_status]) ? $status_map[$coupang_status] : '대기';
    }

    /**
     * 영카트 카테고리 → 쿠팡 카테고리 매핑
     */
    private function mapYoungcartCategoryToCoupang($ca_id) {
        global $g5;
        $sql = "SELECT coupang_category_id FROM " . G5_TABLE_PREFIX . "coupang_category_map WHERE youngcart_ca_id = '" . addslashes($ca_id) . "'";
        $row = sql_fetch($sql);
        if ($row && !empty($row['coupang_category_id'])) {
            return $row['coupang_category_id'];
        }
        return '1001'; // 기본 카테고리
    }

    /**
     * 쿠팡 vendorItemId → 영카트 it_id 찾기
     */
    private function findYoungcartItemId($coupang_item_id) {
        global $g5;
        
        // 매핑 테이블에서 먼저 찾기
        $sql = "SELECT youngcart_it_id FROM " . G5_TABLE_PREFIX . "coupang_item_map WHERE coupang_item_id = '" . addslashes($coupang_item_id) . "'";
        $row = sql_fetch($sql);
        if ($row && !empty($row['youngcart_it_id'])) {
            return $row['youngcart_it_id'];
        }

        // 직접 매칭 시도
        $sql = "SELECT it_id FROM {$g5['g5_shop_item_table']} WHERE it_id = '" . addslashes($coupang_item_id) . "'";
        $row = sql_fetch($sql);
        if ($row) {
            return $row['it_id'];
        }

        return null;
    }

    /**
     * 영카트 재고 업데이트
     */
    private function updateYoungcartStock($it_id, $quantity_change) {
        global $g5;
        $sql = "UPDATE {$g5['g5_shop_item_table']} SET 
                it_stock_qty = GREATEST(0, it_stock_qty + " . (int)$quantity_change . ") 
                WHERE it_id = '" . addslashes($it_id) . "'";
        return sql_query($sql);
    }

    /**
     * 상품 매핑 테이블 업데이트
     */
    private function updateItemMapping($it_id, $status, $error_message = null) {
        global $g5;
        $sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_item_map 
                SET last_sync_date = NOW(), 
                    sync_status = '" . addslashes($status) . "'";
        
        if ($error_message !== null) {
            $sql .= ", error_message = '" . addslashes($error_message) . "'";
        } else {
            $sql .= ", error_message = NULL";
        }
        
        $sql .= " WHERE youngcart_it_id = '" . addslashes($it_id) . "'";
        return sql_query($sql);
    }

    /**
     * 상품 오류 로그
     */
    private function logProductError($it_id, $error_message) {
        global $g5;
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_item_map SET 
                youngcart_it_id = '" . addslashes($it_id) . "',
                coupang_item_id = '',
                sync_status = 'error',
                error_message = '" . addslashes($error_message) . "',
                sync_date = NOW()
                ON DUPLICATE KEY UPDATE
                sync_status = 'error',
                error_message = '" . addslashes($error_message) . "',
                last_sync_date = NOW()";
        return sql_query($sql);
    }

    /**
     * 주문 액션 로그
     */
    private function logOrderAction($od_id, $coupang_order_id, $action_type, $action_data, $response_data = 'SUCCESS') {
        global $g5;
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_order_log SET 
                od_id = '" . addslashes($od_id) . "',
                coupang_order_id = '" . addslashes($coupang_order_id) . "',
                action_type = '" . addslashes($action_type) . "',
                action_data = '" . addslashes(json_encode($action_data, JSON_UNESCAPED_UNICODE)) . "',
                response_data = '" . addslashes(is_string($response_data) ? $response_data : json_encode($response_data, JSON_UNESCAPED_UNICODE)) . "',
                created_date = NOW()";
        return sql_query($sql);
    }
}

