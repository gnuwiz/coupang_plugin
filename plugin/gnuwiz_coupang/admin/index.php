<?php
/**
 * ============================================================================
 * 쿠팡 연동 플러그인 - 관리자 메인 대시보드
 * ============================================================================
 * 파일: /plugin/gnuwiz_coupang/admin/index.php
 * 용도: 쿠팡 연동 관리 메인 페이지 (통합 대시보드)
 * 작성: 그누위즈 (gnuwiz@example.com)
 * 버전: 2.2.0 (Phase 2-2)
 * 
 * 주요 기능:
 * - 실시간 시스템 상태 모니터링
 * - API 연결 상태 확인
 * - 크론 작업 상태 모니터링
 * - 최근 동기화 로그 표시
 * - 통계 및 성과 지표
 * - 빠른 액션 버튼들
 */

include_once('./_common.php');

// 관리자 권한 체크
if (!$is_admin) {
    alert('관리자만 접근할 수 있습니다.');
    goto_url(G5_URL);
}

// 페이지 설정
$g5['title'] = '쿠팡 연동 관리 대시보드';
include_once(G5_ADMIN_PATH.'/admin.head.php');

// 쿠팡 플러그인 초기화
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

// API 인스턴스 생성
$coupang_api = get_coupang_api();
$config_status = validate_coupang_config();

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
                    FROM " . G5_TABLE_PREFIX . "yc_order 
                    WHERE DATE(od_time) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
$order_stats_result = sql_query($order_stats_sql);
$order_stats = sql_fetch_array($order_stats_result);
?>

