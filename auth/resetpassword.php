<?php

/**
 * auth/resetpassword.php
 *
 * Legacy route kept only so old bookmarks and previously e-mailed setup links
 * do not 404. Password resets now e-mail a temporary password from
 * auth/forgotpassword.php.
 */

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: forgotpassword.php');
exit();
