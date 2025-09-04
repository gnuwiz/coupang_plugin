<?php
/**
 * 개선된 쿠팡 연동 수동 동기화 관리 페이지
 * 경로: /plugin/coupang/admin/manual_sync.php
 * 용도: 관리자가 수동으로 동기화를 실행하고 현황을 모니터링
 */

include_once('../_common.php');

// 관리자 권한 체크
if (!$is_admin) {
    die('관리자만 접근할 수 있습니다.');
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// AJAX 요청 처리
if ($action && isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // API 설정 검증
        $config_check = validate_coupang_config();
        if (!$config_check['valid']) {
            throw new Exception('API 설정 오류: ' . implode(', ', $config_check['errors']));
        }
        
        $coupang_api = new CoupangAPI(array(
            'access_key' => COUPANG_ACCESS_KEY,
            'secret_key' => COUPANG_SECRET_KEY,
            'vendor_id'  => COUPANG_VENDOR_ID
        ));
        $result = array('success' => false, 'message' => '', 'stats' => array());
        
        switch ($action) {
            case 'sync_orders':
                $sync_result = $coupang_api->syncOrdersFromCoupang(1);
                $result['success'] = $sync_result['success'];
                $result['message'] = $sync_result['success'] ? '주문 동기화 성공' : $sync_result['message'];
                if (isset($sync_result['stats'])) $result['stats'] = $sync_result['stats'];
                break;
                
            case 'sync_cancelled':
                $sync_result = $coupang_api->syncCancelledOrdersFromCoupang(1);
                $result['success'] = $sync_result['success'];
                $result['message'] = $sync_result['success'] ? '취소 주문 동기화 성공' : $sync_result['message'];
                if (isset($sync_result['stats'])) $result['stats'] = $sync_result['stats'];
                break;
                
            case 'sync_products':
                $sync_result = $coupang_api->syncProductsToCoupang(50); // 테스트용 작은 배치
                $result['success'] = $sync_result['success'];
                $result['message'] = $sync_result['success'] ? '상품 동기화 성공' : $sync_result['message'];
                if (isset($sync_result['stats'])) $result['stats'] = $sync_result['stats'];
                break;
                
            case 'sync_stock':
                $sync_result = $coupang_api->syncStockAndPrice(50); // 테스트용 작은 배치
                $result['success'] = $sync_result['success'];
                $result['message'] = $sync_result['success'] ? '재고/가격 동기화 성공' : $sync_result['message'];
                if (isset($sync_result['stats'])) $result['stats'] = $sync_result['stats'];
                break;
                
            case 'sync_order_status':
                $sync_result = $coupang_api->syncOrderStatusToCoupang(20); // 테스트용 작은 배치
                $result['success'] = $sync_result['success'];
                $result['message'] = $sync_result['success'] ? '주문 상태 동기화 성공' : $sync_result['message'];
                if (isset($sync_result['stats'])) $result['stats'] = $sync_result['stats'];
                break;
                
            case 'sync_product_status':
                $sync_result = $coupang_api->syncProductStatusToCoupang();
                $result['success'] = $sync_result['success'];
                $result['message'] = $sync_result['success'] ? '상품 상태 동기화 성공' : $sync_result['message'];
                if (isset($sync_result['stats'])) $result['stats'] = $sync_result['stats'];
                break;
                
            case 'test_api':
                // API 연결 테스트
                $test_result = $coupang_api->getOrders(date('Y-m-d\TH:i:s\Z', strtotime('-1 day')), date('Y-m-d\TH:i:s\Z'));
                $result['success'] = $test_result['success'];
                $result['message'] = $test_result['success'] ? 'API 연결 성공' : 'API 연결 실패: ' . $test_result['message'];
                $result['data'] = $test_result;
                break;
                
            case 'get_stats':
                $result = get_sync_statistics();
                break;
                
            default:
                throw new Exception('알 수 없는 액션');
        }
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        echo json_encode(array(
            'success' => false, 
            'message' => $e->getMessage(),
            'stats' => array()
        ));
    }
    exit;
}

