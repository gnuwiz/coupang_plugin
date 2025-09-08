<?php
/**
 * === cancelled_orders.php ===
 * 쿠팡 취소 주문 → 영카트 동기화 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/cancelled_orders.php
 * 용도: 쿠팡에서 취소된 주문을 영카트에 반영
 * 실행주기: 매분 실행 권장 (*/1 * * * *)
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
coupang_log('INFO', '취소 주문 동기화 크론 시작');

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // API 설정 검증
    $config_validation = validate_coupang_config();
    if (!$config_validation['valid']) {
        throw new Exception('쿠팡 API 설정 오류: ' . implode(', ', $config_validation['errors']));
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 쿠팡 취소 주문 동기화 시작\n";
    
    // 취소 주문 동기화 실행 (지난 1시간)
    $sync_result = $coupang_api->syncCancelledOrdersFromCoupang(1);
    
    if ($sync_result['success']) {
        $message = "취소 주문 동기화 완료 - 처리: {$sync_result['processed']}건, " .
                  "취소 처리: {$sync_result['cancelled_orders']}건, " .
                  "오류: " . count($sync_result['errors']) . "건";
        
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        
        // 오류가 있으면 상세 출력
        if (!empty($sync_result['errors'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 오류 상세:\n";
            foreach ($sync_result['errors'] as $error) {
                echo "  - 주문 ID: {$error['order_id']}, 오류: {$error['error']}\n";
            }
        }
        
        // 취소된 주문 상세 정보 출력
        if ($sync_result['cancelled_orders'] > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 취소 처리된 주문들:\n";
            $sql = "SELECT coupang_order_id, od_id, od_price 
                    FROM " . G5_TABLE_PREFIX . "yc_order 
                    WHERE od_status = 'CANCEL' 
                    AND od_modify_time >= NOW() - INTERVAL 10 MINUTE 
                    ORDER BY od_modify_time DESC 
                    LIMIT 10";
            $result = sql_query($sql);
            while ($row = sql_fetch_array($result)) {
                echo "  - 주문: {$row['coupang_order_id']}, 영카트 ID: {$row['od_id']}, 금액: " . number_format($row['od_price']) . "원\n";
            }
        }
        
        // 크론 실행 로그 기록
        monitor_cron_execution('cancelled_orders', 'SUCCESS', $message, $sync_result['execution_time']);
        
        // 성공 통계 업데이트
        update_sync_statistics('cancelled_orders', $sync_result['processed'], true);
        
    } else {
        throw new Exception($sync_result['error']);
    }
    
} catch (Exception $e) {
    $error_message = '취소 주문 동기화 실패: ' . $e->getMessage();
    $execution_time = microtime(true) - $cron_start_time;
    
    echo "[" . date('Y-m-d H:i:s') . "] {$error_message}\n";
    
    // 에러 로그 기록
    coupang_log('ERROR', $error_message, array(
        'execution_time' => round($execution_time, 2) . 's',
        'trace' => $e->getTraceAsString()
    ));
    
    // 크론 실행 로그 기록
    monitor_cron_execution('cancelled_orders', 'FAIL', $error_message, $execution_time);
    
    // 실패 통계 업데이트
    update_sync_statistics('cancelled_orders', 0, false);
    
    // CLI에서는 종료 코드 반환
    exit(1);
}

// 전체 실행 시간 계산
$total_execution_time = microtime(true) - $cron_start_time;
echo "[" . date('Y-m-d H:i:s') . "] 취소 주문 동기화 완료 - 총 실행시간: " . round($total_execution_time, 2) . "초\n";

coupang_log('INFO', '취소 주문 동기화 크론 완료', array(
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

exit(0);
?>