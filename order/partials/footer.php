        </div><!-- /px-6 py-5 -->
        </div><!-- /flex-1 overflow-y-auto -->
    </div><!-- /메인 콘텐츠 -->
</div><!-- /flex flex-col h-screen -->

<script>
function showFlash(type, message) {
    const colors = {
        success: 'bg-green-50 border-green-200 text-green-800',
        error:   'bg-red-50 border-red-200 text-red-800',
        warning: 'bg-yellow-50 border-yellow-200 text-yellow-800',
        info:    'bg-blue-50 border-blue-200 text-blue-800',
    };
    const el = document.createElement('div');
    el.className = `fixed top-4 right-4 z-50 px-4 py-3 rounded-lg border shadow-md text-sm flex items-center ${colors[type] || colors.info}`;
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}
</script>
</body>
</html>
