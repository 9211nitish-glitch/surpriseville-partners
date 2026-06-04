<?php
$mainConn = new mysqli(
    "swift.herosite.pro",
    "surpriseville_emp",
    "Sv@123@4567",
    "surpriseville_emp"
);

if ($mainConn->connect_error) {
    die("Main DB Connection Failed: " . $mainConn->connect_error);
}
$mainConn->set_charset("utf8mb4");
