<?php
session_unset();
session_destroy();

// Go back to login page
header("Location: ../login.php");
exit();
?>
=======
header('Location: ../index.php?logout=1');
exit;