<?php
$adminName = trim(($_SESSION['FirstName'] ?? '') . ' ' . ($_SESSION['LastName'] ?? ''));
$adminInitials = strtoupper(
    substr($_SESSION['FirstName'] ?? 'A', 0, 1) . substr($_SESSION['LastName'] ?? 'A', 0, 1)
);
?>
<div class="sidebar-footer">
  <div class="sidebar-user">
    <div class="user-avatar"><?php echo htmlspecialchars($adminInitials); ?></div>
    <div>
      <div class="user-name"><?php echo htmlspecialchars($adminName !== '' ? $adminName : 'Admin User'); ?></div>
      <div class="user-role">Admin</div>
    </div>
  </div>
  <button class="sign-out" type="button">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    Sign Out
  </button>
</div>