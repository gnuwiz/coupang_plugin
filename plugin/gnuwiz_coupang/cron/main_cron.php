<?php
/**
 * === main_cron.php ===
 * 쿠팡 연동 통합 크론 스크립트 (백업용)
 * 경로: /plugin/gnuwiz_coupang/cron/main_cron.php
 * 용도: CLI 인자로 sync_type을 받아서 해당 동기화 실행
 * 실행: php main_cron.php [sync_type]
 */

// CLI 환경에서만 실행
if (php_sapi_name() !== 'cli') {
    die('CLI 환경에서만 실행 가능합니다.');
}

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
$valid_types = array(
    'orders', 'cancelled_orders', 'order_status', 
    'products', 'product_status', 'stock',
    'shipping_places', 'category_recommendations', 'category_cache_cleanup'
);

if (empty($sync_type) || !in_array($sync_type, $valid_types)) {
    echo "사용법: php main_cron.php [sync_type]\n";
    echo "동기화 타입:\n";
    echo "  orders                    - 쿠팡 → 영카트 주문 동기화 (매분 실행)\n";
    echo "  cancelled_orders          - 쿠팡 취소 주문 → 영카트 반영 (매분 실행)\n";
    echo "  order_status              - 영카트 주문 상태 → 쿠팡 반영 (매분 실행)\n";
    echo "  products                  - 영카트 상품 → 쿠팡 등록/업데이트 (하루 2번)\n";
    echo "  product_status            - 영카트 상품 상태 → 쿠팡 반영 (하루 2번)\n";
    echo "  stock                     - 영카트 재고/가격 → 쿠팡 동기화 (하루 2번)\n";
    echo "  shipping_places           - 출고지/반품지 동기화 (하루 1번)\n";
    echo "  category_recommendations  - 카테고리 추천 배치 실행 (하루 1번)\n";
    echo "  category_cache_cleanup    - 카테고리 캐시 정리 (하루 1번)\n";
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
            $result = cron_sync_product_status_to_coupang();
            break;
            
        case 'stock':
            echo $log_prefix . "재고 동기화 실행\n";
            $result = $coupang_api->syncStockToCoupang();
            break;
            
        case 'shipping_places':
            echo $log_prefix . "출고지/반품지 동기화 실행\n";
            $result = $coupang_api->syncShippingPlacesFromCoupang();
            break;
            
        case 'category_recommendations':
            echo $log_prefix . "카테고리 추천 배치 실행\n";
            $batch_limit = 30; // 한 번에 30개씩 처리
            $result = $coupang_api->batchGetCategoryRecommendations($batch_limit);
            
            // 결과 로깅
            if ($result['success']) {
                echo $log_prefix . "처리: {$result['processed']}개, 성공: {$result['succeeded']}개, 실패: {$result['failed']}개\n";
                
                // 성공한 추천들 출력
                foreach ($result['recommendations'] as $rec) {
                    if (isset($rec['category_id'])) {
                        echo $log_prefix . "추천: {$rec['it_name']} → {$rec['category_name']} (신뢰도: " . 
                             number_format($rec['confidence'] * 100, 1) . "%)\n";
                    }
                }
            }
            break;
            
        case 'category_cache_cleanup':
            echo $log_prefix . "카테고리 캐시 정리 실행\n";
            $days = 7; // 7일 이상된 캐시 삭제
            $deleted_count = $coupang_api->cleanupCategoryCache($days);
            
            $result = array(
                'success' => true, 
                'stats' => array(
                    'deleted_cache_count' => $deleted_count,
                    'cleanup_days' => $days
                )
            );
            
            echo $log_prefix . "삭제된 캐시 항목: {$deleted_count}개\n";
            break;
            
        default:
            throw new Exception("지원하지 않는 동기화 타입: {$sync_type}");
    }
    
    // 실행 시간 계산
    $execution_time = microtime(true) - $start_time;
    
    // 결과 처리
    if ($result['success']) {
        echo $log_prefix . "완료 (실행시간: " . number_format($execution_time, 2) . "초)\n";
        
        // 통계 정보 출력
        if (!empty($result['stats'])) {
            echo $log_prefix . "통계: " . json_encode($result['stats'], JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        // 성공 로그 기록
        monitor_cron_execution($sync_type, 'success', '동기화 완료', $execution_time);
        
    } else {
        $error_message = isset($result['error']) ? $result['error'] : '알 수 없는 오류';
        throw new Exception($error_message);
    }
    
} catch (Exception $e) {
    $execution_time = microtime(true) - $start_time;
    $error_message = $e->getMessage();
    
    echo $log_prefix . "오류 발생: {$error_message}\n";
    
    // 오류 로그 기록
    monitor_cron_execution($sync_type, 'error', $error_message, $execution_time);
    
    // 오류 상세 로깅
    coupang_log('ERROR', "크론 실행 오류: {$sync_type}", array(
        'error' => $error_message,
        'execution_time' => $execution_time,
        'trace' => $e->getTraceAsString()
    ));
    
    exit(1);
}

// 메모리 사용량 출력 (디버깅용)
if (function_exists('memory_get_peak_usage')) {
    $memory_usage = memory_get_peak_usage(true);
    echo $log_prefix . "최대 메모리 사용량: " . number_format($memory_usage / 1024 / 1024, 2) . "MB\n";
}

exit(0);
?>