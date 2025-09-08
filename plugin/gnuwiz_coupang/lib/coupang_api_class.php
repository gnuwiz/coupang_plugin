<?php
/**
 * 개선된 쿠팡 API 연동 클래스 (v2.1 - 통합 관리 시스템)
 * 경로: /plugin/gnuwiz_coupang/lib/coupang_api_class.php
 * 용도: 쿠팡 API 전체 기능 통합 관리 (카테고리, 출고지, 상품, 주문)
 * 
 * 기능별 구성:
 * - 섹션 1: 클래스 기본 구조 (생성자, HTTP 요청)
 * - 섹션 2: 설정 및 검증 메서드들  
 * - 섹션 3: 카테고리 관리 메서드들
 * - 섹션 4: 출고지/반품지 관리 메서드들
 * - 섹션 5: 상품 등록/관리 메서드들
 * - 섹션 6: 주문 관리 메서드들
 * - 섹션 7: 전역 헬퍼 함수들
 */

if (!defined('_GNUBOARD_')) exit; // 직접 접근 금지

class CoupangAPI {
    // ==================== 프라이빗 속성들 ====================
    private $access_key;
    private $secret_key;  
    private $vendor_id;
    private $base_url;
    
    // 요청 카운터 및 제한 관리
    private $request_count = 0;
    private $last_request_time = 0;

    // ==================== 생성자 ====================
    /**
     * CoupangAPI 생성자
     * @param array $config API 설정 배열
     */
    public function __construct($config = array()) {
        // 필수 설정값 검증
        if (empty($config['access_key']) || empty($config['secret_key']) || empty($config['vendor_id'])) {
            throw new Exception('쿠팡 API 필수 설정값이 누락되었습니다. (access_key, secret_key, vendor_id)');
        }
        
        $this->access_key = $config['access_key'];
        $this->secret_key = $config['secret_key'];
        $this->vendor_id = $config['vendor_id'];
        $this->base_url = isset($config['base_url']) ? $config['base_url'] : COUPANG_API_BASE_URL;
        
        // 초기화 로그
        $this->log('INFO', 'CoupangAPI 인스턴스 초기화 완료', array(
            'vendor_id' => $this->vendor_id,
            'base_url' => $this->base_url
        ));
    }

    // ==================== HTTP 요청 관련 Private 메서드들 ====================
    
    /**
     * API 요청 헤더 생성 (HMAC-SHA256 인증)
     * @param string $method HTTP 메서드 (GET, POST, PUT, DELETE)
     * @param string $path API 경로
     * @param string $query 쿼리 스트링
     * @return array HTTP 헤더 배열
     */
    private function generateHeaders($method, $path, $query = '') {
        $datetime = gmdate('ymd\THis\Z');
        $message = $datetime . $method . $path . $query;
        $signature = base64_encode(hash_hmac('sha256', $message, $this->secret_key, true));
        
        return array(
            'Content-Type: application/json;charset=UTF-8',
            'Authorization: CEA algorithm=HmacSHA256, access-key=' . $this->access_key . 
                           ', signed-date=' . $datetime . ', signature=' . $signature
        );
    }

    /**
     * API 요청 제한 체크 및 지연 처리
     * @return void
     */
    private function handleRateLimit() {
        $current_time = time();
        
        // API 제한: 초당 요청 수 제한
        if ($current_time === $this->last_request_time) {
            $this->request_count++;
            if ($this->request_count >= 10) { // 초당 최대 10회
                sleep(1);
                $this->request_count = 0;
            }
        } else {
            $this->request_count = 1;
            $this->last_request_time = $current_time;
        }
        
        // 기본 API 지연 (설정값)
        if (defined('COUPANG_API_DELAY') && COUPANG_API_DELAY > 0) {
            sleep(COUPANG_API_DELAY);
        }
    }

    // ==================== Public HTTP 요청 메서드 ====================
    
    /**
     * HTTP 요청 실행 (개선된 버전)
     * @param string $method HTTP 메서드
     * @param string $endpoint API 엔드포인트
     * @param array|null $data 요청 데이터
     * @param array $options 추가 옵션
     * @return array 응답 결과
     */
    public function makeRequest($method, $endpoint, $data = null, $options = array()) {
        // 기본 옵션 설정
        $default_options = array(
            'timeout' => COUPANG_TIMEOUT,
            'retry' => COUPANG_MAX_RETRY,
            'rate_limit' => true
        );
        $options = array_merge($default_options, $options);
        
        // 요청 제한 처리
        if ($options['rate_limit']) {
            $this->handleRateLimit();
        }
        
        // URL 구성
        $url = $this->base_url . $endpoint;
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        
        // 헤더 생성
        $headers = $this->generateHeaders($method, $path, $query ? '?' . $query : '');
        
        // 요청 로그
        $this->log('DEBUG', 'API 요청 시작', array(
            'method' => $method,
            'endpoint' => $endpoint,
            'has_data' => !empty($data)
        ));
        
        // cURL 요청 실행 (재시도 로직 포함)
        $retry_count = 0;
        $max_retry = max(1, intval($options['retry']));
        
        do {
            $result = $this->executeCurlRequest($url, $method, $headers, $data, $options);
            
            // 성공 또는 재시도 불가능한 오류
            if ($result['success'] || !$this->shouldRetry($result, $retry_count)) {
                break;
            }
            
            $retry_count++;
            
            // 재시도 간 지연
            if ($retry_count < $max_retry) {
                $delay = min(pow(2, $retry_count), 10); // 지수적 백오프 (최대 10초)
                $this->log('WARNING', 'API 요청 재시도', array(
                    'retry_count' => $retry_count,
                    'delay' => $delay
                ));
                sleep($delay);
            }
            
        } while ($retry_count < $max_retry);
        
        // 응답 로그
        $this->log($result['success'] ? 'INFO' : 'ERROR', 'API 요청 완료', array(
            'method' => $method,
            'endpoint' => $endpoint,
            'success' => $result['success'],
            'http_code' => $result['http_code'],
            'retry_count' => $retry_count
        ));
        
        return $result;
    }

