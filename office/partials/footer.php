<?php
// Design Ref: §1.2 — CDN 스크립트 + 공통 JS
?>
</div><!-- /.px-5 -->
</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Design Ref: §5 Menu Toggle Logic -->
<script>
(function() {
  const DEFAULT_STATE = {
    sales: true,
    finance: true,
    hr: false,
    "monthly-report": false,
    "pos-data": false
  };

  // localStorage에서 메뉴 상태 읽기
  function getMenuState() {
    const saved = localStorage.getItem('office_menu_state');
    return saved ? JSON.parse(saved) : DEFAULT_STATE;
  }

  // localStorage에 메뉴 상태 저장
  function saveMenuState(state) {
    localStorage.setItem('office_menu_state', JSON.stringify(state));
  }

  // 메뉴 항목 표시/숨김 처리
  function updateMenuItems(section, isExpanded) {
    const items = document.querySelectorAll(`a.menu-item[data-section-parent="${section}"]`);
    console.log(`Updating ${section}: found ${items.length} items, isExpanded: ${isExpanded}`);
    items.forEach(item => {
      item.setAttribute('data-section-collapsed', !isExpanded);
      // Edge 호환성: 직접 스타일 적용
      if (isExpanded) {
        item.style.display = '';
      } else {
        item.style.display = 'none';
      }
    });
  }

  // DOM 초기화 (저장된 상태 적용)
  function initializeMenu() {
    console.log('Initializing menu...');
    const state = getMenuState();
    console.log('Menu state:', state);

    Object.entries(state).forEach(([section, isExpanded]) => {
      const header = document.querySelector(`[data-section="${section}"]`);
      if (!header) {
        console.warn(`Header not found for section: ${section}`);
        return;
      }

      console.log(`Setting ${section}: data-collapsed = ${!isExpanded}`);
      header.setAttribute('data-collapsed', !isExpanded);

      // +/- 아이콘 업데이트
      const icon = header.querySelector('.menu-toggle-icon');
      if (icon) {
        icon.textContent = isExpanded ? '-' : '+';
      }

      updateMenuItems(section, isExpanded);
    });
  }

  // 섹션 헤더 클릭 이벤트
  document.addEventListener('click', (e) => {
    const header = e.target.closest('.menu-section-header');
    if (!header) return;

    const section = header.getAttribute('data-section');
    console.log(`Clicked section: ${section}`);

    const state = getMenuState();
    state[section] = !state[section];
    console.log(`Toggled ${section} to: ${state[section]}`);

    saveMenuState(state);
    header.setAttribute('data-collapsed', !state[section]);

    // +/- 아이콘 업데이트
    const icon = header.querySelector('.menu-toggle-icon');
    if (icon) {
      icon.textContent = state[section] ? '-' : '+';
    }

    updateMenuItems(section, state[section]);
  });

  // 페이지 로드 시 메뉴 상태 초기화
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeMenu);
  } else {
    initializeMenu();
  }
})();
</script>

<?php if (!empty($extra_js)): ?>
<script>
<?php echo $extra_js; ?>
</script>
<?php endif; ?>

<?php if (!empty($inline_script)): ?>
<script>
<?php echo $inline_script; ?>
</script>
<?php endif; ?>

</body>
</html>
<?php ob_end_flush(); ?>
