<?php
/**
 * ============================================================================
 * 쿠팡 연동 플러그인 - API 테스트 AJAX 처리
 * ============================================================================
 * 파일: /plugin/gnuwiz_coupang/ajax/api_test.php
 * 용도: API 테스트 AJAX 요청 전용 처리
 * 작성: 그누위즈 (gnuwiz@example.com)
 * 버전: 2.2.0 (Phase 2-2)
 *
 * 특징:
 * - HTML 출력 없는 순수 JSON 응답
 * - 실제 CoupangAPI 클래스 메서드 기반
 * - 오류 처리 및 로깅
 */

// 플러그인 공통 파일 로드
include_once(dirname(__DIR__) . '/_common.php');

// AJAX 요청이 아니면 차단
if (!isset($_POST['action']) || $_POST['action'] !== 'api_test') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(array(
        'success' => false,
        'message' => '잘못된 요청입니다.'
    ));
    exit;
}

// JSON 헤더 설정
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    // 요청 파라미터 파싱
    $test_type = isset($_POST['test_type']) ? $_POST['test_type'] : '';
    $test_params = isset($_POST['test_params']) ? $_POST['test_params'] : array();

    if (empty($test_type)) {
        throw new Exception('테스트 타입이 지정되지 않았습니다.');
    }

    // API 인스턴스 생성
    $coupang_api = get_coupang_api();

    if (!$coupang_api) {
        throw new Exception('API 인스턴스를 생성할 수 없습니다. 설정을 확인해주세요.');
    }

    // 실행 시간 측정 시작
    $start_time = microtime(true);
    $result = null;

    // 테스트 타입별 실행
    switch ($test_type) {
        // ========== 설정 검증 테스트 ==========
        case 'validate_all_config':
            if (!method_exists($coupang_api, 'validateAllConfig')) {
                throw new Exception('validateAllConfig 메서드가 구현되지 않았습니다.');
            }
            $result = $coupang_api->validateAllConfig();
            break;

        case 'validate_api_config':
            if (!method_exists($coupang_api, 'validateApiConfig')) {
                throw new Exception('validateApiConfig 메서드가 구현되지 않았습니다.');
            }
            $result = $coupang_api->validateApiConfig();
            break;

        case 'validate_shipping_config':
            if (!method_exists($coupang_api, 'validateShippingPlaceConfig')) {
                throw new Exception('validateShippingPlaceConfig 메서드가 구현되지 않았습니다.');
            }
            $result = $coupang_api->validateShippingPlaceConfig();
            break;

        case 'validate_product_config':
            if (!method_exists($coupang_api, 'validateProductConfig')) {
                throw new Exception('validateProductConfig 메서드가 구현되지 않았습니다.');
            }
            $result = $coupang_api->validateProductConfig();
            break;

        // ========== 카테고리 API 테스트 ==========
        case 'category_recommendation':
            if (!method_exists($coupang_api, 'getCategoryRecommendation')) {
                throw new Exception('getCategoryRecommendation 메서드가 구현되지 않았습니다.');
            }

            $product_name = isset($test_params['product_name']) ? trim($test_params['product_name']) : '테스트 상품';
            if (empty($product_name)) {
                throw new Exception('상품명이 필요합니다.');
            }

            $options = array();
            if (!empty($test_params['description'])) {
                $options['product_description'] = trim($test_params['description']);
            }
            if (!empty($test_params['brand'])) {
                $options['brand'] = trim($test_params['brand']);
            }

            $result = $coupang_api->getCategoryRecommendation($product_name, $options);
            break;

        case 'category_metadata':
            if (!method_exists($coupang_api, 'getCategoryMetadata')) {
                throw new Exception('getCategoryMetadata 메서드가 구현되지 않았습니다.');
            }

            $category_id = isset($test_params['category_id']) ? trim($test_params['category_id']) : '';
            if (empty($category_id)) {
                throw new Exception('카테고리 ID가 필요합니다.');
            }

            $result = $coupang_api->getCategoryMetadata($category_id);
            break;

        case 'category_list':
            if (!method_exists($coupang_api, 'getCategoryList')) {
                throw new Exception('getCategoryList 메서드가 구현되지 않았습니다.');
            }

            $parent_id = isset($test_params['parent_id']) && !empty($test_params['parent_id']) ?
                trim($test_params['parent_id']) : null;

            $result = $coupang_api->getCategoryList($parent_id);
            break;

        // ========== 출고지 API 테스트 ==========
        case 'shipping_places':
            if (!method_exists($coupang_api, 'getShippingPlaces')) {
                throw new Exception('getShippingPlaces 메서드가 구현되지 않았습니다.');
            }

            $result = $coupang_api->getShippingPlaces();
            break;

        case 'return_places':
            if (!method_exists($coupang_api, 'getReturnPlaces')) {
                throw new Exception('getReturnPlaces 메서드가 구현되지 않았습니다.');
            }

            $result = $coupang_api->getReturnPlaces();
            break;

        // ========== 상품 API 테스트 ==========
        case 'product_status':
            if (!method_exists($coupang_api, 'getProductStatus')) {
                throw new Exception('getProductStatus 메서드가 구현되지 않았습니다.');
            }

            $seller_product_id = isset($test_params['seller_product_id']) ?
                trim($test_params['seller_product_id']) : '';
            if (empty($seller_product_id)) {
                throw new Exception('판매자 상품 ID가 필요합니다.');
            }

            $result = $coupang_api->getProductStatus($seller_product_id);
            break;

        case 'product_list':
            if (!method_exists($coupang_api, 'getProductList')) {
                throw new Exception('getProductList 메서드가 구현되지 않았습니다.');
            }

            $options = array();
            if (isset($test_params['page']) && is_numeric($test_params['page'])) {
                $options['page'] = intval($test_params['page']);
            }
            if (isset($test_params['size']) && is_numeric($test_params['size'])) {
                $options['size'] = intval($test_params['size']);
            }

            $result = $coupang_api->getProductList($options);
            break;

        // ========== 주문 API 테스트 ==========
        case 'order_list':
            if (!method_exists($coupang_api, 'getOrderList')) {
                throw new Exception('getOrderList 메서드가 구현되지 않았습니다.');
            }

            $options = array();
            if (!empty($test_params['start_date'])) {
                $options['start_date'] = $test_params['start_date'];
            }
            if (!empty($test_params['end_date'])) {
                $options['end_date'] = $test_params['end_date'];
            }

            $result = $coupang_api->getOrderList($options);
            break;

        case 'order_detail':
            if (!method_exists($coupang_api, 'getOrderDetail')) {
                throw new Exception('getOrderDetail 메서드가 구현되지 않았습니다.');
            }

            $order_id = isset($test_params['order_id']) ? trim($test_params['order_id']) : '';
            if (empty($order_id)) {
                throw new Exception('주문 ID가 필요합니다.');
            }

            $result = $coupang_api->getOrderDetail($order_id);
            break;

        // ========== 유틸리티 테스트 ==========
        case 'cleanup_cache':
            if (!method_exists($coupang_api, 'cleanupCategoryCache')) {
                throw new Exception('cleanupCategoryCache 메서드가 구현되지 않았습니다.');
            }

            $days = isset($test_params['days']) && is_numeric($test_params['days']) ?
                intval($test_params['days']) : 7;

            $deleted_rows = $coupang_api->cleanupCategoryCache($days);
            $result = array(
                'success' => true,
                'deleted_rows' => $deleted_rows,
                'message' => "{$days}일 이전 캐시 {$deleted_rows}개 삭제됨"
            );
            break;

        case 'test_connection':
            // 간단한 연결 테스트 (카테고리 목록 조회)
            if (method_exists($coupang_api, 'getCategoryList')) {
                $result = $coupang_api->getCategoryList();
                if ($result['success']) {
                    $result['message'] = 'API 연결 테스트 성공';
                }
            } else {
                $result = array(
                    'success' => true,
                    'message' => 'API 인스턴스가 정상적으로 생성되었습니다.',
                    'api_class' => get_class($coupang_api),
                    'available_methods' => get_class_methods($coupang_api)
                );
            }
            break;

        default:
            throw new Exception('지원하지 않는 테스트 타입입니다: ' . $test_type);
    }

    // 실행 시간 계산
    $execution_time = round((microtime(true) - $start_time) * 1000, 2);

    // 성공 응답
    $response = array(
        'success' => true,
        'result' => $result,
        'execution_time' => $execution_time . ' ms',
        'test_type' => $test_type,
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time()
    );

    // 로그 기록 (선택적)
    if (function_exists('coupang_log')) {
        coupang_log('INFO', 'API 테스트 실행', array(
            'test_type' => $test_type,
            'success' => true,
            'execution_time' => $execution_time
        ));
    }

    echo json_encode($response);

} catch (Exception $e) {
    // 실행 시간 계산 (오류 시에도)
    $execution_time = isset($start_time) ?
        round((microtime(true) - $start_time) * 1000, 2) : 0;

    // 오류 응답
    $error_response = array(
        'success' => false,
        'message' => $e->getMessage(),
        'execution_time' => $execution_time . ' ms',
        'test_type' => isset($test_type) ? $test_type : 'unknown',
        'timestamp' => date('Y-m-d H:i:s'),
        'error_line' => $e->getLine(),
        'error_file' => basename($e->getFile())
    );

    // 오류 로그 기록
    if (function_exists('coupang_log')) {
        coupang_log('ERROR', 'API 테스트 실패', array(
            'test_type' => isset($test_type) ? $test_type : 'unknown',
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ));
    }

    echo json_encode($error_response);

} catch (Error $e) {
    // PHP Fatal Error 처리
    $error_response = array(
        'success' => false,
        'message' => 'PHP 오류: ' . $e->getMessage(),
        'execution_time' => '0 ms',
        'test_type' => isset($test_type) ? $test_type : 'unknown',
        'timestamp' => date('Y-m-d H:i:s'),
        'error_type' => 'Fatal Error'
    );

    echo json_encode($error_response);
}

// 스크립트 종료
exit;
?>