<?php
// 프린트 페이지 공통 레이아웃 (페이지 분할 + 페이지 번호 포함)
// 사용법: kw_print_head($title, $extraCss); ... doc-header + table(#srcTable) ...; kw_print_tail();

function kw_print_head(string $title, string $extraCss = ''): void {
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?></title>
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: 'Malgun Gothic', Arial, sans-serif; color: #111; background: #e5e7eb; }

  h1 { font-size: 16px; margin: 0 0 4px; }
  .meta { font-size: 11px; color: #555; margin-bottom: 8px; }
  .meta span { margin-right: 12px; }

  table { width: 100%; border-collapse: collapse; font-size: 10px; table-layout: fixed; }
  th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; overflow: hidden; word-break: break-word; }
  th { background: #f0f0f0; font-weight: 700; text-align: center; }
  td.center { text-align: center; }
  td.right { text-align: right; }
  td.mono { font-family: 'Consolas', monospace; }
  tr.inactive { color: #999; }
  .sub { font-size: 9px; color: #666; }

  /* 페이지 단위 레이아웃 (A4 가로: 297mm x 210mm, 여백 10mm) */
  .print-page {
    position: relative;
    width: 277mm;
    height: 190mm;
    margin: 0 auto 10px;
    padding: 0;
    background: #fff;
    overflow: hidden;
    box-shadow: 0 0 4px rgba(0,0,0,0.25);
  }
  .print-page .page-content { padding: 8mm; height: 100%; box-sizing: border-box; overflow: hidden; }
  .page-footer {
    position: absolute;
    left: 0; right: 0; bottom: 0;
    text-align: center;
    font-size: 9px;
    color: #666;
    border-top: 1px solid #ccc;
    padding: 3px 0;
    background: #fff;
  }

  #srcWrap { visibility: hidden; position: absolute; top: -99999px; left: 0; width: 261mm; }

  @media print {
    body { background: #fff; }
    .print-page {
      width: auto; height: 190mm; margin: 0; box-shadow: none;
      page-break-after: always;
    }
    .print-page:last-child { page-break-after: auto; }
    @page { size: A4 landscape; margin: 10mm; }
  }
<?php echo $extraCss; ?>
</style>
</head>
<body>
  <div id="pages"></div>

  <!-- 측정/페이지 분할용 원본 (화면에는 보이지 않음) -->
  <div id="srcWrap">
<?php
}

function kw_print_doc_header(string $title, array $metaItems): void {
?>
    <div class="doc-header">
      <h1><?php echo htmlspecialchars($title); ?></h1>
      <div class="meta">
        <?php foreach ($metaItems as $item): ?>
        <span><?php echo $item; ?></span>
        <?php endforeach; ?>
      </div>
    </div>
<?php
}

function kw_print_tail(): void {
?>
  </div>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <script>
    function paginate() {
      var srcWrap  = document.getElementById('srcWrap');
      var headerEl = srcWrap.querySelector('.doc-header');
      var errEl    = srcWrap.querySelector('p');
      var srcTable = document.getElementById('srcTable');
      var thead    = srcTable.querySelector('thead');
      var rows     = Array.prototype.slice.call(srcTable.querySelectorAll('tbody > tr'));
      var pagesEl  = document.getElementById('pages');
      pagesEl.innerHTML = '';

      // 페이지 1장의 사용 가능 높이(px) 측정
      var probe = document.createElement('div');
      probe.className = 'print-page';
      probe.style.visibility = 'hidden';
      var probeContent = document.createElement('div');
      probeContent.className = 'page-content';
      probe.appendChild(probeContent);
      document.body.appendChild(probe);
      var pageContentHeight = probeContent.clientHeight;
      document.body.removeChild(probe);

      var footerHeight = 22;
      var headerHeight = headerEl ? headerEl.offsetHeight + (errEl ? errEl.offsetHeight : 0) + 8 : 0;
      var theadHeight  = thead.offsetHeight;

      var availableFirst = pageContentHeight - headerHeight - theadHeight - footerHeight;
      var availableNext  = pageContentHeight - theadHeight - footerHeight;

      var pagesData = [];
      var current = [];
      var currentHeight = 0;
      var available = availableFirst;

      rows.forEach(function(row) {
        var h = row.offsetHeight;
        if (currentHeight + h > available && current.length > 0) {
          pagesData.push(current);
          current = [];
          currentHeight = 0;
          available = availableNext;
        }
        current.push(row);
        currentHeight += h;
      });
      pagesData.push(current);

      var totalPages = pagesData.length;
      pagesData.forEach(function(rowsArr, idx) {
        var pageDiv = document.createElement('div');
        pageDiv.className = 'print-page';

        var content = document.createElement('div');
        content.className = 'page-content';

        if (idx === 0) {
          if (headerEl) content.appendChild(headerEl);
          if (errEl)    content.appendChild(errEl);
        }

        var table = document.createElement('table');
        var colgroup = srcTable.querySelector('colgroup');
        if (colgroup) table.appendChild(colgroup.cloneNode(true));
        table.appendChild(thead.cloneNode(true));
        var tbody = document.createElement('tbody');
        rowsArr.forEach(function(r) { tbody.appendChild(r); });
        table.appendChild(tbody);
        content.appendChild(table);

        pageDiv.appendChild(content);

        var footer = document.createElement('div');
        footer.className = 'page-footer';
        footer.textContent = 'Page ' + (idx + 1) + ' / ' + totalPages;
        pageDiv.appendChild(footer);

        pagesEl.appendChild(pageDiv);
      });

      // 선택적 결제(서명)란: srcWrap 안에 #signOffSrc 가 있으면 마지막 페이지 하단에 부착
      var signOff = srcWrap.querySelector('#signOffSrc');
      if (signOff && pagesEl.lastElementChild) {
        var lastContent = pagesEl.lastElementChild.querySelector('.page-content');
        if (lastContent) lastContent.appendChild(signOff);
      }

      srcWrap.remove();
    }

    if (document.readyState === 'complete') {
      paginate();
    } else {
      window.addEventListener('load', paginate);
    }
  </script>
</body>
</html>
<?php
}
