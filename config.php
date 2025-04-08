<?php
$db_host = 'localhost'; // معمولا localhost
$db_name = 'db_name';
$db_user = 'db_user';
$db_pass = 'db_pass';

// ایجاد ارتباط با دیتابیس
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($mysqli->connect_error) {
    die('خطای ارتباط با دیتابیس: ' . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4"); 