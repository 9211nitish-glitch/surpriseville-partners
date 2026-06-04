<?php
/**
 * Order Management System
 * Handles unified offline + online orders with broadcast capability
 */

class OrderManagementSystem {
    private $db;
    private $admin_id;
    
    public function __construct($db, $admin_id = null) {
        $this->db = $db;
        $this->admin_id = $admin_id;
    }
    
    /**
     * Create offline order
     * 
     * @param array $order_data ['event_name', 'event_date', 'location', 'budget', 'notes', etc]
     * @return array ['success' => bool, 'order_id' => int, 'message' => string]
     */
    public function createOfflineOrder($order_data) {
        try {
            // Validate required fields
            $required = ['event_name', 'event_date', 'location'];
            foreach ($required as $field) {
                if (empty($order_data[$field])) {
                    return ['success' => false, 'message' => "Missing required field: $field"];
                }
            }
            
            // Prepare INSERT
            $stmt = $this->db->prepare("
                INSERT INTO orders (
                    order_source,
                    event_name,
                    event_date,
                    location,
                    budget,
                    description,
                    broadcast_status,
                    posted_by_admin_id,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $order_source = 'offline';
            $event_name = $order_data['event_name'];
            $event_date = $order_data['event_date'];
            $location = $order_data['location'];
            $budget = floatval($order_data['budget'] ?? 0);
            $description = $order_data['notes'] ?? '';
            $broadcast_status = 'draft'; // Start as draft
            $status = 'pending';
            
            $stmt->bind_param(
                "ssssdsiis",
                $order_source, $event_name, $event_date, $location, $budget,
                $description, $broadcast_status, $this->admin_id, $status
            );
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'order_id' => $stmt->insert_id,
                    'message' => 'Offline order created successfully'
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to create order'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Broadcast order to multiple vendors
     * 
     * @param int $order_id
     * @param array $vendor_ids Array of vendor IDs to notify
     * @param string $broadcast_type 'manual' or 'auto'
     * @return array
     */
    public function broadcastOrder($order_id, $vendor_ids, $broadcast_type = 'manual') {
        try {
            if (empty($vendor_ids)) {
                return ['success' => false, 'message' => 'No vendors selected'];
            }
            
            $this->db->begin_transaction();
            
            try {
                // Update order status
                $stmt = $this->db->prepare("
                    UPDATE orders
                    SET broadcast_status = 'broadcast', updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                
                // Create notifications for each vendor
                $notify_stmt = $this->db->prepare("
                    INSERT INTO order_vendor_notifications (
                        order_id, vendor_id, status, sent_at
                    ) VALUES (?, ?, 'pending', NOW())
                ");
                
                $success_count = 0;
                foreach ($vendor_ids as $vendor_id) {
                    $vendor_id = intval($vendor_id);
                    $notify_stmt->bind_param("ii", $order_id, $vendor_id);
                    if ($notify_stmt->execute()) {
                        $success_count++;
                    }
                }
                
                // Log broadcast history
                $history_stmt = $this->db->prepare("
                    INSERT INTO order_broadcast_history (
                        order_id, broadcast_type, broadcast_to_vendor_ids,
                        posted_by_admin_id, vendor_count, broadcast_status
                    ) VALUES (?, ?, ?, ?, ?, 'sent')
                ");
                
                $vendor_ids_json = json_encode($vendor_ids);
                $history_stmt->bind_param(
                    "issii",
                    $order_id, $broadcast_type, $vendor_ids_json,
                    $this->admin_id, $success_count
                );
                $history_stmt->execute();
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => "Order broadcast to $success_count vendor(s)",
                    'vendors_notified' => $success_count
                ];
                
            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Repost an order as broadcast
     * 
     * @param int $order_id
     * @param array $vendor_ids
     * @return array
     */
    public function repostOrder($order_id, $vendor_ids) {
        // Reset notifications and rebroadcast
        $del_stmt = $this->db->prepare("
            DELETE FROM order_vendor_notifications
            WHERE order_id = ? AND status = 'pending'
        ");
        $del_stmt->bind_param("i", $order_id);
        $del_stmt->execute();
        
        return $this->broadcastOrder($order_id, $vendor_ids, 'repost');
    }
    
    /**
     * Get all orders (both online and offline)
     * 
     * @param string $source 'all', 'online', 'offline'
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAllOrders($source = 'all', $limit = 50, $offset = 0) {
        $query = "
            SELECT 
                o.*,
                (SELECT COUNT(*) FROM order_vendor_notifications WHERE order_id = o.id) as vendor_notifications_count,
                (SELECT COUNT(*) FROM order_vendor_notifications WHERE order_id = o.id AND status = 'accepted') as accepted_count,
                CASE 
                    WHEN o.assigned_vendor_id IS NOT NULL THEN (SELECT name FROM vendors WHERE id = o.assigned_vendor_id)
                    ELSE NULL
                END as assigned_vendor_name
            FROM orders o
            WHERE 1=1
        ";
        
        if ($source !== 'all') {
            $query .= " AND o.order_source = '$source'";
        }
        
        $query .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        
        return $orders;
    }
    
    /**
     * Get order details with notifications
     * 
     * @param int $order_id
     * @return array|null
     */
    public function getOrderDetails($order_id) {
        $stmt = $this->db->prepare("
            SELECT 
                o.*,
                (SELECT COUNT(*) FROM order_vendor_notifications WHERE order_id = o.id) as total_notifications,
                (SELECT COUNT(*) FROM order_vendor_notifications WHERE order_id = o.id AND status = 'accepted') as accepted_count
            FROM orders o
            WHERE o.id = ?
        ");
        
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return null;
        }
        
        $order = $result->fetch_assoc();
        
        // Get vendor notifications
        $notif_stmt = $this->db->prepare("
            SELECT 
                ovn.*,
                v.name, v.business_name, v.email
            FROM order_vendor_notifications ovn
            JOIN vendors v ON ovn.vendor_id = v.id
            WHERE ovn.order_id = ?
            ORDER BY ovn.sent_at DESC
        ");
        
        $notif_stmt->bind_param("i", $order_id);
        $notif_stmt->execute();
        $notif_result = $notif_stmt->get_result();
        
        $order['vendor_notifications'] = [];
        while ($row = $notif_result->fetch_assoc()) {
            $order['vendor_notifications'][] = $row;
        }
        
        return $order;
    }
    
    /**
     * Get broadcast history for an order
     * 
     * @param int $order_id
     * @return array
     */
    public function getBroadcastHistory($order_id) {
        $stmt = $this->db->prepare("
            SELECT *
            FROM order_broadcast_history
            WHERE order_id = ?
            ORDER BY created_at DESC
        ");
        
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $row['broadcast_to_vendor_ids'] = json_decode($row['broadcast_to_vendor_ids'], true);
            $history[] = $row;
        }
        
        return $history;
    }
    
    /**
     * Get statistics
     * 
     * @return array
     */
    public function getStats() {
        $result = $this->db->query("
            SELECT 
                (SELECT COUNT(*) FROM orders WHERE order_source = 'online') as online_orders,
                (SELECT COUNT(*) FROM orders WHERE order_source = 'offline') as offline_orders,
                (SELECT COUNT(*) FROM orders WHERE status = 'pending') as pending_orders,
                (SELECT COUNT(*) FROM orders WHERE status = 'accepted') as accepted_orders,
                (SELECT COUNT(*) FROM orders WHERE broadcast_status = 'broadcast') as broadcast_orders,
                (SELECT COUNT(*) FROM orders WHERE broadcast_status = 'draft') as draft_orders
        ");
        
        return $result->fetch_assoc();
    }
}

?>
