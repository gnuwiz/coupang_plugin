<?php
/**
 * 개선된 쿠팡 API 연동 클래스 (카테고리 추천 기능 포함)
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

    /**
     * API 요청 헤더 생성 (HMAC)
     */
    private function generateHeaders($method, $path, $query = '') {
        $datetime = gmdate('ymd\THis\Z');
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
            CURLOPT_SSL_VERIFYPEER => false,
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
            coupang_log('ERROR', 'HTTP 요청 오류: ' . $error);
            return array('success' => false, 'error' => $error);
        }
        
        $result = json_decode($response, true);
        return array(
            'success'   => ($http_code >= 200 && $http_code < 300),
            'http_code' => $http_code,
            'data'      => $result,
            'message'   => isset($result['message']) ? $result['message'] : ''
        );
    }

    // ===================== 카테고리 관련 API 메서드들 =====================

    /**
     * 쿠팡 카테고리 추천 (범용 메서드)
     * @param string $product_name 상품명 (필수)
     * @param array $options 추가 옵션
     * @return array 추천 결과
     */
    public function getCategoryRecommendation($product_name, $options = array()) {
        // 입력 검증
        if (empty($product_name) || strlen(trim($product_name)) < 2) {
            return array(
                'success' => false,
                'error' => '상품명이 너무 짧습니다. (최소 2자 이상)',
                'data' => null
            );
        }
        
        // 기본 옵션 설정
        $default_options = array(
            'product_description' => '',
            'brand' => '',
            'attributes' => array(),
            'seller_sku_code' => '',
            'cache' => true,
            'retry' => 3
        );
        $options = array_merge($default_options, $options);
        
        // 캐시 확인 (선택적)
        if ($options['cache']) {
            $cache_key = 'coupang_category_' . md5($product_name . serialize($options));
            $cached_result = $this->getCachedCategoryRecommendation($cache_key);
            if ($cached_result !== null) {
                coupang_log('DEBUG', '카테고리 추천 캐시 히트', array('product_name' => $product_name));
                return $cached_result;
            }
        }
        
        // API 요청 데이터 구성
        $endpoint = '/v2/providers/openapi/apis/api/v1/categorization/predict';
        $data = array('productName' => trim($product_name));
        
        // 선택적 정보 추가
        if (!empty($options['product_description'])) {
            $data['productDescription'] = trim($options['product_description']);
        }
        
        if (!empty($options['brand'])) {
            $data['brand'] = trim($options['brand']);
        }
        
        if (!empty($options['attributes']) && is_array($options['attributes'])) {
            $data['attributes'] = $options['attributes'];
        }
        
        if (!empty($options['seller_sku_code'])) {
            $data['sellerSkuCode'] = trim($options['seller_sku_code']);
        }
        
        // API 호출 (재시도 로직 포함)
        $retry_count = 0;
        $max_retry = max(1, intval($options['retry']));
        
        do {
            coupang_log('INFO', '카테고리 추천 API 호출', array(
                'product_name' => $product_name,
                'retry_count' => $retry_count,
                'data_keys' => array_keys($data)
            ));
            
            $result = $this->makeRequest('POST', $endpoint, $data);
            
            // 성공 응답 처리
            if ($result['success'] && isset($result['data']['data'])) {
                $recommendation_data = $result['data']['data'];
                
                // 응답 데이터 검증 및 가공
                $processed_result = $this->processCategoryRecommendation($recommendation_data, $product_name);
                
                // 캐시 저장 (성공 시에만)
                if ($options['cache'] && $processed_result['success']) {
                    $this->setCachedCategoryRecommendation($cache_key, $processed_result);
                }
                
                return $processed_result;
            }
            
            // 에러 응답 분석
            if (isset($result['data']['message'])) {
                $error_message = $result['data']['message'];
                
                // 재시도 불가능한 에러들
                if (strpos($error_message, 'Input product name should not be empty') !== false) {
                    break; // 입력 오류는 재시도 무의미
                }
            }
            
            $retry_count++;
            
            // 재시도 간 지연
            if ($retry_count < $max_retry) {
                sleep(COUPANG_API_DELAY);
            }
            
        } while ($retry_count < $max_retry);
        
        // 최종 실패 처리
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '카테고리 추천 실패';
        
        coupang_log('ERROR', '카테고리 추천 최종 실패', array(
            'product_name' => $product_name,
            'retry_count' => $retry_count,
            'error' => $error_msg,
            'http_code' => $result['http_code']
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'http_code' => $result['http_code'],
            'data' => null
        );
    }

    /**
     * 카테고리 추천 응답 데이터 처리
     */
    private function processCategoryRecommendation($recommendation_data, $product_name) {
        try {
            $result_type = isset($recommendation_data['autoCategorizationPredictionResultType']) 
                          ? $recommendation_data['autoCategorizationPredictionResultType'] 
                          : '';
            
            if ($result_type === 'SUCCESS') {
                $category_id = isset($recommendation_data['predictedCategoryId']) 
                              ? $recommendation_data['predictedCategoryId'] 
                              : '';
                $category_name = isset($recommendation_data['predictedCategoryName']) 
                                ? $recommendation_data['predictedCategoryName'] 
                                : '';
                
                if (!empty($category_id)) {
                    coupang_log('INFO', '카테고리 추천 성공', array(
                        'product_name' => $product_name,
                        'category_id' => $category_id,
                        'category_name' => $category_name
                    ));
                    
                    return array(
                        'success' => true,
                        'data' => array(
                            'result_type' => $result_type,
                            'category_id' => $category_id,
                            'category_name' => $category_name,
                            'comment' => isset($recommendation_data['comment']) ? $recommendation_data['comment'] : null,
                            'confidence' => $this->calculateCategoryConfidence($category_name, $product_name),
                            'recommended_at' => date('Y-m-d H:i:s')
                        )
                    );
                }
            }
            
            // 실패 케이스들
            $error_messages = array(
                'FAILURE' => '카테고리 추천에 실패했습니다.',
                'INSUFFICIENT_INFORMATION' => '상품 정보가 부족하여 카테고리를 추천할 수 없습니다.'
            );
            
            $error_msg = isset($error_messages[$result_type]) 
                        ? $error_messages[$result_type] 
                        : '알 수 없는 오류가 발생했습니다.';
            
            return array(
                'success' => false,
                'error' => $error_msg,
                'data' => array(
                    'result_type' => $result_type,
                    'comment' => isset($recommendation_data['comment']) ? $recommendation_data['comment'] : null
                )
            );
            
        } catch (Exception $e) {
            coupang_log('ERROR', '카테고리 추천 응답 처리 오류', array(
                'product_name' => $product_name,
                'error' => $e->getMessage(),
                'raw_data' => $recommendation_data
            ));
            
            return array(
                'success' => false,
                'error' => '응답 데이터 처리 중 오류가 발생했습니다.',
                'data' => null
            );
        }
    }

    /**
     * 카테고리 추천 신뢰도 계산 (간단한 휴리스틱)
     */
    private function calculateCategoryConfidence($category_name, $product_name) {
        if (empty($category_name) || empty($product_name)) {
            return 0.5; // 기본값
        }
        
        $confidence = 0.7; // 기본 신뢰도
        
        // 상품명에 카테고리 관련 키워드가 포함되어 있으면 신뢰도 증가
        $category_keywords = explode('>', $category_name);
        foreach ($category_keywords as $keyword) {
            $keyword = trim($keyword);
            if (!empty($keyword) && strpos($product_name, $keyword) !== false) {
                $confidence += 0.1;
            }
        }
        
        return min(1.0, $confidence); // 최대 1.0
    }

    /**
     * 캐시된 카테고리 추천 조회
     */
    private function getCachedCategoryRecommendation($cache_key) {
        global $g5;
        
        // 1시간 이내 캐시만 유효
        $sql = "SELECT cache_data FROM " . G5_TABLE_PREFIX . "coupang_category_cache 
                WHERE cache_key = '" . addslashes($cache_key) . "' 
                AND created_date > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        
        $row = sql_fetch($sql);
        if ($row && !empty($row['cache_data'])) {
            $cached_data = json_decode($row['cache_data'], true);
            if ($cached_data !== null) {
                return $cached_data;
            }
        }
        
        return null;
    }

    /**
     * 카테고리 추천 캐시 저장
     */
    private function setCachedCategoryRecommendation($cache_key, $data) {
        global $g5;
        
        $cache_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_category_cache 
                (cache_key, cache_data, created_date) VALUES (
                    '" . addslashes($cache_key) . "',
                    '" . addslashes($cache_data) . "',
                    NOW()
                ) ON DUPLICATE KEY UPDATE 
                    cache_data = '" . addslashes($cache_data) . "',
                    created_date = NOW()";
        
        sql_query($sql);
    }

    /**
     * 영카트 상품 정보로부터 카테고리 추천 받기 (헬퍼 메서드)
     */
    public function getCategoryRecommendationFromYoungcartItem($item_data) {
        // 영카트 상품 데이터에서 필요한 정보 추출
        $product_name = isset($item_data['it_name']) ? $item_data['it_name'] : '';
        
        $options = array();
        
        // 상품 설명 조합
        $description_parts = array();
        if (!empty($item_data['it_basic'])) {
            $description_parts[] = strip_tags($item_data['it_basic']);
        }
        if (!empty($item_data['it_info'])) {
            $description_parts[] = strip_tags($item_data['it_info']);
        }
        if (!empty($description_parts)) {
            $options['product_description'] = implode(' ', $description_parts);
        }
        
        // 브랜드 정보
        if (!empty($item_data['it_maker'])) {
            $options['brand'] = $item_data['it_maker'];
        }
        
        // 상품 속성 정보
        $attributes = array();
        if (!empty($item_data['it_origin'])) {
            $attributes['제조국'] = $item_data['it_origin'];
        }
        if (!empty($item_data['it_weight'])) {
            $attributes['중량'] = $item_data['it_weight'];
        }
        if (!empty($attributes)) {
            $options['attributes'] = $attributes;
        }
        
        // 판매자 상품코드
        if (!empty($item_data['it_id'])) {
            $options['seller_sku_code'] = $item_data['it_id'];
        }
        
        return $this->getCategoryRecommendation($product_name, $options);
    }

    /**
     * 카테고리 추천 결과를 매핑 테이블에 저장
     */
    public function saveCategoryMapping($youngcart_it_id, $coupang_category_id, $category_name = '', $confidence = 0.0) {
        global $g5;
        
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_category_map 
                (youngcart_ca_id, coupang_category_id, coupang_category_name, confidence, created_date) VALUES (
                    '" . addslashes($youngcart_it_id) . "',
                    '" . addslashes($coupang_category_id) . "',
                    '" . addslashes($category_name) . "',
                    " . floatval($confidence) . ",
                    NOW()
                ) ON DUPLICATE KEY UPDATE 
                    coupang_category_id = '" . addslashes($coupang_category_id) . "',
                    coupang_category_name = '" . addslashes($category_name) . "',
                    confidence = " . floatval($confidence) . ",
                    updated_date = NOW()";
        
        $result = sql_query($sql);
        
        if ($result) {
            coupang_log('INFO', '카테고리 매핑 저장 완료', array(
                'youngcart_it_id' => $youngcart_it_id,
                'coupang_category_id' => $coupang_category_id,
                'category_name' => $category_name
            ));
        }
        
        return $result;
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
        if ($delivery_company) $data['deliveryCompany'] = get_coupang_delivery_code($delivery_company);
        
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
        $data = $this->buildProductData($product_data);
        return $this->makeRequest('PUT', $endpoint, $data);
    }

	// ===================== 출고지 관리 API 메서드들 =====================

    /**
     * 출고지 등록
     * @param array $shipping_place_data 출고지 정보
     * @return array API 응답 결과
     */
    public function createOutboundShippingPlace($shipping_place_data) {
        $endpoint = '/v2/providers/openapi/apis/api/v1/marketplace/shipping-places';
        
        // 입력 데이터 검증
        $required_fields = array('name', 'company_name', 'contact_name', 'company_phone', 
                               'zipcode', 'address1', 'address2');
        foreach ($required_fields as $field) {
            if (empty($shipping_place_data[$field])) {
                return array('success' => false, 'error' => "필수 필드 누락: {$field}");
            }
        }
        
        $data = array(
            'shippingPlaceName' => $shipping_place_data['name'],
            'placeAddresses' => array(
                array(
                    'companyContactNumber' => $shipping_place_data['company_phone'],
                    'phoneNumber2' => isset($shipping_place_data['phone2']) ? $shipping_place_data['phone2'] : '',
                    'addressType' => 'OUTBOUND',
                    'companyName' => $shipping_place_data['company_name'],
                    'name' => $shipping_place_data['contact_name'],
                    'phoneNumber1' => $shipping_place_data['phone1'],
                    'zipCode' => $shipping_place_data['zipcode'],
                    'address1' => $shipping_place_data['address1'],
                    'address2' => $shipping_place_data['address2']
                )
            )
        );
        
        coupang_log('INFO', '출고지 등록 요청', array('shipping_place_name' => $shipping_place_data['name']));
        
        $result = $this->makeRequest('POST', $endpoint, $data);
        
        // 결과 처리 및 로그
        if ($result['success']) {
            coupang_log('INFO', '출고지 등록 성공', array(
                'shipping_place_name' => $shipping_place_data['name'],
                'response_data' => $result['data']
            ));
            
            // 로컬 DB에 저장
            $this->saveShippingPlaceToLocal($result['data'], 'OUTBOUND');
        } else {
            coupang_log('ERROR', '출고지 등록 실패', array(
                'shipping_place_name' => $shipping_place_data['name'],
                'error' => $result['message']
            ));
        }
        
        return $result;
    }

    /**
     * 반품지 등록
     * @param array $return_place_data 반품지 정보
     * @return array API 응답 결과
     */
    public function createReturnShippingPlace($return_place_data) {
        $endpoint = '/v2/providers/openapi/apis/api/v1/marketplace/shipping-places';
        
        // 입력 데이터 검증
        $required_fields = array('name', 'company_name', 'contact_name', 'company_phone', 
                               'zipcode', 'address1', 'address2');
        foreach ($required_fields as $field) {
            if (empty($return_place_data[$field])) {
                return array('success' => false, 'error' => "필수 필드 누락: {$field}");
            }
        }
        
        $data = array(
            'shippingPlaceName' => $return_place_data['name'],
            'placeAddresses' => array(
                array(
                    'companyContactNumber' => $return_place_data['company_phone'],
                    'phoneNumber2' => isset($return_place_data['phone2']) ? $return_place_data['phone2'] : '',
                    'addressType' => 'RETURN',
                    'companyName' => $return_place_data['company_name'],
                    'name' => $return_place_data['contact_name'],
                    'phoneNumber1' => $return_place_data['phone1'],
                    'zipCode' => $return_place_data['zipcode'],
                    'address1' => $return_place_data['address1'],
                    'address2' => $return_place_data['address2']
                )
            )
        );
        
        coupang_log('INFO', '반품지 등록 요청', array('return_place_name' => $return_place_data['name']));
        
        $result = $this->makeRequest('POST', $endpoint, $data);
        
        // 결과 처리 및 로그
        if ($result['success']) {
            coupang_log('INFO', '반품지 등록 성공', array(
                'return_place_name' => $return_place_data['name'],
                'response_data' => $result['data']
            ));
            
            // 로컬 DB에 저장
            $this->saveShippingPlaceToLocal($result['data'], 'RETURN');
        } else {
            coupang_log('ERROR', '반품지 등록 실패', array(
                'return_place_name' => $return_place_data['name'],
                'error' => $result['message']
            ));
        }
        
        return $result;
    }

    /**
     * 출고지/반품지 목록 조회
     * @param string $address_type 'OUTBOUND' 또는 'RETURN' 또는 'ALL'
     * @return array API 응답 결과
     */
    public function getShippingPlaces($address_type = 'ALL') {
        $endpoint = '/v2/providers/openapi/apis/api/v1/marketplace/shipping-places';
        
        coupang_log('INFO', '출고지/반품지 목록 조회', array('address_type' => $address_type));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success'] && isset($result['data']['data'])) {
            $shipping_places = $result['data']['data'];
            
            // 타입별 필터링
            if ($address_type !== 'ALL') {
                $filtered_places = array();
                foreach ($shipping_places as $place) {
                    if (isset($place['placeAddresses'])) {
                        foreach ($place['placeAddresses'] as $address) {
                            if ($address['addressType'] === $address_type) {
                                $filtered_places[] = $place;
                                break;
                            }
                        }
                    }
                }
                $shipping_places = $filtered_places;
            }
            
            coupang_log('INFO', '출고지/반품지 조회 성공', array(
                'address_type' => $address_type,
                'count' => count($shipping_places)
            ));
            
            // 로컬 DB에 동기화
            $this->syncShippingPlacesToLocal($shipping_places);
            
            return array(
                'success' => true,
                'data' => $shipping_places,
                'count' => count($shipping_places)
            );
        } else {
            coupang_log('ERROR', '출고지/반품지 조회 실패', array(
                'error' => $result['message']
            ));
            return $result;
        }
    }

    /**
     * 특정 출고지/반품지 상세 조회
     * @param string $shipping_place_code 출고지/반품지 코드
     * @return array API 응답 결과
     */
    public function getShippingPlaceDetail($shipping_place_code) {
        $endpoint = "/v2/providers/openapi/apis/api/v1/marketplace/shipping-places/{$shipping_place_code}";
        
        coupang_log('INFO', '출고지/반품지 상세 조회', array('shipping_place_code' => $shipping_place_code));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success']) {
            coupang_log('INFO', '출고지/반품지 상세 조회 성공', array(
                'shipping_place_code' => $shipping_place_code,
                'response_data' => $result['data']
            ));
        } else {
            coupang_log('ERROR', '출고지/반품지 상세 조회 실패', array(
                'shipping_place_code' => $shipping_place_code,
                'error' => $result['message']
            ));
        }
        
        return $result;
    }

    /**
     * 출고지/반품지 수정
     * @param string $shipping_place_code 출고지/반품지 코드
     * @param array $update_data 수정할 데이터
     * @return array API 응답 결과
     */
    public function updateShippingPlace($shipping_place_code, $update_data) {
        $endpoint = "/v2/providers/openapi/apis/api/v1/marketplace/shipping-places/{$shipping_place_code}";
        
        coupang_log('INFO', '출고지/반품지 수정 요청', array(
            'shipping_place_code' => $shipping_place_code,
            'update_fields' => array_keys($update_data)
        ));
        
        $result = $this->makeRequest('PUT', $endpoint, $update_data);
        
        if ($result['success']) {
            coupang_log('INFO', '출고지/반품지 수정 성공', array(
                'shipping_place_code' => $shipping_place_code,
                'response_data' => $result['data']
            ));
            
            // 로컬 DB 업데이트
            $this->updateShippingPlaceInLocal($shipping_place_code, $update_data);
        } else {
            coupang_log('ERROR', '출고지/반품지 수정 실패', array(
                'shipping_place_code' => $shipping_place_code,
                'error' => $result['message']
            ));
        }
        
        return $result;
    }

    /**
     * 출고지/반품지 삭제
     * @param string $shipping_place_code 출고지/반품지 코드
     * @return array API 응답 결과
     */
    public function deleteShippingPlace($shipping_place_code) {
        $endpoint = "/v2/providers/openapi/apis/api/v1/marketplace/shipping-places/{$shipping_place_code}";
        
        coupang_log('INFO', '출고지/반품지 삭제 요청', array('shipping_place_code' => $shipping_place_code));
        
        $result = $this->makeRequest('DELETE', $endpoint);
        
        if ($result['success']) {
            coupang_log('INFO', '출고지/반품지 삭제 성공', array(
                'shipping_place_code' => $shipping_place_code
            ));
            
            // 로컬 DB에서 삭제
            $this->deleteShippingPlaceFromLocal($shipping_place_code);
        } else {
            coupang_log('ERROR', '출고지/반품지 삭제 실패', array(
                'shipping_place_code' => $shipping_place_code,
                'error' => $result['message']
            ));
        }
        
        return $result;
    }

    // ===================== 출고지 관리 로컬 DB 헬퍼 메서드들 =====================

    /**
     * 출고지/반품지 정보를 로컬 DB에 저장
     * @param array $shipping_place_data 쿠팡 API 응답 데이터
     * @param string $address_type 주소 타입 ('OUTBOUND' 또는 'RETURN')
     * @return bool 저장 성공 여부
     */
    private function saveShippingPlaceToLocal($shipping_place_data, $address_type) {
        global $g5;
        
        if (!isset($shipping_place_data['shippingPlaceCode']) || !isset($shipping_place_data['shippingPlaceName'])) {
            return false;
        }
        
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_shipping_places SET 
                shipping_place_code = '" . addslashes($shipping_place_data['shippingPlaceCode']) . "',
                shipping_place_name = '" . addslashes($shipping_place_data['shippingPlaceName']) . "',
                address_type = '" . addslashes($address_type) . "',
                place_data = '" . addslashes(json_encode($shipping_place_data, JSON_UNESCAPED_UNICODE)) . "',
                status = 'ACTIVE',
                last_sync_date = NOW(),
                created_date = NOW()
                ON DUPLICATE KEY UPDATE
                shipping_place_name = VALUES(shipping_place_name),
                place_data = VALUES(place_data),
                status = VALUES(status),
                last_sync_date = NOW()";
        
        return sql_query($sql);
    }

    /**
     * 출고지/반품지 목록을 로컬 DB에 동기화
     * @param array $shipping_places 쿠팡 API 응답 데이터
     * @return int 동기화된 개수
     */
    private function syncShippingPlacesToLocal($shipping_places) {
        $sync_count = 0;
        
        foreach ($shipping_places as $place) {
            if (isset($place['placeAddresses'])) {
                foreach ($place['placeAddresses'] as $address) {
                    if (isset($address['addressType'])) {
                        if ($this->saveShippingPlaceToLocal($place, $address['addressType'])) {
                            $sync_count++;
                        }
                    }
                }
            }
        }
        
        coupang_log('INFO', '출고지/반품지 로컬 동기화 완료', array('sync_count' => $sync_count));
        
        return $sync_count;
    }

    /**
     * 로컬 DB의 출고지/반품지 정보 업데이트
     * @param string $shipping_place_code 출고지/반품지 코드
     * @param array $update_data 업데이트 데이터
     * @return bool 업데이트 성공 여부
     */
    private function updateShippingPlaceInLocal($shipping_place_code, $update_data) {
        global $g5;
        
        $update_fields = array();
        
        if (isset($update_data['shippingPlaceName'])) {
            $update_fields[] = "shipping_place_name = '" . addslashes($update_data['shippingPlaceName']) . "'";
        }
        
        if (isset($update_data['status'])) {
            $update_fields[] = "status = '" . addslashes($update_data['status']) . "'";
        }
        
        $update_fields[] = "place_data = '" . addslashes(json_encode($update_data, JSON_UNESCAPED_UNICODE)) . "'";
        $update_fields[] = "last_sync_date = NOW()";
        
        if (empty($update_fields)) {
            return false;
        }
        
        $sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_shipping_places SET " . 
               implode(', ', $update_fields) . " 
               WHERE shipping_place_code = '" . addslashes($shipping_place_code) . "'";
        
        return sql_query($sql);
    }

    /**
     * 로컬 DB에서 출고지/반품지 삭제
     * @param string $shipping_place_code 출고지/반품지 코드
     * @return bool 삭제 성공 여부
     */
    private function deleteShippingPlaceFromLocal($shipping_place_code) {
        global $g5;
        
        $sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_shipping_places SET 
                status = 'DELETED',
                last_sync_date = NOW()
                WHERE shipping_place_code = '" . addslashes($shipping_place_code) . "'";
        
        return sql_query($sql);
    }

    /**
     * 로컬 DB에서 출고지 목록 조회
     * @param string $address_type 주소 타입 ('OUTBOUND', 'RETURN', 'ALL')
     * @param string $status 상태 ('ACTIVE', 'DELETED', 'ALL')
     * @return array 출고지 목록
     */
    public function getLocalShippingPlaces($address_type = 'ALL', $status = 'ACTIVE') {
        global $g5;
        
        $where_conditions = array();
        
        if ($address_type !== 'ALL') {
            $where_conditions[] = "address_type = '" . addslashes($address_type) . "'";
        }
        
        if ($status !== 'ALL') {
            $where_conditions[] = "status = '" . addslashes($status) . "'";
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_shipping_places {$where_clause} 
                ORDER BY address_type, shipping_place_name";
        
        $result = sql_query($sql);
        $shipping_places = array();
        
        while ($row = sql_fetch_array($result)) {
            $row['place_data_decoded'] = json_decode($row['place_data'], true);
            $shipping_places[] = $row;
        }
        
        return $shipping_places;
    }

    /**
     * 출고지/반품지 동기화 (크론용)
     * @return array 동기화 결과
     */
    public function syncShippingPlacesFromCoupang() {
        $start_time = microtime(true);
        
        coupang_log('INFO', '출고지/반품지 동기화 시작');
        
        try {
            // 쿠팡에서 출고지/반품지 목록 가져오기
            $result = $this->getShippingPlaces('ALL');
            
            if (!$result['success']) {
                throw new Exception('쿠팡 출고지/반품지 조회 실패: ' . $result['message']);
            }
            
            $sync_count = isset($result['count']) ? $result['count'] : 0;
            $execution_time = microtime(true) - $start_time;
            
            coupang_log('INFO', '출고지/반품지 동기화 완료', array(
                'sync_count' => $sync_count,
                'execution_time' => round($execution_time, 2) . 's'
            ));
            
            return array(
                'success' => true,
                'sync_count' => $sync_count,
                'execution_time' => $execution_time,
                'message' => "출고지/반품지 {$sync_count}개 동기화 완료"
            );
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            
            coupang_log('ERROR', '출고지/반품지 동기화 실패', array(
                'error' => $e->getMessage(),
                'execution_time' => round($execution_time, 2) . 's'
            ));
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => $execution_time
            );
        }
    }

    // ===================== 기존 메서드들 (동기화 관련) =====================
    // 여기에는 기존의 동기화 메서드들이 그대로 유지됩니다
    
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

    // ===================== 배치 처리 메서드들 =====================

    /**
     * 카테고리 추천만 배치로 실행 (등록 전 미리 확인용)
     */
    public function batchGetCategoryRecommendations($limit = 20) {
        global $g5;
        
        $result = array(
            'success' => true,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'recommendations' => array()
        );
        
        try {
            // 카테고리 매핑이 없는 상품들 조회
            $sql = "SELECT i.* FROM {$g5['g5_shop_item_table']} i
                    LEFT JOIN " . G5_TABLE_PREFIX . "coupang_category_map m ON i.it_id = m.youngcart_ca_id
                    WHERE i.it_use = '1' 
                    AND m.youngcart_ca_id IS NULL
                    ORDER BY i.it_update_time DESC
                    LIMIT " . intval($limit);
            
            $items_result = sql_query($sql);
            
            while ($item = sql_fetch_array($items_result)) {
                $result['processed']++;
                
                $category_result = $this->getCategoryRecommendationFromYoungcartItem($item);
                
                if ($category_result['success']) {
                    $result['succeeded']++;
                    
                    // 추천 결과 저장
                    $this->saveCategoryMapping(
                        $item['it_id'],
                        $category_result['data']['category_id'],
                        $category_result['data']['category_name'],
                        $category_result['data']['confidence']
                    );
                    
                    $result['recommendations'][] = array(
                        'it_id' => $item['it_id'],
                        'it_name' => $item['it_name'],
                        'category_id' => $category_result['data']['category_id'],
                        'category_name' => $category_result['data']['category_name'],
                        'confidence' => $category_result['data']['confidence']
                    );
                } else {
                    $result['failed']++;
                    $result['recommendations'][] = array(
                        'it_id' => $item['it_id'],
                        'it_name' => $item['it_name'],
                        'error' => $category_result['error']
                    );
                }
                
                // API 호출 제한 준수
                sleep(COUPANG_API_DELAY);
            }
            
            coupang_log('INFO', '배치 카테고리 추천 완료', array(
                'processed' => $result['processed'],
                'succeeded' => $result['succeeded'],
                'failed' => $result['failed']
            ));
            
        } catch (Exception $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
            
            coupang_log('ERROR', '배치 카테고리 추천 중 오류', array(
                'error' => $e->getMessage()
            ));
        }
        
        return $result;
    }

    /**
     * 카테고리 캐시 정리 (오래된 캐시 삭제)
     */
    public function cleanupCategoryCache($days = 7) {
        global $g5;
        
        $sql = "DELETE FROM " . G5_TABLE_PREFIX . "coupang_category_cache 
                WHERE created_date < DATE_SUB(NOW(), INTERVAL " . intval($days) . " DAY)";
        
        $result = sql_query($sql);
        $affected_rows = sql_affected_rows();
        
        coupang_log('INFO', '카테고리 캐시 정리 완료', array(
            'deleted_rows' => $affected_rows,
            'days' => $days
        ));
        
        return $affected_rows;
    }
}