<style>
    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
        background: #f8f9fa;
        min-height: 100vh;
    }
    
    .dashboard-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    .dashboard-header h1 {
        margin: 0;
        font-size: 2.5em;
        font-weight: 300;
    }
    
    .dashboard-header .subtitle {
        margin-top: 10px;
        opacity: 0.9;
        font-size: 1.1em;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        transition: transform 0.2s, box-shadow 0.2s;
        border-left: 4px solid #667eea;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }
    
    .stat-card h3 {
        margin: 0 0 15px 0;
        color: #2c3e50;
        font-size: 1.1em;
        font-weight: 600;
    }
    
    .stat-value {
        font-size: 2.5em;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 5px;
    }
    
    .stat-label {
        color: #6c757d;
        font-size: 0.9em;
    }
    
    .status-indicator {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 8px;
    }
    
    .status-online { background: #28a745; }
    .status-warning { background: #ffc107; }
    .status-error { background: #dc3545; }
    
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 30px;
    }
    
    .action-btn {
        background: white;
        border: 2px solid #e9ecef;
        padding: 20px;
        border-radius: 10px;
        text-align: center;
        text-decoration: none;
        color: #495057;
        transition: all 0.2s;
        font-weight: 500;
    }
    
    .action-btn:hover {
        border-color: #667eea;
        color: #667eea;
        transform: translateY(-1px);
        text-decoration: none;
    }
    
    .action-btn i {
        font-size: 2em;
        margin-bottom: 10px;
        display: block;
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
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    }
    
    .card h3 {
        margin-top: 0;
        color: #2c3e50;
        border-bottom: 2px solid #f8f9fa;
        padding-bottom: 15px;
    }
    
    .log-entry {
        padding: 12px;
        margin-bottom: 8px;
        border-radius: 6px;
        border-left: 4px solid #dee2e6;
        background: #f8f9fa;
        font-size: 0.9em;
    }
    
    .log-entry.success { 
        border-left-color: #28a745; 
        background: #d4edda; 
    }
    
    .log-entry.error { 
        border-left-color: #dc3545; 
        background: #f8d7da; 
    }
    
    .log-entry.warning { 
        border-left-color: #ffc107; 
        background: #fff3cd; 
    }
    
    .log-time {
        font-size: 0.8em;
        color: #6c757d;
        float: right;
    }
    
    .progress-bar {
        background: #e9ecef;
        border-radius: 10px;
        height: 8px;
        margin-top: 8px;
        overflow: hidden;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea, #764ba2);
        transition: width 0.3s ease;
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
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h1>🚀 쿠팡 연동 관리 대시보드</h1>
        <div class="subtitle">
            실시간 모니터링 • API 상태 확인 • 동기화 관리 • 성과 분석
        </div>
    </div>
    
    <!-- 시스템 상태 알림 -->
    <?php if (!$config_status['valid']): ?>
        <div class="alert alert-danger">
            <strong>❌ 시스템 설정 오류:</strong>
            <?php echo implode('<br>', $config_status['errors']); ?>
        </div>
    <?php else: ?>
        <div class="alert alert-success">
            <span class="status-indicator status-online"></span>
            <strong>✅ 시스템 정상 작동 중</strong> - API 연결 및 모든 설정이 완료되었습니다.
        </div>
    <?php endif; ?>
    
    <!-- 핵심 통계 -->
    <div class="stats-grid">
        <div class="stat-card">
            <h3>📦 상품 동기화</h3>
            <div class="stat-value"><?php echo number_format($total_stats['synced_products'] ?? 0); ?></div>
            <div class="stat-label">
                총 <?php echo number_format($total_stats['total_products'] ?? 0); ?>개 중 동기화됨
            </div>
            <?php 
            $sync_rate = $total_stats['total_products'] > 0 ? 
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
            <h3>⚡ API 응답 상태</h3>
            <?php if ($coupang_api): ?>
                <div class="stat-value" style="color: #28a745;">정상</div>
                <div class="stat-label">
                    <span class="status-indicator status-online"></span>
                    API 연결 활성화
                </div>
            <?php else: ?>
                <div class="stat-value" style="color: #dc3545;">오류</div>
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
        <a href="api_test.php" class="action-btn">
            <i>🧪</i>
            API 테스트
        </a>
        <a href="manual_sync.php" class="action-btn">
            <i>🔄</i>
            수동 동기화
        </a>
        <a href="settings.php" class="action-btn">
            <i>⚙️</i>
            설정 관리
        </a>
        <a href="shipping_places.php" class="action-btn">
            <i>📦</i>
            출고지 관리
        </a>
        <a href="category_test.php" class="action-btn">
            <i>🏷️</i>
            카테고리 테스트
        </a>
        <a href="product_registration.php" class="action-btn">
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
                    $success_rate = $row['total_runs'] > 0 ? ($row['success_runs'] / $row['total_runs']) * 100 : 0;
                    $cron_names = array(
                        'orders' => '📋 주문 동기화',
                        'cancelled_orders' => '❌ 취소 주문',
                        'order_status' => '📝 주문 상태',
                        'products' => '🛍️ 상품 동기화',
                        'product_status' => '📊 상품 상태',
                        'stock' => '📦 재고 관리',
                        'shipping_places' => '🚚 출고지 관리',
                        'category_recommendations' => '🏷️ 카테고리 추천',
                        'category_cache_cleanup' => '🧹 캐시 정리'
                    );
                    $display_name = isset($cron_names[$row['cron_type']]) ? $cron_names[$row['cron_type']] : $row['cron_type'];
                    ?>
                    <div class="log-entry <?php echo $success_rate >= 80 ? 'success' : ($success_rate >= 50 ? 'warning' : 'error'); ?>">
                        <strong><?php echo $display_name; ?></strong>
                        <span class="log-time">
                            성공률: <?php echo round($success_rate, 1); ?>% 
                            (<?php echo $row['success_runs']; ?>/<?php echo $row['total_runs']; ?>)
                        </span>
                        <div style="margin-top: 5px; font-size: 0.85em; color: #6c757d;">
                            평균 실행시간: <?php echo $row['avg_duration'] ? round($row['avg_duration'], 2) . '초' : 'N/A'; ?>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $success_rate; ?>%"></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="log-entry">
                    오늘 실행된 크론 작업이 없습니다.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 최근 활동 로그 -->
        <div class="card">
            <h3>📋 최근 활동 로그</h3>
            
            <?php if (sql_num_rows($recent_logs_result) > 0): ?>
                <?php while ($log = sql_fetch_array($recent_logs_result)): ?>
                    <?php 
                    $log_class = '';
                    switch(strtolower($log['status'])) {
                        case 'success': $log_class = 'success'; break;
                        case 'error': $log_class = 'error'; break;
                        case 'warning': $log_class = 'warning'; break;
                        default: $log_class = '';
                    }
                    ?>
                    <div class="log-entry <?php echo $log_class; ?>">
                        <strong><?php echo htmlspecialchars($log['cron_type']); ?></strong>
                        <span class="log-time"><?php echo date('H:i:s', strtotime($log['created_date'])); ?></span>
                        <div style="margin-top: 3px;">
                            <?php echo htmlspecialchars(mb_substr($log['message'], 0, 100)); ?>
                            <?php if (strlen($log['message']) > 100): ?>...<?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="log-entry">
                    아직 활동 로그가 없습니다.
                </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 15px;">
                <a href="manual_sync.php" style="color: #667eea; text-decoration: none; font-size: 0.9em;">
                    📋 전체 로그 보기 →
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// 페이지 자동 새로고침 (5분마다)
setTimeout(function() {
    window.location.reload();
}, 300000);

// 실시간 시간 업데이트
function updateTime() {
    var now = new Date();
    var timeString = now.toLocaleTimeString('ko-KR');
    document.title = '쿠팡 대시보드 (' + timeString + ')';
}

setInterval(updateTime, 1000);
updateTime();
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
?>