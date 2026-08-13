<?php
session_unset();
session_destroy();
<<<<<<< HEAD

// Go back to login page
header("Location: ../login.php");
exit();
?>
=======
header('Location: ../index.php?logout=1');
exit;
>>>>>>> 09fb0676c4a704c23e037e9d6e4464d2bf05591c
