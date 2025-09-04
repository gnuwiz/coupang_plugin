<?php
/**
 * 쿠팡 연동 통합 크론 스크립트
 * 경로: /plugin/coupang/cron/main_cron.php
 * 실행: php main_cron.php [sync_type]
 * 용도: 모든 동기화 작업을 하나의 파일에서 처리
 */

// 플러그인 경로 설정
define('COUPANG_PLUGIN_PATH', dirname(dirname(__FILE__)));
define('YOUNGCART_ROOT', dirname(dirname(dirname(__FILE__))));

// 영카트 공통 파일 및 API 클래스 로드
include_once(YOUNGCART_ROOT . '/_common.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

// 실행 시작 시간 기록
$start_time = microtime(true);

// CLI 인자 처리
$sync_type = isset($argv[1]) ? $argv[1] : '';
$valid_types = array('orders', 'cancelled_orders', 'order_status', 'products', 'product_status', 'stock');

if (empty($sync_type) || !in_array($sync_type, $valid_types)) {
    echo "사용법: php main_cron.php [sync_type]\n";
    echo "동기화 타입:\n";
    echo "  orders          - 쿠팡 → 영카트 주문 동기화 (매분 실행)\n";
    echo "  cancelled_orders - 쿠팡 취소 주문 → 영카트 반영 (매분 실행)\n";
    echo "  order_status    - 영카트 주문 상태 → 쿠팡 반영 (매분 실행)\n";
    echo "  products        - 영카트 상품 → 쿠팡 등록/업데이트 (하루 2번)\n";
    echo "  product_status  - 영카트 상품 상태 → 쿠팡 반영 (하루 2번)\n";
    echo "  stock          - 영카트 재고/가격 → 쿠팡 동기화 (하루 2번)\n";
    exit(1);
}

$log_prefix = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($sync_type) . ': ';

try {
    echo $log_prefix . "시작\n";
    
    // API 설정 검증
    $config_check = validate_coupang_config();
    if (!$config_check['valid']) {
        throw new Exception('API 설정 오류: ' . implode(', ', $config_check['errors']));
    }
    
    // 크론 실행 시작 로그
    monitor_cron_execution($sync_type, 'start', '동기화 시작');
    
    // 쿠팡 API 인스턴스 생성
    $coupang_api = get_coupang_api();
    $result = array('success' => false, 'stats' => array());
    
    // 동기화 타입별 실행
    switch ($sync_type) {
        case 'orders':
            echo $log_prefix . "주문 동기화 실행\n";
            $result = $coupang_api->syncOrdersFromCoupang(1); // 1시간 범위
            break;
            
        case 'cancelled_orders':
            echo $log_prefix . "취소 주문 동기화 실행\n";
            $result = $coupang_api->syncCancelledOrdersFromCoupang(1); // 1시간 범위
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
            // 이 기능은 래퍼 함수 사용 (복잡한 로직 때문)
            $success = cron_sync_product_status_to_coupang();
            $result = array('success' => $success, 'stats' => array('legacy' => true));
            break;
            
        case 'stock':
            echo $log_prefix . "재고/가격 동기화 실행\n";
            $result = $coupang_api->syncStockAndPrice();
            break;
            
        default:
            throw new Exception('알 수 없는 동기화 타입: ' . $sync_type);
    }
    
    // 실행 결과 처리
    $end_time = microtime(true);
    $execution_time = round(($end_time - $start_time), 2);
    
    if ($result['success']) {
        // 통계 정보 생성
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
        
        // 크론 실행 성공 로그
        monitor_cron_execution($sync_type, 'success', $summary, $execution_time);
        
        exit(0);
        
    } else {
        $error_msg = isset($result['message']) ? $result['message'] : '알 수 없는 오류';
        throw new Exception($error_msg);
    }
    
} catch (Exception $e) {
    $end_time = microtime(true);
    $execution_time = round(($end_time - $start_time), 2);
    
    $error_msg = "오류: " . $e->getMessage() . " (실행시간: {$execution_time}초)";
    echo $log_prefix . $error_msg . "\n";
    
    coupang_log('ERROR', $sync_type . ' 동기화 오류', array(
        'error' => $e->getMessage(),
        'execution_time' => $execution_time
    ));
    
    // 크론 실행 오류 로그
    monitor_cron_execution($sync_type, 'error', $error_msg, $execution_time);
    
    exit(1);
}
?>