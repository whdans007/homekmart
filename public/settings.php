<?php
$page_title = "환경설정";
require_once __DIR__ . '/partials/header.php';

if (!is_logged_in() || !($_SESSION['role'] === 'super_admin')) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'><strong class='font-bold'>접근 불가:</strong><span class='block sm:inline'> 이 페이지에 접근할 권한이 없습니다.</span></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

// Get flash message
$flash_message = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>

<!-- Page header -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">환경설정</h1>
    <p class="mt-2 text-sm text-gray-600">시스템 관리 및 데이터 관리 도구를 제공합니다.</p>
</div>

<?php if ($flash_message): ?>
    <div class="mb-6 rounded-lg p-4 border <?php echo $flash_message['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'; ?>">
        <?php echo htmlspecialchars($flash_message['message']); ?>
    </div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="mt-8">
    <h2 class="text-lg font-medium text-gray-900 mb-4">빠른 작업</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="#" onclick="openProductImportModal()" class="relative group bg-white p-6 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow hover:shadow-md transition-shadow duration-200">
            <div>
                <span class="rounded-lg inline-flex p-3 bg-green-50 text-green-700 ring-4 ring-white">
                    <i class="fas fa-file-excel text-lg"></i>
                </span>
            </div>
            <div class="mt-4">
                <h3 class="text-lg font-medium">
                    <span class="absolute inset-0" aria-hidden="true"></span>
                    상품정보 가져오기
                </h3>
                <p class="mt-2 text-sm text-gray-500">엑셀 파일로 상품 정보를 일괄 등록하거나 업데이트합니다.</p>
            </div>
            <span class="pointer-events-none absolute top-6 right-6 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                <i class="fas fa-arrow-right"></i>
            </span>
        </a>
    </div>
</div>

