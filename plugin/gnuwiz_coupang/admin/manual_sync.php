<?php
/**
 * ============================================================================
 * 쿠팡 연동 플러그인 - 수동 동기화 통합 관리 페이지
 * ============================================================================
 * 파일: /plugin/gnuwiz_coupang/admin/manual_sync.php
 * 용도: 10개 크론 작업 수동 실행 및 통합 모니터링
 * 작성: 그누위즈 (gnuwiz@example.com)
 * 버전: 2.2.0 (Phase 2-2)
 * 
 * 주요 기능:
 * - 10개 크론 작업 개별 수동 실행
 * - 실시간 로그 모니터링
 * - 동기화 통계 및 성과 분석
 * - 배치 실행 및 스케줄 관리
 * - 오류 진단 및 해결 가이드
 */

include_once('./_common.php');

// 관리자 권한 체크
if (!$is_admin) {
    alert('관리자만 접근할 수 있습니다.');
    goto_url(G5_URL);
}

// 페이지 설정
$g5['title'] = '쿠팡 연동 수동 동기화 관리';
include_once(G5_ADMIN_PATH.'/admin.head.php');

// 쿠팡 플러그인 초기화
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

// API 인스턴스 생성
$coupang_api = get_coupang_api();
$config_status = validate_coupang_config();

// AJAX 요청 처리 (크론 작업 실행)
if (isset($_POST['action']) && $_POST['action'] === 'run_cron') {
    header('Content-Type: application/json');
    
    $cron_type = isset($_POST['cron_type']) ? $_POST['cron_type'] : '';
    $params = isset($_POST['params']) ? json_decode($_POST['params'], true) : array();
    
    if (!$coupang_api) {
        echo json_encode(array(
            'success' => false,
            'error' => 'API 인스턴스를 생성할 수 없습니다.',
            'execution_time' => 0
        ));
        exit;
    }
    
    try {
        $start_time = microtime(true);
        $result = array('success' => false, 'error' => '지원하지 않는 크론 타입입니다.');
        
        switch ($cron_type) {
            case 'orders':
                $limit = isset($params['limit']) ? intval($params['limit']) : 50;
                $result = $coupang_api->syncOrdersFromCoupang($limit);
                break;
                
            case 'cancelled_orders':
                $limit = isset($params['limit']) ? intval($params['limit']) : 50;
                $result = $coupang_api->syncCancelledOrdersFromCoupang($limit);
                break;
                
            case 'order_status':
                $limit = isset($params['limit']) ? intval($params['limit']) : 50;
                $result = $coupang_api->syncOrderStatusToCoupang($limit);
                break;
                
            case 'products':
                $limit = isset($params['limit']) ? intval($params['limit']) : 20;
                $result = $coupang_api->syncProductsToCoupang($limit);
                break;
                
            case 'product_status':
                $limit = isset($params['limit']) ? intval($params['limit']) : 50;
                $result = $coupang_api->syncProductStatus($limit);
                break;
                
            case 'stock':
                $limit = isset($params['limit']) ? intval($params['limit']) : 50;
                $result = $coupang_api->syncStockToCoupang($limit);
                break;
                
            case 'shipping_places':
                $result = $coupang_api->syncShippingPlacesFromCoupang();
                break;
                
            case 'category_recommendations':
                $limit = isset($params['limit']) ? intval($params['limit']) : 20;
                $result = $coupang_api->batchProcessCategoryRecommendations($limit);
                break;
                
            case 'category_cache_cleanup':
                $result = $coupang_api->cleanupCategoryCache();
                break;
                
            default:
                throw new Exception('지원하지 않는 크론 타입: ' . $cron_type);
        }
        
        $execution_time = microtime(true) - $start_time;
        
        // 크론 로그 기록
        monitor_cron_execution($cron_type, $result['success'] ? 'SUCCESS' : 'ERROR', 
                             $result['success'] ? '수동 실행 성공' : $result['error'], 
                             $execution_time, $result);
        
        echo json_encode(array(
            'success' => $result['success'],
            'result' => $result,
            'execution_time' => round($execution_time * 1000, 2),
            'cron_type' => $cron_type
        ));
        
    } catch (Exception $e) {
        $execution_time = microtime(true) - $start_time;
        
        // 오류 로그 기록
        monitor_cron_execution($cron_type, 'ERROR', $e->getMessage(), $execution_time);
        
        echo json_encode(array(
            'success' => false,
            'error' => $e->getMessage(),
            'execution_time' => round($execution_time * 1000, 2),
            'cron_type' => $cron_type
        ));
    }
    
    exit;
}

