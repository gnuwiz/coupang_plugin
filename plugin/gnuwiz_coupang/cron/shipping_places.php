<?php
/**
 * 쿠팡 출고지/반품지 동기화 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/shipping_places.php
 * 용도: 출고지/반품지 정보를 쿠팡에서 주기적으로 동기화
 * 실행주기: 하루 1회 (새벽 4시 권장)
 */

// CLI 환경에서만 실행
if (php_sapi_name() !== 'cli') {
    die('CLI 환경에서만 실행 가능합니다.');
}

// 쿠팡 플러그인 초기화
$plugin_path = dirname(__FILE__) . '/..';
if (!file_exists($plugin_path . '/_common.php')) {
    die('쿠팡 플러그인이 설치되지 않았습니다.');
}

include_once($plugin_path . '/_common.php');

// 실행 시작 로그
$cron_start_time = microtime(true);
coupang_log('INFO', '출고지/반품지 동기화 크론 시작');

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // API 설정 검증
    $config_validation = validate_coupang_config();
    if (!$config_validation['valid']) {
        throw new Exception('쿠팡 API 설정 오류: ' . implode(', ', $config_validation['errors']));
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 쿠팡 출고지/반품지 동기화 시작\n";
    
    // 동기화 실행
    $sync_result = $coupang_api->syncShippingPlacesFromCoupang();
    
    if ($sync_result['success']) {
        $message = "출고지/반품지 동기화 완료 - {$sync_result['sync_count']}개 처리";
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        
        // 크론 실행 로그 기록
        monitor_cron_execution('shipping_places', 'SUCCESS', $message, $sync_result['execution_time']);
        
        // 성공 통계 업데이트
        update_sync_statistics('shipping_places', $sync_result['sync_count'], true);
        
    } else {
        throw new Exception($sync_result['error']);
    }
    
} catch (Exception $e) {
    $error_message = '출고지/반품지 동기화 실패: ' . $e->getMessage();
    $execution_time = microtime(true) - $cron_start_time;
    
    echo "[" . date('Y-m-d H:i:s') . "] {$error_message}\n";
    
    // 에러 로그 기록
    coupang_log('ERROR', $error_message, array(
        'execution_time' => round($execution_time, 2) . 's',
        'trace' => $e->getTraceAsString()
    ));
    
    // 크론 실행 로그 기록
    monitor_cron_execution('shipping_places', 'FAIL', $error_message, $execution_time);
    
    // 실패 통계 업데이트
    update_sync_statistics('shipping_places', 0, false);
    
    // CLI에서는 종료 코드 반환
    exit(1);
}

// 전체 실행 시간 계산
$total_execution_time = microtime(true) - $cron_start_time;
echo "[" . date('Y-m-d H:i:s') . "] 출고지/반품지 동기화 완료 - 총 실행시간: " . round($total_execution_time, 2) . "초\n";

coupang_log('INFO', '출고지/반품지 동기화 크론 완료', array(
    'total_execution_time' => round($total_execution_time, 2) . 's'
));

/**
 * 동기화 통계 업데이트
 */
function update_sync_statistics($sync_type, $count, $success) {
    global $g5;
    
    $date = date('Y-m-d');
    $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_sync_stats 
            (sync_type, sync_date, success_count, fail_count, last_execution_time) 
            VALUES ('{$sync_type}', '{$date}', " . ($success ? $count : 0) . ", " . ($success ? 0 : 1) . ", NOW())
            ON DUPLICATE KEY UPDATE 
            success_count = success_count + " . ($success ? $count : 0) . ",
            fail_count = fail_count + " . ($success ? 0 : 1) . ",
            last_execution_time = NOW()";
    
    sql_query($sql);
}

/**
 * 출고지/반품지 크론 함수 (외부 호출용)
 */
function cron_sync_shipping_places_from_coupang() {
    $coupang_api = get_coupang_api();
    return $coupang_api->syncShippingPlacesFromCoupang();
}

?>