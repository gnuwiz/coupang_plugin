<?php
/**
 * ============================================================================
 * 쿠팡 연동 플러그인 - 대시보드 페이지
 * ============================================================================
 * 파일: /plugin/gnuwiz_coupang/pages/dashboard.php
 * 용도: 쿠팡 연동 관리 메인 대시보드
 * 작성: 그누위즈 (gnuwiz@example.com)
 * 버전: 2.2.0 (Phase 2-2)
 * 
 * 주요 기능:
 * - 실시간 시스템 상태 모니터링
 * - API 연결 상태 확인 (실제 메서드 기반)
 * - 크론 작업 상태 모니터링
 * - 최근 동기화 로그 표시
 * - 통계 및 성과 지표
 * - 빠른 액션 버튼들
 */

if (!defined('_GNUBOARD_')) exit;

// 관리자 헤더 포함
include_once(G5_ADMIN_PATH . '/admin.head.php');

// API 인스턴스 및 상태 확인
global $coupang_api;
$config_status = validate_coupang_config();

// 시스템 상태 정보 수집
$system_status = array();
$api_status = array();

if ($coupang_api) {
    try {
        // 실제 구현된 메서드들로 상태 확인
        $system_status = $coupang_api->validateAllConfig();
        $api_status['connection'] = true;
        $api_status['message'] = 'API 연결 정상';
    } catch (Exception $e) {
        $api_status['connection'] = false;
        $api_status['message'] = 'API 연결 오류: ' . $e->getMessage();
    }
} else {
    $api_status['connection'] = false;
    $api_status['message'] = 'API 인스턴스 생성 실패';
}

// 최근 로그 조회 (최신 20개)
$recent_logs_sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
                    ORDER BY created_date DESC LIMIT 20";
$recent_logs_result = sql_query($recent_logs_sql);

// 오늘의 동기화 통계
$today_stats_sql = "SELECT 
                        cron_type,
                        COUNT(*) as total_runs,
                        SUM(CASE WHEN status = 'SUCCESS' THEN 1 ELSE 0 END) as success_runs,
                        SUM(CASE WHEN status = 'ERROR' THEN 1 ELSE 0 END) as error_runs,
                        AVG(execution_duration) as avg_duration
                    FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
                    WHERE DATE(created_date) = CURDATE()
                    GROUP BY cron_type
                    ORDER BY total_runs DESC";
$today_stats_result = sql_query($today_stats_sql);

// 시스템 전체 통계
$total_stats_sql = "SELECT 
                        COUNT(*) as total_products,
                        SUM(CASE WHEN coupang_item_id IS NOT NULL THEN 1 ELSE 0 END) as synced_products
                    FROM " . G5_TABLE_PREFIX . "coupang_item_map";
$total_stats_result = sql_query($total_stats_sql);
$total_stats = sql_fetch_array($total_stats_result);

// 최근 주문 통계 (쿠팡 주문)
$order_stats_sql = "SELECT 
                        COUNT(*) as total_orders,
                        SUM(CASE WHEN od_coupang_yn = 'Y' THEN 1 ELSE 0 END) as coupang_orders
                    FROM " . G5_TABLE_PREFIX . "shop_order
                    WHERE od_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
$order_stats_result = sql_query($order_stats_sql);
$order_stats = sql_fetch_array($order_stats_result);

// AJAX 빠른 테스트 처리
if (isset($_POST['action']) && $_POST['action'] === 'quick_test') {
    header('Content-Type: application/json');
    
    $test_type = isset($_POST['test_type']) ? $_POST['test_type'] : '';
    
    if (!$coupang_api) {
        echo json_encode(array(
            'success' => false,
            'message' => 'API 인스턴스가 없습니다.'
        ));
        exit;
    }
    
    try {
        $start_time = microtime(true);
        
        switch ($test_type) {
            case 'config':
                $result = $coupang_api->validateAllConfig();
                break;
            case 'api_config':
                $result = $coupang_api->validateApiConfig();
                break;
            case 'shipping_config':
                $result = $coupang_api->validateShippingPlaceConfig();
                break;
            case 'product_config':
                $result = $coupang_api->validateProductConfig();
                break;
            case 'category_test':
                $result = $coupang_api->getCategoryRecommendation('테스트 상품');
                break;
            default:
                throw new Exception('지원하지 않는 테스트입니다.');
        }
        
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        echo json_encode(array(
            'success' => true,
            'result' => $result,
            'execution_time' => $execution_time . 'ms'
        ));
        
    } catch (Exception $e) {
        echo json_encode(array(
            'success' => false,
            'message' => $e->getMessage()
        ));
    }
    
    exit;
}
?>