// 최근 크론 로그 조회 (각 타입별 최신 5개)
$recent_logs_sql = "SELECT * FROM (
                        SELECT *, ROW_NUMBER() OVER (PARTITION BY cron_type ORDER BY created_date DESC) as rn 
                        FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
                        WHERE created_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ) ranked 
                    WHERE rn <= 5 
                    ORDER BY created_date DESC 
                    LIMIT 50";
$recent_logs_result = sql_query($recent_logs_sql);

// 오늘의 크론 통계
$today_stats_sql = "SELECT 
                        cron_type,
                        COUNT(*) as total_runs,
                        SUM(CASE WHEN status = 'SUCCESS' THEN 1 ELSE 0 END) as success_runs,
                        SUM(CASE WHEN status = 'ERROR' THEN 1 ELSE 0 END) as error_runs,
                        AVG(execution_duration) as avg_duration,
                        MAX(created_date) as last_run
                    FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
                    WHERE DATE(created_date) = CURDATE()
                    GROUP BY cron_type
                    ORDER BY last_run DESC";
$today_stats_result = sql_query($today_stats_sql);

// 크론 작업 정의
$cron_jobs = array(
    'orders' => array(
        'name' => '📋 주문 동기화',
        'description' => '쿠팡 주문을 영카트로 가져오기',
        'icon' => '📋',
        'category' => 'order',
        'priority' => 'high',
        'default_limit' => 50,
        'execution_time' => '1-2분',
        'frequency' => '매분'
    ),
    'cancelled_orders' => array(
        'name' => '❌ 취소 주문 동기화',
        'description' => '쿠팡 취소 주문 처리',
        'icon' => '❌',
        'category' => 'order',
        'priority' => 'high',
        'default_limit' => 50,
        'execution_time' => '30초-1분',
        'frequency' => '매분'
    ),
    'order_status' => array(
        'name' => '📝 주문 상태 동기화',
        'description' => '영카트 주문 상태를 쿠팡에 전송',
        'icon' => '📝',
        'category' => 'order',
        'priority' => 'high',
        'default_limit' => 50,
        'execution_time' => '30초-1분',
        'frequency' => '매분'
    ),
    'products' => array(
        'name' => '🛍️ 상품 동기화',
        'description' => '영카트 상품을 쿠팡에 등록/업데이트',
        'icon' => '🛍️',
        'category' => 'product',
        'priority' => 'medium',
        'default_limit' => 20,
        'execution_time' => '2-5분',
        'frequency' => '하루 2회'
    ),
    'product_status' => array(
        'name' => '📊 상품 상태 동기화',
        'description' => '쿠팡 상품 승인 상태 확인',
        'icon' => '📊',
        'category' => 'product',
        'priority' => 'medium',
        'default_limit' => 50,
        'execution_time' => '1-2분',
        'frequency' => '하루 2회'
    ),
    'stock' => array(
        'name' => '📦 재고 동기화',
        'description' => '영카트 재고를 쿠팡에 업데이트',
        'icon' => '📦',
        'category' => 'product',
        'priority' => 'medium',
        'default_limit' => 50,
        'execution_time' => '1-3분',
        'frequency' => '하루 2회'
    ),
    'shipping_places' => array(
        'name' => '🚚 출고지 동기화',
        'description' => '쿠팡 출고지/반품지 정보 동기화',
        'icon' => '🚚',
        'category' => 'system',
        'priority' => 'low',
        'default_limit' => 0,
        'execution_time' => '30초-1분',
        'frequency' => '하루 1회'
    ),
    'category_recommendations' => array(
        'name' => '🏷️ 카테고리 추천',
        'description' => '상품 카테고리 자동 추천 처리',
        'icon' => '🏷️',
        'category' => 'system',
        'priority' => 'low',
        'default_limit' => 20,
        'execution_time' => '2-5분',
        'frequency' => '하루 1회'
    ),
    'category_cache_cleanup' => array(
        'name' => '🧹 카테고리 캐시 정리',
        'description' => '오래된 카테고리 캐시 데이터 삭제',
        'icon' => '🧹',
        'category' => 'system',
        'priority' => 'low',
        'default_limit' => 0,
        'execution_time' => '10-30초',
        'frequency' => '하루 1회'
    )
);
?>

