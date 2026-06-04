<?php
/**
 * Video Upload Handler for Decorator Videos
 * Handles validation, storage, and database tracking
 */

class DecoratorVideoUploader {
    private $db;
    private $upload_dir;
    private $allowed_extensions = ['mp4', 'webm', 'mov', 'avi', 'mkv'];
    private $max_file_size = 500 * 1024 * 1024; // 500MB
    private $max_videos_per_order = 3; // before, after, selfie
    
    public function __construct($db, $base_upload_dir = null) {
        $this->db = $db;
        if ($base_upload_dir === null) {
            $base_upload_dir = __DIR__ . '/../../uploads';
        }
        $this->upload_dir = $base_upload_dir . '/decorator-videos';
        $this->ensureDirectoryExists();
    }
    
    /**
     * Ensure upload directory exists
     */
    private function ensureDirectoryExists() {
        if (!is_dir($this->upload_dir)) {
            @mkdir($this->upload_dir, 0755, true);
        }
    }
    
    /**
     * Upload video file
     * 
     * @param array $file $_FILES array element
     * @param int $vendor_id
     * @param int $order_id
     * @param string $video_type 'before', 'after', or 'selfie'
     * @return array ['success' => bool, 'message' => string, 'file_path' => string]
     */
    public function uploadVideo($file, $vendor_id, $order_id, $video_type = 'after') {
        // Validate video type
        if (!in_array($video_type, ['before', 'after', 'selfie'])) {
            return ['success' => false, 'message' => 'Invalid video type'];
        }
        
        // Validate file
        $validation = $this->validateFile($file);
        if (!$validation['success']) {
            return $validation;
        }
        
        // Check existing videos for this order
        $existing = $this->db->prepare("
            SELECT id FROM decorator_videos 
            WHERE vendor_id = ? AND order_id = ?
        ");
        $existing->bind_param("ii", $vendor_id, $order_id);
        $existing->execute();
        
        if ($existing->get_result()->num_rows === 0) {
            // Create new video record
            $this->createVideoRecord($vendor_id, $order_id);
        }
        
        // Generate unique filename
        $filename = $this->generateFileName($vendor_id, $video_type, $file);
        $file_path = $this->upload_dir . '/vendor-' . $vendor_id . '/' . $filename;
        
        // Create vendor directory
        $vendor_dir = $this->upload_dir . '/vendor-' . $vendor_id;
        if (!is_dir($vendor_dir)) {
            @mkdir($vendor_dir, 0755, true);
        }
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            return ['success' => false, 'message' => 'Failed to save video file'];
        }
        
        // Update database
        $update = $this->updateVideoRecord($vendor_id, $order_id, $video_type, '/uploads/decorator-videos/vendor-' . $vendor_id . '/' . $filename);
        
        if (!$update['success']) {
            @unlink($file_path);
            return $update;
        }
        
        return [
            'success' => true,
            'message' => ucfirst($video_type) . ' video uploaded successfully',
            'file_path' => '/uploads/decorator-videos/vendor-' . $vendor_id . '/' . $filename,
            'video_id' => $update['video_id']
        ];
    }
    
    /**
     * Validate uploaded file
     * 
     * @param array $file
     * @return array
     */
    private function validateFile($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload error: ' . $this->getUploadErrorMessage($file['error'])];
        }
        
        // Check file size
        if ($file['size'] > $this->max_file_size) {
            return ['success' => false, 'message' => 'File size exceeds 500MB limit'];
        }
        
