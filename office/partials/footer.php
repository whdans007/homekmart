<?php
// Design Ref: §1.2 — CDN 스크립트 + 공통 JS
?>
</div><!-- /.container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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
