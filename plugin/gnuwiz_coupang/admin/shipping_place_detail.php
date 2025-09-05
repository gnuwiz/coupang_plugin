<?php
/**
 * 쿠팡 출고지/반품지 상세 정보 AJAX 페이지
 * 경로: /plugin/gnuwiz_coupang/admin/shipping_place_detail.php
 * 용도: 출고지/반품지 상세 정보를 AJAX로 반환
 */

include_once('./_common.php');

// 관리자 권한 체크
if (!$is_admin) {
    die('관리자만 접근 가능합니다.');
}

// 쿠팡 설정 및 API 클래스 로드
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_config.php');
include_once(COUPANG_PLUGIN_PATH . '/lib/coupang_api_class.php');

$shipping_place_code = isset($_GET['code']) ? clean_xss_tags($_GET['code']) : '';

if (!$shipping_place_code) {
    die('<div class="alert alert-danger">출고지/반품지 코드가 지정되지 않았습니다.</div>');
}

// API 인스턴스 생성
$coupang_api = get_coupang_api();

// 로컬 DB에서 기본 정보 조회
$sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_shipping_places 
        WHERE shipping_place_code = '" . addslashes($shipping_place_code) . "'";
$local_data = sql_fetch($sql);

if (!$local_data) {
    die('<div class="alert alert-danger">해당 출고지/반품지를 찾을 수 없습니다.</div>');
}

// 쿠팡 API에서 최신 정보 조회
$api_result = $coupang_api->getShippingPlaceDetail($shipping_place_code);
$api_data = null;
if ($api_result['success'] && isset($api_result['data'])) {
    $api_data = $api_result['data'];
}

// JSON 데이터 파싱
$place_data = json_decode($local_data['place_data'], true);
?>

