<?php
// ============================================================================
// 파일 1: admin/shipping_place_sync.php
// ============================================================================
/**
 * 출고지/반품지 개별 동기화 AJAX 스크립트
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
        case 'sync_single':
            if (!$shipping_place_code) {
                throw new Exception('출고지/반품지 코드가 지정되지 않았습니다.');
            }
            
            // 단일 출고지/반품지 상세 조회 및 동기화
            $result = $coupang_api->getShippingPlaceDetail($shipping_place_code);
            
            if ($result['success']) {
                echo json_encode(array(
                    'success' => true,
                    'message' => '동기화가 완료되었습니다.',
                    'data' => $result['data']
                ));
            } else {
                throw new Exception($result['message']);
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