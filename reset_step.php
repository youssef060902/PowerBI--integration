<?php
session_start();
$_SESSION['forgot_password'] = [
    'step' => 0,
    'code' => '',
    'email' => '',
    'timestamp' => 0,
    'attempts' => 0
];
?> 