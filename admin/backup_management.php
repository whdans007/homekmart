<?php
$page_title = '데이터베이스 백업 관리';
require_once 'partials/header.php';
require_permission('settings', 'index.php');

// 백업 디렉토리 경로
$backup_dir = __DIR__ . '/../backups/';

// 기존 백업 파일 목록 가져오기
function getBackupFiles($backup_dir) {
    $files = [];
    if (is_dir($backup_dir)) {
        $scan = scandir($backup_dir);
        foreach ($scan as $file) {
            if ($file != '.' && $file != '..' && $file != '.htaccess' && 
                (pathinfo($file, PATHINFO_EXTENSION) === 'sql' || 
                 substr($file, -7) === '.sql.gz')) {
                $filepath = $backup_dir . $file;
                
                // 백업 타입 판별
                $backup_type = 'full'; // 기본값
                if (strpos($file, '_data_') !== false) {
                    $backup_type = 'data_only';
                } elseif (strpos($file, 'pre_restore_backup_') !== false) {
                    $backup_type = 'pre_restore';
                }
                
                $files[] = [
                    'name' => $file,
                    'size' => filesize($filepath),
                    'date' => filemtime($filepath),
                    'type' => $backup_type
                ];
            }
        }
    }
    // 날짜순으로 정렬 (최신순)
    usort($files, function($a, $b) {
        return $b['date'] - $a['date'];
    });
    return $files;
}

