-- Sample Test Data for Vendor Portal
-- Use this to quickly test the system

-- Insert test vendors
INSERT INTO `vendors` (`name`, `business_name`, `email`, `phone`, `password`, `city`, `status`) VALUES
('Rajesh Kumar', 'Royal Events & Decorations', 'rajesh@example.com', '9876543210', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mumbai', 'active'),
('Priya Sharma', 'Priya Photography Studio', 'priya@example.com', '9876543211', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Delhi', 'active'),
('Amit Patel', 'Amit Catering Services', 'amit@example.com', '9876543212', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mumbai', 'active'),
('Sneha Reddy', 'Sneha DJ & Music', 'sneha@example.com', '9876543213', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bangalore', 'active'),
('Rahul Verma', 'Verma Venue Booking', 'rahul@example.com', '9876543214', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Delhi', 'active');

-- Link vendors to categories
INSERT INTO `vendor_categories` (`vendor_id`, `category_id`) VALUES
(1, 1), -- Rajesh - Decoration
(2, 2), -- Priya - Photography
(3, 3), -- Amit - Catering
(4, 4), -- Sneha - DJ/Music
(5, 5), -- Rahul - Venue
(1, 6), -- Rajesh - Gifts (additional)
(3, 1); -- Amit - Decoration (additional)

-- Create wallets for vendors
INSERT INTO `vendor_wallet` (`vendor_id`, `balance`, `total_earned`, `total_withdrawn`) VALUES
(1, 5000.00, 15000.00, 10000.00),
(2, 8500.00, 8500.00, 0.00),
(3, 3200.00, 12200.00, 9000.00),
(4, 1500.00, 1500.00, 0.00),
(5, 0.00, 5000.00, 5000.00);

-- Insert sample transactions
INSERT INTO `wallet_transactions` (`vendor_id`, `order_id`, `type`, `amount`, `description`, `status`, `created_at`) VALUES
(1, NULL, 'credit', 5000.00, 'Payment for Order #101', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, NULL, 'credit', 10000.00, 'Payment for Order #102', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1, NULL, 'withdrawal', 10000.00, 'Withdrawal to A/C ****1234', 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, NULL, 'credit', 8500.00, 'Payment for Order #103', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, NULL, 'credit', 6000.00, 'Payment for Order #104', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(3, NULL, 'credit', 6200.00, 'Payment for Order #105', 'completed', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3, NULL, 'withdrawal', 9000.00, 'Withdrawal to A/C ****5678', 'completed', NOW());

-- Note: Password for all test vendors is 'password' (hashed)
-- Use these credentials to login and test:
-- Email: rajesh@example.com, Password: password
-- Email: priya@example.com, Password: password
-- Email: amit@example.com, Password: password
-- Email: sneha@example.com, Password: password
-- Email: rahul@example.com, Password: password

-- Admin credentials (already in wallet_admin_schema.sql):
-- Username: admin, Password: admin123
