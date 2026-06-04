<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';
require_once 'vendor/gig_helper.php';

$res = $conn->query("SELECT * FROM manual_tasks ORDER BY id DESC LIMIT 1");
$task = $res->fetch_assoc();

if ($task) {
    echo "Processing Task ID " . $task['id'] . "<br>";
    $conn->query("DELETE FROM task_alerts WHERE task_id = " . $task['id']);
    $conn->query("UPDATE manual_tasks SET status = 'open' WHERE id = " . $task['id']);
    
    echo "Running Smart Broadcast...<br>";
    $bRes = runSmartBroadcast($conn, $task['id'], $task['category_id'], $task['subcategory_id'], $task['latitude'], $task['longitude']);
    print_r($bRes);
} else {
    echo "No task found.";
}
