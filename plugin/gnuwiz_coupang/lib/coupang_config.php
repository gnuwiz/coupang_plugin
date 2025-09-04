<?php
/**
 * 쿠팡 API 설정 파일
 * 경로: /plugin/coupang/lib/coupang_config.php
 * 용도: API 키 및 기본 설정 관리
 */

if (!defined('_GNUBOARD_')) exit; // 직접 접근 금지

// === 쿠팡 플러그인 디렉터리 ===
//define('COUPANG_PLUGIN_PATH', dirname(__FILE__));
//define('YOUNGCART_ROOT', dirname(dirname(dirname(__FILE__))));

// === 쿠팡 API 인증 정보 ===
// 쿠팡 파트너스 센터에서 발급받은 정보를 입력하세요
define('COUPANG_ACCESS_KEY', '7f35aeef-fb5a-47bf-bb99-9099a1d6ad3e');
define('COUPANG_SECRET_KEY', '123622c45cf4eb91324229bc77e45ee28cb8862e');
define('COUPANG_VENDOR_ID', 'A00509424');

// === API 호출 제한 설정 ===
define('COUPANG_API_DELAY', 1);        // API 호출 간 지연 시간 (초)
define('COUPANG_MAX_RETRY', 3);        // 재시도 횟수
define('COUPANG_TIMEOUT', 30);         // 타임아웃 (초)

// === 로그 레벨 설정 ===
define('COUPANG_LOG_LEVEL', 'INFO');   // DEBUG, INFO, WARNING, ERROR

// === 동기화 배치 크기 ===
define('COUPANG_ORDER_BATCH_SIZE', 50);      // 주문 동기화 한번에 처리할 건수
define('COUPANG_PRODUCT_BATCH_SIZE', 100);   // 상품 동기화 한번에 처리할 건수
define('COUPANG_STOCK_BATCH_SIZE', 200);     // 재고 동기화 한번에 처리할 건수

// === 쿠팡 배송업체 코드 매핑 ===
// 영카트 배송업체명을 쿠팡 코드로 변환
$GLOBALS['COUPANG_DELIVERY_COMPANIES'] = array(
    'CJ대한통운' => 'CJGLS',
    '한진택배' => 'HANJIN',
    '롯데택배' => 'LOTTE',
    '로젠택배' => 'LOGEN',
    '우체국택배' => 'EPOST',
    '대신택배' => 'DAESIN',
    '일양로지스' => 'ILYANG',
    '합동택배' => 'HDEXP',
    '건영택배' => 'KUNYOUNG',
    '천일택배' => 'CHUNIL',
    '기타' => 'ETC'
);

// === 설정 검증 함수 ===
function validate_coupang_config() {
    $errors = array();
    
    if (COUPANG_ACCESS_KEY === 'YOUR_ACCESS_KEY_HERE') {
        $errors[] = 'ACCESS_KEY가 설정되지 않았습니다.';
    }
    
    if (COUPANG_SECRET_KEY === 'YOUR_SECRET_KEY_HERE') {
        $errors[] = 'SECRET_KEY가 설정되지 않았습니다.';
    }
    
    if (COUPANG_VENDOR_ID === 'YOUR_VENDOR_ID_HERE') {
        $errors[] = 'VENDOR_ID가 설정되지 않았습니다.';
    }
    
    if (!function_exists('curl_init')) {
        $errors[] = 'cURL 확장이 설치되지 않았습니다.';
    }
    
    return array(
        'valid' => empty($errors),
        'errors' => $errors
    );
}

?>