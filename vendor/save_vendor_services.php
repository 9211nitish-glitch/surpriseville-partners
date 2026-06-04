<?php
// vendor/save_vendor_services.php
session_start();
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';        // $conn => vendor DB (mysqli)
require_once '../db_main.php';   // $mainConn => main DB (mysqli)

$vendor_id = (int)$_SESSION['vendor_id'];

$category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
$subcategory_id = isset($_POST['subcategory_id']) && $_POST['subcategory_id'] !== '' ? (int)$_POST['subcategory_id'] : null;
$services = $_POST['services'] ?? [];        // array of service IDs
$addons_input = $_POST['addons'] ?? [];     // addons[serviceId] = [addonId,...]

// Basic validation: ensure posted services belong to main DB (prevent spoofing)
$validServiceIds = [];
if (!empty($services) && is_array($services)) {
    // prepare dynamic query
    $placeholders = implode(',', array_fill(0, count($services), '?'));
    $types = str_repeat('i', count($services));
    $sql = "SELECT id FROM services WHERE id IN ($placeholders) AND status='active'";
    $stmt = $mainConn->prepare($sql);
    $stmt->bind_param($types, ...array_map('intval', $services));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $validServiceIds[] = (int)$r['id'];
    $stmt->close();
}

// Validate addons similarly: build a map service->valid addon ids
$validAddonsMap = [];
if (!empty($addons_input) && is_array($addons_input)) {
    foreach ($addons_input as $svcId => $addonArr) {
        $svcId = (int)$svcId;
        if (!is_array($addonArr) || empty($addonArr)) continue;
        $placeholders = implode(',', array_fill(0, count($addonArr), '?'));
        $types = str_repeat('i', count($addonArr));
        $sql = "SELECT a.id FROM service_addons sa JOIN addons a ON sa.addon_id = a.id WHERE sa.service_id = ? AND a.id IN ($placeholders)";
        // build params: first service id then addon ids
        $params = array_merge([$svcId], array_map('intval', $addonArr));
        $stmt = $mainConn->prepare($sql);
        // bind params dynamically
        $bindTypes = 'i' . $types;
        $stmt->bind_param($bindTypes, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $validAddonsMap[$svcId][] = (int)$r['id'];
        $stmt->close();
    }
}

// Begin transaction on vendor DB
$conn->begin_transaction();

try {
    // delete previous
    $stmt = $conn->prepare("DELETE FROM vendor_selected_categories WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id); $stmt->execute(); $stmt->close();

    $stmt = $conn->prepare("DELETE FROM vendor_selected_services WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id); $stmt->execute(); $stmt->close();

    $stmt = $conn->prepare("DELETE FROM vendor_selected_addons WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id); $stmt->execute(); $stmt->close();

    // insert category (if selected)
    if (!is_null($category_id)) {
        // ensure category exists in main DB
        $stmtc = $mainConn->prepare("SELECT id FROM categories WHERE id = ?");
        $stmtc->bind_param("i", $category_id);
        $stmtc->execute();
        $res = $stmtc->get_result();
        if ($res->fetch_assoc()) {
            $stmtc->close();
            $stmt = $conn->prepare("INSERT INTO vendor_selected_categories (vendor_id, category_id, subcategory_id) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $vendor_id, $category_id, $subcategory_id);
            $stmt->execute(); $stmt->close();
        } else {
            $stmtc->close();
            $category_id = null; // invalid -> ignore
        }
    }

    // insert services (only valid ones)
    if (!empty($validServiceIds)) {
        $insSvc = $conn->prepare("INSERT INTO vendor_selected_services (vendor_id, service_id) VALUES (?, ?)");
        foreach ($validServiceIds as $svcId) {
            $insSvc->bind_param("ii", $vendor_id, $svcId);
            $insSvc->execute();
        }
        $insSvc->close();
    }

    // insert addons from validated map
    if (!empty($validAddonsMap)) {
        $insAddon = $conn->prepare("INSERT INTO vendor_selected_addons (vendor_id, service_id, addon_id) VALUES (?, ?, ?)");
        foreach ($validAddonsMap as $svcId => $addonList) {
            foreach ($addonList as $aid) {
                $insAddon->bind_param("iii", $vendor_id, $svcId, $aid);
                $insAddon->execute();
            }
        }
        $insAddon->close();
    }

    $conn->commit();
    $_SESSION['flash_success'] = "Services updated successfully.";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['flash_error'] = "Failed to save services: " . $e->getMessage();
}

header("Location: services.php");
exit;
