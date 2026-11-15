-- Catalogue addition — Internet Service Provider, and the two trades
-- that sit next to it.
--
--   Internet Service Provider   36 facilities  (fiber/wireless broadband)
--   Cable TV & DTH Services     16 facilities  (cable operators)
--   CCTV & Security Systems     18 facilities  (camera installers)
--
-- A local ISP usually sells all three, so everything a broadband client
-- would tick lives in the first category — set-top boxes and cameras are
-- facilities there, not a reason to split the client across categories.
-- The second and third exist for businesses that do ONLY cable or ONLY
-- cameras, since a client row carries exactly one category_id.
--
-- Why these names: a facility name is injected verbatim into the AI
-- prompt ("Focus only on: <facility> and <facility>") and ends up inside
-- the customer's Google review, so each one is written as a phrase that
-- reads naturally in an English sentence.
--
-- facilities.category_id is guaranteed to exist here: the 2026_11_07
-- migration runs first (files are applied in filename order) and adds the
-- column when it is missing.
--
-- Safe to re-run. Nothing is updated or deleted; a name that already
-- exists is skipped, because facilities.facility_name is UNIQUE across
-- the whole table.

-- --------------------------------------------------------------------
-- 1. Categories
--    FROM DUAL is required: MySQL rejects a WHERE clause on a SELECT
--    that has no FROM.
-- --------------------------------------------------------------------
INSERT INTO business_categories (category_name, is_active, created_at)
SELECT 'Internet Service Provider', 1, NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM business_categories WHERE category_name = 'Internet Service Provider'
);

INSERT INTO business_categories (category_name, is_active, created_at)
SELECT 'Cable TV & DTH Services', 1, NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM business_categories WHERE category_name = 'Cable TV & DTH Services'
);

INSERT INTO business_categories (category_name, is_active, created_at)
SELECT 'CCTV & Security Systems', 1, NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM business_categories WHERE category_name = 'CCTV & Security Systems'
);

-- --------------------------------------------------------------------
-- 2. Internet Service Provider — 36 sub-categories
--
--    Group A (1-8)   what kind of connection
--    Group B (9-15)  what comes bundled with it
--    Group C (16-22) installation and support
--    Group D (23-27) network quality — the reason people post a review
--    Group E (28-32) billing and plans
--    Group F (33-36) who it is sold to
-- --------------------------------------------------------------------
INSERT INTO facilities (category_id, facility_name, is_active, created_at)
SELECT bc.id, t.facility_name, 1, NOW()
FROM business_categories bc
CROSS JOIN (
    -- Group A — connection types
    SELECT 'Fiber Optic Broadband'            AS facility_name
    UNION ALL SELECT 'Wireless Broadband'
    UNION ALL SELECT 'High Speed Internet'
    UNION ALL SELECT 'Unlimited Data Plans'
    UNION ALL SELECT 'Gigabit Speed Plans'
    UNION ALL SELECT 'Leased Line Connection'
    UNION ALL SELECT 'Static IP Address'
    UNION ALL SELECT 'Dedicated Business Internet'
    -- Group B — bundled services
    UNION ALL SELECT 'Free Wi-Fi Router'
    UNION ALL SELECT 'Wi-Fi Router Installation'
    UNION ALL SELECT 'Mesh Wi-Fi Setup'
    UNION ALL SELECT 'OTT Subscription Bundle'
    UNION ALL SELECT 'IPTV & Live TV'
    UNION ALL SELECT 'VoIP Calling Service'
    UNION ALL SELECT 'CCTV Camera Integration'
    -- Group C — installation and support
    UNION ALL SELECT 'Same Day Installation'
    UNION ALL SELECT 'Free Installation'
    UNION ALL SELECT '24x7 Customer Support'
    UNION ALL SELECT 'On-Site Technical Support'
    UNION ALL SELECT 'Quick Complaint Resolution'
    UNION ALL SELECT 'Local Support Team'
    UNION ALL SELECT 'Dedicated Relationship Manager'
    -- Group D — network quality
    UNION ALL SELECT 'Stable Uninterrupted Connection'
    UNION ALL SELECT 'Consistent Speed at Peak Hours'
    UNION ALL SELECT 'Low Latency for Gaming'
    UNION ALL SELECT 'No Speed Throttling'
    UNION ALL SELECT 'High Network Uptime'
    -- Group E — billing and plans
    UNION ALL SELECT 'Affordable Monthly Plans'
    UNION ALL SELECT 'No Hidden Charges'
    UNION ALL SELECT 'Flexible Plan Upgrade'
    UNION ALL SELECT 'Online Bill Payment'
    UNION ALL SELECT 'Annual Plan Discounts'
    -- Group F — who it serves
    UNION ALL SELECT 'Apartment & Society Coverage'
    UNION ALL SELECT 'Corporate & Office Connectivity'
    UNION ALL SELECT 'Hotel & Hostel Connectivity'
    UNION ALL SELECT 'Rural & Village Coverage'
) AS t
WHERE bc.category_name = 'Internet Service Provider'
  AND NOT EXISTS (
      SELECT 1 FROM facilities f WHERE f.facility_name = t.facility_name
  );