<style>
    .sync-container {
        max-width: 1600px;
        margin: 0 auto;
        padding: 20px;
        background: #f8f9fa;
        min-height: 100vh;
    }
    
    .sync-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    .sync-header h1 {
        margin: 0;
        font-size: 2.5em;
        font-weight: 300;
    }
    
    .sync-header .subtitle {
        margin-top: 10px;
        opacity: 0.9;
        font-size: 1.1em;
    }
    
    .control-panel {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        margin-bottom: 30px;
    }
    
    .batch-controls {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .batch-button {
        background: linear-gradient(135deg, #28a745, #20c997);
        color: white;
        border: none;
        padding: 15px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 500;
        transition: all 0.2s;
        text-align: center;
    }
    
    .batch-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    .batch-button:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }
    
    .batch-button.danger {
        background: linear-gradient(135deg, #dc3545, #c82333);
    }
    
    .batch-button.danger:hover {
        box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
    }
    
    .cron-jobs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .cron-job-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        transition: transform 0.2s, box-shadow 0.2s;
        border-left: 4px solid #dee2e6;
    }
    
    .cron-job-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }
    
    .cron-job-card.priority-high {
        border-left-color: #dc3545;
    }
    
    .cron-job-card.priority-medium {
        border-left-color: #ffc107;
    }
    
    .cron-job-card.priority-low {
        border-left-color: #28a745;
    }
    
    .cron-job-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .cron-job-title {
        font-size: 1.1em;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }
    
    .cron-job-status {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.75em;
        font-weight: 500;
    }
    
    .cron-job-status.success {
        background: #d4edda;
        color: #155724;
    }
    
    .cron-job-status.error {
        background: #f8d7da;
        color: #721c24;
    }
    
    .cron-job-status.warning {
        background: #fff3cd;
        color: #856404;
    }
    
    .cron-job-status.unknown {
        background: #e2e3e5;
        color: #6c757d;
    }
    
    .cron-job-description {
        color: #6c757d;
        font-size: 0.9em;
        margin-bottom: 15px;
    }
    
    .cron-job-meta {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        font-size: 0.8em;
        color: #6c757d;
        margin-bottom: 15px;
    }
    
    .cron-job-controls {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .limit-input {
        width: 60px;
        padding: 5px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 0.8em;
    }
    
    .run-button {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border: none;
        padding: 8px 15px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.85em;
        font-weight: 500;
        transition: all 0.2s;
        flex-grow: 1;
    }
    
    .run-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    .run-button:disabled {
        background: #6c757d;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }
    
    .logs-section {
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
    
    .log-details {
        margin-top: 5px;
        font-size: 0.85em;
        color: #495057;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }
    
    .stat-item {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        text-align: center;
    }
    
    .stat-value {
        font-size: 1.5em;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 5px;
    }
    
    .stat-label {
        color: #6c757d;
        font-size: 0.85em;
    }
    
    .progress-bar {
        background: #e9ecef;
        height: 6px;
        border-radius: 3px;
        margin: 15px 0;
        overflow: hidden;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea, #764ba2);
        transition: width 0.3s ease;
        width: 0%;
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid transparent;
    }
    
    .alert-danger {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }
    
    .alert-success {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }
    
    .alert-info {
        color: #0c5460;
        background-color: #d1ecf1;
        border-color: #bee5eb;
    }
    
    .execution-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }
    
    .execution-modal.show {
        display: flex;
    }
    
    .modal-content {
        background: white;
        padding: 30px;
        border-radius: 12px;
        max-width: 500px;
        width: 90%;
        text-align: center;
    }
    
    .spinner {
        border: 4px solid #f3f3f3;
        border-top: 4px solid #667eea;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    @media (max-width: 768px) {
        .cron-jobs-grid {
            grid-template-columns: 1fr;
        }
        
        .logs-section {
            grid-template-columns: 1fr;
        }
        
        .batch-controls {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="sync-container">
    <div class="sync-header">
        <h1>🔄 쿠팡 연동 수동 동기화 관리</h1>
        <div class="subtitle">
            10개 크론 작업 • 실시간 모니터링 • 배치 실행 • 성과 분석
        </div>
    </div>
    
    <!-- 시스템 상태 알림 -->
    <?php if (!$config_status['valid']): ?>
        <div class="alert alert-danger">
            <strong>❌ API 설정 오류:</strong>
            <?php echo implode('<br>', $config_status['errors']); ?>
        </div>
    <?php else: ?>
        <div class="alert alert-success">
            <strong>✅ 시스템 정상 작동 중</strong> - 모든 크론 작업 실행 준비 완료
        </div>
    <?php endif; ?>
    
    <!-- 배치 제어 패널 -->
    <div class="control-panel">
        <h3>🚀 배치 실행 제어</h3>
        <div class="batch-controls">
            <button class="batch-button" onclick="runBatchSync('order')" id="batchOrderBtn">
                📋 주문 관련 전체 실행
            </button>
            <button class="batch-button" onclick="runBatchSync('product')" id="batchProductBtn">
                🛍️ 상품 관련 전체 실행
            </button>
            <button class="batch-button" onclick="runBatchSync('system')" id="batchSystemBtn">
                ⚙️ 시스템 관련 전체 실행
            </button>
            <button class="batch-button" onclick="runBatchSync('all')" id="batchAllBtn">
                🔥 전체 크론 일괄 실행
            </button>
            <button class="batch-button danger" onclick="stopAllCrons()" id="stopAllBtn">
                🛑 모든 작업 중지
            </button>
            <button class="batch-button" onclick="refreshLogs()" id="refreshBtn">
                🔄 로그 새로고침
            </button>
        </div>
        
        <div class="progress-bar">
            <div class="progress-fill" id="batchProgress"></div>
        </div>
        <div id="batchStatus" style="text-align: center; margin-top: 10px; font-size: 0.9em; color: #6c757d;"></div>
    </div>
    
    <!-- 크론 작업 카드들 -->
    <div class="cron-jobs-grid">
        <?php foreach ($cron_jobs as $cron_type => $job_info): ?>
            <?php
            // 해당 크론의 최근 상태 확인
            $job_status = 'unknown';
            $last_run = '-';
            $success_rate = 0;
            
            sql_data_seek($today_stats_result, 0);
            while ($stat = sql_fetch_array($today_stats_result)) {
                if ($stat['cron_type'] === $cron_type) {
                    $success_rate = $stat['total_runs'] > 0 ? ($stat['success_runs'] / $stat['total_runs']) * 100 : 0;
                    $last_run = date('H:i', strtotime($stat['last_run']));
                    
                    if ($success_rate >= 80) {
                        $job_status = 'success';
                    } elseif ($success_rate >= 50) {
                        $job_status = 'warning';
                    } else {
                        $job_status = 'error';
                    }
                    break;
                }
            }
            ?>
            
            <div class="cron-job-card priority-<?php echo $job_info['priority']; ?>">
                <div class="cron-job-header">
                    <h4 class="cron-job-title"><?php echo $job_info['name']; ?></h4>
                    <span class="cron-job-status <?php echo $job_status; ?>">
                        <?php
                        switch($job_status) {
                            case 'success': echo '✅ 정상'; break;
                            case 'warning': echo '⚠️ 주의'; break;
                            case 'error': echo '❌ 오류'; break;
                            default: echo '❓ 미실행';
                        }
                        ?>
                    </span>
                </div>
                
                <div class="cron-job-description"><?php echo $job_info['description']; ?></div>
                
                <div class="cron-job-meta">
                    <div><strong>실행 주기:</strong> <?php echo $job_info['frequency']; ?></div>
                    <div><strong>예상 소요:</strong> <?php echo $job_info['execution_time']; ?></div>
                    <div><strong>마지막 실행:</strong> <?php echo $last_run; ?></div>
                    <div><strong>성공률:</strong> <?php echo round($success_rate, 1); ?>%</div>
                </div>
                
                <div class="cron-job-controls">
                    <?php if ($job_info['default_limit'] > 0): ?>
                        <label style="font-size: 0.8em;">개수:</label>
                        <input type="number" class="limit-input" 
                               id="limit-<?php echo $cron_type; ?>" 
                               value="<?php echo $job_info['default_limit']; ?>" 
                               min="1" max="200">
                    <?php endif; ?>
                    
                    <button class="run-button" onclick="runSingleCron('<?php echo $cron_type; ?>')" 
                            id="btn-<?php echo $cron_type; ?>">
                        <?php echo $job_info['icon']; ?> 실행
                    </button>
                </div>
                
                <!-- 개별 결과 영역 -->
                <div class="log-entry" id="result-<?php echo $cron_type; ?>" style="display: none; margin-top: 15px;"></div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <div class="logs-section">
        <!-- 실시간 로그 -->
        <div class="card">
            <h3>📋 실시간 실행 로그</h3>
            
            <div id="live-logs">
                <?php if (sql_num_rows($recent_logs_result) > 0): ?>
                    <?php while ($log = sql_fetch_array($recent_logs_result)): ?>
                        <?php 
                        $log_class = '';
                        switch(strtolower($log['status'])) {
                            case 'success': $log_class = 'success'; break;
                            case 'error': $log_class = 'error'; break;
                            case 'warning': $log_class = 'warning'; break;
                        }
                        
                        $cron_display = isset($cron_jobs[$log['cron_type']]) ? 
                                       $cron_jobs[$log['cron_type']]['name'] : 
                                       $log['cron_type'];
                        ?>
                        <div class="log-entry <?php echo $log_class; ?>">
                            <strong><?php echo $cron_display; ?></strong>
                            <span class="log-time"><?php echo date('H:i:s', strtotime($log['created_date'])); ?></span>
                            <div class="log-details">
                                <?php echo htmlspecialchars(mb_substr($log['message'], 0, 100)); ?>
                                <?php if (strlen($log['message']) > 100): ?>...<?php endif; ?>
                                <?php if ($log['execution_duration']): ?>
                                    <br><small>실행시간: <?php echo round($log['execution_duration'], 2); ?>초</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="log-entry">
                        아직 실행된 크론 작업이 없습니다.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 오늘의 통계 -->
        <div class="card">
            <h3>📊 오늘의 실행 통계</h3>
            
            <div class="stats-grid">
                <?php
                $total_runs = 0;
                $total_success = 0;
                $total_errors = 0;
                
                sql_data_seek($today_stats_result, 0);
                while ($stat = sql_fetch_array($today_stats_result)) {
                    $total_runs += $stat['total_runs'];
                    $total_success += $stat['success_runs'];
                    $total_errors += $stat['error_runs'];
                }
                ?>
                
                <div class="stat-item">
                    <div class="stat-value"><?php echo $total_runs; ?></div>
                    <div class="stat-label">총 실행 횟수</div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-value" style="color: #28a745;"><?php echo $total_success; ?></div>
                    <div class="stat-label">성공 실행</div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-value" style="color: #dc3545;"><?php echo $total_errors; ?></div>
                    <div class="stat-label">실패 실행</div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-value" style="color: #667eea;">
                        <?php echo $total_runs > 0 ? round(($total_success / $total_runs) * 100, 1) : 0; ?>%
                    </div>
                    <div class="stat-label">전체 성공률</div>
                </div>
            </div>
            
            <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
            
            <h4>크론별 상세 통계</h4>
            <?php 
            sql_data_seek($today_stats_result, 0);
            if (sql_num_rows($today_stats_result) > 0):
            ?>
                <?php while ($stat = sql_fetch_array($today_stats_result)): ?>
                    <?php 
                    $success_rate = $stat['total_runs'] > 0 ? ($stat['success_runs'] / $stat['total_runs']) * 100 : 0;
                    $cron_display = isset($cron_jobs[$stat['cron_type']]) ? 
                                   $cron_jobs[$stat['cron_type']]['name'] : 
                                   $stat['cron_type'];
                    ?>
                    <div class="log-entry <?php echo $success_rate >= 80 ? 'success' : ($success_rate >= 50 ? 'warning' : 'error'); ?>">
                        <strong><?php echo $cron_display; ?></strong>
                        <span class="log-time"><?php echo round($success_rate, 1); ?>%</span>
                        <div class="log-details">
                            실행: <?php echo $stat['total_runs']; ?>회 | 
                            성공: <?php echo $stat['success_runs']; ?>회 | 
                            평균: <?php echo $stat['avg_duration'] ? round($stat['avg_duration'], 2) . '초' : 'N/A'; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="log-entry">
                    오늘 실행된 크론 작업이 없습니다.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 실행 모달 -->
<div class="execution-modal" id="executionModal">
    <div class="modal-content">
        <div class="spinner"></div>
        <h3 id="modalTitle">크론 작업 실행 중...</h3>
        <p id="modalMessage">잠시만 기다려주세요.</p>
        <div class="progress-bar">
            <div class="progress-fill" id="modalProgress"></div>
        </div>
    </div>
</div>

<script>
// 전역 변수
let batchRunning = false;
let currentBatch = [];
let batchIndex = 0;

// 개별 크론 실행
async function runSingleCron(cronType) {
    const button = document.getElementById('btn-' + cronType);
    const resultDiv = document.getElementById('result-' + cronType);
    const limitInput = document.getElementById('limit-' + cronType);
    
    // 버튼 비활성화
    button.disabled = true;
    button.textContent = '⏳ 실행 중...';
    
    // 결과 영역 초기화
    resultDiv.style.display = 'block';
    resultDiv.className = 'log-entry';
    resultDiv.innerHTML = '실행 중...';
    
    // 매개변수 설정
    const params = {};
    if (limitInput) {
        params.limit = parseInt(limitInput.value) || 10;
    }
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=run_cron&cron_type=' + encodeURIComponent(cronType) + 
                  '&params=' + encodeURIComponent(JSON.stringify(params))
        });
        
        const data = await response.json();
        
        // 결과 표시
        if (data.success) {
            resultDiv.className = 'log-entry success';
            resultDiv.innerHTML = `
                <strong>✅ 실행 완료</strong>
                <span class="log-time">${data.execution_time}ms</span>
                <div class="log-details">
                    ${formatCronResult(data.result)}
                </div>
            `;
        } else {
            resultDiv.className = 'log-entry error';
            resultDiv.innerHTML = `
                <strong>❌ 실행 실패</strong>
                <span class="log-time">${data.execution_time}ms</span>
                <div class="log-details">
                    오류: ${data.error}
                </div>
            `;
        }
        
        // 5초 후 로그 새로고침
        setTimeout(refreshLogs, 5000);
        
    } catch (error) {
        resultDiv.className = 'log-entry error';
        resultDiv.innerHTML = `
            <strong>❌ 네트워크 오류</strong>
            <div class="log-details">
                ${error.message}
            </div>
        `;
    } finally {
        // 버튼 활성화
        button.disabled = false;
        const jobInfo = <?php echo json_encode($cron_jobs); ?>[cronType];
        button.textContent = jobInfo.icon + ' 실행';
    }
}

// 크론 결과 포맷팅
function formatCronResult(result) {
    if (!result) return '결과 없음';
    
    let output = [];
    
    if (result.processed !== undefined) {
        output.push(`처리됨: ${result.processed}개`);
    }
    if (result.success_count !== undefined) {
        output.push(`성공: ${result.success_count}개`);
    }
    if (result.error_count !== undefined) {
        output.push(`실패: ${result.error_count}개`);
    }
    if (result.message) {
        output.push(result.message);
    }
    
    return output.length > 0 ? output.join(' | ') : '실행 완료';
}

// 배치 실행
async function runBatchSync(category) {
    if (batchRunning) return;
    
    const jobCategories = {
        order: ['orders', 'cancelled_orders', 'order_status'],
        product: ['products', 'product_status', 'stock'],
        system: ['shipping_places', 'category_recommendations', 'category_cache_cleanup'],
        all: <?php echo json_encode(array_keys($cron_jobs)); ?>
    };
    
    currentBatch = jobCategories[category] || [];
    if (currentBatch.length === 0) return;
    
    batchRunning = true;
    batchIndex = 0;
    
    // 모든 배치 버튼 비활성화
    document.querySelectorAll('.batch-button').forEach(btn => {
        btn.disabled = true;
    });
    
    // 진행 상황 표시
    const progressBar = document.getElementById('batchProgress');
    const statusDiv = document.getElementById('batchStatus');
    
    statusDiv.textContent = `배치 실행 시작: ${currentBatch.length}개 작업`;
    
    let successful = 0;
    
    for (let i = 0; i < currentBatch.length; i++) {
        const cronType = currentBatch[i];
        
        statusDiv.textContent = `실행 중: ${cronType} (${i + 1}/${currentBatch.length})`;
        
        try {
            await runSingleCron(cronType);
            successful++;
        } catch (error) {
            console.error('배치 실행 오류:', error);
        }
        
        // 진행률 업데이트
        const progress = ((i + 1) / currentBatch.length) * 100;
        progressBar.style.width = progress + '%';
        
        // 작업 간 1초 대기
        if (i < currentBatch.length - 1) {
            await new Promise(resolve => setTimeout(resolve, 1000));
        }
    }
    
    // 완료 상태 표시
    statusDiv.innerHTML = `
        <strong>배치 실행 완료!</strong><br>
        ${currentBatch.length}개 작업 중 ${successful}개 성공
    `;
    
    // 버튼 활성화
    document.querySelectorAll('.batch-button').forEach(btn => {
        btn.disabled = false;
    });
    
    batchRunning = false;
    
    // 로그 새로고침
    setTimeout(refreshLogs, 2000);
}

// 모든 작업 중지
function stopAllCrons() {
    if (confirm('실행 중인 모든 크론 작업을 중지하시겠습니까?')) {
        batchRunning = false;
        currentBatch = [];
        
        // 모든 버튼 활성화
        document.querySelectorAll('button[disabled]').forEach(btn => {
            btn.disabled = false;
        });
        
        // 진행 상황 초기화
        document.getElementById('batchProgress').style.width = '0%';
        document.getElementById('batchStatus').textContent = '모든 작업이 중지되었습니다.';
        
        alert('모든 작업이 중지되었습니다.');
    }
}

// 로그 새로고침
function refreshLogs() {
    window.location.reload();
}

// 자동 새로고침 (2분마다)
setInterval(function() {
    if (!batchRunning) {
        refreshLogs();
    }
}, 120000);
</script>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
?>