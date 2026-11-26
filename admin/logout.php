<?php
require __DIR__ . '/config.php';
if ($u = current_admin()) log_activity($u['email'], 'logout', '');
session_destroy();
header('Location: login.php');
