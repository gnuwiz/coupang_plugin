<?php
/**
 * === shipping_places.php ===
 * 쿠팡 출고지/반품지 동기화 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/shipping_places.php
 * 용도: 출고지/반품지 정보를 쿠팡에서 주기적으로 동기화
 * 실행주기: 하루 1회 실행 권장 (0 4 * * *)
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
    
    // 출고지/반품지 동기화 실행
    $sync_result = $coupang_api->syncShippingPlacesFromCoupang();
    
    if ($sync_result['success']) {
        $message = "출고지/반품지 동기화 완료 - 총 처리: {$sync_result['total_processed']}개, " .
                  "출고지: {$sync_result['outbound_places']}개, " . 
                  "반품지: {$sync_result['return_places']}개";
        
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        
        // 출고지 목록 출력
        if (!empty($sync_result['outbound_list'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 📦 등록된 출고지 목록:\n";
            foreach ($sync_result['outbound_list'] as $place) {
                echo "  - ID: {$place['placeId']}, 이름: {$place['placeName']}, ";
                echo "주소: {$place['address']}, 상태: {$place['status']}\n";
            }
        }
        
        // 반품지 목록 출력
        if (!empty($sync_result['return_list'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 🔄 등록된 반품지 목록:\n";
            foreach ($sync_result['return_list'] as $place) {
                echo "  - ID: {$place['placeId']}, 이름: {$place['placeName']}, ";
                echo "주소: {$place['address']}, 상태: {$place['status']}\n";
            }
        }
        
        // 기본 출고지/반품지 설정 확인
        $default_outbound = defined('COUPANG_DEFAULT_OUTBOUND_PLACE') ? COUPANG_DEFAULT_OUTBOUND_PLACE : '';
        $default_return = defined('COUPANG_DEFAULT_RETURN_PLACE') ? COUPANG_DEFAULT_RETURN_PLACE : '';
        
        if (empty($default_outbound) || empty($default_return)) {
            echo "[" . date('Y-m-d H:i:s') . "] ⚠️ 기본 출고지/반품지가 설정되지 않았습니다.\n";
            echo "[" . date('Y-m-d H:i:s') . "] 설정 페이지에서 기본 출고지/반품지를 설정하세요.\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] 📍 기본 출고지: {$default_outbound}, 기본 반품지: {$default_return}\n";
        }
        
        // 출고지별 사용 통계 출력
        $sql = "SELECT 
                    shipping_place_id, 
                    COUNT(*) as usage_count,
                    MAX(created_date) as last_used
                FROM " . G5_TABLE_PREFIX . "coupang_item_map 
                WHERE shipping_place_id IS NOT NULL 
                GROUP BY shipping_place_id 
                ORDER BY usage_count DESC 
                LIMIT 5";
        $usage_result = sql_query($sql);
        
        if (sql_num_rows($usage_result) > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 📊 출고지 사용 통계 (상위 5개):\n";
            while ($row = sql_fetch_array($usage_result)) {
                echo "  - 출고지 ID: {$row['shipping_place_id']}, 사용 횟수: {$row['usage_count']}개, ";
                echo "최근 사용: {$row['last_used']}\n";
            }
        }
        
        // 크론 실행 로그 기록
        monitor_cron_execution('shipping_places', 'SUCCESS', $message, $sync_result['execution_time']);
        
        // 성공 통계 업데이트
        update_sync_statistics('shipping_places', $sync_result['total_processed'], true);
        
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

exit(0);
?>