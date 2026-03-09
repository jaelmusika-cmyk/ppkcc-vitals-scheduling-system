<?php if (isset($_GET['flash']) && isset($_SESSION['flash_message'])): ?>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    showToast("<?= $_SESSION['flash_message'] ?>", "", "<?= $_SESSION['flash_type'] ?? 'success' ?>");
  });
</script>
<?php 
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);
endif; 
?>