// 파일 삭제 처리
if (isset($_POST['delete_backup']) && isset($_POST['filename'])) {
    $filename = basename($_POST['filename']);
    $filepath = $backup_dir . $filename;
    
    if (file_exists($filepath) && is_file($filepath)) {
        if (unlink($filepath)) {
            $_SESSION['success_message'] = "백업 파일이 성공적으로 삭제되었습니다: $filename";
        } else {
            $_SESSION['error_message'] = "백업 파일 삭제에 실패했습니다: $filename";
        }
    } else {
        $_SESSION['error_message'] = "백업 파일을 찾을 수 없습니다: $filename";
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$backup_files = getBackupFiles($backup_dir);

// 파일 크기 포맷팅 함수
function formatFileSize($size) {
    if ($size >= 1024 * 1024 * 1024) {
        return number_format($size / (1024 * 1024 * 1024), 2) . ' GB';
    } elseif ($size >= 1024 * 1024) {
        return number_format($size / (1024 * 1024), 2) . ' MB';
    } elseif ($size >= 1024) {
        return number_format($size / 1024, 2) . ' KB';
    } else {
        return $size . ' bytes';
    }
}
?>

<div class="flex-1 overflow-auto p-6">
    <div class="max-w-7xl mx-auto">
        <!-- 헤더 -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900"><?php echo t('backup.management_title'); ?></h1>
            <p class="mt-2 text-gray-600"><?php echo t('backup.management_description'); ?></p>
        </div>

        <!-- 성공/에러 메시지 -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($_SESSION['success_message']); ?></p>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['success_message']); endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($_SESSION['error_message']); ?></p>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['error_message']); endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- 새 백업 생성 -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-plus-circle text-primary-600 mr-2"></i><?php echo t('backup.create_backup_title'); ?>
                    </h2>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600 mb-4">
                        <?php echo t('backup.create_backup_description'); ?>
                    </p>
                    
                    <!-- 백업 타입 선택 -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-3">백업 타입</label>
                        <div class="space-y-2">
                            <label class="flex items-center">
                                <input type="radio" name="backup_type" value="full" checked
                                       class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300">
                                <span class="ml-2 text-sm text-gray-900">
                                    <i class="fas fa-database text-primary-600 mr-1"></i>
                                    <strong>전체 백업</strong> - 테이블 구조 + 데이터
                                </span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="backup_type" value="data_only"
                                       class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300">
                                <span class="ml-2 text-sm text-gray-900">
                                    <i class="fas fa-table text-blue-600 mr-1"></i>
                                    <strong>데이터만 백업</strong> - INSERT 구문만 (구조 유지)
                                </span>
                            </label>
                        </div>
                    </div>
                    
                    <div id="backup-progress" class="hidden mb-4">
                        <div class="bg-gray-200 rounded-full h-4 overflow-hidden">
                            <div id="progress-bar" class="bg-blue-600 h-4 rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                        <p id="progress-text" class="text-sm text-gray-700 mt-2 font-medium text-center">백업을 준비 중...</p>
                    </div>
                    
                    <button id="create-backup-btn" onclick="createBackup()" 
                            class="w-full bg-primary-600 hover:bg-primary-700 text-white font-medium py-2 px-4 rounded-md transition-colors duration-200">
                        <i class="fas fa-download mr-2"></i><?php echo t('backup.create_backup'); ?>
                    </button>
                </div>
            </div>

            <!-- 데이터 복원 -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-upload text-orange-600 mr-2"></i>데이터 복원
                    </h2>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600 mb-4">
                        백업 파일을 업로드하여 데이터베이스를 복원합니다.
                        <span class="text-red-600 font-medium">주의: 현재 데이터가 모두 삭제됩니다.</span>
                    </p>
                    
                    <form id="restore-form" enctype="multipart/form-data" class="space-y-4">
                        <div>
                            <label for="backup-file" class="block text-sm font-medium text-gray-700 mb-2">
                                백업 파일 선택 (.sql 파일만)
                            </label>
                            <input type="file" id="backup-file" name="backup_file" accept=".sql"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        </div>
                        
                        <div id="restore-progress" class="hidden mb-4">
                            <div class="bg-gray-200 rounded-full h-4 overflow-hidden">
                                <div id="restore-progress-bar" class="bg-orange-600 h-4 rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                            <p id="restore-progress-text" class="text-sm text-gray-700 mt-2 font-medium text-center">복원을 준비 중...</p>
                        </div>
                        
                        <button type="button" onclick="confirmRestore()" 
                                class="w-full bg-orange-600 hover:bg-orange-700 text-white font-medium py-2 px-4 rounded-md transition-colors duration-200">
                            <i class="fas fa-upload mr-2"></i>데이터 복원
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 기존 백업 목록 -->
        <div class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-list text-blue-600 mr-2"></i>백업 파일 목록
                </h2>
            </div>
            <div class="p-6">
                <?php if (empty($backup_files)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-folder-open text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-500">백업 파일이 없습니다.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    파일명
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    크기
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    생성일시
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    작업
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($backup_files as $file): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php 
                                    $icon = 'fas fa-database text-primary-600';
                                    $type_label = '전체';
                                    $type_class = 'bg-primary-100 text-primary-800';
                                    
                                    if ($file['type'] === 'data_only') {
                                        $icon = 'fas fa-table text-blue-600';
                                        $type_label = '데이터';
                                        $type_class = 'bg-blue-100 text-blue-800';
                                    } elseif ($file['type'] === 'pre_restore') {
                                        $icon = 'fas fa-shield-alt text-orange-600';
                                        $type_label = '복원전';
                                        $type_class = 'bg-orange-100 text-orange-800';
                                    }
                                    ?>
                                    <div class="flex items-center">
                                        <i class="<?php echo $icon; ?> mr-2"></i>
                                        <div class="flex flex-col">
                                            <span><?php echo htmlspecialchars($file['name']); ?></span>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $type_class; ?> mt-1">
                                                <?php echo $type_label; ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo formatFileSize($file['size']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('Y-m-d H:i:s', $file['date']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                    <a href="download_backup.php?file=<?php echo urlencode($file['name']); ?>" 
                                       class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-primary-700 bg-primary-100 hover:bg-primary-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                        <i class="fas fa-download mr-1"></i>다운로드
                                    </a>
                                    <button onclick="confirmDelete('<?php echo htmlspecialchars($file['name'], ENT_QUOTES); ?>')"
                                            class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                        <i class="fas fa-trash mr-1"></i>삭제
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 삭제 확인 폼 (숨김) -->
<form id="delete-form" method="post" style="display: none;">
    <input type="hidden" name="delete_backup" value="1">
    <input type="hidden" name="filename" id="delete-filename">
</form>

<script>
// 진행 상황 업데이트 인터벌
let progressInterval = null;

// 진행 상황 조회 함수
async function checkProgress(type = 'backup', progressId = '') {
    try {
        const response = await fetch('ajax_backup_progress.php?id=' + progressId);
        const result = await response.json();
        
        if (result.success && result.progress) {
            // 백업과 복원에 따라 다른 프로그레스 바 사용
            const progressBar = type === 'backup' 
                ? document.getElementById('progress-bar')
                : document.getElementById('restore-progress-bar');
            const progressText = type === 'backup'
                ? document.getElementById('progress-text')
                : document.getElementById('restore-progress-text');
            const progress = result.progress;
            
            if (progress.status === 'processing' || progress.status === 'clearing') {
                progressBar.style.width = progress.percentage + '%';
                progressText.textContent = progress.message + ' (' + progress.current + '/' + progress.total + ')';
            } else if (progress.status === 'completed') {
                progressBar.style.width = '100%';
                progressText.textContent = type === 'backup' ? '백업 완료!' : '복원 완료!';
                clearInterval(progressInterval);
            }
        }
    } catch (error) {
        console.error('진행 상황 조회 실패:', error);
    }
}

// 백업 생성 함수
async function createBackup() {
    const btn = document.getElementById('create-backup-btn');
    const progress = document.getElementById('backup-progress');
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    
    // 선택된 백업 타입 가져오기
    const backupType = document.querySelector('input[name="backup_type"]:checked').value;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>백업 생성 중...';
    progress.classList.remove('hidden');
    progressBar.style.width = '0%';
    progressText.textContent = '백업을 시작합니다...';
    
    try {
        // 백업 시작 (비동기로 처리하고 바로 progress_id를 받음)
        const startResponse = await fetch('backup_process.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=start_backup&backup_type=${backupType}`
        });
        
        const startResult = await startResponse.json();
        if (startResult.progress_id) {
            // 진행 상황 모니터링 시작
            progressInterval = setInterval(() => checkProgress('backup', startResult.progress_id), 500);
            
            // 실제 백업 실행
            const response = await fetch('backup_process.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=create_backup&backup_type=${backupType}&progress_id=${startResult.progress_id}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                clearInterval(progressInterval);
                progressBar.style.width = '100%';
                progressText.textContent = '백업이 성공적으로 생성되었습니다!';
                
                // 성공 메시지를 표시하고 페이지 새로고침
                setTimeout(() => {
                    alert('백업이 성공적으로 생성되었습니다.\n파일명: ' + result.filename + '\n크기: ' + formatFileSize(result.filesize));
                    location.reload();
                }, 1000);
            } else {
                clearInterval(progressInterval);
                alert('백업 생성에 실패했습니다: ' + result.message);
            }
        } else {
            alert('백업을 시작할 수 없습니다.');
        }
    } catch (error) {
        clearInterval(progressInterval);
        alert('백업 생성 중 오류가 발생했습니다: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-download mr-2"></i>백업 생성';
        setTimeout(() => {
            progress.classList.add('hidden');
            progressBar.style.width = '0%';
            progressText.textContent = '백업을 준비 중...';
        }, 2000);
    }
}

// 파일 크기 포맷팅 함수 추가
function formatFileSize(bytes) {
    if (bytes >= 1073741824) {
        return (bytes / 1073741824).toFixed(2) + ' GB';
    } else if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(2) + ' MB';
    } else if (bytes >= 1024) {
        return (bytes / 1024).toFixed(2) + ' KB';
    } else {
        return bytes + ' bytes';
    }
}

// 복원 확인
function confirmRestore() {
    const fileInput = document.getElementById('backup-file');
    if (!fileInput.files[0]) {
        alert('먼저 백업 파일을 선택해주세요.');
        return;
    }
    
    const filename = fileInput.files[0].name;
    if (!filename.endsWith('.sql')) {
        alert('SQL 파일만 업로드 가능합니다.');
        return;
    }
    
    if (confirm('정말로 데이터를 복원하시겠습니까?\n\n경고: 현재의 모든 데이터가 삭제되고 백업 파일의 데이터로 대체됩니다.\n이 작업은 되돌릴 수 없습니다.')) {
        if (confirm('복원하기 전에 현재 데이터의 백업을 먼저 생성하는 것을 권장합니다.\n\n계속 진행하시겠습니까?')) {
            restoreData();
        }
    }
}

// 데이터 복원 함수
async function restoreData() {
    const fileInput = document.getElementById('backup-file');
    const progress = document.getElementById('restore-progress');
    const progressBar = document.getElementById('restore-progress-bar');
    const progressText = document.getElementById('restore-progress-text');
    
    // 프로그레스 바 표시
    progress.classList.remove('hidden');
    progressBar.style.width = '0%';
    progressText.textContent = '복원을 시작합니다...';
    
    // 진행 상황 모니터링 시작
    progressInterval = setInterval(() => checkProgress('restore'), 500); // 0.5초마다 확인
    
    const formData = new FormData();
    formData.append('backup_file', fileInput.files[0]);
    formData.append('action', 'restore_data');
    
    try {
        const response = await fetch('restore_process.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        clearInterval(progressInterval);
        
        if (result.success) {
            progressBar.style.width = '100%';
            progressText.textContent = '데이터 복원 완료!';
            
            let message = '데이터 복원이 성공적으로 완료되었습니다.\n';
            if (result.stats) {
                message += '\n성공: ' + result.stats.success + '개';
                message += '\n실패: ' + result.stats.errors + '개';
            }
            
            setTimeout(() => {
                alert(message);
                location.reload();
            }, 1000);
        } else {
            alert('데이터 복원에 실패했습니다: ' + result.message);
        }
    } catch (error) {
        clearInterval(progressInterval);
        alert('데이터 복원 중 오류가 발생했습니다: ' + error.message);
    } finally {
        setTimeout(() => {
            progress.classList.add('hidden');
            progressBar.style.width = '0%';
            progressText.textContent = '백업을 준비 중...';
        }, 3000);
    }
}

// 삭제 확인
function confirmDelete(filename) {
    if (confirm('정말로 백업 파일을 삭제하시겠습니까?\n\n파일명: ' + filename + '\n\n이 작업은 되돌릴 수 없습니다.')) {
        document.getElementById('delete-filename').value = filename;
        document.getElementById('delete-form').submit();
    }
}
</script>

<?php require_once 'partials/footer.php'; ?>