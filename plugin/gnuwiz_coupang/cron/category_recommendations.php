<?php
/**
 * === category_recommendations.php ===
 * 쿠팡 카테고리 추천 배치 크론 스크립트
 * 경로: /plugin/gnuwiz_coupang/cron/category_recommendations.php
 * 용도: 영카트 상품들의 카테고리를 자동 추천하고 캐시에 저장
 * 실행주기: 하루 1회 실행 권장 (0 2 * * *)
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
coupang_log('INFO', '카테고리 추천 배치 크론 시작');

try {
    // API 인스턴스 생성
    $coupang_api = get_coupang_api();
    
    // API 설정 검증
    $config_validation = validate_coupang_config();
    if (!$config_validation['valid']) {
        throw new Exception('쿠팡 API 설정 오류: ' . implode(', ', $config_validation['errors']));
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] 🎯 쿠팡 카테고리 추천 배치 시작\n";
    
    // 배치 카테고리 추천 실행 (한 번에 30개씩 처리)
    $batch_limit = 30;
    $sync_result = $coupang_api->batchGetCategoryRecommendations($batch_limit);
    
    if ($sync_result['success']) {
        $message = "카테고리 추천 배치 완료 - 처리: {$sync_result['processed']}건, " .
                  "성공: {$sync_result['succeeded']}건, " . 
                  "실패: {$sync_result['failed']}건, " .
                  "캐시 저장: {$sync_result['cached']}건";
        
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        
        // 성공한 추천들 상세 출력
        if (!empty($sync_result['recommendations'])) {
            echo "[" . date('Y-m-d H:i:s') . "] 🔥 추천 성공한 상품들:\n";
            foreach ($sync_result['recommendations'] as $rec) {
                if (isset($rec['category_id']) && !empty($rec['category_id'])) {
                    $confidence_percent = number_format($rec['confidence'] * 100, 1);
                    echo "  - 상품: {$rec['it_name']}\n";
                    echo "    → 추천 카테고리: {$rec['category_name']} (ID: {$rec['category_id']})\n";
                    echo "    → 신뢰도: {$confidence_percent}%, 브랜드: {$rec['brand']}\n";
                }
            }
        }
        
        // 실패한 추천들 출력
        if (!empty($sync_result['failed_items'])) {
            echo "[" . date('Y-m-d H:i:s') . "] ❌ 추천 실패한 상품들:\n";
            foreach ($sync_result['failed_items'] as $failed) {
                echo "  - 상품 ID: {$failed['item_id']}, 상품명: {$failed['item_name']}\n";
                echo "    오류: {$failed['error']}\n";
            }
        }
        
        // 카테고리별 추천 통계 출력
        $sql = "SELECT 
                    coupang_category_id, 
                    coupang_category_name,
                    COUNT(*) as recommendation_count,
                    AVG(confidence) as avg_confidence
                FROM " . G5_TABLE_PREFIX . "coupang_category_cache 
                WHERE created_date >= CURDATE()
                GROUP BY coupang_category_id, coupang_category_name 
                ORDER BY recommendation_count DESC 
                LIMIT 10";
        $stats_result = sql_query($sql);
        
        if (sql_num_rows($stats_result) > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] 📊 오늘 추천된 카테고리 통계 (상위 10개):\n";
            while ($row = sql_fetch_array($stats_result)) {
                $avg_conf = number_format($row['avg_confidence'] * 100, 1);
                echo "  - {$row['coupang_category_name']} (ID: {$row['coupang_category_id']}): ";
                echo "{$row['recommendation_count']}건, 평균 신뢰도: {$avg_conf}%\n";
            }
        }
        
        // 추천이 필요한 나머지 상품 수 확인
        $sql = "SELECT COUNT(*) as pending_count 
                FROM " . G5_TABLE_PREFIX . "g5_shop_item si
                LEFT JOIN " . G5_TABLE_PREFIX . "coupang_category_cache ccc 
                    ON si.it_id = ccc.youngcart_item_id 
                    AND ccc.created_date >= NOW() - INTERVAL 7 DAY
                WHERE ccc.youngcart_item_id IS NULL 
                AND si.it_use = '1'";
        $pending = sql_fetch($sql);
        
        if ($pending['pending_count'] > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] ⏳ 추천 대기 중인 상품: {$pending['pending_count']}개\n";
            echo "[" . date('Y-m-d H:i:s') . "] 다음 배치에서 처리될 예정입니다.\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] ✅ 모든 활성 상품의 카테고리 추천이 완료되었습니다!\n";
        }
        
        // 크론 실행 로그 기록
        monitor_cron_execution('category_recommendations', 'SUCCESS', $message, $sync_result['execution_time']);
        
        // 성공 통계 업데이트
        update_sync_statistics('category_recommendations', $sync_result['processed'], true);
        
    } else {
        throw new Exception($sync_result['error']);
    }
    
} catch (Exception $e) {
    $error_message = '카테고리 추천 배치 실패: ' . $e->getMessage();
    $execution_time = microtime(true) - $cron_start_time;
    
    echo "[" . date('Y-m-d H:i:s') . "] {$error_message}\n";
    
    // 에러 로그 기록
    coupang_log('ERROR', $error_message, array(
        'execution_time' => round($execution_time, 2) . 's',
        'trace' => $e->getTraceAsString()
    ));
    
    // 크론 실행 로그 기록
    monitor_cron_execution('category_recommendations', 'FAIL', $error_message, $execution_time);
    
    // 실패 통계 업데이트
    update_sync_statistics('category_recommendations', 0, false);
    
    // CLI에서는 종료 코드 반환
    exit(1);
}

// 전체 실행 시간 계산
$total_execution_time = microtime(true) - $cron_start_time;
echo "[" . date('Y-m-d H:i:s') . "] 카테고리 추천 배치 완료 - 총 실행시간: " . round($total_execution_time, 2) . "초\n";

coupang_log('INFO', '카테고리 추천 배치 크론 완료', array(
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