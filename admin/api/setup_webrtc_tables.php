<?php
/**
 * admin/api/setup_webrtc_tables.php
 * ==================================
 * One-time migration: Ensures call_sessions and webrtc_signals tables exist.
 * Run once from browser: https://partners.surpriseville.co.in/admin/api/setup_webrtc_tables.php
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die(json_encode(['error' => 'Unauthorized']));
}

require_once __DIR__ . '/../../db_main.php';
header('Content-Type: application/json');

$results = [];

// 1. Ensure call_sessions table exists with all columns
$sql1 = "CREATE TABLE IF NOT EXISTS `call_sessions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`         INT UNSIGNED NOT NULL,
    `caller_type`      ENUM('admin','vendor','user') NOT NULL,
    `caller_id`        INT UNSIGNED NOT NULL,
    `callee_type`      ENUM('admin','vendor','user') NOT NULL,
    `callee_id`        INT UNSIGNED NOT NULL DEFAULT 0,
    `call_type`        ENUM('audio','video') NOT NULL DEFAULT 'audio',
    `status`           ENUM('ringing','active','ended','declined','missed') NOT NULL DEFAULT 'ringing',
    `sdp_offer`        MEDIUMTEXT NULL,
    `sdp_answer`       MEDIUMTEXT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `answered_at`      DATETIME NULL,
    `ended_at`         DATETIME NULL,
    `duration_seconds` INT UNSIGNED NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_order` (`order_id`),
    INDEX `idx_callee` (`callee_type`, `callee_id`, `status`),
    INDEX `idx_caller` (`caller_type`, `caller_id`),
    INDEX `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$r1 = $mainConn->query($sql1);
$results['call_sessions'] = $r1 ? 'OK' : ('ERROR: ' . $mainConn->error);

// 2. Ensure webrtc_signals table exists
$sql2 = "CREATE TABLE IF NOT EXISTS `webrtc_signals` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `call_session_id` INT UNSIGNED NOT NULL,
    `signal_type`     ENUM('offer','answer','ice','decline','end') NOT NULL,
    `payload`         MEDIUMTEXT NOT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_session` (`call_session_id`, `id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$r2 = $mainConn->query($sql2);
$results['webrtc_signals'] = $r2 ? 'OK' : ('ERROR: ' . $mainConn->error);

// 3. Try adding missing columns if tables already existed
$alterCols = [
    "ALTER TABLE call_sessions ADD COLUMN IF NOT EXISTS callee_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER caller_id",
    "ALTER TABLE call_sessions ADD COLUMN IF NOT EXISTS sdp_offer MEDIUMTEXT NULL AFTER status",
    "ALTER TABLE call_sessions ADD COLUMN IF NOT EXISTS sdp_answer MEDIUMTEXT NULL AFTER sdp_offer",
    "ALTER TABLE call_sessions ADD COLUMN IF NOT EXISTS answered_at DATETIME NULL AFTER created_at",
    "ALTER TABLE call_sessions ADD COLUMN IF NOT EXISTS ended_at DATETIME NULL AFTER answered_at",
    "ALTER TABLE call_sessions ADD COLUMN IF NOT EXISTS duration_seconds INT UNSIGNED NULL DEFAULT 0 AFTER ended_at",
];

foreach ($alterCols as $sql) {
    $mainConn->query($sql); // Ignore errors (column may already exist)
}

// 4. Verify current schema
$schemaCheck = $mainConn->query("DESCRIBE call_sessions");
$schema = [];
if ($schemaCheck) {
    while ($row = $schemaCheck->fetch_assoc()) {
        $schema[] = $row;
    }
}
$results['call_sessions_schema'] = $schema;

$schemaCheck2 = $mainConn->query("DESCRIBE webrtc_signals");
$schema2 = [];
if ($schemaCheck2) {
    while ($row = $schemaCheck2->fetch_assoc()) {
        $schema2[] = $row;
    }
}
$results['webrtc_signals_schema'] = $schema2;

// 5. Recent call sessions
$recent = $mainConn->query("SELECT id, order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, created_at FROM call_sessions ORDER BY id DESC LIMIT 10");
$rows = [];
if ($recent) {
    while ($row = $recent->fetch_assoc()) $rows[] = $row;
}
$results['recent_call_sessions'] = $rows;

echo json_encode($results, JSON_PRETTY_PRINT);
