USE smart_review_system;

INSERT INTO business_categories (category_name, is_active) VALUES
('Hotel', 1),
('Hospital', 1),
('IT/Computer Shop', 1),
('Restaurant', 1),
('Salon', 1),
('IT Services', 1),
('Real Estate', 1);

INSERT INTO facilities (facility_name, is_active) VALUES
('Free WiFi', 1),
('Parking', 1),
('24/7 Support', 1),
('ICU', 1),
('Air Conditioning', 1),
('Home Delivery', 1),
('Wheelchair Access', 1),
('Card Payment', 1);

INSERT INTO admins (full_name, email, password_hash, role, is_active)
VALUES (
  'Super Admin',
  'admin@smartreview.local',
  '$2y$10$nmjtOWCRHL1TBLvwDuglcOM8AuG1Z9WouVHPJazBw.PCwernbzKFu',
  'super_admin',
  1
);

INSERT INTO system_settings (setting_key, setting_value, value_type, is_encrypted)
VALUES
('ai_provider', 'openai', 'string', 0),
('ai_api_key', '', 'string', 1),
('review_word_limit_min', '40', 'int', 0),
('review_word_limit_max', '90', 'int', 0),
('default_review_format', 'short_paragraph', 'string', 0),
('buffer_target_count', '20', 'int', 0);