// 통계 데이터 가져오기 함수 (개선됨)
function get_sync_statistics() {
    global $g5;
    
    // 상품 동기화 현황
    $product_stats = sql_fetch("
        SELECT 
            COUNT(*) as total_items,
            (SELECT COUNT(*) FROM " . G5_TABLE_PREFIX . "coupang_item_map WHERE sync_status = 'active') as synced_active,
            (SELECT COUNT(*) FROM " . G5_TABLE_PREFIX . "coupang_item_map WHERE sync_status = 'inactive') as synced_inactive,
            (SELECT COUNT(*) FROM " . G5_TABLE_PREFIX . "coupang_item_map WHERE sync_status = 'error') as synced_error
        FROM {$g5['g5_shop_item_table']} 
        WHERE it_use = '1'
    ");
    
    // 주문 현황
    $order_stats = sql_fetch("
        SELECT 
            COUNT(*) as total_coupang_orders,
            SUM(CASE WHEN od_status = '입금' THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN od_status = '준비' THEN 1 ELSE 0 END) as preparing_orders,
            SUM(CASE WHEN od_status = '배송' THEN 1 ELSE 0 END) as shipping_orders,
            SUM(CASE WHEN od_status = '완료' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN od_status = '취소' THEN 1 ELSE 0 END) as cancelled_orders
        FROM {$g5['g5_shop_order_table']} 
        WHERE od_coupang_yn = 'Y'
    ");
    
    // 최근 크론 실행 현황
    $cron_stats = sql_fetch("
        SELECT 
            COUNT(*) as total_executions,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
            MAX(execution_time) as last_execution
        FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
        WHERE execution_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    
    // 최근 오류 로그
    $recent_errors = array();
    $error_sql = "SELECT cron_type, message, execution_time 
                  FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
                  WHERE status = 'error' 
                  ORDER BY execution_time DESC 
                  LIMIT 5";
    $error_result = sql_query($error_sql);
    while ($row = sql_fetch_array($error_result)) {
        $recent_errors[] = $row;
    }
    
    return array(
        'success' => true,
        'data' => array(
            'products' => $product_stats,
            'orders' => $order_stats,
            'cron' => $cron_stats,
            'recent_errors' => $recent_errors
        )
    );
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>쿠팡 연동 관리 (개선됨)</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header h1 { color: #2c3e50; margin-bottom: 10px; }
        .header .subtitle { color: #7f8c8d; }
        .version-badge { display: inline-block; background: #3498db; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; margin-left: 10px; }
        
        .card { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card h2 { color: #2c3e50; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #3498db; }
        
        .button-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .btn { display: inline-block; padding: 12px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; font-size: 14px; transition: all 0.3s; text-align: center; position: relative; }
        .btn:hover { background: #2980b9; transform: translateY(-2px); }
        .btn.btn-success { background: #27ae60; }
        .btn.btn-success:hover { background: #229954; }
        .btn.btn-warning { background: #f39c12; }
        .btn.btn-warning:hover { background: #e67e22; }
        .btn.btn-danger { background: #e74c3c; }
        .btn.btn-danger:hover { background: #c0392b; }
        .btn:disabled { background: #95a5a6; cursor: not-allowed; transform: none; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-card h3 { margin-bottom: 10px; font-size: 18px; }
        .stat-card .number { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .stat-card .label { opacity: 0.9; font-size: 14px; }
        
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background: #f8f9fa; font-weight: 600; color: #2c3e50; }
        .table tr:hover { background: #f8f9fa; }
        
        .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .status.success { background: #d4edda; color: #155724; }
        .status.error { background: #f8d7da; color: #721c24; }
        .status.warning { background: #fff3cd; color: #856404; }
        .status.info { background: #d1ecf1; color: #0c5460; }
        
        .log-container { max-height: 300px; overflow-y: auto; background: #2c3e50; color: #ecf0f1; padding: 15px; border-radius: 5px; font-family: 'Courier New', monospace; font-size: 13px; }
        .log-container .log-line { margin-bottom: 5px; }
        .log-container .log-success { color: #27ae60; }
        .log-container .log-error { color: #e74c3c; }
        .log-container .log-warning { color: #f39c12; }
        
        .spinner { display: none; width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite; margin-left: 10px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
        .alert.alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        .alert.alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
        .alert.alert-warning { color: #856404; background-color: #fff3cd; border-color: #ffeaa7; }
        .alert.alert-info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; }
        
        .sync-result { margin-top: 15px; padding: 10px; border-radius: 4px; }
        .sync-result.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .sync-result.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        
        .stats-detail { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; font-size: 12px; }
        .stats-detail div { background: rgba(255,255,255,0.2); padding: 8px; border-radius: 4px; }
        
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .button-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
            .stats-detail { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 쿠팡 연동 관리 대시보드 <span class="version-badge">v2.0 개선됨</span></h1>
            <div class="subtitle">통합 API 클래스 기반 실시간 동기화 관리</div>
        </div>
        
        <!-- API 설정 상태 확인 -->
        <div class="card">
            <h2>⚙️ 시스템 상태</h2>
            <div id="config-status">
                <?php
                $config_check = validate_coupang_config();
                if ($config_check['valid']) {
                    echo '<div class="alert alert-success">✅ API 설정이 완료되었습니다. (통합 API 클래스 사용)</div>';
                } else {
                    echo '<div class="alert alert-danger">❌ API 설정 오류: ' . implode('<br>', $config_check['errors']) . '</div>';
                }
                ?>
            </div>
        </div>
        
        <!-- 수동 동기화 버튼들 -->
        <div class="card">
            <h2>🔄 수동 동기화 (개선된 API)</h2>
            <div class="button-grid">
                <button class="btn btn-success" onclick="executeSync('sync_orders')">
                    📋 주문 동기화 <span class="spinner" id="spinner-orders"></span>
                </button>
                <button class="btn btn-warning" onclick="executeSync('sync_cancelled')">
                    ❌ 취소 주문 동기화 <span class="spinner" id="spinner-cancelled"></span>
                </button>
                <button class="btn btn-success" onclick="executeSync('sync_products')">
                    📦 상품 동기화 <span class="spinner" id="spinner-products"></span>
                </button>
                <button class="btn btn-warning" onclick="executeSync('sync_stock')">
                    📊 재고/가격 동기화 <span class="spinner" id="spinner-stock"></span>
                </button>
                <button class="btn btn-warning" onclick="executeSync('sync_order_status')">
                    🔄 주문 상태 동기화 <span class="spinner" id="spinner-order_status"></span>
                </button>
                <button class="btn btn-warning" onclick="executeSync('sync_product_status')">
                    🏪 상품 상태 동기화 <span class="spinner" id="spinner-product_status"></span>
                </button>
                <button class="btn" onclick="executeSync('test_api')">
                    🔍 API 연결 테스트 <span class="spinner" id="spinner-test"></span>
                </button>
                <button class="btn btn-info" onclick="refreshStats()">
                    📈 통계 새로고침 <span class="spinner" id="spinner-stats"></span>
                </button>
            </div>
            <div id="sync-result"></div>
        </div>
        
        <!-- 동기화 현황 통계 -->
        <div class="card">
            <h2>📊 동기화 현황</h2>
            <div class="stats-grid" id="stats-container">
                <!-- JavaScript로 동적 로드 -->
            </div>
        </div>
        
        <!-- 상세 현황 테이블 -->
        <div class="card">
            <h2>📋 상세 현황</h2>
            
            <h3>상품 동기화 현황</h3>
            <table class="table" id="product-status-table">
                <thead>
                    <tr>
                        <th>항목</th>
                        <th>전체</th>
                        <th>활성</th>
                        <th>비활성</th>
                        <th>오류</th>
                    </tr>
                </thead>
                <tbody id="product-stats-body">
                    <!-- JavaScript로 동적 로드 -->
                </tbody>
            </table>
            
            <h3 style="margin-top: 30px;">주문 현황</h3>
            <table class="table" id="order-status-table">
                <thead>
                    <tr>
                        <th>상태</th>
                        <th>건수</th>
                        <th>비율</th>
                    </tr>
                </thead>
                <tbody id="order-stats-body">
                    <!-- JavaScript로 동적 로드 -->
                </tbody>
            </table>
            
            <h3 style="margin-top: 30px;">최근 오류 현황</h3>
            <table class="table" id="error-table">
                <thead>
                    <tr>
                        <th>크론 타입</th>
                        <th>오류 메시지</th>
                        <th>발생 시간</th>
                    </tr>
                </thead>
                <tbody id="error-stats-body">
                    <!-- JavaScript로 동적 로드 -->
                </tbody>
            </table>
        </div>
        
        <!-- 크론탭 설정 안내 -->
        <div class="card">
            <h2>⏰ 크론탭 설정 (개선된 방식)</h2>
            <p><strong>새로운 통합 크론 시스템을 사용합니다:</strong></p>
            <pre style="background:#f8f9fa;padding:15px;border-radius:5px;overflow-x:auto;">
# 주문 관련 동기화 (매분 실행)
*/1 * * * * /usr/bin/php <?= COUPANG_PLUGIN_PATH ?>/cron/orders.php >> <?= COUPANG_PLUGIN_PATH ?>/logs/orders.log 2>&1
*/1 * * * * /usr/bin/php <?= COUPANG_PLUGIN_PATH ?>/cron/cancelled_orders.php >> <?= COUPANG_PLUGIN_PATH ?>/logs/cancelled.log 2>&1
*/1 * * * * /usr/bin/php <?= COUPANG_PLUGIN_PATH ?>/cron/order_status.php >> <?= COUPANG_PLUGIN_PATH ?>/logs/status.log 2>&1

# 상품 관련 동기화 (하루 2번 실행)
0 9,21 * * * /usr/bin/php <?= COUPANG_PLUGIN_PATH ?>/cron/products.php >> <?= COUPANG_PLUGIN_PATH ?>/logs/products.log 2>&1
15 9,21 * * * /usr/bin/php <?= COUPANG_PLUGIN_PATH ?>/cron/product_status.php >> <?= COUPANG_PLUGIN_PATH ?>/logs/product_status.log 2>&1
30 10,22 * * * /usr/bin/php <?= COUPANG_PLUGIN_PATH ?>/cron/stock.php >> <?= COUPANG_PLUGIN_PATH ?>/logs/stock.log 2>&1

# 또는 통합 크론으로 실행:
*/1 * * * * /usr/bin/php <?= COUPANG_PLUGIN_PATH ?>/cron/main_cron.php orders >> <?= COUPANG_PLUGIN_PATH ?>/logs/unified.log 2>&1
*/1 * * * * /usr/bin/php <?= COUPANG_PLUGIN_PATH ?>/cron/main_cron.php cancelled_orders >> <?= COUPANG_PLUGIN_PATH ?>/logs/unified.log 2>&1
</pre>
        </div>
        
        <!-- 최근 로그 -->
        <div class="card">
            <h2>📝 최근 실행 로그</h2>
            <?php
            $log_files = array(
                'orders.log' => '주문 동기화',
                'cancelled.log' => '취소 주문',
                'products.log' => '상품 동기화',
                'stock.log' => '재고 동기화'
            );
            
            foreach ($log_files as $file => $title) {
                $log_path = COUPANG_PLUGIN_PATH . '/logs/' . $file;
                if (file_exists($log_path)) {
                    echo "<h3>{$title} 로그</h3>";
                    $log_content = file_get_contents($log_path);
                    $lines = explode("\n", $log_content);
                    $recent_lines = array_slice($lines, -10); // 최근 10라인
                    echo "<div class='log-container'>";
                    foreach ($recent_lines as $line) {
                        if (empty($line)) continue;
                        $class = '';
                        if (strpos($line, '성공') !== false || strpos($line, 'success') !== false || strpos($line, '완료') !== false) $class = 'log-success';
                        elseif (strpos($line, '실패') !== false || strpos($line, 'error') !== false || strpos($line, '오류') !== false) $class = 'log-error';
                        elseif (strpos($line, '경고') !== false || strpos($line, 'warning') !== false) $class = 'log-warning';
                        echo "<div class='log-line {$class}'>" . htmlspecialchars($line) . "</div>";
                    }
                    echo "</div>";
                } else {
                    echo "<h3>{$title} 로그</h3>";
                    echo "<div class='log-container'><div class='log-line'>로그 파일이 없습니다.</div></div>";
                }
            }
            ?>
        </div>
    </div>

    <script>
        // 동기화 실행 함수 (개선됨)
        function executeSync(action) {
            const button = event.target;
            const spinnerKey = action.replace('sync_', '').replace('test_api', 'test');
            const spinner = document.getElementById('spinner-' + spinnerKey);
            const resultDiv = document.getElementById('sync-result');
            
            // 버튼 비활성화 및 스피너 표시
            button.disabled = true;
            if (spinner) spinner.style.display = 'inline-block';
            
            // 결과 영역 초기화
            resultDiv.innerHTML = '<div class="sync-result" style="background:#f0f0f0;color:#666;">동기화 실행 중...</div>';
            
            fetch('?action=' + action + '&ajax=1')
                .then(response => response.json())
                .then(data => {
                    const alertClass = data.success ? 'success' : 'error';
                    let html = `<div class="sync-result ${alertClass}">${data.message}`;
                    
                    // 통계 정보 표시
                    if (data.stats && Object.keys(data.stats).length > 0) {
                        html += '<div class="stats-detail">';
                        for (const [key, value] of Object.entries(data.stats)) {
                            if (key !== 'legacy') {
                                const label = key === 'total' ? '전체' : 
                                             key === 'success' ? '성공' :
                                             key === 'new' ? '신규' :
                                             key === 'update' ? '업데이트' :
                                             key === 'skip' ? '스킵' :
                                             key === 'error' ? '실패' :
                                             key === 'stock_success' ? '재고성공' :
                                             key === 'price_success' ? '가격성공' :
                                             key === 'execution_time' ? '실행시간(초)' : key;
                                html += `<div><strong>${label}:</strong> ${value}</div>`;
                            }
                        }
                        html += '</div>';
                    }
                    
                    html += '</div>';
                    
                    // API 테스트의 경우 추가 정보 표시
                    if (action === 'test_api' && data.data) {
                        html += `<pre style="background:#f8f9fa;padding:15px;border-radius:5px;margin-top:10px;overflow-x:auto;max-height:300px;">${JSON.stringify(data.data, null, 2)}</pre>`;
                    }
                    
                    resultDiv.innerHTML = html;
                    
                    // 통계 자동 새로고침
                    if (data.success && action !== 'test_api') {
                        setTimeout(refreshStats, 1000);
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `<div class="sync-result error">오류: ${error.message}</div>`;
                })
                .finally(() => {
                    // 버튼 활성화 및 스피너 숨김
                    button.disabled = false;
                    if (spinner) spinner.style.display = 'none';
                });
        }
        
        // 통계 새로고침 함수 (개선됨)
        function refreshStats() {
            const spinner = document.getElementById('spinner-stats');
            if (spinner) spinner.style.display = 'inline-block';
            
            fetch('?action=get_stats&ajax=1')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateStatsDisplay(data.data);
                    }
                })
                .catch(error => {
                    console.error('통계 로드 오류:', error);
                })
                .finally(() => {
                    if (spinner) spinner.style.display = 'none';
                });
        }
        
        // 통계 화면 업데이트 (개선됨)
        function updateStatsDisplay(stats) {
            // 통계 카드 업데이트
            const statsContainer = document.getElementById('stats-container');
            statsContainer.innerHTML = `
                <div class="stat-card">
                    <h3>📦 상품</h3>
                    <div class="number">${stats.products.synced_active || 0}</div>
                    <div class="label">활성 동기화 / ${stats.products.total_items || 0} 전체</div>
                    <div class="stats-detail">
                        <div>비활성: ${stats.products.synced_inactive || 0}</div>
                        <div>오류: ${stats.products.synced_error || 0}</div>
                    </div>
                </div>
                <div class="stat-card">
                    <h3>📋 주문</h3>
                    <div class="number">${stats.orders.total_coupang_orders || 0}</div>
                    <div class="label">쿠팡 주문</div>
                    <div class="stats-detail">
                        <div>처리중: ${(stats.orders.pending_orders || 0) + (stats.orders.preparing_orders || 0)}</div>
                        <div>완료: ${(stats.orders.completed_orders || 0) + (stats.orders.shipping_orders || 0)}</div>
                    </div>
                </div>
                <div class="stat-card">
                    <h3>🔄 크론 실행</h3>
                    <div class="number">${stats.cron.success_count || 0}</div>
                    <div class="label">24시간 성공 / ${stats.cron.total_executions || 0} 전체</div>
                    <div class="stats-detail">
                        <div>오류: ${stats.cron.error_count || 0}</div>
                        <div>최근: ${stats.cron.last_execution ? new Date(stats.cron.last_execution).toLocaleString() : '없음'}</div>
                    </div>
                </div>
            `;
            
            // 상품 현황 테이블 업데이트
            const productStatsBody = document.getElementById('product-stats-body');
            productStatsBody.innerHTML = `
                <tr>
                    <td>상품</td>
                    <td>${stats.products.total_items || 0}</td>
                    <td><span class="status success">${stats.products.synced_active || 0}</span></td>
                    <td><span class="status warning">${stats.products.synced_inactive || 0}</span></td>
                    <td><span class="status error">${stats.products.synced_error || 0}</span></td>
                </tr>
            `;
            
            // 주문 현황 테이블 업데이트
            const orderStatsBody = document.getElementById('order-stats-body');
            const totalOrders = stats.orders.total_coupang_orders || 0;
            orderStatsBody.innerHTML = `
                <tr>
                    <td><span class="status info">입금</span></td>
                    <td>${stats.orders.pending_orders || 0}</td>
                    <td>${totalOrders > 0 ? Math.round((stats.orders.pending_orders || 0) / totalOrders * 100) : 0}%</td>
                </tr>
                <tr>
                    <td><span class="status warning">준비</span></td>
                    <td>${stats.orders.preparing_orders || 0}</td>
                    <td>${totalOrders > 0 ? Math.round((stats.orders.preparing_orders || 0) / totalOrders * 100) : 0}%</td>
                </tr>
                <tr>
                    <td><span class="status success">배송</span></td>
                    <td>${stats.orders.shipping_orders || 0}</td>
                    <td>${totalOrders > 0 ? Math.round((stats.orders.shipping_orders || 0) / totalOrders * 100) : 0}%</td>
                </tr>
                <tr>
                    <td><span class="status success">완료</span></td>
                    <td>${stats.orders.completed_orders || 0}</td>
                    <td>${totalOrders > 0 ? Math.round((stats.orders.completed_orders || 0) / totalOrders * 100) : 0}%</td>
                </tr>
                <tr>
                    <td><span class="status error">취소</span></td>
                    <td>${stats.orders.cancelled_orders || 0}</td>
                    <td>${totalOrders > 0 ? Math.round((stats.orders.cancelled_orders || 0) / totalOrders * 100) : 0}%</td>
                </tr>
            `;
            
            // 오류 현황 테이블 업데이트
            const errorStatsBody = document.getElementById('error-stats-body');
            if (stats.recent_errors && stats.recent_errors.length > 0) {
                let errorHtml = '';
                stats.recent_errors.forEach(error => {
                    errorHtml += `
                        <tr>
                            <td><span class="status warning">${error.cron_type}</span></td>
                            <td>${error.message.length > 100 ? error.message.substring(0, 100) + '...' : error.message}</td>
                            <td>${new Date(error.execution_time).toLocaleString()}</td>
                        </tr>
                    `;
                });
                errorStatsBody.innerHTML = errorHtml;
            } else {
                errorStatsBody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#999;">최근 오류가 없습니다.</td></tr>';
            }
        }
        
        // 페이지 로드시 통계 자동 로드
        document.addEventListener('DOMContentLoaded', function() {
            refreshStats();
            
            // 5분마다 자동 새로고침
            setInterval(refreshStats, 300000);
        });
    </script>
</body>
</html>