<!-- 상품데이터 가져오기 모달 -->
<div id="productImportModal" class="fixed inset-0 bg-gray-900 bg-opacity-75 backdrop-blur-sm hidden z-50 transition-opacity duration-300">
    <div class="flex items-center justify-center min-h-screen px-4 sm:px-6 lg:px-8">
        <div class="bg-white rounded-3xl shadow-2xl max-w-3xl w-full overflow-hidden transform transition-all duration-300 modal-enter">
            <!-- 헤더 -->
            <div class="bg-gradient-to-r from-green-500 to-emerald-600 px-8 py-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                            <i class="fas fa-file-excel text-white text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-white">상품정보 가져오기</h2>
                            <p class="text-green-100 text-sm">Excel 파일로 상품 정보를 일괄 처리하세요</p>
                        </div>
                    </div>
                    <button onclick="closeProductImportModal()" class="text-white hover:text-green-100 transition-colors duration-200 p-2 rounded-full hover:bg-white hover:bg-opacity-10">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>

            <!-- 컨텐츠 -->
            <div class="p-8">
                <!-- 안내 정보 -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-2xl p-6">
                        <div class="flex items-start space-x-4">
                            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-info-circle text-blue-600"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-blue-900 mb-3">필수 컬럼 정보</h4>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                        <span class="text-sm font-medium text-blue-800">item code</span>
                                        <span class="text-xs text-blue-600">(상품 코드)</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                        <span class="text-sm font-medium text-blue-800">item name</span>
                                        <span class="text-xs text-blue-600">(상품명)</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                        <span class="text-sm font-medium text-blue-800">U. cost</span>
                                        <span class="text-xs text-blue-600">(단가)</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                        <span class="text-sm font-medium text-blue-800">retail</span>
                                        <span class="text-xs text-blue-600">(소매가)</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 파일 업로드 폼 -->
                <div class="mb-8">
                    <label for="modal_excel_file" class="block text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-upload mr-2 text-green-600"></i>
                        Excel 파일 업로드
                    </label>
                    <div class="relative">
                        <label for="modal_excel_file" id="upload-area" class="flex flex-col items-center justify-center w-full h-40 border-3 border-green-300 border-dashed rounded-2xl cursor-pointer bg-gradient-to-br from-green-50 to-emerald-50 hover:from-green-100 hover:to-emerald-100 transition-all duration-300 group">
                            <div id="upload-content" class="flex flex-col items-center justify-center space-y-3">
                                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center group-hover:bg-green-200 transition-colors duration-300">
                                    <i id="upload-icon" class="fas fa-cloud-upload-alt text-3xl text-green-600 group-hover:text-green-700"></i>
                                </div>
                                <div class="text-center">
                                    <p class="text-lg font-semibold text-gray-700 group-hover:text-gray-800">
                                        <span class="text-green-600">클릭하여 파일 선택</span> 
                                        <span class="text-gray-500">또는</span>
                                    </p>
                                    <p class="text-gray-500 group-hover:text-gray-600">드래그 앤 드롭으로 업로드</p>
                                    <p class="text-sm text-gray-400 mt-2">
                                        <i class="fas fa-file-excel mr-1"></i>
                                        XLSX, XLS 파일만 지원
                                    </p>
                                </div>
                            </div>
                            <!-- 업로드 진행 상태 (처음에는 숨김) -->
                            <div id="upload-progress" class="hidden flex flex-col items-center justify-center space-y-3">
                                <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i>
                                </div>
                                <div class="text-center">
                                    <p class="text-lg font-semibold text-blue-700">파일 분석 중...</p>
                                    <div class="w-64 bg-gray-200 rounded-full h-2 mt-3">
                                        <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                                    </div>
                                    <p id="upload-status" class="text-sm text-gray-600 mt-2">Excel 파일을 읽고 있습니다...</p>
                                </div>
                            </div>
                            <input id="modal_excel_file" name="excel_file" type="file" class="hidden" accept=".xlsx, .xls" />
                        </label>
                    </div>
                    <div id="modal-file-name-display" class="mt-4 text-center"></div>
                </div>

                <!-- 미리보기 영역 -->
                <div id="preview-section" class="hidden mb-8">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-2xl p-6">
                        <h3 class="text-lg font-bold text-blue-900 mb-4">
                            <i class="fas fa-eye mr-2"></i>
                            데이터 미리보기 (2열부터 10열까지)
                        </h3>
                        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                            <div class="overflow-x-auto">
                                <table id="preview-table" class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr id="preview-headers"></tr>
                                    </thead>
                                    <tbody id="preview-data"></tbody>
                                </table>
                            </div>
                        </div>
                        <p class="text-sm text-blue-600 mt-3">
                            <i class="fas fa-info-circle mr-1"></i>
                            실제 업로드 시에는 전체 데이터가 처리됩니다.
                        </p>
                    </div>
                </div>

                <!-- 버튼 -->
                <div class="bg-gray-50 rounded-xl p-6 mt-6">
                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="closeProductImportModal()" class="px-8 py-4 border-2 border-gray-300 text-gray-700 rounded-xl hover:bg-gray-100 hover:border-gray-400 transition-all duration-200 font-medium shadow-sm">
                            <i class="fas fa-times mr-2"></i>
                            취소
                        </button>
                        <button type="button" id="preview-btn" onclick="previewData()" class="px-8 py-4 bg-blue-100 hover:bg-blue-200 text-black rounded-xl transition-all duration-200 flex items-center font-bold shadow-lg hover:shadow-xl border-2 border-blue-300" style="color: black !important;">
                            <i class="fas fa-eye mr-3 text-lg" style="color: black !important;"></i>
                            <span style="color: black !important;">미리보기</span>
                        </button>
                        <button type="button" id="upload-btn" onclick="uploadData()" class="px-8 py-4 bg-green-100 hover:bg-green-200 text-black rounded-xl transition-all duration-200 flex items-center font-bold shadow-lg hover:shadow-xl transform hover:scale-105 border-2 border-green-300 hidden" style="color: black !important;">
                            <i class="fas fa-rocket mr-3 text-lg" style="color: black !important;"></i>
                            <span style="color: black !important;">실제 업로드하기</span>
                        </button>
                    </div>
                    <div class="mt-4 text-center">
                        <p class="text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            파일을 선택한 후 <span class="font-semibold text-blue-600">미리보기</span> 버튼을 클릭하여 데이터를 확인하세요
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openProductImportModal() {
    document.getElementById('productImportModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeProductImportModal() {
    document.getElementById('productImportModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    // 파일 선택 초기화
    document.getElementById('modal_excel_file').value = '';
    document.getElementById('modal-file-name-display').innerHTML = '';
    // 미리보기 초기화
    document.getElementById('preview-section').classList.add('hidden');
    document.getElementById('upload-btn').classList.add('hidden');
    document.getElementById('preview-btn').classList.remove('hidden');
    // 미리보기 데이터 초기화
    document.getElementById('preview-headers').innerHTML = '';
    document.getElementById('preview-data').innerHTML = '';
    // 노트 메시지 제거
    const existingNote = document.getElementById('preview-section').querySelector('.bg-yellow-50');
    if (existingNote) {
        existingNote.remove();
    }
}

// 모달 외부 클릭 시 닫기
document.getElementById('productImportModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeProductImportModal();
    }
});

