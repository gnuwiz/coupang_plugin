<?php
/**
 * ============================================================================
 * 쿠팡 연동 플러그인 - API 테스트 페이지
 * ============================================================================
 * 파일: /plugin/gnuwiz_coupang/pages/api_test.php
 * 용도: 쿠팡 API 모든 기능 종합 테스트 (UI만)
 * 작성: 그누위즈 (gnuwiz@example.com)
 * 버전: 2.2.0 (Phase 2-2)
 *
 * 주요 기능:
 * - 실제 CoupangAPI 클래스 메서드 기반 테스트 UI
 * - AJAX 처리는 별도 파일로 분리
 * - 실시간 테스트 및 결과 분석
 */

if (!defined('_GNUBOARD_')) exit;

// 관리자 헤더 포함
include_once(G5_ADMIN_PATH . '/admin.head.php');

// API 인스턴스 확인
global $coupang_api;
$config_status = validate_coupang_config();

// 최근 테스트 로그 조회
$recent_tests_sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_api_test_log 
                     ORDER BY created_date DESC LIMIT 10";
$recent_tests_result = @sql_query($recent_tests_sql);
?>

    <style>
        .coupang-api-test {
            max-width: 1200px;
            margin: 20px auto;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .test-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }

        .test-navigation {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .nav-tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
        }

        .nav-tab {
            padding: 12px 20px;
            background: #fff;
            border: 1px solid #ddd;
            border-bottom: none;
            cursor: pointer;
            transition: all 0.3s;
            margin-right: 5px;
            border-radius: 5px 5px 0 0;
        }

        .nav-tab.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        .nav-tab:hover:not(.active) {
            background: #f8f9fa;
        }

        .test-section {
            display: none;
            background: white;
            padding: 25px;
            border: 1px solid #e9ecef;
            border-radius: 0 0 10px 10px;
        }

        .test-section.active {
            display: block;
        }

        .test-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .btn {
            padding: 10px 20px;
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

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #1e7e34;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .test-result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .test-result.success {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }

        .test-result.error {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }

        .result-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
            max-height: 400px;
            overflow-y: auto;
        }

        .result-json {
            background: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }

        .loading::after {
            content: '⏳';
            margin-left: 10px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
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

        .recent-tests {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-top: 30px;
        }

        .recent-tests-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
        }

        .test-log-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .test-log-item:last-child {
            border-bottom: none;
        }

        @media (max-width: 768px) {
            .nav-tabs {
                flex-wrap: wrap;
            }

            .nav-tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
                margin-bottom: 5px;
            }

            .quick-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>

    <div class="coupang-api-test">
        <!-- 헤더 -->
        <div class="test-header">
            <h1>🔧 쿠팡 API 종합 테스트</h1>
            <p>실제 CoupangAPI 클래스 메서드 기반 완전 테스트</p>
        </div>

        <!-- 상태 정보 -->
        <div class="test-navigation">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
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
                    <button class="btn btn-warning" onclick="refreshPage()">🔄 새로고침</button>
                </div>
            </div>
        </div>

        <!-- 탭 네비게이션 -->
        <div class="nav-tabs">
            <div class="nav-tab active" onclick="showTab('config')">⚙️ 설정 검증</div>
            <div class="nav-tab" onclick="showTab('category')">📂 카테고리 API</div>
            <div class="nav-tab" onclick="showTab('shipping')">🚚 출고지 API</div>
            <div class="nav-tab" onclick="showTab('product')">📦 상품 API</div>
            <div class="nav-tab" onclick="showTab('order')">🛒 주문 API</div>
            <div class="nav-tab" onclick="showTab('utility')">🛠️ 유틸리티</div>
        </div>

        <!-- 설정 검증 섹션 -->
        <div id="config-section" class="test-section active">
            <h3>⚙️ 설정 및 검증 테스트</h3>

            <div class="quick-actions">
                <button class="btn btn-primary" onclick="runTest('validate_all_config')">전체 설정 검증</button>
                <button class="btn btn-primary" onclick="runTest('validate_api_config')">API 설정 검증</button>
                <button class="btn btn-primary" onclick="runTest('validate_shipping_config')">출고지 설정 검증</button>
                <button class="btn btn-primary" onclick="runTest('validate_product_config')">상품 설정 검증</button>
            </div>

            <div class="test-form">
                <h4>🔍 개별 검증 테스트</h4>
                <p>각 설정 영역별로 개별 검증을 수행합니다. 전체 설정 검증은 모든 영역을 한번에 체크합니다.</p>
            </div>

            <div id="config-result"></div>
        </div>

        <!-- 카테고리 API 섹션 -->
        <div id="category-section" class="test-section">
            <h3>📂 카테고리 API 테스트</h3>

            <div class="quick-actions">
                <button class="btn btn-success" onclick="runTest('test_connection')">연결 테스트</button>
                <button class="btn btn-primary" onclick="runTest('category_list')">카테고리 목록</button>
            </div>

            <div class="test-form">
                <h4>🎯 카테고리 추천 테스트</h4>
                <div class="form-group">
                    <label for="product_name">상품명 (필수)</label>
                    <input type="text" id="product_name" class="form-control"
                           placeholder="예: 아이폰 15 Pro 케이스" value="아이폰 15 Pro 케이스">
                </div>
                <div class="form-group">
                    <label for="product_description">상품 설명 (선택)</label>
                    <textarea id="product_description" class="form-control" rows="3"
                              placeholder="상품에 대한 자세한 설명을 입력하세요"></textarea>
                </div>
                <div class="form-group">
                    <label for="brand">브랜드명 (선택)</label>
                    <input type="text" id="brand" class="form-control" placeholder="예: Apple, Samsung">
                </div>
                <button class="btn btn-success" onclick="runCategoryRecommendation()">
                    🎯 카테고리 추천 실행
                </button>
            </div>

            <div class="test-form">
                <h4>📋 카테고리 메타정보 조회</h4>
                <div class="form-group">
                    <label for="category_id">카테고리 ID</label>
                    <input type="text" id="category_id" class="form-control"
                           placeholder="예: 194176">
                </div>
                <button class="btn btn-primary" onclick="runCategoryMetadata()">
                    📋 메타정보 조회
                </button>
            </div>

            <div class="test-form">
                <h4>🌳 카테고리 트리 조회</h4>
                <div class="form-group">
                    <label for="parent_category_id">부모 카테고리 ID (선택, 비우면 최상위)</label>
                    <input type="text" id="parent_category_id" class="form-control"
                           placeholder="예: 1001 (비우면 최상위 카테고리)">
                </div>
                <button class="btn btn-primary" onclick="runCategoryList()">
                    🌳 카테고리 목록 조회
                </button>
            </div>

            <div id="category-result"></div>
        </div>

        <!-- 출고지 API 섹션 -->
        <div id="shipping-section" class="test-section">
            <h3>🚚 출고지/반품지 API 테스트</h3>

            <div class="quick-actions">
                <button class="btn btn-primary" onclick="runTest('shipping_places')">출고지 목록</button>
                <button class="btn btn-primary" onclick="runTest('return_places')">반품지 목록</button>
            </div>

            <div class="test-form">
                <h4>📍 출고지 및 반품지 정보</h4>
                <p>등록된 출고지와 반품지 정보를 조회합니다. 상품 등록 시 필요한 정보입니다.</p>
            </div>

            <div id="shipping-result"></div>
        </div>

        <!-- 상품 API 섹션 -->
        <div id="product-section" class="test-section">
            <h3>📦 상품 API 테스트</h3>

            <div class="quick-actions">
                <button class="btn btn-primary" onclick="runTest('product_list')">상품 목록</button>
            </div>

            <div class="test-form">
                <h4>📊 상품 상태 조회</h4>
                <div class="form-group">
                    <label for="seller_product_id">판매자 상품 ID</label>
                    <input type="text" id="seller_product_id" class="form-control"
                           placeholder="등록된 상품의 판매자 ID를 입력하세요">
                </div>
                <button class="btn btn-success" onclick="runProductStatus()">
                    📊 상품 상태 확인
                </button>
            </div>

            <div class="test-form">
                <h4>📋 상품 목록 조회</h4>
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="product_page">페이지 번호</label>
                        <input type="number" id="product_page" class="form-control"
                               value="1" min="1">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="product_size">페이지 크기</label>
                        <input type="number" id="product_size" class="form-control"
                               value="10" min="1" max="100">
                    </div>
                </div>
                <button class="btn btn-primary" onclick="runProductList()">
                    📋 상품 목록 조회
                </button>
            </div>

            <div id="product-result"></div>
        </div>

        <!-- 주문 API 섹션 -->
        <div id="order-section" class="test-section">
            <h3>🛒 주문 API 테스트</h3>

            <div class="test-form">
                <h4>📅 주문 목록 조회</h4>
                <div style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label for="start_date">시작 날짜</label>
                        <input type="date" id="start_date" class="form-control"
                               value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label for="end_date">종료 날짜</label>
                        <input type="date" id="end_date" class="form-control"
                               value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <button class="btn btn-success" onclick="runOrderList()">
                    📅 주문 목록 조회
                </button>
            </div>

            <div class="test-form">
                <h4>🔍 주문 상세 조회</h4>
                <div class="form-group">
                    <label for="order_id">주문 ID</label>
                    <input type="text" id="order_id" class="form-control"
                           placeholder="조회할 주문 ID를 입력하세요">
                </div>
                <button class="btn btn-primary" onclick="runOrderDetail()">
                    🔍 주문 상세 조회
                </button>
            </div>

            <div id="order-result"></div>
        </div>

        <!-- 유틸리티 섹션 -->
        <div id="utility-section" class="test-section">
            <h3>🛠️ 유틸리티 및 관리 기능</h3>

            <div class="test-form">
                <h4>🧹 캐시 정리</h4>
                <div class="form-group">
                    <label for="cache_days">정리할 기간 (일)</label>
                    <input type="number" id="cache_days" class="form-control"
                           value="7" min="1" max="365">
                    <small style="color: #6c757d;">지정한 일수 이전의 캐시 데이터를 삭제합니다.</small>
                </div>
                <button class="btn btn-warning" onclick="runCacheCleanup()">
                    🧹 캐시 정리 실행
                </button>
            </div>

            <div class="quick-actions">
                <button class="btn btn-success" onclick="runTest('test_connection')">🔗 연결 테스트</button>
            </div>

            <div id="utility-result"></div>
        </div>
    </div>

    <!-- 최근 테스트 로그 -->
