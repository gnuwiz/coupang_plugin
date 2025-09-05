<?php
// ============================================================================
// 파일 2: admin/shipping_place_test.php  
// ============================================================================
/**
 * 출고지/반품지 연결 테스트 AJAX 스크립트
 */
?>
<?php
include_once('./_common.php');

if (!$is_admin) {
    die(json_encode(array('success' => false, 'message' => '관리자만 접근 가능합니다.')));
}

include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

$action = isset($_POST['action']) ? $_POST['action'] : '';
$shipping_place_code = isset($_POST['code']) ? clean_xss_tags($_POST['code']) : '';

header('Content-Type: application/json');

try {
    $coupang_api = get_coupang_api();
    
    switch ($action) {
        case 'test':
            if (!$shipping_place_code) {
                throw new Exception('출고지/반품지 코드가 지정되지 않았습니다.');
            }
            
            // API 연결 테스트
            $start_time = microtime(true);
            $result = $coupang_api->getShippingPlaceDetail($shipping_place_code);
            $execution_time = microtime(true) - $start_time;
            
            if ($result['success']) {
                $message = "연결 테스트 성공!\n";
                $message .= "- 응답시간: " . round($execution_time * 1000, 2) . "ms\n";
                $message .= "- 출고지/반품지명: " . (isset($result['data']['shippingPlaceName']) ? $result['data']['shippingPlaceName'] : '정보 없음');
                
                echo json_encode(array(
                    'success' => true,
                    'message' => $message,
                    'execution_time' => $execution_time,
                    'data' => $result['data']
                ));
            } else {
                throw new Exception('API 호출 실패: ' . $result['message']);
            }
            break;
            
        default:
            throw new Exception('지원하지 않는 액션입니다.');
    }
    
} catch (Exception $e) {
    echo json_encode(array(
        'success' => false,
        'message' => $e->getMessage()
    ));
}
?>
