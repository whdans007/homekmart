            </main>
        </div>
    </div>
    
    <!-- Bootstrap JavaScript for modal support -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile menu toggle
        const menuButton = document.getElementById('mobile-menu-button');
        if (menuButton) {
            menuButton.addEventListener('click', function() {
                const mobileMenu = document.getElementById('mobile-menu');
                if (mobileMenu) {
                    mobileMenu.classList.toggle('hidden');
                }
            });
        }

        function switchAdminStore(storeId) {
            fetch('<?php echo $_admin_web_root; ?>/admin/ajax_switch_store.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'store_id=' + encodeURIComponent(storeId)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('점포 전환에 실패했습니다.');
                }
            })
            .catch(() => alert('점포 전환 중 오류가 발생했습니다.'));
        }
    </script>
</body>
</html>
<?php ob_end_flush(); // 출력 버퍼링을 종료하고 내용을 전송합니다. ?>