-- --------------------------------------------------------------------
-- 3. Cable TV & DTH Services — 16 sub-categories
-- --------------------------------------------------------------------
INSERT INTO facilities (category_id, facility_name, is_active, created_at)
SELECT bc.id, t.facility_name, 1, NOW()
FROM business_categories bc
CROSS JOIN (
    SELECT 'Cable TV Connection'              AS facility_name
    UNION ALL SELECT 'DTH Set Top Box'
    UNION ALL SELECT 'Set Top Box Installation'
    UNION ALL SELECT 'Set Top Box Replacement'
    UNION ALL SELECT 'HD Channel Packs'
    UNION ALL SELECT 'Regional Channel Packs'
    UNION ALL SELECT 'Sports Channel Add-On'
    UNION ALL SELECT 'OTT App Bundle'
    UNION ALL SELECT 'Channel Pack Customisation'
    UNION ALL SELECT 'Multi TV Connection'
    UNION ALL SELECT 'Same Day Activation'
    UNION ALL SELECT 'Clear Signal Quality'
    UNION ALL SELECT 'Reliable Signal in Bad Weather'
    UNION ALL SELECT 'Online Recharge Support'
    UNION ALL SELECT 'Monthly Recharge Reminder'
    UNION ALL SELECT 'Local Operator Support'
) AS t
WHERE bc.category_name = 'Cable TV & DTH Services'
  AND NOT EXISTS (
      SELECT 1 FROM facilities f WHERE f.facility_name = t.facility_name
  );

-- --------------------------------------------------------------------
-- 4. CCTV & Security Systems — 18 sub-categories
-- --------------------------------------------------------------------
INSERT INTO facilities (category_id, facility_name, is_active, created_at)
SELECT bc.id, t.facility_name, 1, NOW()
FROM business_categories bc
CROSS JOIN (
    SELECT 'CCTV Camera Installation'         AS facility_name
    UNION ALL SELECT 'HD & 4K Cameras'
    UNION ALL SELECT 'IP Camera Systems'
    UNION ALL SELECT 'Dome & Bullet Cameras'
    UNION ALL SELECT 'Night Vision Cameras'
    UNION ALL SELECT 'DVR & NVR Setup'
    UNION ALL SELECT 'Mobile Live View Setup'
    UNION ALL SELECT 'Cloud Video Storage'
    UNION ALL SELECT 'Video Door Phone'
    UNION ALL SELECT 'Intercom System'
    UNION ALL SELECT 'Biometric Attendance System'
    UNION ALL SELECT 'Access Control System'
    UNION ALL SELECT 'Burglar Alarm System'
    UNION ALL SELECT 'Fire Alarm System'
    UNION ALL SELECT 'Home & Shop Security Setup'
    UNION ALL SELECT 'Free Site Survey'
    UNION ALL SELECT 'Annual Maintenance Contract'
    UNION ALL SELECT 'Same Day Camera Repair'
) AS t
WHERE bc.category_name = 'CCTV & Security Systems'
  AND NOT EXISTS (
      SELECT 1 FROM facilities f WHERE f.facility_name = t.facility_name
  );
