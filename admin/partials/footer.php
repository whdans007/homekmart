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
    </script>
</body>
</html>
<?php ob_end_flush(); // 출력 버퍼링을 종료하고 내용을 전송합니다. ?>