<div class="row">
    <div class="col-md-6">
        <h6><i class="fa fa-database"></i> 로컬 DB 정보</h6>
        <table class="table table-sm table-bordered">
            <tr>
                <th width="30%">출고지/반품지 코드</th>
                <td><?php echo htmlspecialchars($local_data['shipping_place_code']); ?></td>
            </tr>
            <tr>
                <th>이름</th>
                <td><?php echo htmlspecialchars($local_data['shipping_place_name']); ?></td>
            </tr>
            <tr>
                <th>주소 타입</th>
                <td>
                    <span class="badge bg-<?php echo $local_data['address_type'] === 'OUTBOUND' ? 'primary' : 'success'; ?>">
                        <?php echo $local_data['address_type'] === 'OUTBOUND' ? '출고지' : '반품지'; ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>회사명</th>
                <td><?php echo htmlspecialchars($local_data['company_name']); ?></td>
            </tr>
            <tr>
                <th>담당자</th>
                <td><?php echo htmlspecialchars($local_data['contact_name']); ?></td>
            </tr>
            <tr>
                <th>전화번호</th>
                <td><?php echo htmlspecialchars($local_data['phone1']); ?></td>
            </tr>
            <tr>
                <th>주소</th>
                <td>
                    (<?php echo htmlspecialchars($local_data['zipcode']); ?>) 
                    <?php echo htmlspecialchars($local_data['address1']); ?> 
                    <?php echo htmlspecialchars($local_data['address2']); ?>
                </td>
            </tr>
            <tr>
                <th>상태</th>
                <td>
                    <span class="badge bg-<?php echo $local_data['status'] === 'ACTIVE' ? 'success' : 'secondary'; ?>">
                        <?php echo $local_data['status']; ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>기본 설정</th>
                <td>
                    <?php if ($local_data['is_default_outbound']): ?>
                        <span class="badge bg-primary">기본 출고지</span>
                    <?php endif; ?>
                    <?php if ($local_data['is_default_return']): ?>
                        <span class="badge bg-success">기본 반품지</span>
                    <?php endif; ?>
                    <?php if (!$local_data['is_default_outbound'] && !$local_data['is_default_return']): ?>
                        <span class="text-muted">기본 설정 없음</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>마지막 동기화</th>
                <td><?php echo $local_data['last_sync_date'] ? date('Y-m-d H:i:s', strtotime($local_data['last_sync_date'])) : '없음'; ?></td>
            </tr>
        </table>
    </div>
    
    <div class="col-md-6">
        <h6><i class="fa fa-cloud"></i> 쿠팡 API 정보</h6>
        <?php if ($api_data): ?>
            <table class="table table-sm table-bordered">
                <tr>
                    <th width="30%">API 응답 상태</th>
                    <td><span class="badge bg-success">정상</span></td>
                </tr>
                <?php if (isset($api_data['shippingPlaceCode'])): ?>
                <tr>
                    <th>출고지/반품지 코드</th>
                    <td><?php echo htmlspecialchars($api_data['shippingPlaceCode']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (isset($api_data['shippingPlaceName'])): ?>
                <tr>
                    <th>이름</th>
                    <td><?php echo htmlspecialchars($api_data['shippingPlaceName']); ?></td>
                </tr>
                <?php endif; ?>
                <?php if (isset($api_data['placeAddresses']) && is_array($api_data['placeAddresses'])): ?>
                    <?php foreach ($api_data['placeAddresses'] as $idx, $address): ?>
                    <tr>
                        <th>주소 <?php echo $idx + 1; ?></th>
                        <td>
                            <strong>타입:</strong> <?php echo htmlspecialchars($address['addressType'] ?? ''); ?><br>
                            <strong>회사:</strong> <?php echo htmlspecialchars($address['companyName'] ?? ''); ?><br>
                            <strong>담당자:</strong> <?php echo htmlspecialchars($address['name'] ?? ''); ?><br>
                            <strong>전화:</strong> <?php echo htmlspecialchars($address['phoneNumber1'] ?? ''); ?><br>
                            <strong>주소:</strong> 
                            (<?php echo htmlspecialchars($address['zipCode'] ?? ''); ?>) 
                            <?php echo htmlspecialchars($address['address1'] ?? ''); ?> 
                            <?php echo htmlspecialchars($address['address2'] ?? ''); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="fa fa-exclamation-triangle"></i> 
                쿠팡 API에서 정보를 가져올 수 없습니다.
                <?php if (isset($api_result['message'])): ?>
                    <br><small><?php echo htmlspecialchars($api_result['message']); ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 원본 JSON 데이터 -->
<?php if ($place_data): ?>
<div class="row mt-3">
    <div class="col-12">
        <h6><i class="fa fa-code"></i> 원본 JSON 데이터</h6>
        <div class="card">
            <div class="card-body">
                <pre class="bg-light p-3" style="max-height: 300px; overflow-y: auto; font-size: 12px;"><?php echo htmlspecialchars(json_encode($place_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 동기화 로그 -->
<div class="row mt-3">
    <div class="col-12">
        <h6><i class="fa fa-history"></i> 최근 동기화 로그</h6>
        <?php
        $log_sql = "SELECT * FROM " . G5_TABLE_PREFIX . "coupang_shipping_log 
                    WHERE shipping_place_code = '" . addslashes($shipping_place_code) . "' 
                    ORDER BY created_date DESC LIMIT 5";
        $log_result = sql_query($log_sql);
        
        if (sql_num_rows($log_result) > 0):
        ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th>일시</th>
                            <th>작업</th>
                            <th>상태</th>
                            <th>실행시간</th>
                            <th>메시지</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($log_row = sql_fetch_array($log_result)): ?>
                        <tr>
                            <td style="font-size: 11px;"><?php echo date('m-d H:i', strtotime($log_row['created_date'])); ?></td>
                            <td>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($log_row['action_type']); ?></span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $log_row['status'] === 'SUCCESS' ? 'success' : ($log_row['status'] === 'FAIL' ? 'danger' : 'warning'); ?>">
                                    <?php echo htmlspecialchars($log_row['status']); ?>
                                </span>
                            </td>
                            <td style="font-size: 11px;">
                                <?php echo $log_row['execution_time'] ? $log_row['execution_time'] . 's' : '-'; ?>
                            </td>
                            <td style="font-size: 11px;">
                                <?php echo htmlspecialchars($log_row['error_message'] ?: '성공'); ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">동기화 로그가 없습니다.</div>
        <?php endif; ?>
    </div>
</div>

<!-- 액션 버튼 -->
<div class="row mt-3">
    <div class="col-12">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary btn-sm" onclick="syncSingleShippingPlace('<?php echo $shipping_place_code; ?>')">
                <i class="fa fa-sync"></i> 개별 동기화
            </button>
            <button type="button" class="btn btn-success btn-sm" onclick="testShippingPlace('<?php echo $shipping_place_code; ?>')">
                <i class="fa fa-check"></i> 연결 테스트
            </button>
            <?php if ($local_data['address_type'] === 'OUTBOUND' && !$local_data['is_default_outbound']): ?>
            <a href="../shipping_places.php?action=set_default&code=<?php echo urlencode($shipping_place_code); ?>&type=OUTBOUND" 
               class="btn btn-outline-success btn-sm"
               onclick="return confirm('이 출고지를 기본 출고지로 설정하시겠습니까?')">
                기본 출고지로 설정
            </a>
            <?php elseif ($local_data['address_type'] === 'RETURN' && !$local_data['is_default_return']): ?>
            <a href="../shipping_places.php?action=set_default&code=<?php echo urlencode($shipping_place_code); ?>&type=RETURN" 
               class="btn btn-outline-success btn-sm"
               onclick="return confirm('이 반품지를 기본 반품지로 설정하시겠습니까?')">
                기본 반품지로 설정
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function syncSingleShippingPlace(shippingPlaceCode) {
    if (!confirm('이 출고지/반품지를 개별 동기화하시겠습니까?')) {
        return;
    }
    
    // AJAX로 개별 동기화 실행
    fetch('shipping_place_sync.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=sync_single&code=' + encodeURIComponent(shippingPlaceCode)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('동기화가 완료되었습니다.');
            location.reload(); // 상세 정보 새로고침
        } else {
            alert('동기화 실패: ' + data.message);
        }
    })
    .catch(error => {
        alert('동기화 중 오류가 발생했습니다.');
        console.error('Error:', error);
    });
}

function testShippingPlace(shippingPlaceCode) {
    // AJAX로 연결 테스트 실행
    fetch('shipping_place_test.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=test&code=' + encodeURIComponent(shippingPlaceCode)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('연결 테스트 성공!\n' + data.message);
        } else {
            alert('연결 테스트 실패: ' + data.message);
        }
    })
    .catch(error => {
        alert('테스트 중 오류가 발생했습니다.');
        console.error('Error:', error);
    });
}
</script>

<?php
// 로그 결과 해제
if (isset($log_result)) {
    sql_free_result($log_result);
}
?>