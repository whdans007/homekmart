<?php
// 마스터 파일 업로드 (엑셀/CSV) - 인증 불필요 (독립형 도구)
// 대용량 XLS(수만 행) 처리를 위해 메모리/실행시간 한도 상향
ini_set('memory_limit', '1024M');
@set_time_limit(300);
// PHP 경고가 JSON 앞에 출력되는 것을 방지
ob_start();

require_once __DIR__ . '/../config/db_config.php';

// 한 번이라도 JSON을 보냈는지 표시 (shutdown 핸들러 중복 출력 방지)
$GLOBALS['__json_sent'] = false;

// 응답 전송 함수 (출력 버퍼 정리 후 JSON 출력)
function sendJson(array $data): void {
    $GLOBALS['__json_sent'] = true;
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 치명적 오류(메모리 초과/시간 초과 등)로 스크립트가 죽어도
// 빈 응답("Unexpected end of JSON input") 대신 JSON 메시지를 반환
register_shutdown_function(function () {
    if (!empty($GLOBALS['__json_sent'])) return;
    $err = error_get_last();
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $msg = '서버 처리 한도 초과로 중단되었습니다. ';
        if (stripos($err['message'], 'memory') !== false) {
            $msg .= '파일이 너무 큽니다(메모리 부족). 행 수를 줄이거나 .xlsx로 저장 후 다시 시도하세요.';
        } elseif (stripos($err['message'], 'time') !== false || stripos($err['message'], 'execution') !== false) {
            $msg .= '처리 시간이 초과되었습니다. 파일을 나눠서 업로드하세요.';
        } else {
            $msg .= '오류: ' . $err['message'];
        }
        echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => '알 수 없는 오류로 처리가 중단되었습니다. 파일 크기를 확인하세요.'], JSON_UNESCAPED_UNICODE);
    }
});

$action = $_POST['action'] ?? ($_GET['action'] ?? 'import');

// ── NAS 폴더 파일 목록 반환 ──────────────────────────────────
if ($action === 'list_nas') {
    $nas_dir = dirname(__DIR__) . '/uploads/pos_import/';
    if (!is_dir($nas_dir)) {
        sendJson(['success' => false, 'message' => 'NAS 폴더가 없습니다: ' . $nas_dir]);
    }
    $files = [];
    foreach (scandir($nas_dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) continue;
        $fp = $nas_dir . $f;
        if (!is_file($fp)) continue;
        $files[] = [
            'name'     => $f,
            'size_kb'  => round(filesize($fp) / 1024, 1),
            'modified' => date('Y-m-d H:i:s', filemtime($fp)),
        ];
    }
    // 최신 수정일 순
    usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']));
    sendJson(['success' => true, 'files' => $files]);
}

// ── NAS 파일 삭제 ────────────────────────────────────────────────
if ($action === 'delete_nas') {
    $nas_dir  = dirname(__DIR__) . '/uploads/pos_import/';
    $filename = basename($_POST['nas_filename'] ?? '');
    if (!$filename) {
        sendJson(['success' => false, 'message' => '파일명이 없습니다.']);
    }
    $target = realpath($nas_dir . $filename);
    // realpath로 NAS 폴더 밖 경로 조작 방지
    if (!$target || strpos($target, realpath($nas_dir)) !== 0 || !is_file($target)) {
        sendJson(['success' => false, 'message' => '파일을 찾을 수 없습니다.']);
    }
    if (!unlink($target)) {
        sendJson(['success' => false, 'message' => '파일 삭제 실패. 서버 권한을 확인하세요.']);
    }
    sendJson(['success' => true, 'message' => "삭제 완료: {$filename}"]);
}

