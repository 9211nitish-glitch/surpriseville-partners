<?php
/**
 * Decorator Ranking System - Core Functions
 * 
 * Handles ranking calculations, medal assignments, and rating updates
 */

// Database connection
require_once __DIR__ . '/../db.php';

class DecoratorRankingSystem {
    private $db;
    private $admin_id;
    
    // Point limits
    const MAX_CLIENT_SATISFACTION = 2.00;
    const MAX_VIDEO_REVIEW = 1.00;
    const MAX_GROOMING = 1.00;
    const MAX_COMPLETION_TIME = 1.00;
    const MAX_TOTAL_POINTS = 5.00;
    
    // Medal tiers
    const MEDAL_GOLD = 'gold';
    const MEDAL_SILVER = 'silver';
    const MEDAL_BRONZE = 'bronze';
    const MEDAL_NONE = 'none';
    
    public function __construct($db, $admin_id = null) {
        $this->db = $db;
        $this->admin_id = $admin_id;
    }
    
    /**
     * Assign points to a decorator for a specific order
     * 
     * @param int $vendor_id
     * @param int $order_id
     * @param float $client_satisfaction (0-2)
     * @param float $video_review (0-1)
     * @param float $grooming (0-1)
     * @param float $completion_time (0-1)
     * @param string $comments Optional comments
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function assignPoints($vendor_id, $order_id, $client_satisfaction = 0, $video_review = 0, $grooming = 0, $completion_time = 0, $comments = null) {
        try {
            // Validate inputs
            if (!is_numeric($vendor_id) || $vendor_id <= 0) {
                return ['success' => false, 'message' => 'Invalid vendor ID'];
            }
            
            // Validate and cap points
            $client_satisfaction = $this->validatePoints($client_satisfaction, self::MAX_CLIENT_SATISFACTION);
            $video_review = $this->validatePoints($video_review, self::MAX_VIDEO_REVIEW);
            $grooming = $this->validatePoints($grooming, self::MAX_GROOMING);
            $completion_time = $this->validatePoints($completion_time, self::MAX_COMPLETION_TIME);
            
            $total_points = $client_satisfaction + $video_review + $grooming + $completion_time;
            
            // Check if vendor exists
            $vendor_check = $this->db->prepare("SELECT id FROM vendors WHERE id = ?");
            $vendor_check->bind_param("i", $vendor_id);
            $vendor_check->execute();
            if ($vendor_check->get_result()->num_rows === 0) {
                return ['success' => false, 'message' => 'Vendor not found'];
            }
            
            // Start transaction
            $this->db->begin_transaction();
            
            try {
                // Get previous points for audit
                $prev_query = $this->db->prepare("SELECT total_points FROM decorator_rankings WHERE vendor_id = ?");
                $prev_query->bind_param("i", $vendor_id);
                $prev_query->execute();
                $prev_result = $prev_query->get_result()->fetch_assoc();
                $prev_points = $prev_result ? $prev_result['total_points'] : 0;
                
                // Insert rating record
                $rating_stmt = $this->db->prepare("
                    INSERT INTO decorator_ratings (
                        vendor_id, order_id, client_satisfaction_points, 
                        video_review_points, grooming_points, completion_time_points,
                        total_rating_points, rated_by_admin_id, comments
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $rating_stmt->bind_param(
                    "iidddddis",
                    $vendor_id, $order_id, $client_satisfaction,
                    $video_review, $grooming, $completion_time,
                    $total_points, $this->admin_id, $comments
                );
                $rating_stmt->execute();
                $rating_id = $rating_stmt->insert_id;
                
                // Update or insert ranking record
                $rank_stmt = $this->db->prepare("
                    INSERT INTO decorator_rankings (
                        vendor_id, total_points, client_satisfaction_points,
                        video_review_points, grooming_points, completion_time_points,
                        total_ratings_count
                    ) VALUES (?, ?, ?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                        total_points = total_points + ?,
                        client_satisfaction_points = client_satisfaction_points + ?,
                        video_review_points = video_review_points + ?,
                        grooming_points = grooming_points + ?,
                        completion_time_points = completion_time_points + ?,
                        total_ratings_count = total_ratings_count + 1,
                        updated_at = CURRENT_TIMESTAMP
                ");
                
                $rank_stmt->bind_param(
                    "idddddddddd",
                    $vendor_id, $total_points, $client_satisfaction,
                    $video_review, $grooming, $completion_time,
                    $total_points, $client_satisfaction, $video_review,
                    $grooming, $completion_time
                );
                $rank_stmt->execute();
                
                // Recalculate medals
                $this->recalculateMedals();
                
                // Log to audit
                $this->logAudit($vendor_id, $order_id, 'points_assigned', $prev_points, $prev_points + $total_points, 'Admin assigned points');
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => 'Points assigned successfully',
                    'data' => [
                        'rating_id' => $rating_id,
                        'vendor_id' => $vendor_id,
                        'points_awarded' => $total_points,
                        'breakdown' => [
                            'client_satisfaction' => $client_satisfaction,
                            'video_review' => $video_review,
                            'grooming' => $grooming,
                            'completion_time' => $completion_time
                        ]
                    ]
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
     * Get decorator ranking with position
     * 
     * @param int $vendor_id
     * @return array Ranking data with position
     */
    public function getDecoratorRanking($vendor_id) {
        $query = $this->db->prepare("
            SELECT 
                dr.*,
                v.name,
                v.business_name,
                v.email,
                v.city,
                (SELECT COUNT(*) + 1 FROM decorator_rankings WHERE total_points > dr.total_points) as ranking_position
            FROM decorator_rankings dr
            JOIN vendors v ON dr.vendor_id = v.id
            WHERE dr.vendor_id = ?
        ");
        
        $query->bind_param("i", $vendor_id);
        $query->execute();
        return $query->get_result()->fetch_assoc();
    }
    
    /**
     * Get top N decorators
     * 
     * @param int $limit Default 10
     * @return array List of top decorators
     */
    public function getTopDecorators($limit = 10) {
        $query = $this->db->prepare("
            SELECT 
                dr.*,
                v.name,
                v.business_name,
                v.email,
                v.city,
                (SELECT COUNT(*) + 1 FROM decorator_rankings WHERE total_points > dr.total_points) as ranking_position,
                (SELECT COUNT(*) FROM decorator_videos WHERE vendor_id = v.id AND video_status = 'approved') as approved_videos_count
            FROM decorator_rankings dr
            JOIN vendors v ON dr.vendor_id = v.id
            WHERE v.status = 'active'
            ORDER BY dr.total_points DESC, dr.updated_at DESC
            LIMIT ?
        ");
        
        $query->bind_param("i", $limit);
        $query->execute();
        $result = $query->get_result();
        
        $decorators = [];
        while ($row = $result->fetch_assoc()) {
            $decorators[] = $row;
        }
        return $decorators;
    }
    
    /**
     * Get all decorators with rankings
     * 
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAllDecoratorRankings($limit = 50, $offset = 0) {
        $query = $this->db->prepare("
            SELECT 
                dr.*,
                v.name,
                v.business_name,
                v.email,
                v.city,
                (SELECT COUNT(*) + 1 FROM decorator_rankings WHERE total_points > dr.total_points) as ranking_position,
                (SELECT COUNT(*) FROM decorator_videos WHERE vendor_id = v.id AND video_status = 'approved') as approved_videos_count
            FROM decorator_rankings dr
            JOIN vendors v ON dr.vendor_id = v.id
            WHERE v.status = 'active'
            ORDER BY dr.total_points DESC
            LIMIT ? OFFSET ?
        ");
        
        $query->bind_param("ii", $limit, $offset);
        $query->execute();
        $result = $query->get_result();
        
        $decorators = [];
        while ($row = $result->fetch_assoc()) {
            $decorators[] = $row;
        }
        return $decorators;
    }
    
    /**
     * Recalculate medal tiers based on rankings
     */
    public function recalculateMedals() {
        // Get total number of active decorators
        $count_query = $this->db->query("SELECT COUNT(*) as total FROM decorator_rankings WHERE vendor_id IN (SELECT id FROM vendors WHERE status = 'active')");
        $count_result = $count_query->fetch_assoc();
        $total = $count_result['total'];
        
        if ($total === 0) return;
        
        // Calculate tier cutoffs (Top 10 = Gold)
        $gold_cutoff = min(10, max(1, ceil($total * 0.1)));
        $silver_cutoff = min(20, max(1, ceil($total * 0.2)));
        
        // Update medals
        $this->db->query("UPDATE decorator_rankings SET medal_tier = 'none' WHERE vendor_id IN (SELECT id FROM vendors WHERE status = 'active')");
        
        // Assign gold
        $this->db->prepare("
            UPDATE decorator_rankings SET medal_tier = 'gold'
            WHERE vendor_id IN (SELECT id FROM vendors WHERE status = 'active')
            ORDER BY total_points DESC
            LIMIT ?
        ")->bind_param("i", $gold_cutoff)->execute();
        
        // Assign silver
        $this->db->prepare("
            UPDATE decorator_rankings SET medal_tier = 'silver'
            WHERE vendor_id IN (SELECT id FROM vendors WHERE status = 'active')
            AND medal_tier = 'none'
            ORDER BY total_points DESC
            LIMIT ?
        ")->bind_param("i", $silver_cutoff)->execute();
        
        // Assign bronze to top 50
        $bronze_cutoff = 50;
        $this->db->prepare("
            UPDATE decorator_rankings SET medal_tier = 'bronze'
            WHERE vendor_id IN (SELECT id FROM vendors WHERE status = 'active')
            AND medal_tier = 'none'
            ORDER BY total_points DESC
            LIMIT ?
        ")->bind_param("i", $bronze_cutoff)->execute();
    }
    
    /**
     * Get rating history for a vendor
     * 
     * @param int $vendor_id
     * @param int $limit
     * @return array
     */
    public function getVendorRatingHistory($vendor_id, $limit = 20) {
        $query = $this->db->prepare("
            SELECT 
                dr.*,
                o.id as order_id,
                o.client_name as event_name,
                o.order_date as event_date,
                (SELECT COUNT(*) FROM decorator_videos WHERE order_id = dr.order_id AND video_status = 'approved') as has_videos
            FROM decorator_ratings dr
            LEFT JOIN manual_tasks o ON dr.order_id = o.id
            WHERE dr.vendor_id = ?
            ORDER BY dr.created_at DESC
            LIMIT ?
        ");
        
        $query->bind_param("ii", $vendor_id, $limit);
        $query->execute();
        
        $ratings = [];
        $result = $query->get_result();
        while ($row = $result->fetch_assoc()) {
            $ratings[] = $row;
        }
        return $ratings;
    }
    
    /**
     * Validate and cap points to max allowed
     * 
     * @param float $points
     * @param float $max
     * @return float Validated points
     */
    private function validatePoints($points, $max) {
        $points = floatval($points);
        if ($points < 0) $points = 0;
        if ($points > $max) $points = $max;
        return $points;
    }
    
    /**
     * Log audit trail
     * 
     * @param int $vendor_id
     * @param int $order_id
     * @param string $action
     * @param float $points_before
     * @param float $points_after
     * @param string $reason
     */
    private function logAudit($vendor_id, $order_id, $action, $points_before, $points_after, $reason) {
        $stmt = $this->db->prepare("
            INSERT INTO ranking_audit_log (vendor_id, order_id, action, points_before, points_after, changed_by_admin_id, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("iiisdis", $vendor_id, $order_id, $action, $points_before, $points_after, $this->admin_id, $reason);
        $stmt->execute();
    }
}

?>