// ESC 키로 모달 닫기
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeProductImportModal();
    }
});

// 파일 선택 시 파일명 표시 및 자동 미리보기
document.getElementById('modal_excel_file').addEventListener('change', function() {
    const fileName = this.files[0] ? this.files[0].name : '';
    const displayDiv = document.getElementById('modal-file-name-display');
    if (fileName) {
        displayDiv.innerHTML = `
            <div class="inline-flex items-center space-x-2 bg-green-100 text-green-800 px-4 py-2 rounded-full">
                <i class="fas fa-file-excel text-green-600"></i>
                <span class="font-medium">${fileName}</span>
                <i class="fas fa-check-circle text-green-600"></i>
            </div>
        `;
        
        // 파일 선택 후 1초 뒤에 자동으로 미리보기 실행
        setTimeout(() => {
            previewData();
        }, 800);
        
    } else {
        displayDiv.innerHTML = '';
        // 미리보기 섹션 숨기기
        document.getElementById('preview-section').classList.add('hidden');
        document.getElementById('upload-btn').classList.add('hidden');
    }
});

// 드래그 앤 드롭 기능
const dropArea = document.querySelector('#modal_excel_file').parentElement;

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    dropArea.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

['dragenter', 'dragover'].forEach(eventName => {
    dropArea.addEventListener(eventName, highlight, false);
});

['dragleave', 'drop'].forEach(eventName => {
    dropArea.addEventListener(eventName, unhighlight, false);
});

function highlight(e) {
    dropArea.classList.add('border-green-400', 'bg-green-50');
}

function unhighlight(e) {
    dropArea.classList.remove('border-green-400', 'bg-green-50');
}

dropArea.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    
    if (files.length > 0) {
        document.getElementById('modal_excel_file').files = files;
        const fileName = files[0].name;
        const displayDiv = document.getElementById('modal-file-name-display');
        displayDiv.innerHTML = `
            <div class="inline-flex items-center space-x-2 bg-green-100 text-green-800 px-4 py-2 rounded-full">
                <i class="fas fa-file-excel text-green-600"></i>
                <span class="font-medium">${fileName}</span>
                <i class="fas fa-check-circle text-green-600"></i>
            </div>
        `;
        
        // 드래그 드롭 후 자동으로 미리보기 실행
        setTimeout(() => {
            previewData();
        }, 800);
    }
}

// 업로드 진행 상태 표시
function showUploadProgress() {
    document.getElementById('upload-content').classList.add('hidden');
    document.getElementById('upload-progress').classList.remove('hidden');
    
    // 진행 바 애니메이션
    const progressBar = document.getElementById('progress-bar');
    const statusText = document.getElementById('upload-status');
    
    let progress = 0;
    const interval = setInterval(() => {
        progress += Math.random() * 30;
        if (progress > 90) progress = 90;
        progressBar.style.width = progress + '%';
        
        if (progress < 30) {
            statusText.textContent = 'Excel 파일을 업로드하고 있습니다...';
        } else if (progress < 60) {
            statusText.textContent = '파일 형식을 확인하고 있습니다...';
        } else if (progress < 90) {
            statusText.textContent = '데이터를 분석하고 있습니다...';
        }
    }, 200);
    
    return interval;
}

function hideUploadProgress() {
    document.getElementById('upload-progress').classList.add('hidden');
    document.getElementById('upload-content').classList.remove('hidden');
    document.getElementById('progress-bar').style.width = '0%';
}

function completeUploadProgress(interval) {
    clearInterval(interval);
    document.getElementById('progress-bar').style.width = '100%';
    document.getElementById('upload-status').textContent = '분석 완료!';
    
    setTimeout(() => {
        hideUploadProgress();
    }, 500);
}

