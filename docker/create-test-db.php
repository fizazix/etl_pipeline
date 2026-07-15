<?php

$pdo = new PDO('mysql:host=mysql;port=3306', 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec('CREATE DATABASE IF NOT EXISTS etl_test');
$pdo->exec("GRANT ALL PRIVILEGES ON etl_test.* TO 'etl'@'%'");
$pdo->exec('FLUSH PRIVILEGES');