// ── NAS로 파일 업로드 ────────────────────────────────────────────
if ($action === 'upload_to_nas') {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => '파일이 너무 큽니다 (서버 업로드 제한 초과).',
        UPLOAD_ERR_FORM_SIZE  => '파일이 너무 큽니다.',
        UPLOAD_ERR_PARTIAL    => '파일이 부분적으로만 업로드되었습니다.',
        UPLOAD_ERR_NO_FILE    => '파일이 선택되지 않았습니다.',
        UPLOAD_ERR_NO_TMP_DIR => '서버 임시 디렉토리가 없습니다.',
        UPLOAD_ERR_CANT_WRITE => '서버 디스크 쓰기 실패.',
    ];

    if (!isset($_FILES['nas_upload_file']) || $_FILES['nas_upload_file']['error'] !== UPLOAD_ERR_OK) {
        $err_code = $_FILES['nas_upload_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        sendJson(['success' => false, 'message' => $upload_errors[$err_code] ?? '업로드 오류 (' . $err_code . ')']);
    }

    $orig_name = basename($_FILES['nas_upload_file']['name']);
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        sendJson(['success' => false, 'message' => '.xlsx / .xls / .csv 파일만 업로드 가능합니다.']);
    }

    $nas_dir = dirname(__DIR__) . '/uploads/pos_import/';
    if (!is_dir($nas_dir)) {
        mkdir($nas_dir, 0777, true);
    }

    // 파일명 안전 처리 (경로 조작 방지)
    $safe_name = preg_replace('/[^\w가-힣\-\.]/u', '_', $orig_name);
    $dest = $nas_dir . $safe_name;

    // 동일 파일명 존재 시 타임스탬프 추가
    if (file_exists($dest)) {
        $base  = pathinfo($safe_name, PATHINFO_FILENAME);
        $safe_name = $base . '_' . date('YmdHis') . '.' . $ext;
        $dest  = $nas_dir . $safe_name;
    }

    if (!move_uploaded_file($_FILES['nas_upload_file']['tmp_name'], $dest)) {
        sendJson(['success' => false, 'message' => 'NAS 폴더에 파일 저장 실패. 서버 쓰기 권한을 확인하세요.']);
    }

    $size_kb = round(filesize($dest) / 1024, 1);
    sendJson(['success' => true, 'message' => "✅ 업로드 완료: {$safe_name} ({$size_kb} KB)"]);
}

// ── 가져오기 (import) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => '잘못된 요청입니다.']);
}

