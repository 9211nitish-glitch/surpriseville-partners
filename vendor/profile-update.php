<?php
session_start();
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../db.php";        // vendor DB
require_once "../db_main.php";   // main DB (not required here but kept if needed)

$vendor_id = (int)$_SESSION['vendor_id'];

/* ------------------------------------------------------
   CLEAN HELPER FOR FILE UPLOAD
------------------------------------------------------ */
function uploadFile($fieldName, $folder = "../uploads/kyc/") {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== 0) {
        return null;
    }

    if (!is_dir($folder)) mkdir($folder, 0777, true);

    $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION);
    $filename = $folder . uniqid("kyc_") . "." . $ext;

    if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $filename)) {
        return $filename;
    }
    return null;
}

/* ------------------------------------------------------
   GET FORM DATA
------------------------------------------------------ */
$name           = $_POST['name'] ?? '';
$business_name  = $_POST['business_name'] ?? '';
$email          = $_POST['email'] ?? '';
$phone          = $_POST['phone'] ?? '';
$city           = $_POST['city'] ?? '';
$aadhaar_number = $_POST['aadhaar_number'] ?? '';



/* ------------------------------------------------------
   FILE UPLOADS
------------------------------------------------------ */
$aadhaar_front = uploadFile("aadhaar_front");
$aadhaar_back  = uploadFile("aadhaar_back");

/* ------------------------------------------------------
   BEGIN UPDATE PROCESS
------------------------------------------------------ */
$conn->begin_transaction();

try {

    /* ------------------------------------------------------
       UPDATE BASIC INFO (VENDORS TABLE)
    ------------------------------------------------------ */
    $sql = "UPDATE vendors SET 
                name=?, business_name=?, email=?, phone=?, city=?, 
                aadhaar_number=?";

    // If new Aadhaar uploaded → reset status to pending
    if ($aadhaar_front || $aadhaar_back) {
        $sql .= ", aadhaar_status='pending' ";
    }

    // Complete query build
    $sql .= " WHERE id=?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssssssi",
        $name, $business_name, $email, $phone, $city, $aadhaar_number,
        $vendor_id
    );
    $stmt->execute();
    $stmt->close();

    /* ------------------------------------------------------
       UPDATE AADHAAR FILE PATHS
    ------------------------------------------------------ */
    if ($aadhaar_front) {
        $stmt = $conn->prepare("UPDATE vendors SET aadhaar_front=? WHERE id=?");
        $stmt->bind_param("si", $aadhaar_front, $vendor_id);
        $stmt->execute();
        $stmt->close();
    }

    if ($aadhaar_back) {
        $stmt = $conn->prepare("UPDATE vendors SET aadhaar_back=? WHERE id=?");
        $stmt->bind_param("si", $aadhaar_back, $vendor_id);
        $stmt->execute();
        $stmt->close();
    }



    /* ------------------------------------------------------
       FINISH
    ------------------------------------------------------ */
    $conn->commit();

    $_SESSION['success_msg'] = "Profile updated successfully!";
    header("Location: profile.php");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    die("Error updating profile: " . $e->getMessage());
}

