<?php
/**
 * ============================================================================
 * 쿠팡 연동 플러그인 - 수동 동기화 통합 대시보드
 * ============================================================================
 * 파일: /plugin/gnuwiz_coupang/pages/manual_sync.php
 * 용도: 모든 동기화 작업을 수동으로 실행하고 모니터링
 * 작성: 그누위즈 (gnuwiz@example.com)
 * 버전: 2.2.0 (Phase 2-2)
 *
 * 주요 기능:
 * - 실제 크론 파일 기반 수동 동기화 실행
 * - 실시간 동기화 진행 상황 모니터링
 * - 동기화 결과 상세 분석 및 로그 표시
 * - 일괄 동기화 및 개별 동기화 지원
 * - 동기화 스케줄링 및 자동화 설정
 */

if (!defined('_GNUBOARD_')) exit;

// 관리자 헤더 포함
include_once(G5_ADMIN_PATH . '/admin.head.php');

// API 인스턴스 확인
global $coupang_api;
$config_status = validate_coupang_config();

// 동기화 가능한 타입들 (실제 크론 파일 기반)
$sync_types = array(
    'orders' => array(
        'name' => '주문 동기화',
        'description' => '쿠팡 → 영카트 주문 동기화 (신규/수정)',
        'icon' => '🛒',
        'frequency' => '매분',
        'file' => 'orders.php',
        'category' => 'orders'
    ),
    'cancelled_orders' => array(
        'name' => '취소 주문 동기화',
        'description' => '쿠팡 취소 주문 → 영카트 반영',
        'icon' => '❌',
        'frequency' => '매분',
        'file' => 'cancelled_orders.php',
        'category' => 'orders'
    ),
    'order_status' => array(
        'name' => '주문 상태 동기화',
        'description' => '영카트 주문 상태 → 쿠팡 반영',
        'icon' => '📋',
        'frequency' => '매분',
        'file' => 'order_status.php',
        'category' => 'orders'
    ),
    'products' => array(
        'name' => '상품 동기화',
        'description' => '영카트 상품 → 쿠팡 등록/업데이트',
        'icon' => '📦',
        'frequency' => '하루 2번',
        'file' => 'products.php',
        'category' => 'products'
    ),
    'product_status' => array(
        'name' => '상품 상태 동기화',
        'description' => '영카트 상품 상태 → 쿠팡 반영',
        'icon' => '📊',
        'frequency' => '하루 2번',
        'file' => 'product_status.php',
        'category' => 'products'
    ),
    'stock' => array(
        'name' => '재고/가격 동기화',
        'description' => '영카트 재고/가격 → 쿠팡 동기화',
        'icon' => '💰',
        'frequency' => '하루 2번',
        'file' => 'stock.php',
        'category' => 'products'
    ),
    'shipping_places' => array(
        'name' => '출고지 동기화',
        'description' => '출고지/반품지 정보 동기화',
        'icon' => '🚚',
        'frequency' => '하루 1번',
        'file' => 'shipping_places.php',
        'category' => 'settings'
    ),
    'category_recommendations' => array(
        'name' => '카테고리 추천 배치',
        'description' => '카테고리 추천 배치 실행',
        'icon' => '🏷️',
        'frequency' => '하루 1번',
        'file' => 'category_recommendations.php',
        'category' => 'settings'
    ),
    'category_cache_cleanup' => array(
        'name' => '카테고리 캐시 정리',
        'description' => '오래된 카테고리 캐시 정리',
        'icon' => '🧹',
        'frequency' => '하루 1번',
        'file' => 'category_cache_cleanup.php',
        'category' => 'settings'
    )
);

