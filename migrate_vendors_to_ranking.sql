-- ============================================================
-- Vendor to Ranking Migration Script
-- ============================================================
-- This script initializes the decorator_rankings table
-- for all existing vendors with 0 points

-- STEP 1: Insert all existing vendors into decorator_rankings
INSERT INTO `decorator_rankings` (
  `vendor_id`,
  `total_points`,
  `client_satisfaction_points`,
  `video_review_points`,
  `grooming_points`,
  `completion_time_points`,
  `medal_tier`,
  `total_ratings_count`
)
SELECT 
  `id`,
  0.00,
  0.00,
  0.00,
  0.00,
  0.00,
  'none',
  0
FROM `vendors`
WHERE `id` NOT IN (SELECT DISTINCT `vendor_id` FROM `decorator_rankings`)
ON DUPLICATE KEY UPDATE 
  `total_points` = 0.00,
  `medal_tier` = 'none';

-- STEP 2: Set initial medals for all new entries (none)
-- Already handled in INSERT statement above

-- ============================================================
-- Migration Complete
-- ============================================================
-- Total vendors initialized: Check with:
-- SELECT COUNT(*) FROM decorator_rankings;