$store_id = (int)($_POST['store_id'] ?? 0);
if (!$store_id) {
    // store_id가 0이면 DB에서 첫 번째 유효한 점포를 자동 선택
    try {
        $tmp_dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $tmp_pdo = new PDO($tmp_dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $store_id = (int)($tmp_pdo->query("SELECT id FROM stores WHERE id > 0 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        unset($tmp_pdo);
    } catch (Throwable $e) {
        error_log('ajax_import_master.php: fallback store query failed: ' . $e->getMessage());
    }
    if (!$store_id) {
        sendJson(['success' => false, 'message' => '점포를 먼저 선택해주세요. (화면 새로고침 후 환경설정에서 점포 선택)']);
    }
}

// ── 컬럼 매핑 파라미터 ──
// 우선순위: DB(settings)에 저장된 이 점포의 매핑 → POST 파라미터 → 하드코딩 기본값
// DB를 기준으로 삼아야 다른 PC/브라우저에서 업로드해도 저장해둔 매핑이 그대로 적용됨
$db_colmap = [];
try {
    require_once __DIR__ . '/../lib/settings_helper.php';
    $cm_dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $cm_pdo = new PDO($cm_dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cm_stored = settings_get($cm_pdo, 'pricing_colmap_' . $store_id);
    if ($cm_stored) {
        $decoded = json_decode($cm_stored, true);
        if (is_array($decoded)) $db_colmap = $decoded;
    }
    unset($cm_pdo);
} catch (Throwable $e) {
    error_log('ajax_import_master.php: colmap load failed: ' . $e->getMessage());
}

function pim_pick_col(array $db_colmap, string $key, string $fallback): string {
    if (!empty($db_colmap[$key])) return strtoupper(trim((string)$db_colmap[$key]));
    $posted = strtoupper(trim((string)($_POST[$key] ?? '')));
    return $posted !== '' ? $posted : $fallback;
}

$col_sku   = pim_pick_col($db_colmap, 'col_sku',   'A');
$col_name  = pim_pick_col($db_colmap, 'col_name',  'B');
$col_cost  = pim_pick_col($db_colmap, 'col_cost',  'C');
$col_price = pim_pick_col($db_colmap, 'col_price', 'D');
$header_row = isset($db_colmap['header_row'])
    ? max(1, (int)$db_colmap['header_row'])
    : max(1, (int)($_POST['header_row'] ?? 1));
$data_start = $header_row + 1;

// 매핑된 컬럼 문자 → 필드명
$colmap = ['sku' => $col_sku, 'name' => $col_name, 'cost' => $col_cost, 'price' => $col_price];

// ── 경량 스트리밍 파서 (PhpSpreadsheet 미사용, 대용량 파일 메모리 안전) ──
// 'B' → 1, 'AA' → 26 (0기반 인덱스)
function pim_col_to_index(string $letters): int {
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $letters));
    if ($letters === '') return -1;
    $n = 0;
    for ($i = 0, $l = strlen($letters); $i < $l; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

// CSV를 fgetcsv로 스트리밍 (저메모리)
function pim_read_csv(string $path, array $colmap, int $data_start): array {
    $idx = [];
    foreach ($colmap as $k => $letter) $idx[$k] = pim_col_to_index($letter);
    $rows = [];
    $fh = fopen($path, 'r');
    if ($fh === false) throw new RuntimeException('CSV 파일을 열 수 없습니다.');
    $rownum = 0; $first = true;
    while (($d = fgetcsv($fh, 0, ',')) !== false) {
        $rownum++;
        if ($first) { if (isset($d[0])) $d[0] = preg_replace('/^\xEF\xBB\xBF/', '', $d[0]); $first = false; }
        if ($rownum < $data_start) continue;
        $g = function ($i) use ($d) { return ($i >= 0 && isset($d[$i])) ? (string)$d[$i] : ''; };
        $sku = trim($g($idx['sku']));
        if ($sku === '') continue;
        $rows[] = [
            'sku'           => $sku,
            'name_en'       => trim($g($idx['name'])),
            'cost_price'    => floatval(str_replace(',', '', $g($idx['cost']))),
            'selling_price' => floatval(str_replace(',', '', $g($idx['price']))),
        ];
    }
    fclose($fh);
    return $rows;
}

// XLSX를 ZipArchive + 정규식으로 스트리밍 (저메모리, 수만 행 안전)
// $diag: 결과가 비었을 때 진단용 (실제 데이터가 있는 열/샘플) 반환
function pim_read_xlsx(string $path, array $colmap, int $data_start, array &$diag = []): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('서버에 ZipArchive 확장이 없습니다.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('xlsx(zip) 파일을 열 수 없습니다.');

    // 첫 워크시트 경로 결정 (workbook + rels), 실패 시 sheet1.xml로 폴백
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wbXml   = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml !== false && $relsXml !== false) {
        $wbXml = preg_replace('/<!DOCTYPE[^>]*>/si', '', $wbXml);
        $wb = @simplexml_load_string($wbXml);
        if ($wb !== false) {
            $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $sheets = $wb->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
            if ($sheets && isset($sheets[0])) {
                $rid = (string)$sheets[0]->attributes($rNs)->id;
                $rels = @simplexml_load_string($relsXml);
                if ($rels !== false) {
                    foreach ($rels->Relationship as $rel) {
                        if ((string)$rel['Id'] === $rid) {
                            $t = ltrim((string)$rel['Target'], '/');
                            if (strpos($t, 'xl/') !== 0) $t = 'xl/' . $t;
                            if ($zip->locateName($t) !== false) $sheetPath = $t;
                        }
                    }
                }
            }
        }
    }
    $zip->close();

    // sharedStrings 로드 (정규식 추출 — 실제 파일로 검증된 방식)
    $shared = [];
    $sstXml = @file_get_contents('zip://' . $path . '#xl/sharedStrings.xml');
    if ($sstXml !== false && $sstXml !== '') {
        if (preg_match_all('/<si\b[^>]*>.*?<\/si>|<si\b[^>]*\/>/s', $sstXml, $sis)) {
            foreach ($sis[0] as $si) {
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                    $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                } else {
                    $shared[] = '';
                }
            }
        }
        unset($sstXml);
    }

    // 대상 컬럼 문자 → 필드명
    $wanted = [];
    foreach ($colmap as $field => $letter) {
        $L = strtoupper(preg_replace('/[^A-Za-z]/', '', $letter));
        if ($L !== '') $wanted[$L] = $field;
    }

    // 시트 스트리밍 — </row> 단위로 버퍼를 잘라 정규식으로 추출 (저메모리)
    $sheetUrl = 'zip://' . $path . '#' . $sheetPath;
    $fh = @fopen($sheetUrl, 'r');
    if ($fh === false) throw new RuntimeException('워크시트를 열 수 없습니다: ' . $sheetPath);

    $rows = [];
    $buf = '';
    $lastRow = 0;
    $scanned = 0;
    $diag = ['rows_scanned' => 0, 'sample_cols' => [], 'sample_row' => 0];
    while (!feof($fh)) {
        $buf .= fread($fh, 131072);
        while (($p = strpos($buf, '</row>')) !== false) {
            $chunk = substr($buf, 0, $p);
            $buf   = substr($buf, $p + 6);
            // 현재 행의 <row ...> 시작 위치 (앞쪽 preamble/빈 행 무시)
            $rs = strrpos($chunk, '<row');
            if ($rs === false) continue;
            $rowXml = substr($chunk, $rs);

            // 행 번호 (r 속성 없으면 순번)
            if (preg_match('/^<row\b[^>]*\br="(\d+)"/', $rowXml, $rn)) {
                $rowNum = (int)$rn[1];
            } else {
                $rowNum = $lastRow + 1;
            }
            $lastRow = $rowNum;
            if ($rowNum < $data_start) continue;

            // 셀 추출 — 전체 열을 한 번에 맵으로 (col문자 → 값)
            if (!preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $rowXml, $cs, PREG_SET_ORDER)) continue;
            $allCells = [];
            foreach ($cs as $c) {
                $attrs = $c[1];
                $inner = isset($c[2]) ? $c[2] : '';
                if (!preg_match('/\br="([A-Z]+)\d+"/', $attrs, $cr)) continue;
                $colL = $cr[1];
                $t = '';
                if (preg_match('/\bt="([^"]+)"/', $attrs, $tt)) $t = $tt[1];
                $val = '';
                if ($t === 's') {
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vv)) $val = $shared[(int)$vv[1]] ?? '';
                } elseif ($t === 'inlineStr') {
                    if (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $vv)) $val = html_entity_decode($vv[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                } else {
                    if (preg_match('/<v>(.*?)<\/v>/s', $inner, $vv)) $val = html_entity_decode($vv[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                if ($val !== '') $allCells[$colL] = $val;
            }

            $scanned++;
            // 첫 데이터 행을 진단 샘플로 저장 (값 있는 열만)
            if ($scanned === 1) {
                $diag['sample_row'] = $rowNum;
                foreach ($allCells as $cl => $vv) {
                    $diag['sample_cols'][$cl] = mb_substr((string)$vv, 0, 20);
                }
            }

            // $wanted: 컬럼문자 => 필드명. 필드명으로 컬럼문자 역검색
            $skuCol   = array_search('sku',   $wanted, true);
            $nameCol  = array_search('name',  $wanted, true);
            $costCol  = array_search('cost',  $wanted, true);
            $priceCol = array_search('price', $wanted, true);
            $sku = $skuCol !== false ? trim($allCells[$skuCol] ?? '') : '';
            if ($sku === '') continue;
            $rows[] = [
                'sku'           => $sku,
                'name_en'       => $nameCol  !== false ? trim($allCells[$nameCol] ?? '') : '',
                'cost_price'    => floatval(str_replace(',', '', $costCol  !== false ? ($allCells[$costCol]  ?? '0') : '0')),
                'selling_price' => floatval(str_replace(',', '', $priceCol !== false ? ($allCells[$priceCol] ?? '0') : '0')),
            ];
        }
    }
    $diag['rows_scanned'] = $scanned;
    fclose($fh);

    return $rows;
}

// ── 파일 소스 결정: upload(기본) 또는 nas ──
$source = $_POST['source'] ?? 'upload';
$tmpPath   = null;
$ext       = '';
$cleanup   = false; // 임시 파일 제거 여부

if ($source === 'nas') {
    $nas_dir      = dirname(__DIR__) . '/uploads/pos_import/';
    $nas_filename = basename($_POST['nas_filename'] ?? '');
    if (!$nas_filename) {
        sendJson(['success' => false, 'message' => '파일을 선택해주세요.']);
    }
    $nas_file_path = $nas_dir . $nas_filename;
    if (!is_file($nas_file_path)) {
        sendJson(['success' => false, 'message' => "NAS 폴더에서 파일을 찾을 수 없습니다: {$nas_filename}"]);
    }
    $ext = strtolower(pathinfo($nas_filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        sendJson(['success' => false, 'message' => '.xlsx / .xls / .csv 파일만 지원합니다.']);
    }
    // PhpSpreadsheet이 NAS/SMB 경로를 직접 열지 못하는 경우가 있으므로 시스템 temp로 복사
    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_import_' . time() . '.' . $ext;
    if (!copy($nas_file_path, $tmpPath)) {
        sendJson(['success' => false, 'message' => "파일 복사 실패. NAS 경로: {$nas_file_path}"]);
    }
    $cleanup = true;
} else {
    // HTTP 업로드
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => '파일이 너무 큽니다 (서버 업로드 제한 초과). NAS 폴더 방식을 사용하세요.',
            UPLOAD_ERR_FORM_SIZE  => '파일이 너무 큽니다.',
            UPLOAD_ERR_PARTIAL    => '파일이 부분적으로만 업로드되었습니다.',
            UPLOAD_ERR_NO_FILE    => '파일이 선택되지 않았습니다.',
            UPLOAD_ERR_NO_TMP_DIR => '서버 임시 디렉토리가 없습니다.',
            UPLOAD_ERR_CANT_WRITE => '서버 디스크 쓰기 실패.',
        ];
        $err_code = $_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        sendJson(['success' => false, 'message' => $upload_errors[$err_code] ?? '업로드 오류 (' . $err_code . ')']);
    }

    $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        sendJson(['success' => false, 'message' => '.xlsx / .xls / .csv 파일만 업로드 가능합니다.']);
    }
    $tmpPath = $_FILES['excel_file']['tmp_name'];
}

try {
    libxml_use_internal_errors(true);

    // 파일 형식별 스트리밍 파싱 (PhpSpreadsheet 미사용)
    $diag = [];
    if ($ext === 'csv') {
        $excel_data = pim_read_csv($tmpPath, $colmap, $data_start);
    } elseif ($ext === 'xlsx') {
        $excel_data = pim_read_xlsx($tmpPath, $colmap, $data_start, $diag);
    } else {
        // .xls(구형 BIFF)는 이 서버에서 직접 읽을 수 없음 → 변환 안내
        if ($cleanup && file_exists($tmpPath)) @unlink($tmpPath);
        sendJson(['success' => false, 'message' => "구형 .xls 파일은 직접 읽을 수 없습니다.\nExcel에서 [다른 이름으로 저장] → '.xlsx' 또는 'CSV(쉼표로 분리)'로 저장한 뒤 다시 업로드하세요."]);
    }

    if ($cleanup && file_exists($tmpPath)) @unlink($tmpPath);

    if (empty($excel_data)) {
        $m  = "유효한 데이터가 없습니다.\n";
        $m .= "• 현재 설정 — SKU:{$col_sku} / 상품명:{$col_name} / 원가:{$col_cost} / 판매가:{$col_price} / 헤더:{$header_row}행\n";
        if (!empty($diag['rows_scanned'])) {
            $m .= "• 스캔한 데이터 행: {$diag['rows_scanned']}개\n";
            if (!empty($diag['sample_cols'])) {
                $m .= "• {$diag['sample_row']}행에서 실제로 값이 있는 열:\n";
                foreach ($diag['sample_cols'] as $cl => $vv) {
                    $m .= "   {$cl}열 = {$vv}\n";
                }
                $m .= "→ 위 목록을 보고 SKU/상품명/원가/판매가 열 문자를 다시 지정하세요.";
            } else {
                $m .= "• 데이터 행은 있으나 값이 비어 있습니다. 헤더 행 번호를 확인하세요.";
            }
        } else {
            $m .= "• {$col_sku}열에 SKU/바코드가 있는지, 헤더 행이 {$header_row}행인지 확인하세요.";
        }
        sendJson(['success' => false, 'message' => $m]);
    }

    // DB 저장
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();

    // 점포명 확인
    $store_name_stmt = $pdo->prepare("SELECT name FROM stores WHERE id = ?");
    $store_name_stmt->execute([$store_id]);
    $store_name_str = $store_name_stmt->fetchColumn() ?: "점포 ID {$store_id}";

    // 기본 카테고리
    $default_cat = (int)($pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn() ?: 1);

    $success = $errors = $new_products = $updated = $name_updated = 0;
    $log = [];

    foreach ($excel_data as $row) {
        $sku           = $row['sku'];
        $name_en       = $row['name_en'];
        $selling_price = $row['selling_price'];
        $cost_price    = $row['cost_price'];

        if (empty($name_en)) { $errors++; continue; }
        if ($selling_price < 0 || $cost_price < 0) { $errors++; continue; }

        try {
            $chk = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
            $chk->execute([$sku]);
            $product_id = $chk->fetchColumn();

            if (!$product_id) {
                $ins = $pdo->prepare("INSERT INTO products (sku, name_ko, name_en, category_id, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
                $ins->execute([$sku, $name_en, $name_en, $default_cat]);
                $product_id = (int)$pdo->lastInsertId();
                $new_products++;
            } else {
                // 기존 상품: 영문 상품명(name_en)만 업데이트. 수동 편집한 한글명(name_ko)은 보존.
                $upd = $pdo->prepare("UPDATE products SET name_en = ?, updated_at = NOW() WHERE id = ? AND (name_en <> ? OR name_en IS NULL)");
                $upd->execute([$name_en, $product_id, $name_en]);
                if ($upd->rowCount() > 0) $name_updated++;
            }

            $inv = $pdo->prepare("
                INSERT INTO inventory (product_id, store_id, selling_price, cost_price, quantity)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), cost_price = VALUES(cost_price)
            ");
            $inv->execute([$product_id, $store_id, $selling_price, $cost_price]);
            $updated++;
            $success++;
        } catch (Throwable $e) {
            $errors++;
            if (count($log) < 5) $log[] = "SKU {$sku}: " . $e->getMessage();
        }
    }

    if ($success > 0) {
        $pdo->commit();
        $col_info = "SKU:{$col_sku} / 상품명:{$col_name} / 원가:{$col_cost} / 판매가:{$col_price} / 헤더:{$header_row}행";
        $msg  = "✅ 업로드 완료! [{$store_name_str}]\n";
        $msg .= "• 전체 행: " . count($excel_data) . "개\n";
        $msg .= "• 신규 상품: {$new_products}개\n";
        $msg .= "• 상품명 업데이트: {$name_updated}개\n";
        $msg .= "• 가격 업데이트: {$updated}개\n";
        $msg .= "• 성공: {$success}개";
        if ($errors > 0) $msg .= "\n• 실패: {$errors}개 (이름/가격 없는 행 제외)";
        $msg .= "\n\n컬럼 설정: {$col_info}";
    } else {
        $pdo->rollBack();
        $msg  = "❌ 저장 실패 (실패: {$errors}개)\n";
        $msg .= "{$col_sku}열(SKU/바코드), {$col_name}열(상품명)이 올바른지 확인하세요.";
    }
    if (!empty($log)) $msg .= "\n\n오류 내역:\n" . implode("\n", $log);

    sendJson(['success' => $success > 0, 'message' => $msg]);

} catch (Throwable $e) {
    if ($cleanup && isset($tmpPath) && file_exists($tmpPath)) @unlink($tmpPath);
    error_log('pricing/ajax_import_master.php error: ' . $e->getMessage());
    sendJson(['success' => false, 'message' => '파일 처리 오류: ' . $e->getMessage()]);
}