// ===================== 전역 헬퍼 함수들 =====================

/**
 * Coupang API 인스턴스 생성
 */
function get_coupang_api() {
    $config = array(
        'access_key' => COUPANG_ACCESS_KEY,
        'secret_key' => COUPANG_SECRET_KEY,
        'vendor_id'  => COUPANG_VENDOR_ID
    );
    return new CoupangAPI($config);
}

/**
 * 크론 실행 상태 기록
 */
function monitor_cron_execution($cron_type, $status, $message = '', $execution_duration = null) {
    global $g5;
    $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_cron_log SET 
            cron_type = '" . addslashes($cron_type) . "',
            status = '" . addslashes($status) . "',
            message = '" . addslashes($message) . "'";
    
    if ($execution_duration !== null) {
        $sql .= ", execution_duration = " . floatval($execution_duration);
    }
    
    $sql .= ", created_date = NOW()";
    return sql_query($sql);
}

// ===================== 개선된 크론 래퍼 함수들 =====================

/**
 * 크론 동기화 함수들 (기존 유지)
 */
function cron_sync_orders_from_coupang($hours = 1) {
    $coupang_api = get_coupang_api();
    return $coupang_api->syncOrdersFromCoupang($hours);
}

function cron_sync_cancelled_orders_from_coupang($hours = 1) {
    $coupang_api = get_coupang_api();
    return $coupang_api->syncCancelledOrdersFromCoupang($hours);
}

function cron_sync_order_status_to_coupang() {
    $coupang_api = get_coupang_api();
    return $coupang_api->syncOrderStatusToCoupang();
}

function cron_sync_products_to_coupang() {
    $coupang_api = get_coupang_api();
    return $coupang_api->syncProductsToCoupang();
}

function cron_sync_product_status_to_coupang() {
    $coupang_api = get_coupang_api();
    return $coupang_api->syncProductStatusToCoupang();
}

function cron_sync_stock_to_coupang() {
    $coupang_api = get_coupang_api();
    return $coupang_api->syncStockToCoupang();
}

/**
 * 새로운 카테고리 관련 크론 함수들
 */
function cron_batch_category_recommendations($limit = 20) {
    $coupang_api = get_coupang_api();
    return $coupang_api->batchGetCategoryRecommendations($limit);
}

function cron_cleanup_category_cache($days = 7) {
    $coupang_api = get_coupang_api();
    return $coupang_api->cleanupCategoryCache($days);
}

?>