<style>
    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    }
    
    .dashboard-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 12px;
        margin-bottom: 30px;
        text-align: center;
    }
    
    .dashboard-header h1 {
        margin: 0 0 10px 0;
        font-size: 2.5em;
        font-weight: 300;
    }
    
    .dashboard-header .subtitle {
        opacity: 0.9;
        font-size: 1.1em;
    }
    
    .navigation-menu {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        padding: 0;
        overflow: hidden;
    }
    
    .nav-links {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-wrap: wrap;
    }
    
    .nav-links li {
        margin: 0;
    }
    
    .nav-links a {
        display: block;
        padding: 15px 20px;
        text-decoration: none;
        color: #495057;
        border-right: 1px solid #e9ecef;
        transition: all 0.2s ease;
        font-weight: 500;
    }
    
    .nav-links a:hover {
        background: #f8f9fa;
        color: #667eea;
    }
    
    .nav-links a.active {
        background: #667eea;
        color: white;
    }
    
    .nav-links li:last-child a {
        border-right: none;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
        border: 1px solid #e1e5e9;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
    }
    
    .stat-card h3 {
        margin: 0 0 15px 0;
        color: #2d3748;
        font-size: 1.1em;
        font-weight: 600;
    }
    
    .stat-value {
        font-size: 2.2em;
        font-weight: bold;
        margin-bottom: 8px;
    }
    
    .stat-label {
        color: #718096;
        font-size: 0.9em;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .status-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
    }
    
    .status-online { background: #48bb78; }
    .status-warning { background: #ed8936; }
    .status-error { background: #f56565; }
    
    .progress-bar {
        background: #e2e8f0;
        height: 8px;
        border-radius: 4px;
        margin: 15px 0;
        overflow: hidden;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #48bb78, #38a169);
        transition: width 0.3s ease;
    }
    
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 30px;
    }
    
    .action-btn {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 15px 20px;
        background: white;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        text-decoration: none;
        color: #2d3748;
        font-weight: 500;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    
    .action-btn:hover {
        border-color: #667eea;
        background: #f7fafc;
        transform: translateY(-1px);
    }
    
    .action-btn i {
        font-size: 1.2em;
    }
    
    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
    }
    
    .card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
        border: 1px solid #e1e5e9;
    }
    
    .card h3 {
        margin: 0 0 20px 0;
        color: #2d3748;
        font-size: 1.3em;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .cron-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .cron-item:last-child {
        border-bottom: none;
    }
    
    .cron-name {
        font-weight: 500;
        color: #2d3748;
    }
    
    .cron-stats {
        display: flex;
        gap: 15px;
        font-size: 0.9em;
        color: #718096;
    }
    
    .log-item {
        padding: 10px 0;
        border-bottom: 1px solid #e2e8f0;
        font-size: 0.9em;
    }
    
    .log-item:last-child {
        border-bottom: none;
    }
    
    .log-time {
        color: #718096;
        font-size: 0.8em;
    }
    
    .log-status {
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 0.8em;
        font-weight: 500;
    }
    
    .log-success { background: #c6f6d5; color: #22543d; }
    .log-error { background: #fed7d7; color: #742a2a; }
    .log-warning { background: #feebc8; color: #7b341e; }
    
    .test-section {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-top: 20px;
    }
    
    .test-buttons {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
        margin-bottom: 15px;
    }
    
    .test-btn {
        padding: 8px 12px;
        background: #667eea;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.9em;
        transition: background 0.2s ease;
    }
    
    .test-btn:hover {
        background: #5a67d8;
    }
    
    .test-btn:disabled {
        background: #a0aec0;
        cursor: not-allowed;
    }
    
    .test-result {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 15px;
        margin-top: 10px;
        display: none;
    }
    
    .test-result.show {
        display: block;
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid transparent;
    }
    
    .alert-success {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }
    
    .alert-danger {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }
    
    .alert-warning {
        color: #856404;
        background-color: #fff3cd;
        border-color: #ffeaa7;
    }
    
    @media (max-width: 768px) {
        .content-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .quick-actions {
            grid-template-columns: 1fr;
        }
        
        .nav-links {
            flex-direction: column;
        }
        
        .nav-links a {
            border-right: none;
            border-bottom: 1px solid #e9ecef;
        }
        
        .nav-links li:last-child a {
            border-bottom: none;
        }
    }
</style>

<div class="dashboard-container">
    <!-- 대시보드 헤더 -->
    <div class="dashboard-header">
        <h1>🚀 쿠팡 연동 관리 대시보드</h1>
        <div class="subtitle">
            API 상태 모니터링 • 실시간 동기화 관리 • 성과 분석 • 시스템 제어
        </div>
    </div>
    
    <!-- 네비게이션 메뉴 -->
    <nav class="navigation-menu">
        <ul class="nav-links">
            <li>
                <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_manual_sync">
                    🔄 수동 동기화
                </a>
            </li>
            <li>
                <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_shipping_places">
                    📦 출고지 관리
                </a>
            </li>
            <li>
                <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_category_test">
                    🏷️ 카테고리 테스트
                </a>
            </li>
            <li>
                <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_product_registration">
                    ➕ 상품 등록
                </a>
            </li>
            <li>
                <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_settings">
                    ⚙️ 설정 관리
                </a>
            </li>
        </ul>
    </nav>
    
    <!-- 시스템 상태 알림 -->
    <?php if (!$config_status['valid']): ?>
        <div class="alert alert-danger">
            <strong>❌ 시스템 설정 오류:</strong><br>
            <?php echo implode('<br>', $config_status['errors']); ?>
        </div>
    <?php elseif (!$api_status['connection']): ?>
        <div class="alert alert-warning">
            <strong>⚠️ API 연결 문제:</strong> <?php echo $api_status['message']; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-success">
            <strong>✅ 시스템 정상 작동 중</strong> - 모든 기능이 활성화되었습니다.
        </div>
    <?php endif; ?>
    
    <!-- 주요 통계 카드들 -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>📊 상품 동기화 현황</h3>
            <div class="stat-value"><?php echo number_format($total_stats['synced_products'] ?? 0); ?></div>
            <div class="stat-label">
                동기화 완료 / 전체 <?php echo number_format($total_stats['total_products'] ?? 0); ?>개
            </div>
            <?php 
            $sync_rate = ($total_stats['total_products'] > 0) ? 
                ($total_stats['synced_products'] / $total_stats['total_products']) * 100 : 0;
            ?>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $sync_rate; ?>%"></div>
            </div>
        </div>
        
        <div class="stat-card">
            <h3>📋 최근 주문 (7일)</h3>
            <div class="stat-value"><?php echo number_format($order_stats['coupang_orders'] ?? 0); ?></div>
            <div class="stat-label">
                쿠팡 주문 / 전체 <?php echo number_format($order_stats['total_orders'] ?? 0); ?>건
            </div>
        </div>
        
        <div class="stat-card">
            <h3>⚡ API 연결 상태</h3>
            <?php if ($api_status['connection']): ?>
                <div class="stat-value" style="color: #48bb78;">정상</div>
                <div class="stat-label">
                    <span class="status-indicator status-online"></span>
                    API 연결 활성화
                </div>
            <?php else: ?>
                <div class="stat-value" style="color: #f56565;">오류</div>
                <div class="stat-label">
                    <span class="status-indicator status-error"></span>
                    API 연결 실패
                </div>
            <?php endif; ?>
        </div>
        
        <div class="stat-card">
            <h3>🔄 오늘 크론 실행</h3>
            <?php
            $today_total = 0;
            $today_success = 0;
            while ($row = sql_fetch_array($today_stats_result)) {
                $today_total += $row['total_runs'];
                $today_success += $row['success_runs'];
            }
            sql_data_seek($today_stats_result, 0); // 결과셋 포인터 리셋
            ?>
            <div class="stat-value"><?php echo number_format($today_total); ?></div>
            <div class="stat-label">
                성공: <?php echo number_format($today_success); ?>회
                <?php if ($today_total > 0): ?>
                    (<?php echo round(($today_success/$today_total)*100, 1); ?>%)
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 빠른 액션 버튼들 -->
    <div class="quick-actions">
        <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_api_test" class="action-btn">
            <i>🧪</i>
            API 테스트
        </a>
        <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_manual_sync" class="action-btn">
            <i>🔄</i>
            수동 동기화
        </a>
        <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_settings" class="action-btn">
            <i>⚙️</i>
            설정 관리
        </a>
        <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_shipping_places" class="action-btn">
            <i>📦</i>
            출고지 관리
        </a>
        <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_category_test" class="action-btn">
            <i>🏷️</i>
            카테고리 테스트
        </a>
        <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_product_registration" class="action-btn">
            <i>➕</i>
            상품 등록
        </a>
    </div>
    
    <div class="content-grid">
        <!-- 오늘의 크론 작업 상태 -->
        <div class="card">
            <h3>📊 오늘의 동기화 작업 현황</h3>
            
            <?php if (sql_num_rows($today_stats_result) > 0): ?>
                <?php while ($row = sql_fetch_array($today_stats_result)): ?>
                    <?php 
                    $success_rate = $row['total_runs'] > 0 ? 
                        round(($row['success_runs'] / $row['total_runs']) * 100, 1) : 0;
                    ?>
                    <div class="cron-item">
                        <div class="cron-name">
                            <?php
                            $cron_names = array(
                                'orders' => '📦 주문 동기화',
                                'cancelled_orders' => '❌ 취소 주문',
                                'order_status' => '📋 주문 상태',
                                'products' => '🛍️ 상품 동기화',
                                'product_status' => '📊 상품 상태',
                                'stock' => '📈 재고 동기화',
                                'shipping_places' => '🚚 출고지 동기화',
                                'category_recommendations' => '🏷️ 카테고리 추천',
                                'category_cache_cleanup' => '🧹 캐시 정리'
                            );
                            echo isset($cron_names[$row['cron_type']]) ? 
                                $cron_names[$row['cron_type']] : $row['cron_type'];
                            ?>
                        </div>
                        <div class="cron-stats">
                            <span>총 <?php echo $row['total_runs']; ?>회</span>
                            <span style="color: #48bb78;">성공 <?php echo $row['success_runs']; ?>회</span>
                            <?php if ($row['error_runs'] > 0): ?>
                                <span style="color: #f56565;">오류 <?php echo $row['error_runs']; ?>회</span>
                            <?php endif; ?>
                            <span>(<?php echo $success_rate; ?>%)</span>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color: #718096; text-align: center; padding: 20px;">
                    오늘 실행된 크론 작업이 없습니다.
                </p>
            <?php endif; ?>
            
            <!-- 빠른 테스트 섹션 -->
            <div class="test-section">
                <h4>🔧 빠른 시스템 테스트</h4>
                <div class="test-buttons">
                    <button class="test-btn" onclick="quickTest('config')">전체 설정</button>
                    <button class="test-btn" onclick="quickTest('api_config')">API 설정</button>
                    <button class="test-btn" onclick="quickTest('shipping_config')">출고지 설정</button>
                    <button class="test-btn" onclick="quickTest('product_config')">상품 설정</button>
                    <button class="test-btn" onclick="quickTest('category_test')">카테고리 테스트</button>
                </div>
                <div class="test-result" id="testResult"></div>
            </div>
        </div>
        
        <!-- 최근 동기화 로그 -->
        <div class="card">
            <h3>📋 최근 동기화 로그</h3>
            
            <?php if (sql_num_rows($recent_logs_result) > 0): ?>
                <?php $log_count = 0; ?>
                <?php while ($log = sql_fetch_array($recent_logs_result)): ?>
                    <?php if ($log_count >= 10) break; // 최대 10개만 표시 ?>
                    <div class="log-item">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            <span class="log-status log-<?php echo strtolower($log['status']); ?>">
                                <?php echo $log['status']; ?>
                            </span>
                            <span class="log-time">
                                <?php echo date('m-d H:i', strtotime($log['created_date'])); ?>
                            </span>
                        </div>
                        <div style="font-weight: 500; color: #2d3748;">
                            <?php echo htmlspecialchars($log['cron_type']); ?>
                        </div>
                        <?php if (!empty($log['message'])): ?>
                            <div style="color: #718096; font-size: 0.85em; margin-top: 3px;">
                                <?php echo htmlspecialchars(mb_substr($log['message'], 0, 50)) . 
                                    (mb_strlen($log['message']) > 50 ? '...' : ''); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($log['execution_duration'] > 0): ?>
                            <div style="color: #718096; font-size: 0.8em;">
                                실행시간: <?php echo round($log['execution_duration'], 2); ?>초
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php $log_count++; ?>
                <?php endwhile; ?>
                
                <div style="text-align: center; margin-top: 15px;">
                    <a href="<?php echo G5_ADMIN_URL; ?>/view.php?call=coupang_manual_sync&tab=logs" 
                       style="color: #667eea; text-decoration: none; font-size: 0.9em;">
                        📄 전체 로그 보기
                    </a>
                </div>
            <?php else: ?>
                <p style="color: #718096; text-align: center; padding: 20px;">
                    동기화 로그가 없습니다.
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// 빠른 테스트 함수
function quickTest(testType) {
    const resultDiv = document.getElementById('testResult');
    const button = event.target;
    
    // 버튼 비활성화
    button.disabled = true;
    button.textContent = '테스트 중...';
    
    // 결과 영역 표시
    resultDiv.className = 'test-result show';
    resultDiv.innerHTML = '<div style="text-align: center;">🔄 테스트 실행 중...</div>';
    
    // AJAX 요청
    fetch('', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=quick_test&test_type=' + encodeURIComponent(testType)
    })
    .then(response => response.json())
    .then(data => {
        let resultHtml = '';
        
        if (data.success) {
            resultHtml = `
                <div style="color: #155724; margin-bottom: 10px;">
                    <strong>✅ 테스트 성공</strong> 
                    ${data.execution_time ? '(' + data.execution_time + ')' : ''}
                </div>
                <pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 0.85em; max-height: 200px; overflow-y: auto;">${JSON.stringify(data.result, null, 2)}</pre>
            `;
        } else {
            resultHtml = `
                <div style="color: #721c24;">
                    <strong>❌ 테스트 실패:</strong> ${data.message}
                </div>
            `;
        }
        
        resultDiv.innerHTML = resultHtml;
    })
    .catch(error => {
        resultDiv.innerHTML = `
            <div style="color: #721c24;">
                <strong>❌ 요청 오류:</strong> ${error.message}
            </div>
        `;
    })
    .finally(() => {
        // 버튼 복원
        button.disabled = false;
        button.textContent = getTestButtonText(testType);
    });
}

function getTestButtonText(testType) {
    const buttonTexts = {
        'config': '전체 설정',
        'api_config': 'API 설정',
        'shipping_config': '출고지 설정',
        'product_config': '상품 설정',
        'category_test': '카테고리 테스트'
    };
    return buttonTexts[testType] || testType;
}

// 페이지 로드 시 자동 새로고침 (5분마다)
setTimeout(function() {
    location.reload();
}, 300000); // 5분 = 300,000ms
</script>

<?php
// 관리자 푸터 포함
include_once(G5_ADMIN_PATH . '/admin.tail.php');
?>