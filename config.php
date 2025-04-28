<?php
$db_host = 'localhost';
$db_name = 'database_name';
$db_user = 'database_user';
$db_pass = 'database_password';

// ایجاد ارتباط با دیتابیس
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($mysqli->connect_error) {
    die('خطای ارتباط با دیتابیس: ' . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4"); 