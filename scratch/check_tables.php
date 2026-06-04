<?php
require_once __DIR__ . '/../db_main.php';
$res = $mainConn->query("SHOW TABLES");
while ($row = $res->fetch_row()) {
    echo $row[0] . "\n";
}
