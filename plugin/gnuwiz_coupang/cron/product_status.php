<?php
/**
 * === product_status.php ===
 * 영카트 → 쿠팡 상품 상태 동기화 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/product_status.php
 * 용도: 영카트 상품의 상태(판매중지, 품절 등)를 쿠팡에 반영
 * 실행주기: 하루 2번 실행 권장 (15 9,21 * * *)
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
coupang_log('INFO', '상품 상태 동기화 크론 시작');

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // API 설정 검증
    $config_validation = validate_coupang_config();
    if (!$config_validation['valid']) {
        throw new Exception('쿠팡 API 설정 오류: ' . implode(', ', $config_validation['errors']));
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 영카트 → 쿠팡 상품 상태 동기화 시작\n";
    
    // 상품 상태 동기화를 위한 래퍼 함수 호출
    $sync_result = cron_sync_product_status_to_coupang();
    
    if ($sync_result['success']) {
        $message = "상품 상태 동기화 완료 - 처리: {$sync_result['processed']}건, " .
                  "판매중지: {$sync_result['stopped']}건, " . 
                  "판매재개: {$sync_result['resumed']}건, " .
                  "품절처리: {$sync_result['out_of_stock']}건";
        
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        
        // 상태 변경된 상품들 상세 출력
        if (!empty($sync_result['status_changes'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 상태 변경된 상품들:\n";
            foreach ($sync_result['status_changes'] as $change) {
                echo "  - 상품 ID: {$change['item_id']}, 상품명: {$change['item_name']}, ";
                echo "변경: {$change['old_status']} → {$change['new_status']}\n";
            }
        }
        
        // 승인 요청이 필요한 상품들 확인
        $sql = "SELECT COUNT(*) as cnt FROM " . G5_TABLE_PREFIX . "coupang_item_map cim
                INNER JOIN " . G5_TABLE_PREFIX . "g5_shop_item si ON cim.youngcart_item_id = si.it_id
                WHERE cim.approval_status = 'PENDING' OR cim.approval_status IS NULL";
        $result = sql_fetch($sql);
        if ($result['cnt'] > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 승인 대기 중인 상품: {$result['cnt']}개\n";
        }
        
        // 크론 실행 로그 기록
        monitor_cron_execution('product_status', 'SUCCESS', $message, $sync_result['execution_time']);
        
        // 성공 통계 업데이트
        update_sync_statistics('product_status', $sync_result['processed'], true);
        
    } else {
        throw new Exception($sync_result['error']);
    }
    
} catch (Exception $e) {
    $error_message = '상품 상태 동기화 실패: ' . $e->getMessage();
    $execution_time = microtime(true) - $cron_start_time;
    
    echo "[" . date('Y-m-d H:i:s') . "] {$error_message}\n";
    
    // 에러 로그 기록
    coupang_log('ERROR', $error_message, array(
        'execution_time' => round($execution_time, 2) . 's',
        'trace' => $e->getTraceAsString()
    ));
    
    // 크론 실행 로그 기록
    monitor_cron_execution('product_status', 'FAIL', $error_message, $execution_time);
    
    // 실패 통계 업데이트
    update_sync_statistics('product_status', 0, false);
    
    // CLI에서는 종료 코드 반환
    exit(1);
}

// 전체 실행 시간 계산
$total_execution_time = microtime(true) - $cron_start_time;
echo "[" . date('Y-m-d H:i:s') . "] 상품 상태 동기화 완료 - 총 실행시간: " . round($total_execution_time, 2) . "초\n";

coupang_log('INFO', '상품 상태 동기화 크론 완료', array(
    'total_execution_time' => round($total_execution_time, 2) . 's'
));

/**
 * 상품 상태 동기화 래퍼 함수
 */
function cron_sync_product_status_to_coupang() {
    global $g5;
    
    $result = array(
        'success' => true,
        'processed' => 0,
        'stopped' => 0,
        'resumed' => 0,
        'out_of_stock' => 0,
        'status_changes' => array(),
        'errors' => array()
    );
    
    try {
        $coupang_api = get_coupang_api();
        
        // 상태가 변경된 상품들 조회
        $sql = "SELECT si.it_id, si.it_name, si.it_use, si.it_stock_qty,
                       cim.coupang_product_id, cim.sync_status, cim.last_sync_time
                FROM " . G5_TABLE_PREFIX . "g5_shop_item si
                INNER JOIN " . G5_TABLE_PREFIX . "coupang_item_map cim ON si.it_id = cim.youngcart_item_id
                WHERE (si.it_use != cim.sync_status OR cim.last_sync_time < si.it_update_time)
                AND cim.coupang_product_id IS NOT NULL
                ORDER BY si.it_update_time DESC
                LIMIT 50";
        
        $query_result = sql_query($sql);
        
        while ($row = sql_fetch_array($query_result)) {
            $result['processed']++;
            
            $new_status = ($row['it_use'] == '1' && $row['it_stock_qty'] > 0) ? 'ACTIVE' : 'INACTIVE';
            $old_status = $row['sync_status'];
            
            // 상품 상태 업데이트 시도
            $update_result = $coupang_api->updateProductStatus($row['coupang_product_id'], $new_status);
            
            if ($update_result['success']) {
                // 로컬 동기화 상태 업데이트
                $update_sql = "UPDATE " . G5_TABLE_PREFIX . "coupang_item_map 
                              SET sync_status = '" . addslashes($new_status) . "', 
                                  last_sync_time = NOW() 
                              WHERE youngcart_item_id = '" . addslashes($row['it_id']) . "'";
                sql_query($update_sql);
                
                // 상태 변경 추적
                if ($new_status == 'INACTIVE') {
                    $result['stopped']++;
                } else {
                    $result['resumed']++;
                }
                
                if ($row['it_stock_qty'] == 0) {
                    $result['out_of_stock']++;
                }
                
                $result['status_changes'][] = array(
                    'item_id' => $row['it_id'],
                    'item_name' => $row['it_name'],
                    'old_status' => $old_status,
                    'new_status' => $new_status
                );
                
            } else {
                $result['errors'][] = array(
                    'item_id' => $row['it_id'],
                    'error' => $update_result['error']
                );
            }
            
            // API 호출 제한을 위한 지연
            usleep(500000); // 0.5초 대기
        }
        
    } catch (Exception $e) {
        $result['success'] = false;
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

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