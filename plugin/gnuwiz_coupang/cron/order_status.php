<?php
/**
 * === order_status.php ===
 * 영카트 → 쿠팡 주문 상태 동기화 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/order_status.php
 * 용도: 영카트에서 변경된 주문 상태(배송, 배송완료 등)를 쿠팡에 반영
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
coupang_log('INFO', '주문 상태 동기화 크론 시작');

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // API 설정 검증
    $config_validation = validate_coupang_config();
    if (!$config_validation['valid']) {
        throw new Exception('쿠팡 API 설정 오류: ' . implode(', ', $config_validation['errors']));
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 영카트 → 쿠팡 주문 상태 동기화 시작\n";
    
    // 주문 상태 동기화 실행 (최대 20건씩 처리)
    $sync_result = $coupang_api->syncOrderStatusToCoupang(20);
    
    if ($sync_result['success']) {
        $message = "주문 상태 동기화 완료 - 처리: {$sync_result['processed']}건, " .
                  "성공: {$sync_result['updated']}건, " . 
                  "실패: {$sync_result['failed']}건";
        
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        
        // 처리된 주문들 상세 출력
        if (!empty($sync_result['orders'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 처리된 주문 상세:\n";
            foreach ($sync_result['orders'] as $order) {
                $status_text = $order['status'] == 'success' ? '성공' : '실패';
                echo "  - 주문 ID: {$order['order_id']}, 상태: {$order['od_status']}, 결과: {$status_text}\n";
                if (isset($order['error'])) {
                    echo "    오류: {$order['error']}\n";
                }
            }
        }
        
        // 배송 정보가 업데이트된 주문들 출력
        if ($sync_result['updated'] > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 배송 정보 업데이트된 주문:\n";
            $sql = "SELECT coupang_order_id, od_id, od_invoice, od_status 
                    FROM " . G5_TABLE_PREFIX . "yc_order 
                    WHERE od_status IN ('SHIP', 'DELIVER') 
                    AND od_modify_time >= NOW() - INTERVAL 10 MINUTE 
                    ORDER BY od_modify_time DESC 
                    LIMIT 10";
            $result = sql_query($sql);
            while ($row = sql_fetch_array($result)) {
                echo "  - 주문: {$row['coupang_order_id']}, 영카트 ID: {$row['od_id']}, 송장: {$row['od_invoice']}, 상태: {$row['od_status']}\n";
            }
        }
        
        // 크론 실행 로그 기록
        monitor_cron_execution('order_status', 'SUCCESS', $message, $sync_result['execution_time']);
        
        // 성공 통계 업데이트
        update_sync_statistics('order_status', $sync_result['processed'], true);
        
    } else {
        throw new Exception($sync_result['error']);
    }
    
} catch (Exception $e) {
    $error_message = '주문 상태 동기화 실패: ' . $e->getMessage();
    $execution_time = microtime(true) - $cron_start_time;
    
    echo "[" . date('Y-m-d H:i:s') . "] {$error_message}\n";
    
    // 에러 로그 기록
    coupang_log('ERROR', $error_message, array(
        'execution_time' => round($execution_time, 2) . 's',
        'trace' => $e->getTraceAsString()
    ));
    
    // 크론 실행 로그 기록
    monitor_cron_execution('order_status', 'FAIL', $error_message, $execution_time);
    
    // 실패 통계 업데이트
    update_sync_statistics('order_status', 0, false);
    
    // CLI에서는 종료 코드 반환
    exit(1);
}

// 전체 실행 시간 계산
$total_execution_time = microtime(true) - $cron_start_time;
echo "[" . date('Y-m-d H:i:s') . "] 주문 상태 동기화 완료 - 총 실행시간: " . round($total_execution_time, 2) . "초\n";

coupang_log('INFO', '주문 상태 동기화 크론 완료', array(
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