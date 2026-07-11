<?php
require_once __DIR__ . '/auth.php';

logout_user();
redirect_to('user-login.php');
?>
