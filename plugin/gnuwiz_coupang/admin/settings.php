<?php
/**
 * 쿠팡 연동 설정 관리 페이지
 * 경로: /plugin/coupang/admin/settings.php
 * 용도: 카테고리 매핑, API 설정 등을 관리
 */

include_once('../_common.php');

// 관리자 권한 체크
if (!$is_admin) {
    die('관리자만 접근할 수 있습니다.');
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

// 폼 처리
if ($action) {
    switch ($action) {
        case 'save_category_mapping':
            $youngcart_ca_id = $_POST['youngcart_ca_id'];
            $coupang_category_id = $_POST['coupang_category_id'];
            $coupang_category_name = $_POST['coupang_category_name'];
            
            if ($youngcart_ca_id && $coupang_category_id) {
                $sql = "INSERT INTO " . G5_TABLE_PREFIX . "coupang_category_map 
                        (youngcart_ca_id, coupang_category_id, coupang_category_name, sync_date) 
                        VALUES ('{$youngcart_ca_id}', '{$coupang_category_id}', '{$coupang_category_name}', NOW())
                        ON DUPLICATE KEY UPDATE 
                        coupang_category_id = '{$coupang_category_id}',
                        coupang_category_name = '{$coupang_category_name}',
                        sync_date = NOW()";
                
                if (sql_query($sql)) {
                    $message = '카테고리 매핑이 저장되었습니다.';
                    $message_type = 'success';
                } else {
                    $message = '카테고리 매핑 저장 실패: ' . sql_error();
                    $message_type = 'error';
                }
            } else {
                $message = '필수 항목을 입력하세요.';
                $message_type = 'error';
            }
            break;
            
        case 'delete_category_mapping':
            $mapping_id = (int)$_POST['mapping_id'];
            if ($mapping_id) {
                $sql = "DELETE FROM " . G5_TABLE_PREFIX . "coupang_category_map WHERE id = {$mapping_id}";
                if (sql_query($sql)) {
                    $message = '카테고리 매핑이 삭제되었습니다.';
                    $message_type = 'success';
                } else {
                    $message = '카테고리 매핑 삭제 실패: ' . sql_error();
                    $message_type = 'error';
                }
            }
            break;
    }
}

// 영카트 카테고리 목록 가져오기
function get_youngcart_categories() {
    global $g5;
    $categories = array();
    
    $sql = "SELECT ca_id, ca_name FROM {$g5['g5_shop_category_table']} ORDER BY ca_order, ca_id";
    $result = sql_query($sql);
    
    while ($row = sql_fetch_array($result)) {
        $categories[] = $row;
    }
    
    return $categories;
}

// 현재 매핑 목록 가져오기
function get_current_mappings() {
    global $g5;
    $mappings = array();
    
    $sql = "SELECT m.*, c.ca_name as youngcart_ca_name 
            FROM " . G5_TABLE_PREFIX . "coupang_category_map m
            LEFT JOIN {$g5['g5_shop_category_table']} c ON m.youngcart_ca_id = c.ca_id
            ORDER BY m.sync_date DESC";
    
    $result = sql_query($sql);
    
    while ($row = sql_fetch_array($result)) {
        $mappings[] = $row;
    }
    
    return $mappings;
}

$youngcart_categories = get_youngcart_categories();
$current_mappings = get_current_mappings();
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>쿠팡 연동 설정</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header h1 { color: #2c3e50; margin-bottom: 10px; }
        .header .subtitle { color: #7f8c8d; }
        
        .card { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card h2 { color: #2c3e50; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #3498db; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #2c3e50; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-control:focus { outline: none; border-color: #3498db; box-shadow: 0 0 5px rgba(52, 152, 219, 0.3); }
        
        .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; font-size: 14px; transition: background 0.3s; }
        .btn:hover { background: #2980b9; }
        .btn.btn-success { background: #27ae60; }
        .btn.btn-success:hover { background: #229954; }
        .btn.btn-danger { background: #e74c3c; }
        .btn.btn-danger:hover { background: #c0392b; }
        
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background: #f8f9fa; font-weight: 600; color: #2c3e50; }
        .table tr:hover { background: #f8f9fa; }
        
        .alert { padding: 15px; margin-bottom: 20px; border: 1px solid transparent; border-radius: 4px; }
        .alert.alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        .alert.alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 15px; }
        
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚙️ 쿠팡 연동 설정</h1>
            <div class="subtitle">카테고리 매핑 및 기본 설정 관리</div>
        </div>
        
        <?php if (isset($message)): ?>
            <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>
        
        <!-- API 설정 현황 -->
        <div class="card">
            <h2>🔑 API 설정 현황</h2>
            <?php
            $config_check = validate_coupang_config();
            if ($config_check['valid']) {
                echo '<div class="alert alert-success">✅ API 설정이 완료되었습니다.</div>';
                echo '<p><strong>Access Key:</strong> ' . substr(COUPANG_ACCESS_KEY, 0, 10) . '***</p>';
                echo '<p><strong>Vendor ID:</strong> ' . COUPANG_VENDOR_ID . '</p>';
            } else {
                echo '<div class="alert alert-danger">❌ API 설정 오류</div>';
                echo '<ul>';
                foreach ($config_check['errors'] as $error) {
                    echo '<li>' . $error . '</li>';
                }
                echo '</ul>';
                echo '<p><strong>설정 파일 경로:</strong> ' . COUPANG_PLUGIN_PATH . '/lib/coupang_config.php</p>';
            }
            ?>
        </div>
        
        <!-- 카테고리 매핑 관리 -->
        <div class="card">
            <h2>🗂️ 카테고리 매핑 관리</h2>
            <p>영카트 카테고리를 쿠팡 카테고리에 연결합니다.</p>
            
            <!-- 새 매핑 추가 폼 -->
            <form method="post">
                <input type="hidden" name="action" value="save_category_mapping">
                <div class="form-row">
                    <div class="form-group">
                        <label>영카트 카테고리</label>
                        <select name="youngcart_ca_id" class="form-control" required>
                            <option value="">선택하세요</option>
                            <?php foreach ($youngcart_categories as $category): ?>
                                <option value="<?= $category['ca_id'] ?>">
                                    <?= $category['ca_id'] ?> - <?= htmlspecialchars($category['ca_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>쿠팡 카테고리 ID</label>
                        <input type="text" name="coupang_category_id" class="form-control" placeholder="예: 1001" required>
                    </div>
                    <div class="form-group">
                        <label>쿠팡 카테고리명</label>
                        <input type="text" name="coupang_category_name" class="form-control" placeholder="예: 생활용품" required>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-success">매핑 추가</button>
                    </div>
                </div>
            </form>
            
            <!-- 현재 매핑 목록 -->
            <h3>현재 매핑 목록</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>영카트 카테고리</th>
                        <th>쿠팡 카테고리 ID</th>
                        <th>쿠팡 카테고리명</th>
                        <th>동기화 일시</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($current_mappings)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #999;">매핑된 카테고리가 없습니다.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($current_mappings as $mapping): ?>
                            <tr>
                                <td>
                                    <?= $mapping['youngcart_ca_id'] ?> - 
                                    <?= htmlspecialchars($mapping['youngcart_ca_name'] ?: '(카테고리 삭제됨)') ?>
                                </td>
                                <td><?= htmlspecialchars($mapping['coupang_category_id']) ?></td>
                                <td><?= htmlspecialchars($mapping['coupang_category_name']) ?></td>
                                <td><?= $mapping['sync_date'] ?></td>
                                <td>
                                    <form method="post" style="display: inline-block;" onsubmit="return confirm('정말 삭제하시겠습니까?');">
                                        <input type="hidden" name="action" value="delete_category_mapping">
                                        <input type="hidden" name="mapping_id" value="<?= $mapping['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;">삭제</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 기본 쿠팡 카테고리 참고 -->
        <div class="card">
            <h2>📋 쿠팡 주요 카테고리 참고</h2>
            <p>아래는 쿠팡의 주요 카테고리 ID입니다. 정확한 카테고리 ID는 쿠팡 파트너센터에서 확인하세요.</p>
            
            <table class="table">
                <thead>
                    <tr>
                        <th>카테고리 ID</th>
                        <th>카테고리명</th>
                        <th>설명</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>1001</td><td>생활용품</td><td>일반 생활용품</td></tr>
                    <tr><td>1002</td><td>의류/액세서리</td><td>옷, 신발, 액세서리</td></tr>
                    <tr><td>1003</td><td>식품</td><td>식품, 건강식품</td></tr>
                    <tr><td>1004</td><td>전자제품</td><td>가전, IT기기</td></tr>
                    <tr><td>1005</td><td>도서/음반</td><td>책, CD, DVD</td></tr>
                    <tr><td>1006</td><td>화장품/미용</td><td>화장품, 미용용품</td></tr>
                    <tr><td>1007</td><td>스포츠/레저</td><td>스포츠용품, 레저용품</td></tr>
                    <tr><td>1008</td><td>자동차용품</td><td>자동차 관련 용품</td></tr>
                    <tr><td>1009</td><td>완구/취미</td><td>장난감, 취미용품</td></tr>
                    <tr><td>1010</td><td>기타</td><td>기타 상품</td></tr>
                </tbody>
            </table>
            
            <div class="alert alert-info" style="margin-top: 15px; color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb;">
                <strong>참고:</strong> 실제 쿠팡 카테고리 ID는 쿠팡 파트너센터에서 확인하시기 바랍니다. 
                잘못된 카테고리 ID 사용시 상품 등록이 실패할 수 있습니다.
            </div>
        </div>
        
        <!-- 동기화 설정 -->
        <div class="card">
            <h2>🔄 동기화 설정</h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <div>
                    <h3>현재 설정값</h3>
                    <p><strong>API 호출 지연:</strong> <?= COUPANG_API_DELAY ?>초</p>
                    <p><strong>재시도 횟수:</strong> <?= COUPANG_MAX_RETRY ?>회</p>
                    <p><strong>타임아웃:</strong> <?= COUPANG_TIMEOUT ?>초</p>
                    <p><strong>로그 레벨:</strong> <?= COUPANG_LOG_LEVEL ?></p>
                </div>
                
                <div>
                    <h3>배치 크기</h3>
                    <p><strong>주문 배치:</strong> <?= COUPANG_ORDER_BATCH_SIZE ?>건</p>
                    <p><strong>상품 배치:</strong> <?= COUPANG_PRODUCT_BATCH_SIZE ?>건</p>
                    <p><strong>재고 배치:</strong> <?= COUPANG_STOCK_BATCH_SIZE ?>건</p>
                </div>
            </div>
            
            <div class="alert alert-info" style="margin-top: 15px; color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb;">
                <strong>설정 변경:</strong> 동기화 설정을 변경하려면 
                <code>/plugin/coupang/lib/coupang_config.php</code> 파일을 편집하세요.
            </div>
        </div>
        
        <!-- 시스템 정보 -->
        <div class="card">
            <h2>💻 시스템 정보</h2>
            
            <?php
            $version_file = COUPANG_PLUGIN_PATH . '/version.txt';
            $version_info = array();
            
            if (file_exists($version_file)) {
                $version_content = file_get_contents($version_file);
                $version_info = json_decode($version_content, true);
            }
            ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <div>
                    <h3>플러그인 정보</h3>
                    <p><strong>버전:</strong> <?= isset($version_info['version']) ? $version_info['version'] : '1.0.0' ?></p>
                    <p><strong>설치일:</strong> <?= isset($version_info['install_date']) ? $version_info['install_date'] : '알 수 없음' ?></p>
                    <p><strong>플러그인 경로:</strong> <?= COUPANG_PLUGIN_PATH ?></p>
                </div>
                
                <div>
                    <h3>환경 정보</h3>
                    <p><strong>PHP 버전:</strong> <?= PHP_VERSION ?></p>
                    <p><strong>영카트 버전:</strong> <?= defined('G5_VERSION') ? G5_VERSION : '알 수 없음' ?></p>
                    <p><strong>MySQL 버전:</strong> <?= sql_fetch("SELECT VERSION() as version")['version'] ?></p>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <h3>디렉터리 상태</h3>
                <?php
                $directories = array(
                    'logs' => COUPANG_PLUGIN_PATH . '/logs',
                    'sql' => COUPANG_PLUGIN_PATH . '/sql',
                    'backup' => COUPANG_PLUGIN_PATH . '/backup'
                );
                
                foreach ($directories as $name => $path) {
                    $status = is_dir($path) ? '✅' : '❌';
                    $writable = is_writable($path) ? '(쓰기 가능)' : '(쓰기 불가)';
                    echo "<p><strong>{$name}:</strong> {$status} {$path} {$writable}</p>";
                }
                ?>
            </div>
        </div>
    </div>
</body>
</html>