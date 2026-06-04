<?php
/**
 * Order Helpers for Vendor Portal
 * Cleaned and robustified.
 */

if (!function_exists('uploadFiles')) {
    function uploadFiles($files, $dest_dir)
    {
        $uploaded = [];
        $errors_found = [];

        if (!is_dir($dest_dir)) {
            mkdir($dest_dir, 0777, true);
        }

        // Normalize structure (handles both single and multiple via name="field[]")
        $names     = is_array($files['name'])     ? $files['name']     : [$files['name']];
        $errs      = is_array($files['error'])    ? $files['error']    : [$files['error']];
        $tmp_names = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];

        foreach ($names as $key => $name) {
            if (empty($name)) continue;

            $errorCode = $errs[$key];
            if ($errorCode === 0) {
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $newName = uniqid() . '.' . $ext;
                $target = rtrim($dest_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newName;

                if (move_uploaded_file($tmp_names[$key], $target)) {
                    $uploaded[] = $newName;
                } else {
                    $errors_found[] = "Failed to save $name. Check folder permissions.";
                }
            } else {
                // Map common PHP upload errors
                switch ($errorCode) {
                    case 1:
                    case 2: $errors_found[] = "File '$name' is too large. (Max 2MB per file)"; break;
                    case 3: $errors_found[] = "File '$name' was only partially uploaded."; break;
                    case 4: /* No file was uploaded - ignore if part of array */ break;
                    default: $errors_found[] = "Upload error ($errorCode) for file '$name'."; break;
                }
            }
        }

        return [
            'success' => count($uploaded) > 0,
            'files' => $uploaded,
            'errors' => $errors_found
        ];
    }
}

if (!function_exists('syncLegacy')) {
    function syncLegacy($mysql_conn, $oid, $type, $status, $timeCol, $locCol, $locVal, $proofCol = '', $proofVal = '')
    {
        $isMain      = ($type === 'decoration');
        $statusCol   = $isMain ? "vendor_status" : "addon_status";
        $timePrefix  = $isMain ? "" : "addon_";
        $locPrefix   = $isMain ? "vendor_loc_" : "addon_loc_";
        $proofPrefix = $isMain ? "" : "addon_";

        $sql = "UPDATE orders SET $statusCol = ?, " . ($timePrefix . $timeCol) . " = NOW(), " . ($locPrefix . $locCol) . " = ? ";
        
        // Keep the main order status in sync if this is the main service vendor
        if ($isMain) {
            $mainStatus = $status;
            if (in_array($status, ['out_for_service', 'reached', 'started'])) {
                $mainStatus = 'in_progress';
            }
            $sql .= ", status = '$mainStatus' ";
        }
        
        if ($proofCol) {
            $column = $proofPrefix . $proofCol;
            $sql .= ", $column = ? WHERE id = ?";
            $st = $mysql_conn->prepare($sql);
            $st->bind_param("sssi", $status, $locVal, $proofVal, $oid);
        } else {
            $sql .= " WHERE id = ?";
            $st = $mysql_conn->prepare($sql);
            $st->bind_param("ssi", $status, $locVal, $oid);
        }
        
        $st->execute();
        $st->close();
    }
}