// 미리보기 함수
function previewData() {
    const fileInput = document.getElementById('modal_excel_file');
    if (!fileInput.files[0]) {
        alert('먼저 Excel 파일을 선택해주세요.');
        return;
    }

    const previewBtn = document.getElementById('preview-btn');
    const originalText = previewBtn.innerHTML;
    previewBtn.disabled = true;
    previewBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>분석 중...';

    // 업로드 진행 상태 표시
    const progressInterval = showUploadProgress();

    const formData = new FormData();
    formData.append('excel_file', fileInput.files[0]);

    fetch('simple_preview.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // 응답 상태 확인
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        // 응답 텍스트를 먼저 확인
        return response.text().then(text => {
            console.log('서버 응답:', text);
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON 파싱 오류:', e);
                console.error('응답 내용:', text);
                throw new Error('서버에서 잘못된 JSON을 반환했습니다: ' + text.substring(0, 100));
            }
        });
    })
    .then(data => {
        if (data.success) {
            // 기존 노트 메시지 제거
            const existingNote = document.getElementById('preview-section').querySelector('.bg-yellow-50');
            if (existingNote) {
                existingNote.remove();
            }
            displayPreview(data);
            document.getElementById('preview-section').classList.remove('hidden');
            document.getElementById('upload-btn').classList.remove('hidden');
            completeUploadProgress(progressInterval);
        } else {
            clearInterval(progressInterval);
            hideUploadProgress();
            alert('미리보기 실패: ' + data.message);
        }
    })
    .catch(error => {
        clearInterval(progressInterval);
        hideUploadProgress();
        console.error('Error:', error);
        alert('미리보기 중 오류가 발생했습니다: ' + error.message);
    })
    .finally(() => {
        previewBtn.disabled = false;
        previewBtn.innerHTML = originalText;
    });
}

// 미리보기 데이터 표시
function displayPreview(data) {
    const headersRow = document.getElementById('preview-headers');
    const dataBody = document.getElementById('preview-data');
    
    // 헤더 생성
    headersRow.innerHTML = '';
    data.headers.forEach(header => {
        const th = document.createElement('th');
        th.className = 'px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b';
        th.textContent = header || '(빈 헤더)';
        headersRow.appendChild(th);
    });
    
    // 데이터 행 생성
    dataBody.innerHTML = '';
    data.data.forEach((row, index) => {
        const tr = document.createElement('tr');
        tr.className = index % 2 === 0 ? 'bg-white' : 'bg-gray-50';
        
        row.forEach(cell => {
            const td = document.createElement('td');
            td.className = 'px-4 py-3 text-sm text-gray-900 border-b';
            td.textContent = cell || '';
            tr.appendChild(td);
        });
        
        dataBody.appendChild(tr);
    });

    // 노트 메시지가 있는 경우 표시
    if (data.note) {
        const noteDiv = document.createElement('div');
        noteDiv.className = 'mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg';
        noteDiv.innerHTML = `
            <div class="flex items-start space-x-2">
                <i class="fas fa-exclamation-triangle text-yellow-600 mt-1"></i>
                <div>
                    <p class="text-sm font-medium text-yellow-800">주의사항</p>
                    <p class="text-sm text-yellow-700">${data.note}</p>
                    <p class="text-xs text-yellow-600 mt-1">처리 방법: ${data.method}</p>
                </div>
            </div>
        `;
        document.getElementById('preview-section').querySelector('.bg-gradient-to-r').appendChild(noteDiv);
    }
}

// 실제 업로드 함수
function uploadData() {
    const fileInput = document.getElementById('modal_excel_file');
    if (!fileInput.files[0]) {
        alert('파일이 선택되지 않았습니다.');
        return;
    }

    if (!confirm('선택한 파일로 상품 데이터를 실제로 업로드하시겠습니까?')) {
        return;
    }

    const uploadBtn = document.getElementById('upload-btn');
    const originalText = uploadBtn.innerHTML;
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>업로드 중...';

    const formData = new FormData();
    formData.append('excel_file', fileInput.files[0]);

    fetch('product_bulk_upload.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // PHP에서 리다이렉트하므로 페이지가 새로고침됨
        window.location.reload();
    })
    .catch(error => {
        console.error('Error:', error);
        alert('업로드 중 오류가 발생했습니다.');
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = originalText;
    });
}
</script>

<?php
require_once __DIR__ . '/partials/footer.php';
?>