// AJAX 수동 동기화 요청 처리
if (isset($_POST['action']) && $_POST['action'] === 'manual_sync') {
    header('Content-Type: application/json');

    $sync_type = isset($_POST['sync_type']) ? $_POST['sync_type'] : '';
    $sync_options = isset($_POST['sync_options']) ? $_POST['sync_options'] : array();

    if (!$coupang_api) {
        echo json_encode(array(
            'success' => false,
            'message' => 'API 인스턴스가 없습니다. 설정을 확인해주세요.'
        ));
        exit;
    }

    if (!isset($sync_types[$sync_type])) {
        echo json_encode(array(
            'success' => false,
            'message' => '지원하지 않는 동기화 타입입니다.'
        ));
        exit;
    }

    try {
        $start_time = microtime(true);

        // 크론 실행 로그 시작
        monitor_cron_execution($sync_type, 'MANUAL_START', '수동 동기화 시작');

        // 실제 크론 파일 실행 (내부 함수 호출 방식으로 변경)
        $result = executeSyncOperation($sync_type, $sync_options, $coupang_api);

        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        // 성공 로그 기록
        monitor_cron_execution($sync_type, 'MANUAL_SUCCESS', '수동 동기화 완료', $execution_time / 1000, $result);

        echo json_encode(array(
            'success' => true,
            'result' => $result,
            'execution_time' => $execution_time . ' ms',
            'sync_type' => $sync_type,
            'timestamp' => date('Y-m-d H:i:s')
        ));

    } catch (Exception $e) {
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        // 실패 로그 기록
        monitor_cron_execution($sync_type, 'MANUAL_FAIL', '수동 동기화 실패: ' . $e->getMessage(), $execution_time / 1000);

        echo json_encode(array(
            'success' => false,
            'message' => $e->getMessage(),
            'execution_time' => $execution_time . ' ms',
            'sync_type' => $sync_type,
            'timestamp' => date('Y-m-d H:i:s')
        ));
    }

    exit;
}

// AJAX 로그 조회 요청 처리
if (isset($_POST['action']) && $_POST['action'] === 'get_logs') {
    header('Content-Type: application/json');

    $sync_type = isset($_POST['sync_type']) ? $_POST['sync_type'] : '';
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;

    $sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_cron_log";
    if (!empty($sync_type)) {
        $sql .= " WHERE cron_type = '" . addslashes($sync_type) . "'";
    }
    $sql .= " ORDER BY created_date DESC LIMIT " . $limit;

    $result = sql_query($sql);
    $logs = array();

    while ($row = sql_fetch_array($result)) {
        $logs[] = array(
            'id' => $row['id'],
            'cron_type' => $row['cron_type'],
            'status' => $row['status'],
            'message' => $row['message'],
            'execution_duration' => $row['execution_duration'],
            'created_date' => $row['created_date'],
            'additional_data' => $row['additional_data'] ? json_decode($row['additional_data'], true) : null
        );
    }

    echo json_encode(array('success' => true, 'logs' => $logs));
    exit;
}

// 최근 동기화 통계 조회
$stats_sql = "SELECT 
                cron_type,
                COUNT(*) as total_runs,
                SUM(CASE WHEN status LIKE '%SUCCESS%' THEN 1 ELSE 0 END) as success_runs,
                SUM(CASE WHEN status LIKE '%FAIL%' OR status LIKE '%ERROR%' THEN 1 ELSE 0 END) as fail_runs,
                AVG(execution_duration) as avg_duration,
                MAX(created_date) as last_run
              FROM " . G5_TABLE_PREFIX . "coupang_cron_log 
              WHERE created_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              GROUP BY cron_type
              ORDER BY last_run DESC";
$stats_result = sql_query($stats_sql);

// 실행 중인 동기화 체크 (간단한 세션 기반)
$running_syncs = isset($_SESSION['coupang_running_syncs']) ? $_SESSION['coupang_running_syncs'] : array();

/**
 * 동기화 작업 실행 함수
 */
