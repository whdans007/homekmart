                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 언어 전환
        const languageSwitcher = document.getElementById('language-switcher');
        if (languageSwitcher) {
            languageSwitcher.addEventListener('change', function() {
                fetch('ajax_set_language.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'language=' + encodeURIComponent(this.value)
                })
                .then(r => r.json())
                .then(d => { if (d.success) { window.location.reload(); } })
                .catch(() => {});
            });
        }

        // 메뉴 토글 (데스크톱 사이드바 / 모바일 메뉴)
        const toggle = document.getElementById('unified-menu-toggle');
        const sidebar = document.getElementById('desktop-sidebar');
        const mobileMenu = document.getElementById('mobile-menu');
        if (toggle) {
            toggle.addEventListener('click', function() {
                if (window.innerWidth >= 768) {
                    if (sidebar) {
                        sidebar.classList.toggle('md:hidden');
                        sidebar.classList.toggle('md:flex');
                    }
                } else if (mobileMenu) {
                    mobileMenu.classList.toggle('hidden');
                }
            });
        }
    });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>