    /**
     * cURL 요청 실행
     * @param string $url 요청 URL
     * @param string $method HTTP 메서드
     * @param array $headers HTTP 헤더
     * @param array|null $data 요청 데이터
     * @param array $options 옵션
     * @return array 응답 결과
     */
    private function executeCurlRequest($url, $method, $headers, $data, $options) {
        $ch = curl_init();
        
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => intval($options['timeout']),
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3
        ));
        
        // POST/PUT 데이터 설정
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        // 요청 실행
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // cURL 오류 처리
        if ($error) {
            return array(
                'success' => false,
                'error' => 'HTTP 요청 오류: ' . $error,
                'http_code' => 0,
                'data' => null,
                'message' => $error
            );
        }
        
        // JSON 응답 파싱
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'JSON 파싱 오류: ' . json_last_error_msg(),
                'http_code' => $http_code,
                'data' => $response,
                'message' => 'Invalid JSON response'
            );
        }
        
        return array(
            'success' => ($http_code >= 200 && $http_code < 300),
            'http_code' => $http_code,
            'data' => $result,
            'message' => isset($result['message']) ? $result['message'] : '',
            'raw_response' => $response
        );
    }

    /**
     * 재시도 여부 판단
     * @param array $result API 응답 결과
     * @param int $retry_count 현재 재시도 횟수
     * @return bool 재시도 필요 여부
     */
    private function shouldRetry($result, $retry_count) {
        $http_code = $result['http_code'];
        
        // 재시도 불가능한 HTTP 상태 코드들
        $non_retryable_codes = array(400, 401, 403, 404, 422);
        if (in_array($http_code, $non_retryable_codes)) {
            return false;
        }
        
        // 서버 오류 또는 네트워크 오류는 재시도
        if ($http_code >= 500 || $http_code === 0) {
            return true;
        }
        
        // 429 (Too Many Requests)는 재시도
        if ($http_code === 429) {
            return true;
        }
        
        return false;
    }

    /**
     * 로그 기록 (내부 헬퍼 메서드)
     * @param string $level 로그 레벨
     * @param string $message 로그 메시지
     * @param array $context 추가 컨텍스트
     */
    private function log($level, $message, $context = array()) {
        // coupang_log 함수가 있다면 사용, 없으면 error_log 사용
        if (function_exists('coupang_log')) {
            coupang_log($level, $message, $context);
        } else {
            $log_message = "[{$level}] {$message}";
            if (!empty($context)) {
                $log_message .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }
            error_log($log_message);
        }
    }

    // ==================== 섹션 2: 설정 및 검증 메서드들 ====================
    
    /**
     * 전체 쿠팡 API 설정 검증
     * @return array 검증 결과
     */
    public function validateAllConfig() {
        $validation_result = array(
            'success' => true,
            'errors' => array(),
            'warnings' => array(),
            'details' => array()
        );
        
        // 1. 기본 API 설정 검증
        $api_validation = $this->validateApiConfig();
        if (!$api_validation['success']) {
            $validation_result['success'] = false;
            $validation_result['errors'] = array_merge($validation_result['errors'], $api_validation['errors']);
        }
        $validation_result['details']['api_config'] = $api_validation;
        
        // 2. 출고지 설정 검증
        $shipping_validation = $this->validateShippingPlaceConfig();
        if (!$shipping_validation['success']) {
            $validation_result['warnings'] = array_merge($validation_result['warnings'], $shipping_validation['errors']);
        }
        $validation_result['details']['shipping_config'] = $shipping_validation;
        
        // 3. 상품 등록 설정 검증
        $product_validation = $this->validateProductConfig();
        if (!$product_validation['success']) {
            $validation_result['warnings'] = array_merge($validation_result['warnings'], $product_validation['errors']);
        }
        $validation_result['details']['product_config'] = $product_validation;
        
        // 4. 데이터베이스 구조 검증
        $db_validation = $this->validateDatabaseStructure();
        if (!$db_validation['success']) {
            $validation_result['success'] = false;
            $validation_result['errors'] = array_merge($validation_result['errors'], $db_validation['errors']);
        }
        $validation_result['details']['database_structure'] = $db_validation;
        
        // 전체 검증 결과 로그
        $this->log($validation_result['success'] ? 'INFO' : 'ERROR', '전체 설정 검증 완료', array(
            'success' => $validation_result['success'],
            'error_count' => count($validation_result['errors']),
            'warning_count' => count($validation_result['warnings'])
        ));
        
        return $validation_result;
    }

    /**
     * 기본 API 설정 검증
     * @return array 검증 결과
     */
    public function validateApiConfig() {
        $validation_result = array(
            'success' => true,
            'errors' => array(),
            'details' => array()
        );
        
        // Access Key 검증
        if (empty($this->access_key) || $this->access_key === 'YOUR_ACCESS_KEY_HERE') {
            $validation_result['success'] = false;
            $validation_result['errors'][] = 'ACCESS_KEY가 설정되지 않았습니다.';
        } else {
            $validation_result['details'][] = 'ACCESS_KEY: 설정됨';
        }
        
        // Secret Key 검증
        if (empty($this->secret_key) || $this->secret_key === 'YOUR_SECRET_KEY_HERE') {
            $validation_result['success'] = false;
            $validation_result['errors'][] = 'SECRET_KEY가 설정되지 않았습니다.';
        } else {
            $validation_result['details'][] = 'SECRET_KEY: 설정됨';
        }
        
        // Vendor ID 검증
        if (empty($this->vendor_id) || $this->vendor_id === 'YOUR_VENDOR_ID_HERE') {
            $validation_result['success'] = false;
            $validation_result['errors'][] = 'VENDOR_ID가 설정되지 않았습니다.';
        } else {
            $validation_result['details'][] = 'VENDOR_ID: ' . $this->vendor_id;
        }
        
        // cURL 확장 검증
        if (!function_exists('curl_init')) {
            $validation_result['success'] = false;
            $validation_result['errors'][] = 'cURL 확장이 설치되지 않았습니다.';
        } else {
            $validation_result['details'][] = 'cURL: 사용 가능';
        }
        
        // JSON 확장 검증
        if (!function_exists('json_encode') || !function_exists('json_decode')) {
            $validation_result['success'] = false;
            $validation_result['errors'][] = 'JSON 확장이 설치되지 않았습니다.';
        } else {
            $validation_result['details'][] = 'JSON: 사용 가능';
        }
        
        // 타임아웃 설정 검증
        if (!defined('COUPANG_TIMEOUT') || COUPANG_TIMEOUT < 5) {
            $validation_result['errors'][] = '타임아웃 설정이 너무 짧습니다. (최소 5초 권장)';
        } else {
            $validation_result['details'][] = 'TIMEOUT: ' . COUPANG_TIMEOUT . '초';
        }
        
        return $validation_result;
    }

    /**
     * 출고지 설정 검증
     * @return array 검증 결과
     */
    public function validateShippingPlaceConfig() {
        $validation_result = array(
            'success' => true,
            'errors' => array(),
            'details' => array()
        );
        
        // 기본 출고지 설정 확인
        if (!defined('COUPANG_DEFAULT_OUTBOUND_PLACE') || empty(COUPANG_DEFAULT_OUTBOUND_PLACE)) {
            $validation_result['success'] = false;
            $validation_result['errors'][] = '기본 출고지가 설정되지 않았습니다.';
        } else {
            $validation_result['details'][] = '기본 출고지: ' . COUPANG_DEFAULT_OUTBOUND_PLACE;
        }
        
        // 기본 반품지 설정 확인
        if (!defined('COUPANG_DEFAULT_RETURN_PLACE') || empty(COUPANG_DEFAULT_RETURN_PLACE)) {
            $validation_result['errors'][] = '기본 반품지가 설정되지 않았습니다. (선택사항)';
        } else {
            $validation_result['details'][] = '기본 반품지: ' . COUPANG_DEFAULT_RETURN_PLACE;
        }
        
        // 출고지 동기화 간격 확인
        if (!defined('COUPANG_SHIPPING_SYNC_INTERVAL') || COUPANG_SHIPPING_SYNC_INTERVAL < 1) {
            $validation_result['errors'][] = '출고지 동기화 간격이 설정되지 않았습니다.';
        } else {
            $validation_result['details'][] = '동기화 간격: ' . COUPANG_SHIPPING_SYNC_INTERVAL . '시간';
        }
        
        // 출고지 테이블 존재 확인
        $shipping_table_exists = $this->checkTableExists(G5_TABLE_PREFIX . 'coupang_shipping_places');
        if (!$shipping_table_exists) {
            $validation_result['success'] = false;
            $validation_result['errors'][] = '출고지 테이블이 존재하지 않습니다.';
        } else {
            $validation_result['details'][] = '출고지 테이블: 존재함';
        }
        
        return $validation_result;
    }

    /**
     * 상품 등록 설정 검증
     * @return array 검증 결과
     */
    public function validateProductConfig() {
        $validation_result = array(
            'success' => true,
            'errors' => array(),
            'details' => array()
        );
        
        // 기본 출고일 설정 확인
        if (!defined('COUPANG_DEFAULT_SHIPPING_TIME') || COUPANG_DEFAULT_SHIPPING_TIME < 1) {
            $validation_result['errors'][] = '기본 출고일이 올바르게 설정되지 않았습니다.';
        } else {
            $validation_result['details'][] = '기본 출고일: ' . COUPANG_DEFAULT_SHIPPING_TIME . '일';
        }
        
        // 자동 승인 요청 설정 확인
        if (!defined('COUPANG_AUTO_APPROVAL_REQUEST')) {
            $validation_result['errors'][] = '자동 승인 요청 설정이 정의되지 않았습니다.';
        } else {
            $approval_status = COUPANG_AUTO_APPROVAL_REQUEST ? '활성화' : '비활성화';
            $validation_result['details'][] = '자동 승인 요청: ' . $approval_status;
        }
        
        // 기본 Vendor User ID 확인
        if (!defined('COUPANG_DEFAULT_VENDOR_USER_ID') || empty(COUPANG_DEFAULT_VENDOR_USER_ID)) {
            $validation_result['errors'][] = '기본 쿠팡 Wing User ID가 설정되지 않았습니다.';
        } else {
            $validation_result['details'][] = 'Wing User ID: ' . COUPANG_DEFAULT_VENDOR_USER_ID;
        }
        
        // 상품 매핑 테이블 존재 확인
        $product_table_exists = $this->checkTableExists(G5_TABLE_PREFIX . 'coupang_item_map');
        if (!$product_table_exists) {
            $validation_result['success'] = false;
            $validation_result['errors'][] = '상품 매핑 테이블이 존재하지 않습니다.';
        } else {
            $validation_result['details'][] = '상품 매핑 테이블: 존재함';
        }
        
        return $validation_result;
    }

    /**
     * 데이터베이스 구조 검증
     * @return array 검증 결과
     */
    public function validateDatabaseStructure() {
        $validation_result = array(
            'success' => true,
            'errors' => array(),
            'details' => array()
        );
        
        // 필수 테이블들 확인
        $required_tables = array(
            'coupang_item_map' => '상품 매핑',
            'coupang_category_map' => '카테고리 매핑',
            'coupang_category_cache' => '카테고리 캐시',
            'coupang_order_log' => '주문 로그',
            'coupang_cron_log' => '크론 로그',
            'coupang_shipping_places' => '출고지 정보'
        );
        
        foreach ($required_tables as $table => $description) {
            $table_name = G5_TABLE_PREFIX . $table;
            if ($this->checkTableExists($table_name)) {
                $validation_result['details'][] = "{$description} 테이블: 존재함";
            } else {
                $validation_result['success'] = false;
                $validation_result['errors'][] = "{$description} 테이블이 존재하지 않습니다. ({$table_name})";
            }
        }
        
        // 영카트 주문 테이블 쿠팡 필드 확인
        $order_fields = $this->checkOrderTableFields();
        if ($order_fields['success']) {
            $validation_result['details'][] = '주문 테이블 쿠팡 필드: 존재함';
        } else {
            $validation_result['errors'] = array_merge($validation_result['errors'], $order_fields['errors']);
        }
        
        return $validation_result;
    }

    /**
     * 출고지 데이터 유효성 검증
     * @param array $shipping_data 출고지 데이터
     * @return array 검증 결과
     */
    public function validateShippingPlaceData($shipping_data) {
        $validation_result = array(
            'success' => true,
            'errors' => array(),
            'details' => array()
        );
        
        // 필수 필드 확인
        $required_fields = array(
            'name' => '출고지명',
            'company_name' => '회사명',
            'contact_name' => '담당자명',
            'company_phone' => '회사 전화번호',
            'zipcode' => '우편번호',
            'address1' => '주소1',
            'address2' => '주소2'
        );
        
        foreach ($required_fields as $field => $field_name) {
            if (empty($shipping_data[$field])) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = "{$field_name}이(가) 비어있습니다.";
            }
        }
        
        // 전화번호 형식 검증
        if (!empty($shipping_data['company_phone'])) {
            $phone = preg_replace('/[^0-9]/', '', $shipping_data['company_phone']);
            if (strlen($phone) < 9 || strlen($phone) > 11) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '회사 전화번호 형식이 올바르지 않습니다.';
            }
        }
        
        // 우편번호 형식 검증
        if (!empty($shipping_data['zipcode'])) {
            $zipcode = preg_replace('/[^0-9]/', '', $shipping_data['zipcode']);
            if (strlen($zipcode) !== 5) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '우편번호는 5자리 숫자여야 합니다.';
            }
        }
        
        // 이름 길이 검증
        if (!empty($shipping_data['name']) && strlen($shipping_data['name']) > 50) {
            $validation_result['success'] = false;
            $validation_result['errors'][] = '출고지명은 50자를 초과할 수 없습니다.';
        }
        
        return $validation_result;
    }

    /**
     * 상품 데이터 유효성 검증
     * @param array $product_data 상품 데이터
     * @return array 검증 결과
     */
    public function validateProductData($product_data) {
        $validation_result = array(
            'success' => true,
            'errors' => array(),
            'details' => array()
        );
        
        // 필수 필드 확인
        $required_fields = array(
            'displayName' => '상품명',
            'salePrice' => '판매가격',
            'originalPrice' => '정가',
            'maximumBuyCount' => '최대구매수량',
            'outboundShippingTimeDay' => '출고예정일'
        );
        
        foreach ($required_fields as $field => $field_name) {
            if (!isset($product_data[$field]) || $product_data[$field] === '') {
                $validation_result['success'] = false;
                $validation_result['errors'][] = "{$field_name}이(가) 설정되지 않았습니다.";
            }
        }
        
        // 가격 검증
        if (isset($product_data['salePrice'])) {
            if (!is_numeric($product_data['salePrice']) || $product_data['salePrice'] < 0) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '판매가격은 0 이상의 숫자여야 합니다.';
            }
        }
        
        if (isset($product_data['originalPrice'])) {
            if (!is_numeric($product_data['originalPrice']) || $product_data['originalPrice'] < 0) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '정가는 0 이상의 숫자여야 합니다.';
            }
        }
        
        // 할인율 검증
        if (isset($product_data['salePrice']) && isset($product_data['originalPrice'])) {
            if ($product_data['salePrice'] > $product_data['originalPrice']) {
                $validation_result['errors'][] = '판매가격이 정가보다 높습니다.';
            }
        }
        
        // 재고 수량 검증
        if (isset($product_data['maximumBuyCount'])) {
            if (!is_numeric($product_data['maximumBuyCount']) || $product_data['maximumBuyCount'] < 1) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '최대구매수량은 1 이상이어야 합니다.';
            }
        }
        
        // 출고예정일 검증
        if (isset($product_data['outboundShippingTimeDay'])) {
            if (!is_numeric($product_data['outboundShippingTimeDay']) || $product_data['outboundShippingTimeDay'] < 1) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '출고예정일은 1일 이상이어야 합니다.';
            }
        }
        
        return $validation_result;
    }

    // ==================== Private 헬퍼 메서드들 ====================
    
    /**
     * 테이블 존재 여부 확인
     * @param string $table_name 테이블명
     * @return bool 존재 여부
     */
    private function checkTableExists($table_name) {
        $sql = "SHOW TABLES LIKE '" . addslashes($table_name) . "'";
        $result = sql_query($sql);
        return sql_num_rows($result) > 0;
    }

    /**
     * 주문 테이블 쿠팡 필드 확인
     * @return array 확인 결과
     */
    private function checkOrderTableFields() {
        $result = array(
            'success' => true,
            'errors' => array()
        );
        
        $required_fields = array(
            'coupang_order_id' => '쿠팡 주문 ID',
            'coupang_vendor_id' => '쿠팡 판매자 ID',
            'coupang_sync_status' => '동기화 상태'
        );
        
        foreach ($required_fields as $field => $description) {
            $sql = "SHOW COLUMNS FROM " . G5_TABLE_PREFIX . "yc_order LIKE '" . addslashes($field) . "'";
            $field_result = sql_query($sql);
            
            if (sql_num_rows($field_result) === 0) {
                $result['success'] = false;
                $result['errors'][] = "주문 테이블에 {$description} 필드가 없습니다. ({$field})";
            }
        }
        
        return $result;
    }

    // ==================== 섹션 3: 카테고리 관리 메서드들 ====================
    
    /**
     * 쿠팡 카테고리 추천 (개선된 버전)
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
                'data' => null,
                'confidence' => 0
            );
        }
        
        // 기본 옵션 설정
        $default_options = array(
            'product_description' => '',
            'brand' => '',
            'attributes' => array(),
            'seller_sku_code' => '',
            'cache' => true,
            'retry' => 3,
            'save_to_db' => true
        );
        $options = array_merge($default_options, $options);
        
        // 캐시 확인 (선택적)
        if ($options['cache']) {
            $cache_key = $this->generateCategoryCacheKey($product_name, $options);
            $cached_result = $this->getCachedCategoryRecommendation($cache_key);
            if ($cached_result !== null) {
                $this->log('DEBUG', '카테고리 추천 캐시 히트', array('product_name' => $product_name));
                return $cached_result;
            }
        }
        
        // API 요청 데이터 구성
        $endpoint = COUPANG_API_CATEGORY_PREDICT;
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
        $this->log('INFO', '카테고리 추천 API 호출', array(
            'product_name' => $product_name,
            'data_keys' => array_keys($data)
        ));
        
        $result = $this->makeRequest('POST', $endpoint, $data, array('retry' => $options['retry']));
        
        // 성공 응답 처리
        if ($result['success'] && isset($result['data']['data'])) {
            $recommendation_data = $result['data']['data'];
            
            // 응답 데이터 검증 및 가공
            $processed_result = $this->processCategoryRecommendation($recommendation_data, $product_name);
            
            // 캐시 저장 (성공 시에만)
            if ($options['cache'] && $processed_result['success']) {
                $this->setCachedCategoryRecommendation($cache_key, $processed_result);
            }
            
            // DB 저장 (선택적)
            if ($options['save_to_db'] && $processed_result['success']) {
                $this->saveCategoryRecommendationToDB($processed_result, $product_name, $options);
            }
            
            return $processed_result;
        }
        
        // 실패 처리
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '카테고리 추천 API 호출 실패';
        $this->log('ERROR', '카테고리 추천 실패', array(
            'product_name' => $product_name,
            'error' => $error_msg,
            'http_code' => $result['http_code']
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'data' => null,
            'confidence' => 0,
            'http_code' => $result['http_code']
        );
    }

    /**
     * 카테고리 메타정보 조회
     * @param string $category_id 카테고리 ID
     * @return array 메타정보 결과
     */
    public function getCategoryMetadata($category_id) {
        if (empty($category_id)) {
            return array(
                'success' => false,
                'error' => '카테고리 ID가 필요합니다.',
                'data' => null
            );
        }
        
        // 캐시 확인
        $cache_key = 'coupang_category_meta_' . $category_id;
        $cached_result = $this->getCachedCategoryMetadata($cache_key);
        if ($cached_result !== null) {
            return $cached_result;
        }
        
        $endpoint = COUPANG_API_CATEGORIES . '/' . $category_id;
        
        $this->log('INFO', '카테고리 메타정보 조회', array('category_id' => $category_id));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success'] && isset($result['data']['data'])) {
            $metadata = array(
                'success' => true,
                'data' => $result['data']['data'],
                'category_id' => $category_id,
                'attributes' => isset($result['data']['data']['attributes']) ? $result['data']['data']['attributes'] : array(),
                'notices' => isset($result['data']['data']['notices']) ? $result['data']['data']['notices'] : array()
            );
            
            // 캐시 저장 (24시간)
            $this->setCachedCategoryMetadata($cache_key, $metadata, 86400);
            
            return $metadata;
        }
        
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '카테고리 메타정보 조회 실패';
        $this->log('ERROR', '카테고리 메타정보 조회 실패', array(
            'category_id' => $category_id,
            'error' => $error_msg
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'data' => null
        );
    }

    /**
     * 카테고리 목록 조회
     * @param string|null $parent_id 부모 카테고리 ID (null이면 최상위)
     * @return array 카테고리 목록
     */
    public function getCategoryList($parent_id = null) {
        $endpoint = COUPANG_API_CATEGORIES;
        if (!empty($parent_id)) {
            $endpoint .= '?parentId=' . urlencode($parent_id);
        }
        
        // 캐시 확인
        $cache_key = 'coupang_category_list_' . ($parent_id ? $parent_id : 'root');
        $cached_result = $this->getCachedCategoryList($cache_key);
        if ($cached_result !== null) {
            return $cached_result;
        }
        
        $this->log('INFO', '카테고리 목록 조회', array('parent_id' => $parent_id));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success'] && isset($result['data']['data'])) {
            $category_list = array(
                'success' => true,
                'data' => $result['data']['data'],
                'parent_id' => $parent_id,
                'count' => count($result['data']['data'])
            );
            
            // 캐시 저장 (12시간)
            $this->setCachedCategoryList($cache_key, $category_list, 43200);
            
            return $category_list;
        }
        
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '카테고리 목록 조회 실패';
        $this->log('ERROR', '카테고리 목록 조회 실패', array(
            'parent_id' => $parent_id,
            'error' => $error_msg
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'data' => array()
        );
    }

    /**
     * 배치 카테고리 추천 처리
     * @param int $limit 처리할 상품 수
     * @return array 배치 처리 결과
     */
    public function batchGetCategoryRecommendations($limit = 20) {
        $this->log('INFO', '배치 카테고리 추천 시작', array('limit' => $limit));
        
        $result = array(
            'success' => true,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'recommendations' => array(),
            'errors' => array()
        );
        
        // 카테고리가 없는 영카트 상품들 조회
        $sql = "SELECT it_id, it_name, it_basic, it_maker, ca_id 
                FROM " . G5_TABLE_PREFIX . "yc_item it
                LEFT JOIN " . G5_TABLE_PREFIX . "coupang_category_map ccm 
                    ON it.ca_id = ccm.youngcart_ca_id
                WHERE ccm.coupang_category_id IS NULL 
                    AND it.it_use = '1'
                ORDER BY it.it_time DESC 
                LIMIT " . intval($limit);
        
        $query_result = sql_query($sql);
        
        while ($row = sql_fetch_array($query_result)) {
            $result['processed']++;
            
            // 상품별 카테고리 추천 실행
            $options = array(
                'product_description' => $row['it_basic'],
                'brand' => $row['it_maker'],
                'cache' => true,
                'save_to_db' => true
            );
            
            $recommendation = $this->getCategoryRecommendation($row['it_name'], $options);
            
            if ($recommendation['success']) {
                $result['succeeded']++;
                $result['recommendations'][] = array(
                    'it_id' => $row['it_id'],
                    'it_name' => $row['it_name'],
                    'category_id' => $recommendation['data']['category_id'],
                    'category_name' => $recommendation['data']['category_name'],
                    'confidence' => $recommendation['confidence']
                );
                
                // 영카트 상품에 카테고리 매핑 저장
                $this->saveCategoryMappingForItem($row['it_id'], $row['ca_id'], $recommendation);
                
            } else {
                $result['failed']++;
                $result['errors'][] = array(
                    'it_id' => $row['it_id'],
                    'it_name' => $row['it_name'],
                    'error' => $recommendation['error']
                );
            }
            
            // API 제한을 위한 지연
            sleep(1);
        }
        
        $this->log('INFO', '배치 카테고리 추천 완료', array(
            'processed' => $result['processed'],
            'succeeded' => $result['succeeded'],
            'failed' => $result['failed']
        ));
        
        return $result;
    }

    /**
     * 카테고리 캐시 정리
     * @param int $days 보관 기간 (일)
     * @return int 삭제된 레코드 수
     */
    public function cleanupCategoryCache($days = 7) {
        $this->log('INFO', '카테고리 캐시 정리 시작', array('days' => $days));
        
        $sql = "DELETE FROM " . G5_TABLE_PREFIX . "coupang_category_cache 
                WHERE created_date < DATE_SUB(NOW(), INTERVAL " . intval($days) . " DAY)";
        
        $result = sql_query($sql);
        $affected_rows = sql_affected_rows();
        
        $this->log('INFO', '카테고리 캐시 정리 완료', array(
            'deleted_rows' => $affected_rows,
            'days' => $days
        ));
        
        return $affected_rows;
    }

    // ==================== 카테고리 관련 Private 헬퍼 메서드들 ====================
    
    /**
     * 카테고리 추천 응답 데이터 처리
     * @param array $recommendation_data API 응답 데이터
     * @param string $product_name 원본 상품명
     * @return array 처리된 결과
     */
    private function processCategoryRecommendation($recommendation_data, $product_name) {
        // 응답 데이터 검증
        if (!isset($recommendation_data['autoCategorizationPredictionResultType'])) {
            return array(
                'success' => false,
                'error' => '잘못된 API 응답 형식',
                'data' => null,
                'confidence' => 0
            );
        }
        
        $result_type = $recommendation_data['autoCategorizationPredictionResultType'];
        
        if ($result_type !== 'SUCCESS') {
            return array(
                'success' => false,
                'error' => '카테고리 추천 실패: ' . $result_type,
                'data' => null,
                'confidence' => 0
            );
        }
        
        // 성공 응답 처리
        $category_id = isset($recommendation_data['predictedCategoryId']) ? $recommendation_data['predictedCategoryId'] : '';
        $category_name = isset($recommendation_data['predictedCategoryName']) ? $recommendation_data['predictedCategoryName'] : '';
        
        if (empty($category_id)) {
            return array(
                'success' => false,
                'error' => '추천된 카테고리 ID가 없습니다.',
                'data' => null,
                'confidence' => 0
            );
        }
        
        // 신뢰도 계산 (임시로 0.8 설정, 실제로는 다른 로직 필요)
        $confidence = 0.8;
        
        return array(
            'success' => true,
            'data' => array(
                'category_id' => $category_id,
                'category_name' => $category_name,
                'product_name' => $product_name,
                'result_type' => $result_type,
                'comment' => isset($recommendation_data['comment']) ? $recommendation_data['comment'] : null
            ),
            'confidence' => $confidence,
            'error' => null
        );
    }

    /**
     * 카테고리 캐시 키 생성
     * @param string $product_name 상품명
     * @param array $options 옵션
     * @return string 캐시 키
     */
    private function generateCategoryCacheKey($product_name, $options) {
        $cache_data = array(
            'product_name' => $product_name,
            'product_description' => $options['product_description'],
            'brand' => $options['brand'],
            'attributes' => $options['attributes']
        );
        return 'coupang_category_' . md5(serialize($cache_data));
    }

    /**
     * 캐시된 카테고리 추천 조회
     * @param string $cache_key 캐시 키
     * @return array|null 캐시된 결과
     */
    private function getCachedCategoryRecommendation($cache_key) {
        $sql = "SELECT cache_data, created_date 
                FROM " . G5_TABLE_PREFIX . "coupang_category_cache 
                WHERE cache_key = '" . addslashes($cache_key) . "' 
                    AND created_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
                LIMIT 1";
        
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            return json_decode($row['cache_data'], true);
        }
        
        return null;
    }

    /**
     * 카테고리 추천 결과 캐시 저장
     * @param string $cache_key 캐시 키
     * @param array $result 추천 결과
     */
    private function setCachedCategoryRecommendation($cache_key, $result) {
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_category_cache 
                (cache_key, cache_data, created_date) VALUES 
                ('" . addslashes($cache_key) . "', 
                 '" . addslashes(json_encode($result, JSON_UNESCAPED_UNICODE)) . "', 
                 NOW())
                ON DUPLICATE KEY UPDATE 
                cache_data = VALUES(cache_data), 
                created_date = VALUES(created_date)";
        
        sql_query($sql);
    }

    /**
     * 카테고리 메타정보 캐시 조회
     * @param string $cache_key 캐시 키
     * @return array|null 캐시된 결과
     */
    private function getCachedCategoryMetadata($cache_key) {
        return $this->getCachedCategoryRecommendation($cache_key); // 동일한 로직 사용
    }

    /**
     * 카테고리 메타정보 캐시 저장
     * @param string $cache_key 캐시 키
     * @param array $metadata 메타정보
     * @param int $ttl 캐시 유지 시간 (초)
     */
    private function setCachedCategoryMetadata($cache_key, $metadata, $ttl = 86400) {
        $this->setCachedCategoryRecommendation($cache_key, $metadata); // 동일한 로직 사용
    }

    /**
     * 카테고리 목록 캐시 조회
     * @param string $cache_key 캐시 키
     * @return array|null 캐시된 결과
     */
    private function getCachedCategoryList($cache_key) {
        return $this->getCachedCategoryRecommendation($cache_key); // 동일한 로직 사용
    }

    /**
     * 카테고리 목록 캐시 저장
     * @param string $cache_key 캐시 키
     * @param array $category_list 카테고리 목록
     * @param int $ttl 캐시 유지 시간 (초)
     */
    private function setCachedCategoryList($cache_key, $category_list, $ttl = 43200) {
        $this->setCachedCategoryRecommendation($cache_key, $category_list); // 동일한 로직 사용
    }

    /**
     * 카테고리 추천 결과 DB 저장
     * @param array $result 추천 결과
     * @param string $product_name 상품명
     * @param array $options 옵션
     */
    private function saveCategoryRecommendationToDB($result, $product_name, $options) {
        if (!$result['success'] || !isset($result['data']['category_id'])) {
            return;
        }
        
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_category_map 
                (youngcart_ca_id, coupang_category_id, coupang_category_name, confidence, created_date) VALUES 
                ('', 
                 '" . addslashes($result['data']['category_id']) . "', 
                 '" . addslashes($result['data']['category_name']) . "', 
                 " . floatval($result['confidence']) . ", 
                 NOW())
                ON DUPLICATE KEY UPDATE 
                coupang_category_name = VALUES(coupang_category_name),
                confidence = VALUES(confidence),
                updated_date = NOW()";
        
        sql_query($sql);
    }

    /**
     * 영카트 상품에 카테고리 매핑 저장
     * @param string $it_id 상품 ID
     * @param string $ca_id 영카트 카테고리 ID
     * @param array $recommendation 추천 결과
     */
    private function saveCategoryMappingForItem($it_id, $ca_id, $recommendation) {
        if (!$recommendation['success']) {
            return;
        }
        
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_category_map 
                (youngcart_ca_id, coupang_category_id, coupang_category_name, confidence, created_date) VALUES 
                ('" . addslashes($ca_id) . "', 
                 '" . addslashes($recommendation['data']['category_id']) . "', 
                 '" . addslashes($recommendation['data']['category_name']) . "', 
                 " . floatval($recommendation['confidence']) . ", 
                 NOW())
                ON DUPLICATE KEY UPDATE 
                coupang_category_id = VALUES(coupang_category_id),
                coupang_category_name = VALUES(coupang_category_name),
                confidence = VALUES(confidence),
                updated_date = NOW()";
        
        sql_query($sql);
    }

    // ==================== 섹션 4: 출고지/반품지 관리 메서드들 ====================
    
    /**
     * 출고지 목록 조회
     * @return array 출고지 목록 결과
     */
    public function getOutboundShippingPlaces() {
        $endpoint = COUPANG_API_OUTBOUND_PLACES;
        
        $this->log('INFO', '출고지 목록 조회 시작');
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success'] && isset($result['data']['data'])) {
            $shipping_places = array(
                'success' => true,
                'data' => $result['data']['data'],
                'count' => count($result['data']['data'])
            );
            
            // 로컬 DB에 동기화
            $this->syncShippingPlacesToLocal($result['data']['data'], 'OUTBOUND');
            
            $this->log('INFO', '출고지 목록 조회 완료', array(
                'count' => $shipping_places['count']
            ));
            
            return $shipping_places;
        }
        
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '출고지 목록 조회 실패';
        $this->log('ERROR', '출고지 목록 조회 실패', array(
            'error' => $error_msg,
            'http_code' => $result['http_code']
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'data' => array(),
            'count' => 0
        );
    }

    /**
     * 반품지 목록 조회
     * @return array 반품지 목록 결과
     */
    public function getReturnShippingPlaces() {
        $endpoint = COUPANG_API_RETURN_PLACES;
        
        $this->log('INFO', '반품지 목록 조회 시작');
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success'] && isset($result['data']['data'])) {
            $shipping_places = array(
                'success' => true,
                'data' => $result['data']['data'],
                'count' => count($result['data']['data'])
            );
            
            // 로컬 DB에 동기화
            $this->syncShippingPlacesToLocal($result['data']['data'], 'RETURN');
            
            $this->log('INFO', '반품지 목록 조회 완료', array(
                'count' => $shipping_places['count']
            ));
            
            return $shipping_places;
        }
        
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '반품지 목록 조회 실패';
        $this->log('ERROR', '반품지 목록 조회 실패', array(
            'error' => $error_msg,
            'http_code' => $result['http_code']
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'data' => array(),
            'count' => 0
        );
    }

    /**
     * 출고지 등록
     * @param array $shipping_place_data 출고지 정보
     * @return array API 응답 결과
     */
    public function createOutboundShippingPlace($shipping_place_data) {
        // 입력 데이터 검증
        $validation = $this->validateShippingPlaceData($shipping_place_data);
        if (!$validation['success']) {
            return array(
                'success' => false,
                'error' => '입력 데이터 검증 실패: ' . implode(', ', $validation['errors']),
                'validation_errors' => $validation['errors']
            );
        }
        
        $endpoint = COUPANG_API_OUTBOUND_PLACES;
        
        // API 요청 데이터 구성
        $data = array(
            'shippingPlaceName' => $shipping_place_data['name'],
            'placeAddresses' => array(
                array(
                    'companyContactNumber' => $shipping_place_data['company_phone'],
                    'phoneNumber2' => isset($shipping_place_data['phone2']) ? $shipping_place_data['phone2'] : '',
                    'addressType' => 'OUTBOUND',
                    'companyName' => $shipping_place_data['company_name'],
                    'name' => $shipping_place_data['contact_name'],
                    'phoneNumber1' => isset($shipping_place_data['phone1']) ? $shipping_place_data['phone1'] : '',
                    'zipCode' => $shipping_place_data['zipcode'],
                    'address1' => $shipping_place_data['address1'],
                    'address2' => $shipping_place_data['address2']
                )
            )
        );
        
        $this->log('INFO', '출고지 등록 요청', array(
            'shipping_place_name' => $shipping_place_data['name']
        ));
        
        $result = $this->makeRequest('POST', $endpoint, $data);
        
        // 결과 처리 및 로그
        if ($result['success']) {
            $this->log('INFO', '출고지 등록 성공', array(
                'shipping_place_name' => $shipping_place_data['name'],
                'response_data' => $result['data']
            ));
            
            // 로컬 DB에 저장
            if (isset($result['data']['data'])) {
                $this->saveShippingPlaceToLocal($result['data']['data'], 'OUTBOUND');
            }
            
            return array(
                'success' => true,
                'data' => $result['data'],
                'message' => '출고지 등록이 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '출고지 등록 실패', array(
                'shipping_place_name' => $shipping_place_data['name'],
                'error' => $result['message'],
                'http_code' => $result['http_code']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 반품지 등록
     * @param array $return_place_data 반품지 정보
     * @return array API 응답 결과
     */
    public function createReturnShippingPlace($return_place_data) {
        // 입력 데이터 검증
        $validation = $this->validateShippingPlaceData($return_place_data);
        if (!$validation['success']) {
            return array(
                'success' => false,
                'error' => '입력 데이터 검증 실패: ' . implode(', ', $validation['errors']),
                'validation_errors' => $validation['errors']
            );
        }
        
        $endpoint = COUPANG_API_RETURN_PLACES;
        
        // API 요청 데이터 구성
        $data = array(
            'returnShippingPlaceName' => $return_place_data['name'],
            'placeAddresses' => array(
                array(
                    'companyContactNumber' => $return_place_data['company_phone'],
                    'phoneNumber2' => isset($return_place_data['phone2']) ? $return_place_data['phone2'] : '',
                    'addressType' => 'RETURN',
                    'companyName' => $return_place_data['company_name'],
                    'name' => $return_place_data['contact_name'],
                    'phoneNumber1' => isset($return_place_data['phone1']) ? $return_place_data['phone1'] : '',
                    'zipCode' => $return_place_data['zipcode'],
                    'address1' => $return_place_data['address1'],
                    'address2' => $return_place_data['address2']
                )
            )
        );
        
        $this->log('INFO', '반품지 등록 요청', array(
            'return_place_name' => $return_place_data['name']
        ));
        
        $result = $this->makeRequest('POST', $endpoint, $data);
        
        // 결과 처리 및 로그
        if ($result['success']) {
            $this->log('INFO', '반품지 등록 성공', array(
                'return_place_name' => $return_place_data['name'],
                'response_data' => $result['data']
            ));
            
            // 로컬 DB에 저장
            if (isset($result['data']['data'])) {
                $this->saveShippingPlaceToLocal($result['data']['data'], 'RETURN');
            }
            
            return array(
                'success' => true,
                'data' => $result['data'],
                'message' => '반품지 등록이 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '반품지 등록 실패', array(
                'return_place_name' => $return_place_data['name'],
                'error' => $result['message'],
                'http_code' => $result['http_code']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 출고지/반품지 수정
     * @param string $shipping_place_code 출고지/반품지 코드
     * @param array $update_data 수정할 데이터
     * @return array API 응답 결과
     */
    public function updateShippingPlace($shipping_place_code, $update_data) {
        if (empty($shipping_place_code)) {
            return array(
                'success' => false,
                'error' => '출고지/반품지 코드가 필요합니다.'
            );
        }
        
        // 입력 데이터 검증
        $validation = $this->validateShippingPlaceData($update_data);
        if (!$validation['success']) {
            return array(
                'success' => false,
                'error' => '입력 데이터 검증 실패: ' . implode(', ', $validation['errors']),
                'validation_errors' => $validation['errors']
            );
        }
        
        // 기존 출고지 정보 조회하여 타입 확인
        $existing_place = $this->getShippingPlaceFromLocal($shipping_place_code);
        $address_type = $existing_place ? $existing_place['address_type'] : 'OUTBOUND';
        
        $endpoint = '/v2/providers/openapi/apis/api/v1/marketplace/shipping-places/' . $shipping_place_code;
        
        // API 요청 데이터 구성
        $data = array(
            'shippingPlaceCode' => $shipping_place_code,
            'shippingPlaceName' => $update_data['name'],
            'placeAddresses' => array(
                array(
                    'companyContactNumber' => $update_data['company_phone'],
                    'phoneNumber2' => isset($update_data['phone2']) ? $update_data['phone2'] : '',
                    'addressType' => $address_type,
                    'companyName' => $update_data['company_name'],
                    'name' => $update_data['contact_name'],
                    'phoneNumber1' => isset($update_data['phone1']) ? $update_data['phone1'] : '',
                    'zipCode' => $update_data['zipcode'],
                    'address1' => $update_data['address1'],
                    'address2' => $update_data['address2']
                )
            )
        );
        
        $this->log('INFO', '출고지/반품지 수정 요청', array(
            'shipping_place_code' => $shipping_place_code,
            'shipping_place_name' => $update_data['name']
        ));
        
        $result = $this->makeRequest('PUT', $endpoint, $data);
        
        // 결과 처리
        if ($result['success']) {
            $this->log('INFO', '출고지/반품지 수정 성공', array(
                'shipping_place_code' => $shipping_place_code
            ));
            
            // 로컬 DB 업데이트
            $this->updateShippingPlaceInLocal($shipping_place_code, $update_data);
            
            return array(
                'success' => true,
                'data' => $result['data'],
                'message' => '출고지/반품지 수정이 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '출고지/반품지 수정 실패', array(
                'shipping_place_code' => $shipping_place_code,
                'error' => $result['message']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 출고지/반품지 삭제
     * @param string $shipping_place_code 출고지/반품지 코드
     * @return array API 응답 결과
     */
    public function deleteShippingPlace($shipping_place_code) {
        if (empty($shipping_place_code)) {
            return array(
                'success' => false,
                'error' => '출고지/반품지 코드가 필요합니다.'
            );
        }
        
        $endpoint = '/v2/providers/openapi/apis/api/v1/marketplace/shipping-places/' . $shipping_place_code;
        
        $this->log('INFO', '출고지/반품지 삭제 요청', array(
            'shipping_place_code' => $shipping_place_code
        ));
        
        $result = $this->makeRequest('DELETE', $endpoint);
        
        // 결과 처리
        if ($result['success']) {
            $this->log('INFO', '출고지/반품지 삭제 성공', array(
                'shipping_place_code' => $shipping_place_code
            ));
            
            // 로컬 DB에서 삭제
            $this->deleteShippingPlaceFromLocal($shipping_place_code);
            
            return array(
                'success' => true,
                'message' => '출고지/반품지 삭제가 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '출고지/반품지 삭제 실패', array(
                'shipping_place_code' => $shipping_place_code,
                'error' => $result['message']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 쿠팡에서 출고지/반품지 정보 동기화
     * @return array 동기화 결과
     */
    public function syncShippingPlacesFromCoupang() {
        $this->log('INFO', '출고지/반품지 동기화 시작');
        
        $sync_result = array(
            'success' => true,
            'outbound_count' => 0,
            'return_count' => 0,
            'errors' => array()
        );
        
        // 출고지 동기화
        $outbound_result = $this->getOutboundShippingPlaces();
        if ($outbound_result['success']) {
            $sync_result['outbound_count'] = $outbound_result['count'];
        } else {
            $sync_result['success'] = false;
            $sync_result['errors'][] = '출고지 동기화 실패: ' . $outbound_result['error'];
        }
        
        // 반품지 동기화
        $return_result = $this->getReturnShippingPlaces();
        if ($return_result['success']) {
            $sync_result['return_count'] = $return_result['count'];
        } else {
            $sync_result['success'] = false;
            $sync_result['errors'][] = '반품지 동기화 실패: ' . $return_result['error'];
        }
        
        $this->log('INFO', '출고지/반품지 동기화 완료', array(
            'success' => $sync_result['success'],
            'outbound_count' => $sync_result['outbound_count'],
            'return_count' => $sync_result['return_count'],
            'error_count' => count($sync_result['errors'])
        ));
        
        return $sync_result;
    }

    /**
     * 기본 출고지 조회
     * @param string $type 타입 ('OUTBOUND' 또는 'RETURN')
     * @return array|null 기본 출고지 정보
     */
    public function getDefaultShippingPlace($type = 'OUTBOUND') {
        $config_key = ($type === 'RETURN') ? 'COUPANG_DEFAULT_RETURN_PLACE' : 'COUPANG_DEFAULT_OUTBOUND_PLACE';
        
        if (!defined($config_key) || empty(constant($config_key))) {
            return null;
        }
        
        $default_code = constant($config_key);
        
        // 로컬 DB에서 조회
        $sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_shipping_places 
                WHERE shipping_place_code = '" . addslashes($default_code) . "' 
                    AND address_type = '" . addslashes($type) . "' 
                    AND is_active = 'Y'
                LIMIT 1";
        
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            return $row;
        }
        
        return null;
    }

    // ==================== 출고지 관련 Private 헬퍼 메서드들 ====================
    
    /**
     * 출고지/반품지 데이터를 로컬 DB에 동기화
     * @param array $shipping_places_data 쿠팡 API 응답 데이터
     * @param string $address_type 주소 타입 ('OUTBOUND' 또는 'RETURN')
     */
    private function syncShippingPlacesToLocal($shipping_places_data, $address_type) {
        foreach ($shipping_places_data as $place_data) {
            $this->saveShippingPlaceToLocal($place_data, $address_type);
        }
    }

    /**
     * 단일 출고지/반품지를 로컬 DB에 저장
     * @param array $place_data 출고지 데이터
     * @param string $address_type 주소 타입
     */
    private function saveShippingPlaceToLocal($place_data, $address_type) {
        if (!isset($place_data['shippingPlaceCode']) || !isset($place_data['shippingPlaceName'])) {
            return;
        }
        
        $addresses = isset($place_data['placeAddresses']) ? $place_data['placeAddresses'] : array();
        $address_info = !empty($addresses) ? $addresses[0] : array();
        
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_shipping_places 
                (shipping_place_code, shipping_place_name, address_type, company_name, 
                 contact_name, company_phone, phone1, phone2, zipcode, address1, address2, 
                 is_active, created_date, updated_date) VALUES 
                ('" . addslashes($place_data['shippingPlaceCode']) . "', 
                 '" . addslashes($place_data['shippingPlaceName']) . "', 
                 '" . addslashes($address_type) . "', 
                 '" . addslashes(isset($address_info['companyName']) ? $address_info['companyName'] : '') . "', 
                 '" . addslashes(isset($address_info['name']) ? $address_info['name'] : '') . "', 
                 '" . addslashes(isset($address_info['companyContactNumber']) ? $address_info['companyContactNumber'] : '') . "', 
                 '" . addslashes(isset($address_info['phoneNumber1']) ? $address_info['phoneNumber1'] : '') . "', 
                 '" . addslashes(isset($address_info['phoneNumber2']) ? $address_info['phoneNumber2'] : '') . "', 
                 '" . addslashes(isset($address_info['zipCode']) ? $address_info['zipCode'] : '') . "', 
                 '" . addslashes(isset($address_info['address1']) ? $address_info['address1'] : '') . "', 
                 '" . addslashes(isset($address_info['address2']) ? $address_info['address2'] : '') . "', 
                 'Y', NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                shipping_place_name = VALUES(shipping_place_name),
                company_name = VALUES(company_name),
                contact_name = VALUES(contact_name),
                company_phone = VALUES(company_phone),
                phone1 = VALUES(phone1),
                phone2 = VALUES(phone2),
                zipcode = VALUES(zipcode),
                address1 = VALUES(address1),
                address2 = VALUES(address2),
                is_active = VALUES(is_active),
                updated_date = VALUES(updated_date)";
        
        sql_query($sql);
    }

    /**
     * 로컬 DB에서 출고지 정보 조회
     * @param string $shipping_place_code 출고지 코드
     * @return array|null 출고지 정보
     */
    private function getShippingPlaceFromLocal($shipping_place_code) {
        $sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_shipping_places 
                WHERE shipping_place_code = '" . addslashes($shipping_place_code) . "' 
                LIMIT 1";
        
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            return $row;
        }
        
        return null;
    }

    /**
     * 로컬 DB의 출고지 정보 업데이트
     * @param string $shipping_place_code 출고지 코드
     * @param array $update_data 업데이트할 데이터
     */
    private function updateShippingPlaceInLocal($shipping_place_code, $update_data) {
        $sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_shipping_places SET 
                shipping_place_name = '" . addslashes($update_data['name']) . "',
                company_name = '" . addslashes($update_data['company_name']) . "',
                contact_name = '" . addslashes($update_data['contact_name']) . "',
                company_phone = '" . addslashes($update_data['company_phone']) . "',
                phone1 = '" . addslashes(isset($update_data['phone1']) ? $update_data['phone1'] : '') . "',
                phone2 = '" . addslashes(isset($update_data['phone2']) ? $update_data['phone2'] : '') . "',
                zipcode = '" . addslashes($update_data['zipcode']) . "',
                address1 = '" . addslashes($update_data['address1']) . "',
                address2 = '" . addslashes($update_data['address2']) . "',
                updated_date = NOW()
                WHERE shipping_place_code = '" . addslashes($shipping_place_code) . "'";
        
        sql_query($sql);
    }

    /**
     * 로컬 DB에서 출고지 삭제
     * @param string $shipping_place_code 출고지 코드
     */
    private function deleteShippingPlaceFromLocal($shipping_place_code) {
        $sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_shipping_places SET 
                is_active = 'N', updated_date = NOW()
                WHERE shipping_place_code = '" . addslashes($shipping_place_code) . "'";
        
        sql_query($sql);
    }

    // ==================== 섹션 5: 상품 등록/관리 메서드들 ====================
    
    /**
     * 상품 등록
     * @param array $product_data 상품 데이터
     * @return array API 응답 결과
     */
    public function createProduct($product_data) {
        // 입력 데이터 검증
        $validation = $this->validateProductData($product_data);
        if (!$validation['success']) {
            return array(
                'success' => false,
                'error' => '상품 데이터 검증 실패: ' . implode(', ', $validation['errors']),
                'validation_errors' => $validation['errors']
            );
        }
        
        $endpoint = COUPANG_API_PRODUCTS;
        
        // API 요청 데이터 구성
        $api_data = $this->buildProductApiData($product_data);
        
        $this->log('INFO', '상품 등록 요청', array(
            'product_name' => $product_data['displayName'],
            'category_id' => isset($product_data['categoryId']) ? $product_data['categoryId'] : 'N/A'
        ));
        
        $result = $this->makeRequest('POST', $endpoint, $api_data);
        
        // 결과 처리
        if ($result['success']) {
            $response_data = $result['data'];
            
            $this->log('INFO', '상품 등록 성공', array(
                'product_name' => $product_data['displayName'],
                'product_id' => isset($response_data['data']['productId']) ? $response_data['data']['productId'] : 'N/A'
            ));
            
            // 로컬 DB에 매핑 정보 저장
            if (isset($response_data['data']['productId']) && isset($product_data['youngcart_item_id'])) {
                $this->saveProductMappingToLocal($product_data['youngcart_item_id'], $response_data['data']);
            }
            
            return array(
                'success' => true,
                'data' => $response_data,
                'product_id' => isset($response_data['data']['productId']) ? $response_data['data']['productId'] : null,
                'message' => '상품 등록이 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '상품 등록 실패', array(
                'product_name' => $product_data['displayName'],
                'error' => $result['message'],
                'http_code' => $result['http_code']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 상품 수정
     * @param string $product_id 쿠팡 상품 ID
     * @param array $product_data 수정할 상품 데이터
     * @return array API 응답 결과
     */
    public function updateProduct($product_id, $product_data) {
        if (empty($product_id)) {
            return array(
                'success' => false,
                'error' => '상품 ID가 필요합니다.'
            );
        }
        
        // 입력 데이터 검증
        $validation = $this->validateProductData($product_data);
        if (!$validation['success']) {
            return array(
                'success' => false,
                'error' => '상품 데이터 검증 실패: ' . implode(', ', $validation['errors']),
                'validation_errors' => $validation['errors']
            );
        }
        
        $endpoint = COUPANG_API_PRODUCTS . '/' . $product_id;
        
        // API 요청 데이터 구성
        $api_data = $this->buildProductApiData($product_data);
        
        $this->log('INFO', '상품 수정 요청', array(
            'product_id' => $product_id,
            'product_name' => $product_data['displayName']
        ));
        
        $result = $this->makeRequest('PUT', $endpoint, $api_data);
        
        // 결과 처리
        if ($result['success']) {
            $this->log('INFO', '상품 수정 성공', array(
                'product_id' => $product_id
            ));
            
            // 로컬 DB 매핑 정보 업데이트
            if (isset($product_data['youngcart_item_id'])) {
                $this->updateProductMappingInLocal($product_data['youngcart_item_id'], $result['data']);
            }
            
            return array(
                'success' => true,
                'data' => $result['data'],
                'message' => '상품 수정이 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '상품 수정 실패', array(
                'product_id' => $product_id,
                'error' => $result['message']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 상품 조회
     * @param string $product_id 쿠팡 상품 ID
     * @return array API 응답 결과
     */
    public function getProduct($product_id) {
        if (empty($product_id)) {
            return array(
                'success' => false,
                'error' => '상품 ID가 필요합니다.'
            );
        }
        
        $endpoint = COUPANG_API_PRODUCTS . '/' . $product_id;
        
        $this->log('INFO', '상품 조회', array('product_id' => $product_id));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success']) {
            return array(
                'success' => true,
                'data' => $result['data'],
                'product_id' => $product_id
            );
        } else {
            $this->log('ERROR', '상품 조회 실패', array(
                'product_id' => $product_id,
                'error' => $result['message']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 상품 재고/가격 수정
     * @param string $product_id 쿠팡 상품 ID
     * @param array $items_data 아이템별 재고/가격 정보
     * @return array API 응답 결과
     */
    public function updateProductStock($product_id, $items_data) {
        if (empty($product_id)) {
            return array(
                'success' => false,
                'error' => '상품 ID가 필요합니다.'
            );
        }
        
        if (empty($items_data) || !is_array($items_data)) {
            return array(
                'success' => false,
                'error' => '아이템 데이터가 필요합니다.'
            );
        }
        
        $endpoint = COUPANG_API_PRODUCTS . '/' . $product_id . '/items';
        
        // API 요청 데이터 구성
        $api_data = array('items' => array());
        
        foreach ($items_data as $item) {
            $item_data = array();
            
            // 필수 필드 확인
            if (isset($item['itemName'])) {
                $item_data['itemName'] = $item['itemName'];
            }
            if (isset($item['originalPrice'])) {
                $item_data['originalPrice'] = intval($item['originalPrice']);
            }
            if (isset($item['salePrice'])) {
                $item_data['salePrice'] = intval($item['salePrice']);
            }
            if (isset($item['maximumBuyCount'])) {
                $item_data['maximumBuyCount'] = intval($item['maximumBuyCount']);
            }
            
            $api_data['items'][] = $item_data;
        }
        
        $this->log('INFO', '상품 재고/가격 수정 요청', array(
            'product_id' => $product_id,
            'items_count' => count($api_data['items'])
        ));
        
        $result = $this->makeRequest('PUT', $endpoint, $api_data);
        
        if ($result['success']) {
            $this->log('INFO', '상품 재고/가격 수정 성공', array(
                'product_id' => $product_id
            ));
            
            return array(
                'success' => true,
                'data' => $result['data'],
                'message' => '상품 재고/가격 수정이 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '상품 재고/가격 수정 실패', array(
                'product_id' => $product_id,
                'error' => $result['message']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 상품 승인 요청
     * @param string $product_id 쿠팡 상품 ID
     * @return array API 응답 결과
     */
    public function requestProductApproval($product_id) {
        if (empty($product_id)) {
            return array(
                'success' => false,
                'error' => '상품 ID가 필요합니다.'
            );
        }
        
        $endpoint = COUPANG_API_PRODUCTS . '/' . $product_id . '/approval/request';
        
        $this->log('INFO', '상품 승인 요청', array('product_id' => $product_id));
        
        $result = $this->makeRequest('PUT', $endpoint);
        
        if ($result['success']) {
            $this->log('INFO', '상품 승인 요청 성공', array(
                'product_id' => $product_id
            ));
            
            // 로컬 DB에 승인 요청 상태 업데이트
            $this->updateProductApprovalStatusInLocal($product_id, 'REQUESTED');
            
            return array(
                'success' => true,
                'data' => $result['data'],
                'message' => '상품 승인 요청이 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '상품 승인 요청 실패', array(
                'product_id' => $product_id,
                'error' => $result['message']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 상품 승인 상태 조회
     * @param string $product_id 쿠팡 상품 ID
     * @return array API 응답 결과
     */
    public function getProductApprovalStatus($product_id) {
        if (empty($product_id)) {
            return array(
                'success' => false,
                'error' => '상품 ID가 필요합니다.'
            );
        }
        
        $endpoint = COUPANG_API_PRODUCTS . '/' . $product_id . '/approval';
        
        $this->log('INFO', '상품 승인 상태 조회', array('product_id' => $product_id));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success']) {
            $approval_data = $result['data'];
            $status = isset($approval_data['data']['approvalStatus']) ? $approval_data['data']['approvalStatus'] : 'UNKNOWN';
            
            // 로컬 DB에 승인 상태 업데이트
            $this->updateProductApprovalStatusInLocal($product_id, $status);
            
            return array(
                'success' => true,
                'data' => $approval_data,
                'approval_status' => $status,
                'product_id' => $product_id
            );
        } else {
            $this->log('ERROR', '상품 승인 상태 조회 실패', array(
                'product_id' => $product_id,
                'error' => $result['message']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 영카트 상품들을 쿠팡에 동기화 (기존 메서드 개선)
     * @param int $limit 동기화할 상품 수
     * @return array 동기화 결과
     */
    public function syncProductsToCoupang($limit = 10) {
        $this->log('INFO', '상품 동기화 시작', array('limit' => $limit));
        
        $result = array(
            'success' => true,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'products' => array(),
            'errors' => array()
        );
        
        // 동기화 대상 상품 조회 (쿠팡에 등록되지 않은 상품들)
        $sql = "SELECT it.*, ca.ca_name 
                FROM " . G5_TABLE_PREFIX . "yc_item it
                LEFT JOIN " . G5_TABLE_PREFIX . "yc_category ca ON it.ca_id = ca.ca_id
                LEFT JOIN " . G5_TABLE_PREFIX . "coupang_item_map cim ON it.it_id = cim.youngcart_item_id
                WHERE cim.coupang_product_id IS NULL 
                    AND it.it_use = '1'
                    AND it.it_sell_open = '1'
                ORDER BY it.it_time DESC 
                LIMIT " . intval($limit);
        
        $query_result = sql_query($sql);
        
        while ($row = sql_fetch_array($query_result)) {
            $result['processed']++;
            
            // 영카트 상품 데이터를 쿠팡 형식으로 변환
            $product_data = $this->convertYoungcartToCoupangFormat($row);
            
            if (!$product_data) {
                $result['failed']++;
                $result['errors'][] = array(
                    'it_id' => $row['it_id'],
                    'it_name' => $row['it_name'],
                    'error' => '상품 데이터 변환 실패'
                );
                continue;
            }
            
            // 쿠팡에 상품 등록
            $create_result = $this->createProduct($product_data);
            
            if ($create_result['success']) {
                $result['succeeded']++;
                $result['products'][] = array(
                    'it_id' => $row['it_id'],
                    'it_name' => $row['it_name'],
                    'coupang_product_id' => $create_result['product_id']
                );
            } else {
                $result['failed']++;
                $result['errors'][] = array(
                    'it_id' => $row['it_id'],
                    'it_name' => $row['it_name'],
                    'error' => $create_result['error']
                );
            }
            
            // API 제한을 위한 지연
            sleep(2);
        }
        
        $this->log('INFO', '상품 동기화 완료', array(
            'processed' => $result['processed'],
            'succeeded' => $result['succeeded'],
            'failed' => $result['failed']
        ));
        
        return $result;
    }

    /**
     * 재고 동기화 (기존 메서드 개선)
     * @param int $limit 동기화할 상품 수
     * @return array 동기화 결과
     */
    public function syncStockToCoupang($limit = 20) {
        $this->log('INFO', '재고 동기화 시작', array('limit' => $limit));
        
        $result = array(
            'success' => true,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'stocks' => array(),
            'errors' => array()
        );
        
        // 재고 동기화 대상 상품 조회 (쿠팡에 등록된 상품들)
        $sql = "SELECT it.*, cim.coupang_product_id, cim.coupang_item_id
                FROM " . G5_TABLE_PREFIX . "yc_item it
                INNER JOIN " . G5_TABLE_PREFIX . "coupang_item_map cim ON it.it_id = cim.youngcart_item_id
                WHERE cim.coupang_product_id IS NOT NULL 
                    AND it.it_use = '1'
                    AND (cim.last_stock_sync IS NULL 
                         OR cim.last_stock_sync < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                ORDER BY cim.last_stock_sync ASC 
                LIMIT " . intval($limit);
        
        $query_result = sql_query($sql);
        
        while ($row = sql_fetch_array($query_result)) {
            $result['processed']++;
            
            // 재고/가격 데이터 구성
            $stock_data = array(
                array(
                    'itemName' => $row['it_name'],
                    'originalPrice' => intval($row['it_cust_price']),
                    'salePrice' => intval($row['it_price']),
                    'maximumBuyCount' => intval($row['it_stock_qty'])
                )
            );
            
            // 재고 업데이트
            $stock_result = $this->updateProductStock($row['coupang_product_id'], $stock_data);
            
            if ($stock_result['success']) {
                $result['succeeded']++;
                $result['stocks'][] = array(
                    'it_id' => $row['it_id'],
                    'it_name' => $row['it_name'],
                    'stock_qty' => $row['it_stock_qty'],
                    'price' => $row['it_price']
                );
                
                // 동기화 시간 업데이트
                $this->updateStockSyncTimeInLocal($row['it_id']);
                
            } else {
                $result['failed']++;
                $result['errors'][] = array(
                    'it_id' => $row['it_id'],
                    'it_name' => $row['it_name'],
                    'error' => $stock_result['error']
                );
            }
            
            // API 제한을 위한 지연
            sleep(1);
        }
        
        $this->log('INFO', '재고 동기화 완료', array(
            'processed' => $result['processed'],
            'succeeded' => $result['succeeded'],
            'failed' => $result['failed']
        ));
        
        return $result;
    }

    // ==================== 상품 관련 Private 헬퍼 메서드들 ====================
    
    /**
     * 상품 데이터를 쿠팡 API 형식으로 구성
     * @param array $product_data 입력 상품 데이터
     * @return array 쿠팡 API 형식 데이터
     */
    private function buildProductApiData($product_data) {
        // 기본 상품 정보
        $api_data = array(
            'displayName' => $product_data['displayName'],
            'vendorItemName' => isset($product_data['vendorItemName']) ? $product_data['vendorItemName'] : $product_data['displayName'],
            'categoryId' => $product_data['categoryId'],
            'images' => isset($product_data['images']) ? $product_data['images'] : array(),
            'outboundShippingPlaceCode' => isset($product_data['outboundShippingPlaceCode']) ? 
                                          $product_data['outboundShippingPlaceCode'] : 
                                          COUPANG_DEFAULT_OUTBOUND_PLACE,
            'vendorUserId' => isset($product_data['vendorUserId']) ? 
                             $product_data['vendorUserId'] : 
                             COUPANG_DEFAULT_VENDOR_USER_ID,
            'requested' => isset($product_data['requested']) ? 
                          $product_data['requested'] : 
                          COUPANG_AUTO_APPROVAL_REQUEST
        );
        
        // 선택적 필드들
        $optional_fields = array(
            'brandName', 'modelName', 'manufacturerName', 'description',
            'afterServiceInformation', 'afterServiceContactNumber',
            'returnChargeVendor', 'extraInfoMessage'
        );
        
        foreach ($optional_fields as $field) {
            if (isset($product_data[$field])) {
                $api_data[$field] = $product_data[$field];
            }
        }
        
        // 아이템 정보
        if (isset($product_data['items']) && is_array($product_data['items'])) {
            $api_data['items'] = $product_data['items'];
        } else {
            // 기본 아이템 생성
            $api_data['items'] = array(
                array(
                    'itemName' => isset($product_data['itemName']) ? $product_data['itemName'] : $product_data['displayName'],
                    'originalPrice' => intval($product_data['originalPrice']),
                    'salePrice' => intval($product_data['salePrice']),
                    'maximumBuyCount' => intval($product_data['maximumBuyCount']),
                    'outboundShippingTimeDay' => isset($product_data['outboundShippingTimeDay']) ? 
                                                intval($product_data['outboundShippingTimeDay']) : 
                                                COUPANG_DEFAULT_SHIPPING_TIME,
                    'unitCount' => isset($product_data['unitCount']) ? intval($product_data['unitCount']) : 1,
                    'adultOnly' => isset($product_data['adultOnly']) ? $product_data['adultOnly'] : 'EVERYONE',
                    'taxType' => isset($product_data['taxType']) ? $product_data['taxType'] : 'TAX',
                    'parallelImported' => 'NOT_PARALLEL_IMPORTED',
                    'overseasPurchased' => 'NOT_OVERSEAS_PURCHASED',
                    'pccNeeded' => 'false'
                )
            );
        }
        
        return $api_data;
    }

    /**
     * 영카트 상품을 쿠팡 형식으로 변환
     * @param array $youngcart_item 영카트 상품 데이터
     * @return array|false 쿠팡 형식 데이터 또는 false
     */
    private function convertYoungcartToCoupangFormat($youngcart_item) {
        // 카테고리 매핑 확인
        $category_mapping = $this->getCategoryMappingForYoungcart($youngcart_item['ca_id']);
        if (!$category_mapping) {
            return false;
        }
        
        // 기본 상품 데이터 구성
        $product_data = array(
            'youngcart_item_id' => $youngcart_item['it_id'],
            'displayName' => $youngcart_item['it_name'],
            'vendorItemName' => $youngcart_item['it_name'],
            'categoryId' => $category_mapping['coupang_category_id'],
            'originalPrice' => intval($youngcart_item['it_cust_price']),
            'salePrice' => intval($youngcart_item['it_price']),
            'maximumBuyCount' => intval($youngcart_item['it_stock_qty']),
            'description' => strip_tags($youngcart_item['it_basic']),
            'brandName' => $youngcart_item['it_maker'],
            'modelName' => $youngcart_item['it_model'],
            'itemName' => $youngcart_item['it_name']
        );
        
        // 상품 이미지 처리
        $images = $this->getYoungcartItemImages($youngcart_item['it_id']);
        if (!empty($images)) {
            $product_data['images'] = $images;
        }
        
        return $product_data;
    }

    /**
     * 영카트 카테고리의 쿠팡 매핑 조회
     * @param string $youngcart_ca_id 영카트 카테고리 ID
     * @return array|false 매핑 정보 또는 false
     */
    private function getCategoryMappingForYoungcart($youngcart_ca_id) {
        $sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_category_map 
                WHERE youngcart_ca_id = '" . addslashes($youngcart_ca_id) . "' 
                LIMIT 1";
        
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            return $row;
        }
        
        return false;
    }

    /**
     * 영카트 상품 이미지 조회
     * @param string $item_id 상품 ID
     * @return array 이미지 URL 배열
     */
    private function getYoungcartItemImages($item_id) {
        $images = array();
        
        // 대표 이미지
        $main_image = G5_DATA_URL . '/item/' . $item_id . '_main.jpg';
        if (file_exists(G5_DATA_PATH . '/item/' . $item_id . '_main.jpg')) {
            $images[] = $main_image;
        }
        
        // 추가 이미지들 (1~10)
        for ($i = 1; $i <= 10; $i++) {
            $image_file = G5_DATA_PATH . '/item/' . $item_id . '_' . $i . '.jpg';
            if (file_exists($image_file)) {
                $images[] = G5_DATA_URL . '/item/' . $item_id . '_' . $i . '.jpg';
            }
        }
        
        return $images;
    }

    /**
     * 상품 매핑 정보를 로컬 DB에 저장
     * @param string $youngcart_item_id 영카트 상품 ID
     * @param array $coupang_data 쿠팡 응답 데이터
     */
    private function saveProductMappingToLocal($youngcart_item_id, $coupang_data) {
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_item_map 
                (youngcart_item_id, coupang_product_id, coupang_item_id, 
                 sync_status, created_date, last_sync_date) VALUES 
                ('" . addslashes($youngcart_item_id) . "', 
                 '" . addslashes($coupang_data['productId']) . "', 
                 '" . addslashes(isset($coupang_data['itemId']) ? $coupang_data['itemId'] : '') . "', 
                 'SYNCED', NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                coupang_product_id = VALUES(coupang_product_id),
                coupang_item_id = VALUES(coupang_item_id),
                sync_status = VALUES(sync_status),
                last_sync_date = VALUES(last_sync_date)";
        
        sql_query($sql);
    }

    /**
     * 상품 매핑 정보 업데이트
     * @param string $youngcart_item_id 영카트 상품 ID
     * @param array $coupang_data 쿠팡 응답 데이터
     */
    private function updateProductMappingInLocal($youngcart_item_id, $coupang_data) {
        $sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_item_map SET 
                sync_status = 'SYNCED', 
                last_sync_date = NOW()
                WHERE youngcart_item_id = '" . addslashes($youngcart_item_id) . "'";
        
        sql_query($sql);
    }

    /**
     * 상품 승인 상태를 로컬 DB에 업데이트
     * @param string $coupang_product_id 쿠팡 상품 ID
     * @param string $approval_status 승인 상태
     */
    private function updateProductApprovalStatusInLocal($coupang_product_id, $approval_status) {
        $sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_item_map SET 
                approval_status = '" . addslashes($approval_status) . "', 
                last_approval_check = NOW()
                WHERE coupang_product_id = '" . addslashes($coupang_product_id) . "'";
        
        sql_query($sql);
    }

    /**
     * 재고 동기화 시간 업데이트
     * @param string $youngcart_item_id 영카트 상품 ID
     */
    private function updateStockSyncTimeInLocal($youngcart_item_id) {
        $sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_item_map SET 
                last_stock_sync = NOW()
                WHERE youngcart_item_id = '" . addslashes($youngcart_item_id) . "'";
        
        sql_query($sql);
    }

    // ==================== 섹션 6: 주문 관리 메서드들 ====================
    
    /**
     * 주문 목록 조회
     * @param array $search_params 검색 조건
     * @return array 주문 목록 결과
     */
    public function getOrders($search_params = array()) {
        // 기본 검색 조건 설정
        $default_params = array(
            'maxPerPage' => 50,
            'nextToken' => '',
            'createdAtFrom' => date('Y-m-d\TH:i:s\Z', strtotime('-1 hour')),
            'createdAtTo' => date('Y-m-d\TH:i:s\Z'),
            'status' => '' // ACCEPT, INSTRUCT, SHIP, DELIVER, CONFIRM 등
        );
        $params = array_merge($default_params, $search_params);
        
        // 쿼리 파라미터 구성
        $query_string = '';
        foreach ($params as $key => $value) {
            if (!empty($value)) {
                $query_string .= ($query_string ? '&' : '?') . urlencode($key) . '=' . urlencode($value);
            }
        }
        
        $endpoint = COUPANG_API_ORDERS . $query_string;
        
        $this->log('INFO', '주문 목록 조회', array(
            'params' => $params
        ));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success'] && isset($result['data']['data'])) {
            $orders_data = array(
                'success' => true,
                'data' => $result['data']['data'],
                'count' => count($result['data']['data']),
                'nextToken' => isset($result['data']['nextToken']) ? $result['data']['nextToken'] : null,
                'search_params' => $params
            );
            
            $this->log('INFO', '주문 목록 조회 완료', array(
                'count' => $orders_data['count'],
                'has_next' => !empty($orders_data['nextToken'])
            ));
            
            return $orders_data;
        }
        
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '주문 목록 조회 실패';
        $this->log('ERROR', '주문 목록 조회 실패', array(
            'error' => $error_msg,
            'http_code' => $result['http_code']
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'data' => array(),
            'count' => 0
        );
    }

    /**
     * 주문 상세 조회
     * @param string $order_id 쿠팡 주문 ID
     * @return array 주문 상세 결과
     */
    public function getOrderDetail($order_id) {
        if (empty($order_id)) {
            return array(
                'success' => false,
                'error' => '주문 ID가 필요합니다.'
            );
        }
        
        $endpoint = COUPANG_API_ORDERS . '/' . $order_id;
        
        $this->log('INFO', '주문 상세 조회', array('order_id' => $order_id));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success'] && isset($result['data']['data'])) {
            return array(
                'success' => true,
                'data' => $result['data']['data'],
                'order_id' => $order_id
            );
        }
        
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '주문 상세 조회 실패';
        $this->log('ERROR', '주문 상세 조회 실패', array(
            'order_id' => $order_id,
            'error' => $error_msg
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'data' => null
        );
    }

    /**
     * 배송 정보 업데이트 (발송 처리)
     * @param string $order_id 쿠팡 주문 ID
     * @param array $shipping_data 배송 정보
     * @return array API 응답 결과
     */
    public function updateShippingInfo($order_id, $shipping_data) {
        if (empty($order_id)) {
            return array(
                'success' => false,
                'error' => '주문 ID가 필요합니다.'
            );
        }
        
        // 배송 정보 검증
        $validation = $this->validateShippingData($shipping_data);
        if (!$validation['success']) {
            return array(
                'success' => false,
                'error' => '배송 데이터 검증 실패: ' . implode(', ', $validation['errors']),
                'validation_errors' => $validation['errors']
            );
        }
        
        $endpoint = COUPANG_API_ORDERS . '/' . $order_id . '/acknowledgement';
        
        // API 요청 데이터 구성
        $api_data = array();
        
        if (isset($shipping_data['tracking_number'])) {
            $api_data['trackingNumber'] = $shipping_data['tracking_number'];
        }
        
        if (isset($shipping_data['delivery_company'])) {
            $api_data['deliveryCompany'] = $this->getCoupangDeliveryCode($shipping_data['delivery_company']);
        }
        
        if (isset($shipping_data['shipping_date'])) {
            $api_data['shippingDate'] = $shipping_data['shipping_date'];
        }
        
        $this->log('INFO', '배송 정보 업데이트 요청', array(
            'order_id' => $order_id,
            'tracking_number' => isset($shipping_data['tracking_number']) ? $shipping_data['tracking_number'] : 'N/A'
        ));
        
        $result = $this->makeRequest('PUT', $endpoint, $api_data);
        
        if ($result['success']) {
            $this->log('INFO', '배송 정보 업데이트 성공', array(
                'order_id' => $order_id
            ));
            
            // 로컬 DB에 배송 정보 업데이트
            $this->updateOrderShippingInLocal($order_id, $shipping_data);
            
            return array(
                'success' => true,
                'data' => $result['data'],
                'message' => '배송 정보 업데이트가 완료되었습니다.'
            );
        } else {
            $this->log('ERROR', '배송 정보 업데이트 실패', array(
                'order_id' => $order_id,
                'error' => $result['message']
            ));
            
            return array(
                'success' => false,
                'error' => $result['message'],
                'http_code' => $result['http_code']
            );
        }
    }

    /**
     * 취소된 주문 조회
     * @param array $search_params 검색 조건
     * @return array 취소 주문 목록
     */
    public function getCancelledOrders($search_params = array()) {
        // 기본 검색 조건 설정
        $default_params = array(
            'maxPerPage' => 50,
            'nextToken' => '',
            'requestedAtFrom' => date('Y-m-d\TH:i:s\Z', strtotime('-1 hour')),
            'requestedAtTo' => date('Y-m-d\TH:i:s\Z')
        );
        $params = array_merge($default_params, $search_params);
        
        // 쿼리 파라미터 구성
        $query_string = '';
        foreach ($params as $key => $value) {
            if (!empty($value)) {
                $query_string .= ($query_string ? '&' : '?') . urlencode($key) . '=' . urlencode($value);
            }
        }
        
        $endpoint = COUPANG_API_ORDERS . '/cancellation' . $query_string;
        
        $this->log('INFO', '취소 주문 조회', array(
            'params' => $params
        ));
        
        $result = $this->makeRequest('GET', $endpoint);
        
        if ($result['success'] && isset($result['data']['data'])) {
            $cancelled_orders = array(
                'success' => true,
                'data' => $result['data']['data'],
                'count' => count($result['data']['data']),
                'nextToken' => isset($result['data']['nextToken']) ? $result['data']['nextToken'] : null
            );
            
            $this->log('INFO', '취소 주문 조회 완료', array(
                'count' => $cancelled_orders['count']
            ));
            
            return $cancelled_orders;
        }
        
        $error_msg = isset($result['data']['message']) ? $result['data']['message'] : '취소 주문 조회 실패';
        $this->log('ERROR', '취소 주문 조회 실패', array(
            'error' => $error_msg,
            'http_code' => $result['http_code']
        ));
        
        return array(
            'success' => false,
            'error' => $error_msg,
            'data' => array(),
            'count' => 0
        );
    }

    /**
     * 쿠팡에서 주문 동기화 (기존 메서드 개선)
     * @param int $hours 동기화할 시간 범위
     * @return array 동기화 결과
     */
    public function syncOrdersFromCoupang($hours = 1) {
        $this->log('INFO', '주문 동기화 시작', array('hours' => $hours));
        
        $result = array(
            'success' => true,
            'processed' => 0,
            'new_orders' => 0,
            'updated_orders' => 0,
            'errors' => array()
        );
        
        // 동기화 시간 범위 설정
        $from_time = date('Y-m-d\TH:i:s\Z', strtotime("-{$hours} hours"));
        $to_time = date('Y-m-d\TH:i:s\Z');
        
        $search_params = array(
            'createdAtFrom' => $from_time,
            'createdAtTo' => $to_time,
            'maxPerPage' => 100
        );
        
        $next_token = '';
        do {
            if (!empty($next_token)) {
                $search_params['nextToken'] = $next_token;
            }
            
            $orders_result = $this->getOrders($search_params);
            
            if (!$orders_result['success']) {
                $result['success'] = false;
                $result['errors'][] = '주문 조회 실패: ' . $orders_result['error'];
                break;
            }
            
            // 각 주문 처리
            foreach ($orders_result['data'] as $order) {
                $result['processed']++;
                
                $sync_result = $this->syncSingleOrderToLocal($order);
                
                if ($sync_result['success']) {
                    if ($sync_result['is_new']) {
                        $result['new_orders']++;
                    } else {
                        $result['updated_orders']++;
                    }
                } else {
                    $result['errors'][] = array(
                        'order_id' => $order['orderId'],
                        'error' => $sync_result['error']
                    );
                }
            }
            
            $next_token = $orders_result['nextToken'];
            
            // API 제한을 위한 지연
            if (!empty($next_token)) {
                sleep(1);
            }
            
        } while (!empty($next_token));
        
        $this->log('INFO', '주문 동기화 완료', array(
            'processed' => $result['processed'],
            'new_orders' => $result['new_orders'],
            'updated_orders' => $result['updated_orders'],
            'error_count' => count($result['errors'])
        ));
        
        return $result;
    }

    /**
     * 취소 주문 동기화 (기존 메서드 개선)
     * @param int $hours 동기화할 시간 범위
     * @return array 동기화 결과
     */
    public function syncCancelledOrdersFromCoupang($hours = 1) {
        $this->log('INFO', '취소 주문 동기화 시작', array('hours' => $hours));
        
        $result = array(
            'success' => true,
            'processed' => 0,
            'cancelled_orders' => 0,
            'errors' => array()
        );
        
        // 동기화 시간 범위 설정
        $from_time = date('Y-m-d\TH:i:s\Z', strtotime("-{$hours} hours"));
        $to_time = date('Y-m-d\TH:i:s\Z');
        
        $search_params = array(
            'requestedAtFrom' => $from_time,
            'requestedAtTo' => $to_time,
            'maxPerPage' => 100
        );
        
        $next_token = '';
        do {
            if (!empty($next_token)) {
                $search_params['nextToken'] = $next_token;
            }
            
            $cancelled_result = $this->getCancelledOrders($search_params);
            
            if (!$cancelled_result['success']) {
                $result['success'] = false;
                $result['errors'][] = '취소 주문 조회 실패: ' . $cancelled_result['error'];
                break;
            }
            
            // 각 취소 주문 처리
            foreach ($cancelled_result['data'] as $cancelled_order) {
                $result['processed']++;
                
                $cancel_result = $this->processCancelledOrderToLocal($cancelled_order);
                
                if ($cancel_result['success']) {
                    $result['cancelled_orders']++;
                } else {
                    $result['errors'][] = array(
                        'order_id' => $cancelled_order['orderId'],
                        'error' => $cancel_result['error']
                    );
                }
            }
            
            $next_token = $cancelled_result['nextToken'];
            
            // API 제한을 위한 지연
            if (!empty($next_token)) {
                sleep(1);
            }
            
        } while (!empty($next_token));
        
        $this->log('INFO', '취소 주문 동기화 완료', array(
            'processed' => $result['processed'],
            'cancelled_orders' => $result['cancelled_orders'],
            'error_count' => count($result['errors'])
        ));
        
        return $result;
    }

    // ==================== 주문 관련 Private 헬퍼 메서드들 ====================
    
    /**
     * 배송 데이터 유효성 검증
     * @param array $shipping_data 배송 데이터
     * @return array 검증 결과
     */
    private function validateShippingData($shipping_data) {
        $validation_result = array(
            'success' => true,
            'errors' => array()
        );
        
        // 송장번호 검증
        if (isset($shipping_data['tracking_number'])) {
            $tracking_number = trim($shipping_data['tracking_number']);
            if (empty($tracking_number)) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '송장번호가 비어있습니다.';
            } elseif (strlen($tracking_number) < 5) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '송장번호가 너무 짧습니다.';
            }
        }
        
        // 택배사 검증
        if (isset($shipping_data['delivery_company'])) {
            $delivery_company = trim($shipping_data['delivery_company']);
            if (empty($delivery_company)) {
                $validation_result['success'] = false;
                $validation_result['errors'][] = '택배사가 지정되지 않았습니다.';
            }
        }
        
        return $validation_result;
    }

    /**
     * 택배사명을 쿠팡 코드로 변환
     * @param string $delivery_company 택배사명
     * @return string 쿠팡 택배사 코드
     */
    private function getCoupangDeliveryCode($delivery_company) {
        global $COUPANG_DELIVERY_COMPANIES;
        
        if (isset($COUPANG_DELIVERY_COMPANIES[$delivery_company])) {
            return $COUPANG_DELIVERY_COMPANIES[$delivery_company];
        }
        
        // 기본값
        return 'ETC';
    }

    /**
     * 단일 주문을 로컬 DB에 동기화
     * @param array $order_data 쿠팡 주문 데이터
     * @return array 동기화 결과
     */
    private function syncSingleOrderToLocal($order_data) {
        if (!isset($order_data['orderId'])) {
            return array(
                'success' => false,
                'error' => '주문 ID가 없습니다.',
                'is_new' => false
            );
        }
        
        $order_id = $order_data['orderId'];
        
        // 기존 주문 확인
        $existing_order = $this->getOrderFromLocal($order_id);
        $is_new = empty($existing_order);
        
        try {
            if ($is_new) {
                // 새 주문 생성
                $this->createOrderInLocal($order_data);
            } else {
                // 기존 주문 업데이트
                $this->updateOrderInLocal($order_id, $order_data);
            }
            
            return array(
                'success' => true,
                'is_new' => $is_new,
                'order_id' => $order_id
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'is_new' => $is_new
            );
        }
    }

    /**
     * 취소 주문을 로컬에서 처리
     * @param array $cancelled_order 취소 주문 데이터
     * @return array 처리 결과
     */
    private function processCancelledOrderToLocal($cancelled_order) {
        if (!isset($cancelled_order['orderId'])) {
            return array(
                'success' => false,
                'error' => '주문 ID가 없습니다.'
            );
        }
        
        $order_id = $cancelled_order['orderId'];
        
        try {
            // 로컬 주문 상태를 취소로 변경
            $sql = "UPDATE " . G5_TABLE_PREFIX . "yc_order SET 
                    od_status = 'CANCELLED',
                    od_cancel_date = NOW(),
                    coupang_sync_status = 'CANCELLED'
                    WHERE coupang_order_id = '" . addslashes($order_id) . "'";
            
            sql_query($sql);
            
            // 취소 로그 기록
            $this->logOrderCancellation($order_id, $cancelled_order);
            
            return array(
                'success' => true,
                'order_id' => $order_id
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * 로컬 DB에서 주문 조회
     * @param string $coupang_order_id 쿠팡 주문 ID
     * @return array|null 주문 정보
     */
    private function getOrderFromLocal($coupang_order_id) {
        $sql = "SELECT * FROM " . G5_TABLE_PREFIX . "yc_order 
                WHERE coupang_order_id = '" . addslashes($coupang_order_id) . "' 
                LIMIT 1";
        
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            return $row;
        }
        
        return null;
    }

    /**
     * 로컬 DB에 새 주문 생성
     * @param array $order_data 쿠팡 주문 데이터
     */
    private function createOrderInLocal($order_data) {
        // 주문 데이터를 영카트 형식으로 변환
        $yc_order_data = $this->convertCoupangToYoungcartOrder($order_data);
        
        // 영카트 주문 테이블에 삽입
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "yc_order 
                (od_id, coupang_order_id, coupang_vendor_id, mb_id, od_name, 
                 od_email, od_tel, od_hp, od_addr1, od_addr2, od_addr3, 
                 od_price, od_status, od_time, coupang_sync_status) VALUES 
                ('" . addslashes($yc_order_data['od_id']) . "', 
                 '" . addslashes($order_data['orderId']) . "', 
                 '" . addslashes($this->vendor_id) . "', 
                 '" . addslashes($yc_order_data['mb_id']) . "', 
                 '" . addslashes($yc_order_data['od_name']) . "', 
                 '" . addslashes($yc_order_data['od_email']) . "', 
                 '" . addslashes($yc_order_data['od_tel']) . "', 
                 '" . addslashes($yc_order_data['od_hp']) . "', 
                 '" . addslashes($yc_order_data['od_addr1']) . "', 
                 '" . addslashes($yc_order_data['od_addr2']) . "', 
                 '" . addslashes($yc_order_data['od_addr3']) . "', 
                 " . intval($yc_order_data['od_price']) . ", 
                 'ACCEPT', NOW(), 'SYNCED')";
        
        sql_query($sql);
    }

    /**
     * 로컬 DB 주문 정보 업데이트
     * @param string $coupang_order_id 쿠팡 주문 ID
     * @param array $order_data 쿠팡 주문 데이터
     */
    private function updateOrderInLocal($coupang_order_id, $order_data) {
        $status = isset($order_data['orderStatus']) ? $order_data['orderStatus'] : 'ACCEPT';
        
        $sql = "UPDATE " . G5_TABLE_PREFIX . "yc_order SET 
                od_status = '" . addslashes($status) . "',
                coupang_sync_status = 'SYNCED',
                od_modify_time = NOW()
                WHERE coupang_order_id = '" . addslashes($coupang_order_id) . "'";
        
        sql_query($sql);
    }

    /**
     * 쿠팡 주문을 영카트 형식으로 변환
     * @param array $coupang_order 쿠팡 주문 데이터
     * @return array 영카트 주문 데이터
     */
    private function convertCoupangToYoungcartOrder($coupang_order) {
        return array(
            'od_id' => 'CP' . date('ymd') . '_' . substr($coupang_order['orderId'], -8),
            'mb_id' => '', // 쿠팡 주문은 비회원 처리
            'od_name' => isset($coupang_order['receiver']['name']) ? $coupang_order['receiver']['name'] : '',
            'od_email' => isset($coupang_order['orderer']['email']) ? $coupang_order['orderer']['email'] : '',
            'od_tel' => isset($coupang_order['receiver']['tel']) ? $coupang_order['receiver']['tel'] : '',
            'od_hp' => isset($coupang_order['receiver']['phone']) ? $coupang_order['receiver']['phone'] : '',
            'od_addr1' => isset($coupang_order['receiver']['addr1']) ? $coupang_order['receiver']['addr1'] : '',
            'od_addr2' => isset($coupang_order['receiver']['addr2']) ? $coupang_order['receiver']['addr2'] : '',
            'od_addr3' => isset($coupang_order['receiver']['addr3']) ? $coupang_order['receiver']['addr3'] : '',
            'od_price' => isset($coupang_order['paidAt']) ? intval($coupang_order['totalPrice']) : 0
        );
    }

    /**
     * 주문 배송 정보를 로컬 DB에 업데이트
     * @param string $order_id 쿠팡 주문 ID
     * @param array $shipping_data 배송 정보
     */
    private function updateOrderShippingInLocal($order_id, $shipping_data) {
        $sql = "UPDATE " . G5_TABLE_PREFIX . "yc_order SET 
                od_invoice = '" . addslashes(isset($shipping_data['tracking_number']) ? $shipping_data['tracking_number'] : '') . "',
                od_invoice_time = NOW(),
                od_status = 'SHIP'
                WHERE coupang_order_id = '" . addslashes($order_id) . "'";
        
        sql_query($sql);
    }

    /**
     * 주문 취소 로그 기록
     * @param string $order_id 주문 ID
     * @param array $cancelled_data 취소 데이터
     */
    private function logOrderCancellation($order_id, $cancelled_data) {
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_order_log 
                (coupang_order_id, log_type, log_message, created_date) VALUES 
                ('" . addslashes($order_id) . "', 
                 'CANCEL', 
                 '" . addslashes('주문 취소: ' . json_encode($cancelled_data, JSON_UNESCAPED_UNICODE)) . "', 
                 NOW())";
        
        sql_query($sql);
    }

    // ==================== 섹션 7: 클래스 종료 및 전역 함수들 ====================
    
    /**
     * 영카트 주문 상태를 쿠팡에 동기화 (기존 메서드 개선)
     * @param int $limit 동기화할 주문 수
     * @return array 동기화 결과
     */
    public function syncOrderStatusToCoupang($limit = 20) {
        $this->log('INFO', '주문 상태 동기화 시작', array('limit' => $limit));
        
        $result = array(
            'success' => true,
            'processed' => 0,
            'updated' => 0,
            'failed' => 0,
            'orders' => array(),
            'errors' => array()
        );
        
        // 동기화 대상 주문 조회 (영카트에서 상태가 변경된 주문들)
        $sql = "SELECT od.*, cim.coupang_product_id
                FROM " . G5_TABLE_PREFIX . "yc_order od
                INNER JOIN " . G5_TABLE_PREFIX . "coupang_item_map cim ON od.od_id = cim.youngcart_item_id
                WHERE od.coupang_order_id IS NOT NULL 
                    AND od.coupang_sync_status != od.od_status
                    AND od.od_status IN ('SHIP', 'DELIVER', 'CONFIRM')
                ORDER BY od.od_modify_time DESC 
                LIMIT " . intval($limit);
        
        $query_result = sql_query($sql);
        
        while ($row = sql_fetch_array($query_result)) {
            $result['processed']++;
            
            // 주문 상태에 따른 처리
            $sync_result = null;
            switch ($row['od_status']) {
                case 'SHIP':
                    // 배송 정보 업데이트
                    if (!empty($row['od_invoice'])) {
                        $shipping_data = array(
                            'tracking_number' => $row['od_invoice'],
                            'delivery_company' => $row['od_invoice_company'] ? $row['od_invoice_company'] : '기타'
                        );
                        $sync_result = $this->updateShippingInfo($row['coupang_order_id'], $shipping_data);
                    }
                    break;
                    
                case 'DELIVER':
                case 'CONFIRM':
                    // 배송완료/구매확정은 별도 API가 있다면 처리
                    $sync_result = array('success' => true, 'message' => '배송완료 상태는 자동 처리됩니다.');
                    break;
            }
            
            if ($sync_result && $sync_result['success']) {
                $result['updated']++;
                $result['orders'][] = array(
                    'od_id' => $row['od_id'],
                    'coupang_order_id' => $row['coupang_order_id'],
                    'status' => $row['od_status']
                );
                
                // 동기화 상태 업데이트
                $this->updateOrderSyncStatusInLocal($row['od_id'], $row['od_status']);
                
            } else {
                $result['failed']++;
                $result['errors'][] = array(
                    'od_id' => $row['od_id'],
                    'coupang_order_id' => $row['coupang_order_id'],
                    'error' => $sync_result ? $sync_result['error'] : '동기화 처리 실패'
                );
            }
            
            // API 제한을 위한 지연
            sleep(1);
        }
        
        $this->log('INFO', '주문 상태 동기화 완료', array(
            'processed' => $result['processed'],
            'updated' => $result['updated'],
            'failed' => $result['failed']
        ));
        
        return $result;
    }

    /**
     * API 상태 확인 (헬스체크)
     * @return array 상태 확인 결과
     */
    public function checkApiHealth() {
        $health_result = array(
            'success' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'api_status' => 'unknown',
            'response_time' => 0,
            'checks' => array()
        );
        
        $start_time = microtime(true);
        
        try {
            // 간단한 API 호출로 상태 확인 (카테고리 목록 조회)
            $test_result = $this->getCategoryList();
            
            $health_result['response_time'] = round((microtime(true) - $start_time) * 1000, 2); // ms
            
            if ($test_result['success']) {
                $health_result['api_status'] = 'healthy';
                $health_result['checks'][] = array(
                    'name' => 'API 연결',
                    'status' => 'pass',
                    'message' => 'API 서버 정상 응답'
                );
            } else {
                $health_result['success'] = false;
                $health_result['api_status'] = 'unhealthy';
                $health_result['checks'][] = array(
                    'name' => 'API 연결',
                    'status' => 'fail',
                    'message' => $test_result['error']
                );
            }
            
        } catch (Exception $e) {
            $health_result['success'] = false;
            $health_result['api_status'] = 'error';
            $health_result['response_time'] = round((microtime(true) - $start_time) * 1000, 2);
            $health_result['checks'][] = array(
                'name' => 'API 연결',
                'status' => 'error',
                'message' => $e->getMessage()
            );
        }
        
        // 설정 검증 추가
        $config_check = $this->validateAllConfig();
        $health_result['checks'][] = array(
            'name' => '설정 검증',
            'status' => $config_check['success'] ? 'pass' : 'fail',
            'message' => $config_check['success'] ? '설정 정상' : '설정 오류: ' . implode(', ', $config_check['errors'])
        );
        
        return $health_result;
    }

    /**
     * 통계 정보 조회
     * @param array $options 옵션
     * @return array 통계 결과
     */
    public function getStatistics($options = array()) {
        $default_options = array(
            'period' => 'today', // today, week, month
            'include_details' => false
        );
        $options = array_merge($default_options, $options);
        
        $stats = array(
            'period' => $options['period'],
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => array(),
            'details' => array()
        );
        
        // 기간 설정
        $date_condition = $this->getDateConditionForPeriod($options['period']);
        
        // 주문 통계
        $order_stats = $this->getOrderStatistics($date_condition);
        $stats['summary']['orders'] = $order_stats;
        
        // 상품 통계
        $product_stats = $this->getProductStatistics($date_condition);
        $stats['summary']['products'] = $product_stats;
        
        // 동기화 통계
        $sync_stats = $this->getSyncStatistics($date_condition);
        $stats['summary']['sync'] = $sync_stats;
        
        // 상세 정보 포함 시
        if ($options['include_details']) {
            $stats['details'] = array(
                'recent_orders' => $this->getRecentOrdersDetail(10),
                'sync_errors' => $this->getRecentSyncErrors(5),
                'category_mappings' => $this->getCategoryMappingStats()
            );
        }
        
        return $stats;
    }

    // ==================== Private 통계 및 상태 관리 메서드들 ====================
    
    /**
     * 주문 동기화 상태를 로컬 DB에 업데이트
     * @param string $od_id 영카트 주문 ID
     * @param string $status 동기화된 상태
     */
    private function updateOrderSyncStatusInLocal($od_id, $status) {
        $sql = "UPDATE " . G5_TABLE_PREFIX . "yc_order SET 
                coupang_sync_status = '" . addslashes($status) . "',
                od_modify_time = NOW()
                WHERE od_id = '" . addslashes($od_id) . "'";
        
        sql_query($sql);
    }

    /**
     * 기간별 날짜 조건 생성
     * @param string $period 기간 (today, week, month)
     * @return string SQL 날짜 조건
     */
    private function getDateConditionForPeriod($period) {
        switch ($period) {
            case 'today':
                return "DATE(created_date) = CURDATE()";
            case 'week':
                return "created_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case 'month':
                return "created_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            default:
                return "1=1"; // 전체
        }
    }

    /**
     * 주문 통계 조회
     * @param string $date_condition 날짜 조건
     * @return array 주문 통계
     */
    private function getOrderStatistics($date_condition) {
        $stats = array(
            'total_orders' => 0,
            'new_orders' => 0,
            'shipped_orders' => 0,
            'cancelled_orders' => 0,
            'total_amount' => 0
        );
        
        // 전체 주문 수
        $sql = "SELECT COUNT(*) as cnt, SUM(od_price) as total_price 
                FROM " . G5_TABLE_PREFIX . "yc_order 
                WHERE coupang_order_id IS NOT NULL AND {$date_condition}";
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            $stats['total_orders'] = intval($row['cnt']);
            $stats['total_amount'] = intval($row['total_price']);
        }
        
        // 상태별 주문 수
        $status_counts = array('ACCEPT' => 'new_orders', 'SHIP' => 'shipped_orders', 'CANCELLED' => 'cancelled_orders');
        foreach ($status_counts as $status => $key) {
            $sql = "SELECT COUNT(*) as cnt 
                    FROM " . G5_TABLE_PREFIX . "yc_order 
                    WHERE coupang_order_id IS NOT NULL 
                        AND od_status = '{$status}' 
                        AND {$date_condition}";
            $result = sql_query($sql);
            if ($row = sql_fetch_array($result)) {
                $stats[$key] = intval($row['cnt']);
            }
        }
        
        return $stats;
    }

    /**
     * 상품 통계 조회
     * @param string $date_condition 날짜 조건
     * @return array 상품 통계
     */
    private function getProductStatistics($date_condition) {
        $stats = array(
            'total_products' => 0,
            'synced_products' => 0,
            'pending_approval' => 0,
            'approved_products' => 0
        );
        
        // 전체 상품 수
        $sql = "SELECT COUNT(*) as cnt 
                FROM " . G5_TABLE_PREFIX . "coupang_item_map 
                WHERE {$date_condition}";
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            $stats['total_products'] = intval($row['cnt']);
        }
        
        // 동기화된 상품 수
        $sql = "SELECT COUNT(*) as cnt 
                FROM " . G5_TABLE_PREFIX . "coupang_item_map 
                WHERE coupang_product_id IS NOT NULL AND {$date_condition}";
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            $stats['synced_products'] = intval($row['cnt']);
        }
        
        // 승인 관련 통계
        $approval_counts = array('REQUESTED' => 'pending_approval', 'APPROVED' => 'approved_products');
        foreach ($approval_counts as $status => $key) {
            $sql = "SELECT COUNT(*) as cnt 
                    FROM " . G5_TABLE_PREFIX . "coupang_item_map 
                    WHERE approval_status = '{$status}' AND {$date_condition}";
            $result = sql_query($sql);
            if ($row = sql_fetch_array($result)) {
                $stats[$key] = intval($row['cnt']);
            }
        }
        
        return $stats;
    }

    /**
     * 동기화 통계 조회
     * @param string $date_condition 날짜 조건
     * @return array 동기화 통계
     */
    private function getSyncStatistics($date_condition) {
        $stats = array(
            'total_sync_jobs' => 0,
            'successful_syncs' => 0,
            'failed_syncs' => 0,
            'last_sync_time' => null
        );
        
        // 크론 로그 기반 통계
        $sql = "SELECT COUNT(*) as cnt, status 
                FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
                WHERE {$date_condition} 
                GROUP BY status";
        $result = sql_query($sql);
        while ($row = sql_fetch_array($result)) {
            $stats['total_sync_jobs'] += intval($row['cnt']);
            if ($row['status'] === 'success') {
                $stats['successful_syncs'] = intval($row['cnt']);
            } elseif ($row['status'] === 'error') {
                $stats['failed_syncs'] = intval($row['cnt']);
            }
        }
        
        // 마지막 동기화 시간
        $sql = "SELECT MAX(created_date) as last_sync 
                FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
                WHERE status = 'success'";
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            $stats['last_sync_time'] = $row['last_sync'];
        }
        
        return $stats;
    }

    /**
     * 최근 주문 상세 정보
     * @param int $limit 조회할 건수
     * @return array 최근 주문 목록
     */
    private function getRecentOrdersDetail($limit) {
        $orders = array();
        
        $sql = "SELECT od_id, coupang_order_id, od_name, od_price, od_status, od_time 
                FROM " . G5_TABLE_PREFIX . "yc_order 
                WHERE coupang_order_id IS NOT NULL 
                ORDER BY od_time DESC 
                LIMIT " . intval($limit);
        
        $result = sql_query($sql);
        while ($row = sql_fetch_array($result)) {
            $orders[] = $row;
        }
        
        return $orders;
    }

    /**
     * 최근 동기화 오류 조회
     * @param int $limit 조회할 건수
     * @return array 오류 목록
     */
    private function getRecentSyncErrors($limit) {
        $errors = array();
        
        $sql = "SELECT cron_type, message, created_date 
                FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
                WHERE status = 'error' 
                ORDER BY created_date DESC 
                LIMIT " . intval($limit);
        
        $result = sql_query($sql);
        while ($row = sql_fetch_array($result)) {
            $errors[] = $row;
        }
        
        return $errors;
    }

    /**
     * 카테고리 매핑 통계
     * @return array 매핑 통계
     */
    private function getCategoryMappingStats() {
        $stats = array(
            'total_mappings' => 0,
            'high_confidence' => 0,
            'low_confidence' => 0
        );
        
        $sql = "SELECT COUNT(*) as cnt, 
                SUM(CASE WHEN confidence >= 0.8 THEN 1 ELSE 0 END) as high_conf,
                SUM(CASE WHEN confidence < 0.5 THEN 1 ELSE 0 END) as low_conf
                FROM " . G5_TABLE_PREFIX . "coupang_category_map";
        
        $result = sql_query($sql);
        if ($row = sql_fetch_array($result)) {
            $stats['total_mappings'] = intval($row['cnt']);
            $stats['high_confidence'] = intval($row['high_conf']);
            $stats['low_confidence'] = intval($row['low_conf']);
        }
        
        return $stats;
    }

} // ==================== CoupangAPI 클래스 종료 ====================

// ===================== 전역 헬퍼 함수들 =====================

/**
 * Coupang API 인스턴스 생성 (개선된 버전)
 * @param array $custom_config 사용자 정의 설정 (선택)
 * @return CoupangAPI|null API 인스턴스 또는 null
 */
function get_coupang_api($custom_config = array()) {
    try {
        $default_config = array(
            'access_key' => COUPANG_ACCESS_KEY,
            'secret_key' => COUPANG_SECRET_KEY,
            'vendor_id'  => COUPANG_VENDOR_ID
        );
        
        $config = array_merge($default_config, $custom_config);
        
        // 설정 검증
        foreach ($default_config as $key => $value) {
            if (empty($config[$key])) {
                throw new Exception("쿠팡 API 설정 누락: {$key}");
            }
        }
        
        return new CoupangAPI($config);
    } catch (Exception $e) {
        if (function_exists('coupang_log')) {
            coupang_log('ERROR', 'API 인스턴스 생성 실패', array('error' => $e->getMessage()));
        }
        return null;
    }
}

/**
 * 크론 실행 상태 기록 (개선된 버전)
 * @param string $cron_type 크론 타입
 * @param string $status 실행 상태
 * @param string $message 메시지
 * @param float $execution_duration 실행 시간 (초)
 * @param array $additional_data 추가 데이터
 * @return bool 기록 성공 여부
 */
function monitor_cron_execution($cron_type, $status, $message = '', $execution_duration = null, $additional_data = array()) {
    try {
        global $g5;
        
        $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_cron_log SET 
                cron_type = '" . addslashes($cron_type) . "',
                status = '" . addslashes($status) . "',
                message = '" . addslashes($message) . "'";
        
        if ($execution_duration !== null) {
            $sql .= ", execution_duration = " . floatval($execution_duration);
        }
        
        if (!empty($additional_data)) {
            $sql .= ", additional_data = '" . addslashes(json_encode($additional_data, JSON_UNESCAPED_UNICODE)) . "'";
        }
        
        $sql .= ", created_date = NOW()";
        
        return sql_query($sql) ? true : false;
    } catch (Exception $e) {
        error_log("크론 로그 기록 실패: " . $e->getMessage());
        return false;
    }
}

/**
 * 쿠팡 로그 기록 함수 (통합된 로깅)
 * @param string $level 로그 레벨 (DEBUG, INFO, WARNING, ERROR)
 * @param string $message 로그 메시지
 * @param array $context 추가 컨텍스트
 * @param string $log_file 로그 파일명 (선택)
 */
function coupang_log($level, $message, $context = array(), $log_file = 'general.log') {
    // 로그 레벨 필터링
    if (!should_log_level($level)) {
        return;
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$level}] {$message}";
    
    // 컨텍스트 추가
    if (!empty($context)) {
        $log_entry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    
    $log_entry .= "\n";
    
    // 로그 파일 경로
    $log_dir = COUPANG_PLUGIN_PATH . '/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_path = $log_dir . '/' . $log_file;
    
    // 로그 파일 크기 체크 (10MB 초과 시 로테이션)
    if (file_exists($log_path) && filesize($log_path) > 10 * 1024 * 1024) {
        $backup_path = $log_path . '.' . date('Y-m-d-H-i-s');
        rename($log_path, $backup_path);
    }
    
    // 로그 기록
    file_put_contents($log_path, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * 로그 레벨 확인
 * @param string $level 확인할 레벨
 * @return bool 로그 기록 여부
 */
function should_log_level($level) {
    $levels = array('DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3);
    $current_level = defined('COUPANG_LOG_LEVEL') ? COUPANG_LOG_LEVEL : 'INFO';
    
    $level_value = isset($levels[$level]) ? $levels[$level] : 1;
    $current_value = isset($levels[$current_level]) ? $levels[$current_level] : 1;
    
    return $level_value >= $current_value;
}

// ===================== 개선된 크론 래퍼 함수들 =====================

/**
 * 주문 동기화 크론 함수 (개선)
 * @param int $hours 동기화할 시간 범위
 * @return array 동기화 결과
 */
function cron_sync_orders_from_coupang($hours = 1) {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return array('success' => false, 'error' => 'API 인스턴스 생성 실패');
    }
    return $coupang_api->syncOrdersFromCoupang($hours);
}

/**
 * 취소 주문 동기화 크론 함수 (개선)
 * @param int $hours 동기화할 시간 범위
 * @return array 동기화 결과
 */
function cron_sync_cancelled_orders_from_coupang($hours = 1) {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return array('success' => false, 'error' => 'API 인스턴스 생성 실패');
    }
    return $coupang_api->syncCancelledOrdersFromCoupang($hours);
}

/**
 * 주문 상태 동기화 크론 함수 (개선)
 * @param int $limit 동기화할 주문 수
 * @return array 동기화 결과
 */
function cron_sync_order_status_to_coupang($limit = 20) {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return array('success' => false, 'error' => 'API 인스턴스 생성 실패');
    }
    return $coupang_api->syncOrderStatusToCoupang($limit);
}

/**
 * 상품 동기화 크론 함수 (개선)
 * @param int $limit 동기화할 상품 수
 * @return array 동기화 결과
 */
function cron_sync_products_to_coupang($limit = 10) {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return array('success' => false, 'error' => 'API 인스턴스 생성 실패');
    }
    return $coupang_api->syncProductsToCoupang($limit);
}

/**
 * 재고 동기화 크론 함수 (개선)
 * @param int $limit 동기화할 상품 수
 * @return array 동기화 결과
 */
function cron_sync_stock_to_coupang($limit = 20) {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return array('success' => false, 'error' => 'API 인스턴스 생성 실패');
    }
    return $coupang_api->syncStockToCoupang($limit);
}

/**
 * 카테고리 추천 배치 크론 함수 (개선)
 * @param int $limit 처리할 상품 수
 * @return array 처리 결과
 */
function cron_batch_category_recommendations($limit = 20) {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return array('success' => false, 'error' => 'API 인스턴스 생성 실패');
    }
    return $coupang_api->batchGetCategoryRecommendations($limit);
}

/**
 * 카테고리 캐시 정리 크론 함수 (개선)
 * @param int $days 보관 기간
 * @return int 삭제된 레코드 수
 */
function cron_cleanup_category_cache($days = 7) {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return 0;
    }
    return $coupang_api->cleanupCategoryCache($days);
}

/**
 * 출고지 동기화 크론 함수 (신규)
 * @return array 동기화 결과
 */
function cron_sync_shipping_places() {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return array('success' => false, 'error' => 'API 인스턴스 생성 실패');
    }
    return $coupang_api->syncShippingPlacesFromCoupang();
}

/**
 * API 헬스체크 크론 함수 (신규)
 * @return array 헬스체크 결과
 */
function cron_api_health_check() {
    $coupang_api = get_coupang_api();
    if (!$coupang_api) {
        return array('success' => false, 'error' => 'API 인스턴스 생성 실패');
    }
    return $coupang_api->checkApiHealth();
}

// ===================== 호환성 함수들 =====================

/**
 * 레거시 상품 상태 동기화 함수 (호환성 유지)
 * @return bool 성공 여부
 */
function cron_sync_product_status_to_coupang() {
    // 기존 복잡한 로직이 있었다면 여기서 처리
    // 현재는 단순히 재고 동기화로 대체
    $result = cron_sync_stock_to_coupang(10);
    return $result['success'];
}

/**
 * 플러그인 정보 조회
 * @return array 플러그인 정보
 */
function get_coupang_plugin_info() {
    return array(
        'name' => '쿠팡 연동 플러그인',
        'version' => COUPANG_PLUGIN_VERSION,
        'description' => '영카트와 쿠팡 판매자 API 연동',
        'author' => '그누위즈',
        'active' => COUPANG_PLUGIN_ACTIVE,
        'last_update' => file_exists(COUPANG_PLUGIN_PATH . '/version.json') ? 
                        filemtime(COUPANG_PLUGIN_PATH . '/version.json') : time()
    );
}

?>