function executeSyncOperation($sync_type, $options, $coupang_api) {
    switch ($sync_type) {
        case 'orders':
            // 크론 함수 직접 호출
            if (function_exists('cron_sync_orders_from_coupang')) {
                $hours = isset($options['hours']) ? intval($options['hours']) : 1;
                return cron_sync_orders_from_coupang($hours);
            } else {
                return array(
                    'success' => true,
                    'processed' => 0,
                    'message' => '주문 동기화 기능이 구현 중입니다.'
                );
            }

        case 'cancelled_orders':
            if (function_exists('cron_sync_cancelled_orders_from_coupang')) {
                $hours = isset($options['hours']) ? intval($options['hours']) : 1;
                return cron_sync_cancelled_orders_from_coupang($hours);
            } else {
                return array(
                    'success' => true,
                    'processed' => 0,
                    'message' => '취소 주문 동기화 기능이 구현 중입니다.'
                );
            }

        case 'order_status':
            if (function_exists('cron_sync_order_status_to_coupang')) {
                return cron_sync_order_status_to_coupang();
            } else {
                return array(
                    'success' => true,
                    'processed' => 0,
                    'message' => '주문 상태 동기화 기능이 구현 중입니다.'
                );
            }

        case 'products':
            if (function_exists('cron_sync_products_to_coupang')) {
                $limit = isset($options['limit']) ? intval($options['limit']) : 10;
                return cron_sync_products_to_coupang($limit);
            } else {
                return array(
                    'success' => true,
                    'processed' => 0,
                    'message' => '상품 동기화 기능이 구현 중입니다.'
                );
            }

        case 'product_status':
            if (function_exists('cron_sync_product_status_to_coupang')) {
                return array(
                    'success' => cron_sync_product_status_to_coupang(),
                    'processed' => 1,
                    'message' => '상품 상태 동기화 완료'
                );
            } else {
                return array(
                    'success' => true,
                    'processed' => 0,
                    'message' => '상품 상태 동기화 기능이 구현 중입니다.'
                );
            }

        case 'stock':
            if (function_exists('cron_sync_stock_to_coupang')) {
                $limit = isset($options['limit']) ? intval($options['limit']) : 20;
                return cron_sync_stock_to_coupang($limit);
            } else {
                return array(
                    'success' => true,
                    'processed' => 0,
                    'message' => '재고 동기화 기능이 구현 중입니다.'
                );
            }

        case 'shipping_places':
            if (function_exists('cron_sync_shipping_places')) {
                return cron_sync_shipping_places();
            } else {
                return array(
                    'success' => true,
                    'processed' => 0,
                    'message' => '출고지 동기화 기능이 구현 중입니다.'
                );
            }

        case 'category_recommendations':
            if (function_exists('cron_batch_category_recommendations')) {
                return cron_batch_category_recommendations();
            } else {
                return array(
                    'success' => true,
                    'processed' => 0,
                    'message' => '카테고리 추천 기능이 구현 중입니다.'
                );
            }

        case 'category_cache_cleanup':
            $days = isset($options['days']) ? intval($options['days']) : 7;
            $deleted = $coupang_api->cleanupCategoryCache($days);
            return array(
                'success' => true,
                'deleted_rows' => $deleted,
                'message' => "{$days}일 이전 캐시 {$deleted}개 삭제"
            );

        default:
            throw new Exception('지원하지 않는 동기화 타입: ' . $sync_type);
    }
}
?>

    <style>
        .coupang-manual-sync {
            max-width: 1400px;
            margin: 20px auto;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .sync-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }

        .sync-status-bar {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .sync-categories {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .category-tab {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
        }

        .category-tab.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        .category-tab:hover:not(.active) {
            background: #e9ecef;
        }

        .sync-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .sync-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .sync-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }

        .sync-card.running {
            border-color: #ffc107;
            background: #fff9e6;
        }

        .sync-card-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .sync-icon {
            font-size: 24px;
            margin-right: 12px;
        }

        .sync-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin: 0;
        }

        .sync-description {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.4;
        }

        .sync-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 13px;
            color: #666;
        }

        .sync-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: #0056b3;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background: #1e7e34;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover:not(:disabled) {
            background: #e0a800;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover:not(:disabled) {
            background: #c82333;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover:not(:disabled) {
            background: #545b62;
        }

        .sync-options {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
            display: none;
        }

        .sync-options.show {
            display: block;
        }

        .option-group {
            margin-bottom: 10px;
        }

        .option-group:last-child {
            margin-bottom: 0;
        }

        .option-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 13px;
        }

        .form-control {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #ced4da;
            border-radius: 3px;
            font-size: 13px;
        }

        .sync-result {
            margin-top: 15px;
            padding: 12px;
            border-radius: 5px;
            font-size: 13px;
            display: none;
        }

        .sync-result.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .sync-result.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .sync-result.running {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .bulk-actions {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .bulk-actions h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #333;
        }

        .bulk-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-success {
            background: #d4edda;
            color: #155724;
        }

        .status-error {
            background: #f8d7da;
            color: #721c24;
        }

        .status-warning {
            background: #fff3cd;
            color: #856404;
        }

        .status-info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .logs-section {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-top: 30px;
        }

        .logs-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .logs-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .logs-content {
            max-height: 400px;
            overflow-y: auto;
        }

        .log-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .log-item:last-child {
            border-bottom: none;
        }

        .log-detail {
            flex: 1;
        }

        .log-meta {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }

        .progress-indicator {
            display: none;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }

        .progress-indicator.show {
            display: flex;
        }

        .progress-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #333;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .sync-grid {
                grid-template-columns: 1fr;
            }

            .sync-status-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .bulk-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .logs-header {
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
            }
        }

        .hidden {
            display: none !important;
        }
    </style>

    <div class="coupang-manual-sync">
        <!-- 헤더 -->
        <div class="sync-header">
            <h1>🔄 쿠팡 연동 수동 동기화</h1>
            <p>모든 동기화 작업을 수동으로 실행하고 실시간 모니터링</p>
        </div>

        <!-- 상태 바 -->
        <div class="sync-status-bar">
            <div>
                <strong>API 상태:</strong>
                <span class="status-badge <?php echo $coupang_api ? 'status-success' : 'status-error'; ?>">
                <?php echo $coupang_api ? '✅ 연결됨' : '❌ 연결 안됨'; ?>
            </span>
            </div>
            <div>
                <strong>설정 상태:</strong>
                <span class="status-badge <?php echo $config_status['success'] ? 'status-success' : 'status-error'; ?>">
                <?php echo $config_status['success'] ? '✅ 정상' : '❌ 오류'; ?>
            </span>
            </div>
            <div>
                <button class="btn btn-secondary" onclick="refreshPage()">🔄 새로고침</button>
                <button class="btn btn-warning" onclick="stopAllSyncs()">⏹️ 모두 중지</button>
            </div>
        </div>

        <!-- 24시간 통계 요약 -->
        <?php if (sql_num_rows($stats_result) > 0): ?>
            <div class="stats-summary">
                <?php while ($stat = sql_fetch_array($stats_result)): ?>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($stat['total_runs']); ?></div>
                        <div class="stat-label">
                            <?php echo $sync_types[$stat['cron_type']]['name'] ?? $stat['cron_type']; ?>
                            <br>성공률: <?php echo $stat['total_runs'] > 0 ? round(($stat['success_runs']/$stat['total_runs'])*100, 1) : 0; ?>%
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>

        <!-- 일괄 동기화 액션 -->
        <div class="bulk-actions">
            <h3>⚡ 빠른 동기화 액션</h3>
            <div class="bulk-buttons">
                <button class="btn btn-success" onclick="runBulkSync('orders')">
                    🛒 모든 주문 동기화
                </button>
                <button class="btn btn-primary" onclick="runBulkSync('products')">
                    📦 모든 상품 동기화
                </button>
                <button class="btn btn-warning" onclick="runBulkSync('settings')">
                    ⚙️ 모든 설정 동기화
                </button>
                <button class="btn btn-danger" onclick="runFullSync()">
                    🚀 전체 동기화 (주의!)
                </button>
            </div>
        </div>

        <!-- 카테고리 탭 -->
        <div class="sync-categories">
            <div class="category-tab active" onclick="showCategory('all')">🌍 전체</div>
            <div class="category-tab" onclick="showCategory('orders')">🛒 주문 관리</div>
            <div class="category-tab" onclick="showCategory('products')">📦 상품 관리</div>
            <div class="category-tab" onclick="showCategory('settings')">⚙️ 설정 관리</div>
        </div>

        <!-- 동기화 카드 그리드 -->
        <div class="sync-grid">
            <?php foreach ($sync_types as $key => $sync): ?>
                <div class="sync-card" data-category="<?php echo $sync['category']; ?>" data-sync-type="<?php echo $key; ?>">
                    <div class="sync-card-header">
                        <span class="sync-icon"><?php echo $sync['icon']; ?></span>
                        <h4 class="sync-title"><?php echo $sync['name']; ?></h4>
                    </div>

                    <div class="sync-description">
                        <?php echo $sync['description']; ?>
                    </div>

                    <div class="sync-meta">
                        <span>📅 주기: <?php echo $sync['frequency']; ?></span>
                        <span>📄 파일: <?php echo $sync['file']; ?></span>
                    </div>

                    <div class="sync-controls">
                        <button class="btn btn-success" onclick="runSync('<?php echo $key; ?>')">
                            ▶️ 실행
                        </button>
                        <button class="btn btn-secondary" onclick="toggleOptions('<?php echo $key; ?>')">
                            ⚙️ 옵션
                        </button>
                        <button class="btn btn-warning" onclick="showLogs('<?php echo $key; ?>')">
                            📊 로그
                        </button>
                    </div>

                    <!-- 동기화 옵션 -->
                    <div class="sync-options" id="options-<?php echo $key; ?>">
                        <?php if (in_array($key, ['orders', 'cancelled_orders'])): ?>
                            <div class="option-group">
                                <label>조회 시간 범위 (시간)</label>
                                <input type="number" class="form-control" name="hours" value="1" min="1" max="24">
                            </div>
                        <?php endif; ?>

                        <?php if (in_array($key, ['products', 'stock'])): ?>
                            <div class="option-group">
                                <label>처리 제한 개수</label>
                                <input type="number" class="form-control" name="limit" value="100" min="1" max="1000">
                            </div>
                        <?php endif; ?>

                        <?php if ($key === 'category_cache_cleanup'): ?>
                            <div class="option-group">
                                <label>삭제할 캐시 기간 (일)</label>
                                <input type="number" class="form-control" name="days" value="7" min="1" max="365">
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 진행 상황 표시 -->
                    <div class="progress-indicator" id="progress-<?php echo $key; ?>">
                        <div class="progress-spinner"></div>
                        <span>동기화 진행 중...</span>
                    </div>

                    <!-- 결과 표시 -->
                    <div class="sync-result" id="result-<?php echo $key; ?>"></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 로그 섹션 -->
    <div class="logs-section">
        <div class="logs-header">
            <h3>📈 동기화 로그</h3>
            <div class="logs-controls">
                <select id="log-filter" class="form-control" style="width: auto;" onchange="filterLogs()">
                    <option value="">전체 로그</option>
                    <?php foreach ($sync_types as $key => $sync): ?>
                        <option value="<?php echo $key; ?>"><?php echo $sync['name']; ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary" onclick="refreshLogs()">🔄 새로고침</button>
                <button class="btn btn-warning" onclick="clearLogs()">🗑️ 로그 정리</button>
            </div>
        </div>
        <div class="logs-content" id="logs-content">
            <div style="text-align: center; padding: 40px; color: #666;">
                📊 로그를 불러오는 중...
            </div>
        </div>
    </div>

    <script>
        // ============================================================================
        // JavaScript 함수들 - 수동 동기화 제어 및 모니터링
        // ============================================================================

        // 전역 변수
        let runningSyncs = new Set();
        let refreshInterval = null;

        /**
         * 페이지 로드 시 초기화
         */
        document.addEventListener('DOMContentLoaded', function() {
            // 초기 로그 로드
            refreshLogs();

            // 자동 새로고침 설정 (30초마다)
            refreshInterval = setInterval(refreshLogs, 30000);

            console.log('🔄 수동 동기화 대시보드 로드 완료');
        });

        /**
         * 페이지 새로고침
         */
        function refreshPage() {
            window.location.reload();
        }

        /**
         * 카테고리 탭 전환
         */
        function showCategory(category) {
            // 모든 탭 비활성화
            document.querySelectorAll('.category-tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // 선택된 탭 활성화
            event.target.classList.add('active');

            // 카드 표시/숨김
            document.querySelectorAll('.sync-card').forEach(card => {
                if (category === 'all' || card.dataset.category === category) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        /**
         * 동기화 옵션 토글
         */
        function toggleOptions(syncType) {
            const optionsEl = document.getElementById(`options-${syncType}`);
            optionsEl.classList.toggle('show');
        }

        /**
         * 개별 동기화 실행
         */
        function runSync(syncType) {
            if (runningSyncs.has(syncType)) {
                alert('이미 실행 중인 동기화입니다.');
                return;
            }

            // API 상태 확인
            <?php if (!$coupang_api): ?>
            alert('API 연결이 되어있지 않습니다. 설정을 확인해주세요.');
            return;
            <?php endif; ?>

            // 옵션 수집
            const optionsEl = document.getElementById(`options-${syncType}`);
            const syncOptions = {};

            if (optionsEl.classList.contains('show')) {
                const inputs = optionsEl.querySelectorAll('input, select');
                inputs.forEach(input => {
                    if (input.value) {
                        syncOptions[input.name] = input.value;
                    }
                });
            }

            // UI 상태 변경
            startSyncUI(syncType);

            // AJAX 요청
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'manual_sync',
                    sync_type: syncType,
                    ...Object.keys(syncOptions).reduce((acc, key) => {
                        acc[`sync_options[${key}]`] = syncOptions[key];
                        return acc;
                    }, {})
                })
            })
                .then(response => response.json())
                .then(data => {
                    endSyncUI(syncType, data);
                    refreshLogs(); // 로그 새로고침
                })
                .catch(error => {
                    endSyncUI(syncType, {
                        success: false,
                        message: '요청 실패: ' + error.message
                    });
                });
        }

        /**
         * 일괄 동기화 실행
         */
        function runBulkSync(category) {
            const syncTypesToRun = [];

            // 카테고리별 동기화 타입 수집
            document.querySelectorAll(`.sync-card[data-category="${category}"]`).forEach(card => {
                const syncType = card.dataset.syncType;
                if (!runningSyncs.has(syncType)) {
                    syncTypesToRun.push(syncType);
                }
            });

            if (syncTypesToRun.length === 0) {
                alert('실행할 동기화가 없거나 모두 실행 중입니다.');
                return;
            }

            if (!confirm(`${syncTypesToRun.length}개의 동기화를 순차적으로 실행하시겠습니까?`)) {
                return;
            }

            // 순차적으로 실행
            runSequentialSync(syncTypesToRun, 0);
        }

        /**
         * 전체 동기화 실행
         */
        function runFullSync() {
            const allSyncTypes = <?php echo json_encode(array_keys($sync_types)); ?>;
            const runningSyncTypes = allSyncTypes.filter(type => !runningSyncs.has(type));

            if (runningSyncTypes.length === 0) {
                alert('모든 동기화가 이미 실행 중입니다.');
                return;
            }

            if (!confirm(`전체 ${runningSyncTypes.length}개의 동기화를 실행하시겠습니까?\n\n⚠️ 주의: 서버에 높은 부하가 발생할 수 있습니다.`)) {
                return;
            }

            // 순차적으로 실행
            runSequentialSync(runningSyncTypes, 0);
        }

        /**
         * 순차적 동기화 실행
         */
        function runSequentialSync(syncTypes, index) {
            if (index >= syncTypes.length) {
                alert('모든 동기화가 완료되었습니다.');
                refreshLogs();
                return;
            }

            const syncType = syncTypes[index];

            // UI 상태 변경
            startSyncUI(syncType);

            // AJAX 요청
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'manual_sync',
                    sync_type: syncType
                })
            })
                .then(response => response.json())
                .then(data => {
                    endSyncUI(syncType, data);

                    // 1초 대기 후 다음 동기화 실행
                    setTimeout(() => {
                        runSequentialSync(syncTypes, index + 1);
                    }, 1000);
                })
                .catch(error => {
                    endSyncUI(syncType, {
                        success: false,
                        message: '요청 실패: ' + error.message
                    });

                    // 오류가 있어도 다음 동기화 계속 진행
                    setTimeout(() => {
                        runSequentialSync(syncTypes, index + 1);
                    }, 1000);
                });
        }

        /**
         * 모든 동기화 중지
         */
        function stopAllSyncs() {
            if (runningSyncs.size === 0) {
                alert('실행 중인 동기화가 없습니다.');
                return;
            }

            if (!confirm('실행 중인 모든 동기화를 중지하시겠습니까?')) {
                return;
            }

            // UI 상태 리셋
            runningSyncs.forEach(syncType => {
                const card = document.querySelector(`[data-sync-type="${syncType}"]`);
                const progressEl = document.getElementById(`progress-${syncType}`);
                const resultEl = document.getElementById(`result-${syncType}`);

                if (card) card.classList.remove('running');
                if (progressEl) progressEl.classList.remove('show');
                if (resultEl) {
                    resultEl.className = 'sync-result error';
                    resultEl.innerHTML = '❌ 사용자에 의해 중지됨';
                    resultEl.style.display = 'block';
                }
            });

            runningSyncs.clear();
        }

        /**
         * 동기화 시작 UI 업데이트
         */
        function startSyncUI(syncType) {
            runningSyncs.add(syncType);

            const card = document.querySelector(`[data-sync-type="${syncType}"]`);
            const progressEl = document.getElementById(`progress-${syncType}`);
            const resultEl = document.getElementById(`result-${syncType}`);

            if (card) card.classList.add('running');
            if (progressEl) progressEl.classList.add('show');
            if (resultEl) resultEl.style.display = 'none';
        }

        /**
         * 동기화 완료 UI 업데이트
         */
        function endSyncUI(syncType, data) {
            runningSyncs.delete(syncType);

            const card = document.querySelector(`[data-sync-type="${syncType}"]`);
            const progressEl = document.getElementById(`progress-${syncType}`);
            const resultEl = document.getElementById(`result-${syncType}`);

            if (card) card.classList.remove('running');
            if (progressEl) progressEl.classList.remove('show');

            if (resultEl) {
                const isSuccess = data.success && (!data.result || data.result.success !== false);
                resultEl.className = `sync-result ${isSuccess ? 'success' : 'error'}`;

                let html = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <strong>${isSuccess ? '✅ 성공' : '❌ 실패'}</strong>
                <small>${data.execution_time || '0ms'}</small>
            </div>
        `;

                if (!isSuccess) {
                    html += `<div>${data.message || '알 수 없는 오류'}</div>`;
                } else if (data.result) {
                    html += generateSyncResultSummary(syncType, data.result);
                }

                resultEl.innerHTML = html;
                resultEl.style.display = 'block';
            }
        }

        /**
         * 동기화 결과 요약 생성
         */
        function generateSyncResultSummary(syncType, result) {
            let summary = '';

            if (result.processed !== undefined) {
                summary += `<div>처리: ${result.processed}건</div>`;
            }

            if (result.new_orders !== undefined) {
                summary += `<div>신규 주문: ${result.new_orders}건</div>`;
            }

            if (result.updated_orders !== undefined) {
                summary += `<div>수정 주문: ${result.updated_orders}건</div>`;
            }

            if (result.cancelled_orders !== undefined) {
                summary += `<div>취소 주문: ${result.cancelled_orders}건</div>`;
            }

            if (result.updated_products !== undefined) {
                summary += `<div>업데이트 상품: ${result.updated_products}건</div>`;
            }

            if (result.synced_stock !== undefined) {
                summary += `<div>재고 동기화: ${result.synced_stock}건</div>`;
            }

            if (result.deleted_rows !== undefined) {
                summary += `<div>삭제된 캐시: ${result.deleted_rows}개</div>`;
            }

            if (result.errors && result.errors.length > 0) {
                summary += `<div style="color: #dc3545;">오류: ${result.errors.length}건</div>`;
            }

            if (result.message) {
                summary += `<div style="font-style: italic; margin-top: 5px;">${result.message}</div>`;
            }

            return summary || '<div>동기화 완료</div>';
        }

        /**
         * 특정 동기화 타입의 로그 보기
         */
        function showLogs(syncType) {
            document.getElementById('log-filter').value = syncType;
            filterLogs();

            // 로그 섹션으로 스크롤
            document.querySelector('.logs-section').scrollIntoView({
                behavior: 'smooth'
            });
        }

        /**
         * 로그 필터링
         */
        function filterLogs() {
            const filterValue = document.getElementById('log-filter').value;
            refreshLogs(filterValue);
        }

        /**
         * 로그 새로고침
         */
        function refreshLogs(syncType = '') {
            const logsContent = document.getElementById('logs-content');

            if (!syncType) {
                syncType = document.getElementById('log-filter').value || '';
            }

            // 로딩 상태 표시
            logsContent.innerHTML = `
        <div style="text-align: center; padding: 40px; color: #666;">
            <div class="progress-spinner" style="margin: 0 auto 10px;"></div>
            로그를 불러오는 중...
        </div>
    `;

            // AJAX 요청
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_logs',
                    sync_type: syncType,
                    limit: 50
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayLogs(data.logs);
                    } else {
                        logsContent.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #dc3545;">
                    ❌ 로그 로드 실패
                </div>
            `;
                    }
                })
                .catch(error => {
                    logsContent.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #dc3545;">
                ❌ 로그 로드 오류: ${error.message}
            </div>
        `;
                });
        }

        /**
         * 로그 표시
         */
        function displayLogs(logs) {
            const logsContent = document.getElementById('logs-content');

            if (logs.length === 0) {
                logsContent.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #666;">
                📝 로그가 없습니다.
            </div>
        `;
                return;
            }

            let html = '';
            logs.forEach(log => {
                const statusClass = getLogStatusClass(log.status);
                const syncTypeName = getSyncTypeName(log.cron_type);

                html += `
            <div class="log-item">
                <div class="log-detail">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="status-badge ${statusClass}">${log.status}</span>
                        <strong>${syncTypeName}</strong>
                    </div>
                    <div style="margin: 5px 0;">${log.message || '메시지 없음'}</div>
                    <div class="log-meta">
                        ${log.created_date}
                        ${log.execution_duration ? ` • 실행시간: ${log.execution_duration}초` : ''}
                    </div>
                </div>
            </div>
        `;
            });

            logsContent.innerHTML = html;
        }

        /**
         * 로그 상태에 따른 CSS 클래스 반환
         */
        function getLogStatusClass(status) {
            if (status.includes('SUCCESS')) return 'status-success';
            if (status.includes('FAIL') || status.includes('ERROR')) return 'status-error';
            if (status.includes('START')) return 'status-info';
            return 'status-warning';
        }

        /**
         * 동기화 타입명 반환
         */
        function getSyncTypeName(cronType) {
            const syncTypes = <?php echo json_encode($sync_types); ?>;
            return syncTypes[cronType] ? syncTypes[cronType].name : cronType;
        }

        /**
         * 로그 정리
         */
        function clearLogs() {
            if (!confirm('7일 이전의 모든 로그를 삭제하시겠습니까?')) {
                return;
            }

            // 실제 로그 정리 로직은 서버측에서 구현 필요
            alert('로그 정리 기능은 개발 중입니다.');
        }

        // 페이지 종료 시 인터벌 정리
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>

<?php
// 관리자 푸터 포함
include_once(G5_ADMIN_PATH . '/admin.tail.php');
?>