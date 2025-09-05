<!DOCTYPE html>
<html>
<head>
    <title>🎯 쿠팡 카테고리 추천 테스트</title>
    <meta charset='utf-8'>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; background: #f8f9fa; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 2.5rem; font-weight: 700; }
        .subtitle { margin-top: 10px; opacity: 0.9; font-size: 1.1rem; }
        
        .card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .card h2 { margin-top: 0; color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #34495e; }
        .form-control { width: 100%; padding: 12px; border: 2px solid #e1e8ed; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; }
        .form-control:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1); }
        .form-control.textarea { min-height: 100px; resize: vertical; }
        
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-primary { background: linear-gradient(45deg, #3498db, #2980b9); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(52, 152, 219, 0.4); }
        .btn-success { background: linear-gradient(45deg, #27ae60, #229954); color: white; }
        .btn-warning { background: linear-gradient(45deg, #f39c12, #e67e22); color: white; }
        
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .full-width { grid-column: 1 / -1; }
        
        .result-container { margin-top: 20px; padding: 20px; border-radius: 8px; }
        .result-success { background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border: 1px solid #c3e6cb; color: #155724; }
        .result-error { background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); border: 1px solid #f5c6cb; color: #721c24; }
        
        .category-info { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0; }
        .confidence-bar { background: #e9ecef; height: 8px; border-radius: 4px; overflow: hidden; margin-top: 5px; }
        .confidence-fill { background: linear-gradient(90deg, #dc3545 0%, #ffc107 50%, #28a745 100%); height: 100%; transition: width 0.3s; }
        
        .loading { display: none; text-align: center; padding: 20px; }
        .spinner { border: 3px solid #f3f3f3; border-top: 3px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 0 auto 10px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .batch-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 15px; }
        .batch-item { background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #3498db; }
        .batch-item.success { border-left-color: #28a745; }
        .batch-item.error { border-left-color: #dc3545; }
        
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .grid { grid-template-columns: 1fr; }
            .header h1 { font-size: 2rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 쿠팡 카테고리 추천 테스트</h1>
            <div class="subtitle">머신러닝 기반 자동 카테고리 매칭 시스템</div>
        </div>

        <!-- 단일 상품 테스트 -->
        <div class="card">
            <h2>🔍 단일 상품 카테고리 추천 테스트</h2>
            
            <form id="categoryTestForm">
                <div class="grid">
                    <div class="form-group">
                        <label for="productName">상품명 *</label>
                        <input type="text" id="productName" class="form-control" 
                               placeholder="예: 삼성 갤럭시 S24 케이스" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="brand">브랜드</label>
                        <input type="text" id="brand" class="form-control" 
                               placeholder="예: 삼성, LG, 애플">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="productDescription">상품 설명</label>
                    <textarea id="productDescription" class="form-control textarea" 
                              placeholder="상품의 상세 설명을 입력하면 더 정확한 카테고리 추천이 가능합니다..."></textarea>
                </div>
                
                <div class="grid">
                    <div class="form-group">
                        <label for="origin">제조국</label>
                        <input type="text" id="origin" class="form-control" 
                               placeholder="예: 한국, 중국, 미국">
                    </div>
                    
                    <div class="form-group">
                        <label for="weight">중량</label>
                        <input type="text" id="weight" class="form-control" 
                               placeholder="예: 200g, 1.5kg">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    🔍 카테고리 추천 받기
                </button>
            </form>
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <div>카테고리 추천 중...</div>
            </div>
            
            <div id="testResult"></div>
        </div>

        <!-- 배치 테스트 -->
        <div class="card">
            <h2>📦 영카트 상품 배치 카테고리 추천</h2>
            <p>등록된 영카트 상품들의 카테고리를 자동으로 추천받습니다.</p>
            
            <div class="grid">
                <div class="form-group">
                    <label for="batchLimit">처리할 상품 수</label>
                    <select id="batchLimit" class="form-control">
                        <option value="5">5개</option>
                        <option value="10" selected>10개</option>
                        <option value="20">20개</option>
                        <option value="50">50개</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" class="btn btn-success" onclick="runBatchTest()">
                        🚀 배치 추천 실행
                    </button>
                </div>
            </div>
            
            <div id="batchResult"></div>
        </div>

        <!-- 캐시 관리 -->
        <div class="card">
            <h2>🗂️ 캐시 관리</h2>
            <p>카테고리 추천 결과는 1시간 동안 캐시됩니다.</p>
            
            <button type="button" class="btn btn-warning" onclick="clearCache()">
                🗑️ 캐시 정리 (7일 이상)
            </button>
            
            <div id="cacheResult"></div>
        </div>

        <!-- 사용 가이드 -->
        <div class="card">
            <h2>📖 사용 가이드</h2>
            
            <h3>💡 추천 정확도 향상 팁</h3>
            <ul>
                <li><strong>상품명:</strong> 구체적이고 상세하게 입력 (브랜드, 모델명, 특징 포함)</li>
                <li><strong>상품 설명:</strong> 용도, 재질, 사이즈 등 상세 정보 포함</li>
                <li><strong>브랜드:</strong> 정확한 브랜드명 입력</li>
                <li><strong>속성 정보:</strong> 제조국, 중량 등 추가 정보 제공</li>
            </ul>
            
            <h3>⚠️ 주의사항</h3>
            <ul>
                <li>딜 형식의 상품명은 피하세요 (예: "인기상품 모음전")</li>
                <li>여러 상품을 하나의 이름에 섞지 마세요</li>
                <li>애매한 키워드보다는 명확한 상품 분류를 사용하세요</li>
            </ul>
            
            <h3>🎯 신뢰도 지표</h3>
            <div style="margin: 10px 0;">
                <div style="display: flex; align-items: center; margin: 5px 0;">
                    <div style="width: 20px; height: 8px; background: #dc3545; margin-right: 10px;"></div>
                    <span>0.0 - 0.6: 낮음 (수동 확인 권장)</span>
                </div>
                <div style="display: flex; align-items: center; margin: 5px 0;">
                    <div style="width: 20px; height: 8px; background: #ffc107; margin-right: 10px;"></div>
                    <span>0.6 - 0.8: 보통 (주의 깊게 검토)</span>
                </div>
                <div style="display: flex; align-items: center; margin: 5px 0;">
                    <div style="width: 20px; height: 8px; background: #28a745; margin-right: 10px;"></div>
                    <span>0.8 - 1.0: 높음 (자동 적용 가능)</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 단일 상품 테스트
        document.getElementById('categoryTestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const loading = document.getElementById('loading');
            const result = document.getElementById('testResult');
            
            loading.style.display = 'block';
            result.innerHTML = '';
            
            const formData = {
                product_name: document.getElementById('productName').value,
                brand: document.getElementById('brand').value,
                product_description: document.getElementById('productDescription').value,
                origin: document.getElementById('origin').value,
                weight: document.getElementById('weight').value
            };
            
            // AJAX 요청 시뮬레이션 (실제로는 PHP로 요청)
            setTimeout(() => {
                loading.style.display = 'none';
                
                // 예시 응답 (실제로는 서버에서 받아옴)
                const mockResponse = {
                    success: true,
                    data: {
                        category_id: "63950",
                        category_name: "휴대폰액세서리 > 케이스 > 범퍼케이스",
                        confidence: 0.85,
                        result_type: "SUCCESS"
                    }
                };
                
                if (mockResponse.success) {
                    result.innerHTML = `
                        <div class="result-container result-success">
                            <h3>✅ 카테고리 추천 성공!</h3>
                            <div class="category-info">
                                <div><strong>카테고리 ID:</strong> ${mockResponse.data.category_id}</div>
                                <div><strong>카테고리명:</strong> ${mockResponse.data.category_name}</div>
                                <div><strong>신뢰도:</strong> ${(mockResponse.data.confidence * 100).toFixed(1)}%</div>
                                <div class="confidence-bar">
                                    <div class="confidence-fill" style="width: ${mockResponse.data.confidence * 100}%"></div>
                                </div>
                            </div>
                            <p><small>💡 이 추천 결과를 상품 등록 시 자동으로 적용할 수 있습니다.</small></p>
                        </div>
                    `;
                } else {
                    result.innerHTML = `
                        <div class="result-container result-error">
                            <h3>❌ 카테고리 추천 실패</h3>
                            <p>${mockResponse.error}</p>
                        </div>
                    `;
                }
            }, 2000);
        });
        
        // 배치 테스트
        function runBatchTest() {
            const limit = document.getElementById('batchLimit').value;
            const result = document.getElementById('batchResult');
            
            result.innerHTML = '<div class="loading"><div class="spinner"></div><div>배치 처리 중...</div></div>';
            
            setTimeout(() => {
                // 예시 배치 결과
                const mockBatchResult = {
                    success: true,
                    processed: parseInt(limit),
                    succeeded: Math.floor(parseInt(limit) * 0.8),
                    failed: Math.floor(parseInt(limit) * 0.2),
                    recommendations: [
                        { it_id: 'item001', it_name: '삼성 갤럭시 케이스', category_name: '휴대폰액세서리 > 케이스', confidence: 0.92 },
                        { it_id: 'item002', it_name: 'LG 냉장고', category_name: '가전 > 냉장고', confidence: 0.88 },
                        { it_id: 'item003', it_name: '나이키 운동화', category_name: '신발 > 운동화', confidence: 0.95 }
                    ]
                };
                
                let html = `
                    <div class="result-container result-success">
                        <h3>📊 배치 처리 완료</h3>
                        <p><strong>처리:</strong> ${mockBatchResult.processed}개 |