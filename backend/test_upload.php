<?php
// backend/test_upload.php
header('Content-Type: application/json');

$res = [
    'post' => $_POST,
    'files' => $_FILES,
    'upload_max' => ini_get('upload_max_filesize'),
    'post_max' => ini_get('post_max_size'),
    'temp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
    'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'php_version' => PHP_VERSION,
];

// Test writing to uploads
$test_file = '../uploads/proofs/test_write.txt';
if (file_put_contents($test_file, 'test write at ' . date('Y-m-d H:i:s'))) {
    $res['write_test'] = 'Success';
    unlink($test_file);
} else {
    $res['write_test'] = 'Failed: ' . (error_get_last()['message'] ?? 'Unknown Error');
}

echo json_encode($res);