        // Check file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowed_extensions)) {
            return ['success' => false, 'message' => 'Invalid file format. Allowed: ' . implode(', ', $this->allowed_extensions)];
        }
        
        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
        if (!in_array($mime_type, $allowed_mimes)) {
            return ['success' => false, 'message' => 'Invalid video MIME type'];
        }
        
        return ['success' => true];
    }
    
    /**
     * Generate unique filename
     * 
     * @param int $vendor_id
     * @param string $video_type
     * @param array $file
     * @return string
     */
    private function generateFileName($vendor_id, $video_type, $file) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $timestamp = time();
        $random = substr(md5(uniqid()), 0, 8);
        
        return "order-{$video_type}-{$timestamp}-{$random}.{$ext}";
    }
    
    /**
     * Create new video record
     * 
     * @param int $vendor_id
     * @param int $order_id
     * @return bool
     */
    private function createVideoRecord($vendor_id, $order_id) {
        $stmt = $this->db->prepare("
            INSERT INTO decorator_videos (vendor_id, order_id, video_status)
            VALUES (?, ?, 'pending')
        ");
        
        $stmt->bind_param("ii", $vendor_id, $order_id);
        return $stmt->execute();
    }
    
    /**
     * Update video record with file path
     * 
     * @param int $vendor_id
     * @param int $order_id
     * @param string $video_type
     * @param string $file_path
     * @return array
     */
    private function updateVideoRecord($vendor_id, $order_id, $video_type, $file_path) {
        $column = ($video_type === 'selfie') ? 'vendor_selfie_url' : $video_type . '_video_url';
        
        $stmt = $this->db->prepare("
            UPDATE decorator_videos 
            SET $column = ?, updated_at = CURRENT_TIMESTAMP
            WHERE vendor_id = ? AND order_id = ?
        ");
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error: ' . $this->db->error];
        }
        
        $stmt->bind_param("sii", $file_path, $vendor_id, $order_id);
        if (!$stmt->execute()) {
            return ['success' => false, 'message' => 'Failed to update video record'];
        }
        
        // Get the video ID
        $query = $this->db->prepare("
            SELECT id FROM decorator_videos 
            WHERE vendor_id = ? AND order_id = ?
        ");
        $query->bind_param("ii", $vendor_id, $order_id);
        $query->execute();
        $result = $query->get_result()->fetch_assoc();
        
        return [
            'success' => true,
            'video_id' => $result['id'] ?? null
        ];
    }
    
    /**
     * Get upload error message
     * 
     * @param int $error
     * @return string
     */
    private function getUploadErrorMessage($error) {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
                return 'File exceeds upload_max_filesize';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds form MAX_FILE_SIZE';
            case UPLOAD_ERR_PARTIAL:
                return 'File only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            default:
                return 'Unknown upload error';
        }
    }
    
    /**
     * Approve video (admin action)
     * 
     * @param int $video_id
     * @param int $admin_id
     * @param string $approval_notes
     * @return array
     */
    public function approveVideo($video_id, $admin_id, $approval_notes = null) {
        $stmt = $this->db->prepare("
            UPDATE decorator_videos
            SET video_status = 'approved', 
                approved_by_admin_id = ?,
                approved_at = CURRENT_TIMESTAMP,
                approval_notes = ?
            WHERE id = ?
        ");
        
        $stmt->bind_param("isi", $admin_id, $approval_notes, $video_id);
        if ($stmt->execute()) {
            // Add to portfolio
            $this->addToPortfolio($video_id);
            return ['success' => true, 'message' => 'Video approved'];
        }
        
        return ['success' => false, 'message' => 'Failed to approve video'];
    }
    
    /**
     * Reject video (admin action)
     * 
     * @param int $video_id
     * @param int $admin_id
     * @param string $rejection_notes
     * @return array
     */
    public function rejectVideo($video_id, $admin_id, $rejection_notes = null) {
        $stmt = $this->db->prepare("
            UPDATE decorator_videos
            SET video_status = 'rejected',
                approved_by_admin_id = ?,
                approved_at = CURRENT_TIMESTAMP,
                approval_notes = ?
            WHERE id = ?
        ");
        
        $stmt->bind_param("isi", $admin_id, $rejection_notes, $video_id);
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Video rejected'];
        }
        
        return ['success' => false, 'message' => 'Failed to reject video'];
    }
    
    /**
     * Add approved video to vendor's portfolio
     * 
     * @param int $video_id
     * @return bool
     */
    private function addToPortfolio($video_id) {
        // Get video details
        $query = $this->db->prepare("
            SELECT vendor_id, before_video_url, after_video_url, vendor_selfie_url
            FROM decorator_videos
            WHERE id = ?
        ");
        $query->bind_param("i", $video_id);
        $query->execute();
        $video = $query->get_result()->fetch_assoc();
        
        if (!$video) return false;
        
        // Check if already in portfolio
        $check = $this->db->prepare("SELECT id FROM decorator_video_portfolio WHERE video_id = ?");
        $check->bind_param("i", $video_id);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            return true; // Already in portfolio
        }
        
        // Add to portfolio
        $stmt = $this->db->prepare("
            INSERT INTO decorator_video_portfolio (
                vendor_id, video_id, 
                before_video_url, after_video_url, vendor_selfie_url,
                display_order
            ) VALUES (?, ?, ?, ?, ?, (SELECT COUNT(*) + 1 FROM decorator_video_portfolio WHERE vendor_id = ?))
        ");
        
        $stmt->bind_param(
            "iissssi",
            $video['vendor_id'], $video_id,
            $video['before_video_url'], $video['after_video_url'], $video['vendor_selfie_url'],
            $video['vendor_id']
        );
        
        return $stmt->execute();
    }
    
    /**
     * Get all videos for a vendor
     * 
     * @param int $vendor_id
     * @param string $status 'all', 'pending', 'approved', 'rejected'
     * @return array
     */
    public function getVendorVideos($vendor_id, $status = 'all') {
        $query = "
            SELECT dv.*
            FROM decorator_videos dv
            WHERE dv.vendor_id = ?
        ";
        
        if ($status !== 'all') {
            $query .= " AND dv.video_status = ?";
        }
        
        $query .= " ORDER BY dv.uploaded_at DESC";
        
        if ($status === 'all') {
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("i", $vendor_id);
        } else {
            $stmt = $this->db->prepare($query);
            $stmt->bind_param("is", $vendor_id, $status);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $videos = [];
        while ($row = $result->fetch_assoc()) {
            $videos[] = $row;
        }
        
        return $videos;
    }
    
    /**
     * Get pending videos for admin approval
     * 
     * @param int $limit
     * @return array
     */
    public function getPendingVideos($limit = 50) {
        $query = $this->db->prepare("
            SELECT dv.*, v.name, v.business_name
            FROM decorator_videos dv
            JOIN vendors v ON dv.vendor_id = v.id
            WHERE dv.video_status = 'pending'
            ORDER BY dv.uploaded_at ASC
            LIMIT ?
        ");
        
        $query->bind_param("i", $limit);
        $query->execute();
        $result = $query->get_result();
        
        $videos = [];
        while ($row = $result->fetch_assoc()) {
            $videos[] = $row;
        }
        
        return $videos;
    }
}

?>