<?php if ($recent_tests_result && sql_num_rows($recent_tests_result) > 0): ?>
    <div class="recent-tests">
        <div class="recent-tests-header">📈 최근 테스트 로그</div>
        <?php while ($log = sql_fetch_array($recent_tests_result)): ?>
            <div class="test-log-item">
                <div>
                    <strong><?php echo htmlspecialchars($log['test_type']); ?></strong>
                    <small style="color: #6c757d; margin-left: 10px;">
                        <?php echo $log['created_date']; ?>
                    </small>
                </div>
                <div>
            <span class="status-badge <?php echo $log['status'] === 'SUCCESS' ? 'status-success' : 'status-error'; ?>">
                <?php echo $log['status']; ?>
            </span>
                    <small style="margin-left: 10px;">
                        <?php echo $log['execution_time']; ?>ms
                    </small>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php endif; ?>

    <script>
        // ============================================================================
        // JavaScript 함수들 - AJAX 테스트 및 UI 제어 (AJAX 파일 분리 버전)
        // ============================================================================

        /**
         * 탭 전환 함수
         */
        function showTab(tabName) {
            // 모든 탭과 섹션 비활성화
            document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.test-section').forEach(section => section.classList.remove('active'));

            // 선택된 탭과 섹션 활성화
            event.target.classList.add('active');
            document.getElementById(tabName + '-section').classList.add('active');
        }

        /**
         * 페이지 새로고침
         */
        function refreshPage() {
            window.location.reload();
        }

        /**
         * 일반 테스트 실행 (매개변수 없는 테스트)
         */
        function runTest(testType) {
            const resultContainer = getCurrentResultContainer();
            showLoading(resultContainer);

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=api_test&test_type=${testType}`
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '요청 실패: ' + error.message);
                });
        }

        /**
         * 카테고리 추천 테스트 실행
         */
        function runCategoryRecommendation() {
            const productName = document.getElementById('product_name').value.trim();
            const description = document.getElementById('product_description').value.trim();
            const brand = document.getElementById('brand').value.trim();

            if (!productName) {
                alert('상품명을 입력해주세요.');
                return;
            }

            const resultContainer = document.getElementById('category-result');
            showLoading(resultContainer);

            const params = new URLSearchParams({
                action: 'api_test',
                test_type: 'category_recommendation',
                'test_params[product_name]': productName,
                'test_params[description]': description,
                'test_params[brand]': brand
            });

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '카테고리 추천 요청 실패: ' + error.message);
                });
        }

        /**
         * 카테고리 메타정보 조회
         */
        function runCategoryMetadata() {
            const categoryId = document.getElementById('category_id').value.trim();

            if (!categoryId) {
                alert('카테고리 ID를 입력해주세요.');
                return;
            }

            const resultContainer = document.getElementById('category-result');
            showLoading(resultContainer);

            const params = new URLSearchParams({
                action: 'api_test',
                test_type: 'category_metadata',
                'test_params[category_id]': categoryId
            });

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '카테고리 메타정보 요청 실패: ' + error.message);
                });
        }

        /**
         * 카테고리 목록 조회
         */
        function runCategoryList() {
            const parentId = document.getElementById('parent_category_id').value.trim();

            const resultContainer = document.getElementById('category-result');
            showLoading(resultContainer);

            const params = new URLSearchParams({
                action: 'api_test',
                test_type: 'category_list'
            });

            if (parentId) {
                params.append('test_params[parent_id]', parentId);
            }

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '카테고리 목록 요청 실패: ' + error.message);
                });
        }

        /**
         * 상품 상태 조회
         */
        function runProductStatus() {
            const sellerProductId = document.getElementById('seller_product_id').value.trim();

            if (!sellerProductId) {
                alert('판매자 상품 ID를 입력해주세요.');
                return;
            }

            const resultContainer = document.getElementById('product-result');
            showLoading(resultContainer);

            const params = new URLSearchParams({
                action: 'api_test',
                test_type: 'product_status',
                'test_params[seller_product_id]': sellerProductId
            });

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '상품 상태 요청 실패: ' + error.message);
                });
        }

        /**
         * 상품 목록 조회
         */
        function runProductList() {
            const page = document.getElementById('product_page').value;
            const size = document.getElementById('product_size').value;

            const resultContainer = document.getElementById('product-result');
            showLoading(resultContainer);

            const params = new URLSearchParams({
                action: 'api_test',
                test_type: 'product_list',
                'test_params[page]': page,
                'test_params[size]': size
            });

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '상품 목록 요청 실패: ' + error.message);
                });
        }

        /**
         * 주문 목록 조회
         */
        function runOrderList() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;

            if (!startDate || !endDate) {
                alert('시작 날짜와 종료 날짜를 모두 입력해주세요.');
                return;
            }

            const resultContainer = document.getElementById('order-result');
            showLoading(resultContainer);

            const params = new URLSearchParams({
                action: 'api_test',
                test_type: 'order_list',
                'test_params[start_date]': startDate,
                'test_params[end_date]': endDate
            });

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '주문 목록 요청 실패: ' + error.message);
                });
        }

        /**
         * 주문 상세 조회
         */
        function runOrderDetail() {
            const orderId = document.getElementById('order_id').value.trim();

            if (!orderId) {
                alert('주문 ID를 입력해주세요.');
                return;
            }

            const resultContainer = document.getElementById('order-result');
            showLoading(resultContainer);

            const params = new URLSearchParams({
                action: 'api_test',
                test_type: 'order_detail',
                'test_params[order_id]': orderId
            });

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '주문 상세 요청 실패: ' + error.message);
                });
        }

        /**
         * 캐시 정리 실행
         */
        function runCacheCleanup() {
            const days = document.getElementById('cache_days').value;

            if (!days || days < 1) {
                alert('올바른 일수를 입력해주세요. (최소 1일)');
                return;
            }

            if (!confirm(`${days}일 이전의 캐시 데이터를 모두 삭제하시겠습니까?`)) {
                return;
            }

            const resultContainer = document.getElementById('utility-result');
            showLoading(resultContainer);

            const params = new URLSearchParams({
                action: 'api_test',
                test_type: 'cleanup_cache',
                'test_params[days]': days
            });

            fetch('/plugin/gnuwiz_coupang/ajax/api_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            })
                .then(response => response.json())
                .then(data => {
                    displayResult(resultContainer, data);
                })
                .catch(error => {
                    displayError(resultContainer, '캐시 정리 요청 실패: ' + error.message);
                });
        }

        /**
         * 현재 활성 탭의 결과 컨테이너 반환
         */
        function getCurrentResultContainer() {
            const activeSection = document.querySelector('.test-section.active');
            if (!activeSection) return null;

            const sectionId = activeSection.id.replace('-section', '');
            return document.getElementById(sectionId + '-result');
        }

        /**
         * 로딩 상태 표시
         */
        function showLoading(container) {
            if (!container) return;

            container.innerHTML = `
        <div class="loading">
            🔄 테스트 실행 중입니다...
        </div>
    `;
        }

        /**
         * 결과 표시
         */
        function displayResult(container, data) {
            if (!container) return;

            const isSuccess = data.success && (!data.result || data.result.success !== false);
            const resultClass = isSuccess ? 'success' : 'error';

            let html = `
        <div class="test-result ${resultClass}">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <strong>${isSuccess ? '✅ 테스트 성공' : '❌ 테스트 실패'}</strong>
                <div style="margin-left: auto;">
                    <span class="status-badge status-${isSuccess ? 'success' : 'error'}">
                        ${data.execution_time || '0ms'}
                    </span>
                    <small style="margin-left: 10px; color: #6c757d;">
                        ${data.timestamp || new Date().toLocaleString()}
                    </small>
                </div>
            </div>
    `;

            if (!isSuccess) {
                html += `<div style="margin-bottom: 10px;"><strong>오류:</strong> ${data.message || '알 수 없는 오류'}</div>`;
            }

            // 결과 데이터가 있는 경우 표시
            if (data.result) {
                html += `
            <div class="result-details">
                <h5>📊 응답 데이터:</h5>
        `;

                // 성공한 경우 요약 정보 표시
                if (isSuccess && data.result.success !== false) {
                    html += generateResultSummary(data.test_type, data.result);
                }

                // 전체 JSON 데이터 표시
                html += `
                <details style="margin-top: 15px;">
                    <summary style="cursor: pointer; font-weight: 600;">🔍 상세 JSON 데이터 보기</summary>
                    <div class="result-json">${JSON.stringify(data.result, null, 2)}</div>
                </details>
            </div>
        `;
            }

            html += '</div>';
            container.innerHTML = html;
        }

        /**
         * 테스트 타입별 결과 요약 생성
         */
        function generateResultSummary(testType, result) {
            let summary = '';

            switch (testType) {
                case 'validate_all_config':
                case 'validate_api_config':
                case 'validate_shipping_config':
                case 'validate_product_config':
                    summary = generateConfigSummary(result);
                    break;

                case 'category_recommendation':
                    summary = generateCategoryRecommendationSummary(result);
                    break;

                case 'category_metadata':
                    summary = generateCategoryMetadataSummary(result);
                    break;

                case 'category_list':
                    summary = generateCategoryListSummary(result);
                    break;

                case 'shipping_places':
                case 'return_places':
                    summary = generateShippingPlacesSummary(result);
                    break;

                case 'product_status':
                    summary = generateProductStatusSummary(result);
                    break;

                case 'product_list':
                    summary = generateProductListSummary(result);
                    break;

                case 'order_list':
                    summary = generateOrderListSummary(result);
                    break;

                case 'cleanup_cache':
                    summary = generateCacheCleanupSummary(result);
                    break;

                default:
                    summary = '<p>결과 요약을 생성할 수 없습니다.</p>';
            }

            return summary;
        }

        /**
         * 설정 검증 결과 요약
         */
        function generateConfigSummary(result) {
            let html = '<div><h6>📋 검증 결과:</h6><ul>';

            if (result.success) {
                html += '<li style="color: #155724;">✅ 모든 설정이 정상입니다.</li>';
            } else {
                html += '<li style="color: #721c24;">❌ 설정에 문제가 있습니다.</li>';
            }

            if (result.errors && result.errors.length > 0) {
                html += '<li><strong>오류:</strong><ul>';
                result.errors.forEach(error => {
                    html += `<li style="color: #721c24;">${error}</li>`;
                });
                html += '</ul></li>';
            }

            if (result.warnings && result.warnings.length > 0) {
                html += '<li><strong>경고:</strong><ul>';
                result.warnings.forEach(warning => {
                    html += `<li style="color: #856404;">${warning}</li>`;
                });
                html += '</ul></li>';
            }

            if (result.details && Array.isArray(result.details)) {
                html += '<li><strong>상세 정보:</strong><ul>';
                result.details.forEach(detail => {
                    html += `<li style="color: #155724;">${detail}</li>`;
                });
                html += '</ul></li>';
            }

            html += '</ul></div>';
            return html;
        }

        /**
         * 카테고리 추천 결과 요약
         */
        function generateCategoryRecommendationSummary(result) {
            if (!result.success) {
                return `<p style="color: #721c24;">❌ ${result.error || '카테고리 추천 실패'}</p>`;
            }

            const data = result.data;
            return `
        <div>
            <h6>🎯 추천 결과:</h6>
            <ul>
                <li><strong>카테고리 ID:</strong> ${data.category_id}</li>
                <li><strong>카테고리명:</strong> ${data.category_name}</li>
                <li><strong>신뢰도:</strong> ${(result.confidence * 100).toFixed(1)}%</li>
                <li><strong>상품명:</strong> ${data.product_name}</li>
            </ul>
        </div>
    `;
        }

        /**
         * 카테고리 메타정보 결과 요약
         */
        function generateCategoryMetadataSummary(result) {
            if (!result.success) {
                return `<p style="color: #721c24;">❌ ${result.error || '메타정보 조회 실패'}</p>`;
            }

            const data = result.data;
            const attributeCount = data.attributes ? data.attributes.length : 0;
            const noticeCount = data.notices ? data.notices.length : 0;

            return `
        <div>
            <h6>📋 카테고리 정보:</h6>
            <ul>
                <li><strong>카테고리 ID:</strong> ${result.category_id}</li>
                <li><strong>속성 개수:</strong> ${attributeCount}개</li>
                <li><strong>공지사항:</strong> ${noticeCount}개</li>
            </ul>
        </div>
    `;
        }

        /**
         * 카테고리 목록 결과 요약
         */
        function generateCategoryListSummary(result) {
            if (!result.success) {
                return `<p style="color: #721c24;">❌ ${result.error || '카테고리 목록 조회 실패'}</p>`;
            }

            const count = result.data && Array.isArray(result.data) ? result.data.length : 0;

            return `
        <div>
            <h6>🌳 카테고리 목록:</h6>
            <ul>
                <li><strong>조회된 카테고리 수:</strong> ${count}개</li>
            </ul>
        </div>
    `;
        }

        /**
         * 출고지 결과 요약
         */
        function generateShippingPlacesSummary(result) {
            if (!result.success) {
                return `<p style="color: #721c24;">❌ ${result.error || '출고지 조회 실패'}</p>`;
            }

            const count = result.data && Array.isArray(result.data) ? result.data.length : 0;

            return `
        <div>
            <h6>📍 출고지 정보:</h6>
            <ul>
                <li><strong>등록된 출고지 수:</strong> ${count}개</li>
            </ul>
        </div>
    `;
        }

        /**
         * 상품 상태 결과 요약
         */
        function generateProductStatusSummary(result) {
            if (!result.success) {
                return `<p style="color: #721c24;">❌ ${result.error || '상품 상태 조회 실패'}</p>`;
            }

            return `
        <div>
            <h6>📦 상품 상태:</h6>
            <ul>
                <li><strong>상태 조회 성공</strong></li>
            </ul>
        </div>
    `;
        }

        /**
         * 상품 목록 결과 요약
         */
        function generateProductListSummary(result) {
            if (!result.success) {
                return `<p style="color: #721c24;">❌ ${result.error || '상품 목록 조회 실패'}</p>`;
            }

            const count = result.data && Array.isArray(result.data) ? result.data.length : 0;

            return `
        <div>
            <h6>📋 상품 목록:</h6>
            <ul>
                <li><strong>조회된 상품 수:</strong> ${count}개</li>
            </ul>
        </div>
    `;
        }

        /**
         * 주문 목록 결과 요약
         */
        function generateOrderListSummary(result) {
            if (!result.success) {
                return `<p style="color: #721c24;">❌ ${result.error || '주문 목록 조회 실패'}</p>`;
            }

            const count = result.data && Array.isArray(result.data) ? result.data.length : 0;

            return `
        <div>
            <h6>🛒 주문 목록:</h6>
            <ul>
                <li><strong>조회된 주문 수:</strong> ${count}개</li>
            </ul>
        </div>
    `;
        }

        /**
         * 캐시 정리 결과 요약
         */
        function generateCacheCleanupSummary(result) {
            return `
        <div>
            <h6>🧹 캐시 정리 완료:</h6>
            <ul>
                <li><strong>삭제된 항목:</strong> ${result.deleted_rows || 0}개</li>
                <li><strong>메시지:</strong> ${result.message || '캐시 정리 완료'}</li>
            </ul>
        </div>
    `;
        }

        /**
         * 오류 표시
         */
        function displayError(container, message) {
            if (!container) return;

            container.innerHTML = `
        <div class="test-result error">
            <strong>❌ 오류 발생</strong>
            <div style="margin-top: 10px;">${message}</div>
        </div>
    `;
        }

        // ============================================================================
        // 페이지 로드 시 초기화
        // ============================================================================
        document.addEventListener('DOMContentLoaded', function() {
            // 초기 상태 확인
            console.log('🔧 쿠팡 API 테스트 페이지 로드 완료');

            // 키보드 단축키 설정
            document.addEventListener('keydown', function(e) {
                // Ctrl+Enter: 현재 탭의 첫 번째 테스트 실행
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    const activeSection = document.querySelector('.test-section.active');
                    if (activeSection) {
                        const firstButton = activeSection.querySelector('.btn-primary, .btn-success');
                        if (firstButton) {
                            firstButton.click();
                        }
                    }
                }
            });
        });
    </script>

<?php
// 관리자 푸터 포함
include_once(G5_ADMIN_PATH . '/admin.tail.php');
?>