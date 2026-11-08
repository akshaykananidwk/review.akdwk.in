-- Catalogue addition — Agarbatti & Pooja Items
--
-- Adds one business category and the 30 facilities (sub-categories) that
-- sit under it, for incense makers and pooja-item sellers: agarbatti,
-- dhoop, sambrani, havan samagri and the fragrance range they sell under.
--
-- Why these names: a facility name is injected verbatim into the AI
-- prompt ("Focus only on: <facility> and <facility>") and ends up inside
-- the customer's Google review, so each one is written as a phrase that
-- reads naturally in an English sentence. Brand-specific product names
-- are deliberately avoided — facilities are shared by every client in the
-- category, so they describe the fragrance, not one company's label.
--
-- facilities.category_id is guaranteed to exist here: the 2026_11_07
-- migration runs first (files are applied in filename order) and adds the
-- column when it is missing. No PREPARE/EXECUTE guard is needed, which
-- also keeps this file free of the row-returning no-op that wedged
-- update #10.
--
-- Safe to re-run. Nothing is updated or deleted; a name that already
-- exists is skipped, because facilities.facility_name is UNIQUE across
-- the whole table.

-- --------------------------------------------------------------------
-- 1. The category itself
--    FROM DUAL is required: MySQL rejects a WHERE clause on a SELECT
--    that has no FROM.
-- --------------------------------------------------------------------
INSERT INTO business_categories (category_name, is_active, created_at)
SELECT 'Agarbatti & Pooja Items', 1, NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM business_categories
    WHERE category_name = 'Agarbatti & Pooja Items'
);

-- --------------------------------------------------------------------
-- 2. Its sub-categories (facilities)
--
--    Group A (1-9)   what the business sells
--    Group B (10-15) the fragrance range
--    Group C (16-24) product quality — what a happy customer names first
--    Group D (25-30) how they sell and deliver
-- --------------------------------------------------------------------
INSERT INTO facilities (category_id, facility_name, is_active, created_at)
SELECT bc.id, t.facility_name, 1, NOW()
FROM business_categories bc
CROSS JOIN (
    -- Group A — products
    SELECT 'Organic Agarbatti'               AS facility_name
    UNION ALL SELECT 'Dhoop Sticks'
    UNION ALL SELECT 'Dhoop Cones'
    UNION ALL SELECT 'Sambrani Cups'
    UNION ALL SELECT 'Havan Samagri'
    UNION ALL SELECT 'Camphor Tablets'
    UNION ALL SELECT 'Pooja Oil & Cotton Wicks'
    UNION ALL SELECT 'Scented Candles'
    UNION ALL SELECT 'Festival Gift Packs'
    -- Group B — fragrance range
    UNION ALL SELECT 'Chandan Fragrance'
    UNION ALL SELECT 'Mogra Fragrance'
    UNION ALL SELECT 'Rose Fragrance'
    UNION ALL SELECT 'Parijat Fragrance'
    UNION ALL SELECT 'Kesar Chandan Fragrance'
    UNION ALL SELECT 'Oudh Fragrance'
    -- Group C — product quality
    UNION ALL SELECT 'Made from Temple Flowers'
    UNION ALL SELECT '100% Natural Ingredients'
    UNION ALL SELECT 'Charcoal Free'
    UNION ALL SELECT 'Low Smoke'
    UNION ALL SELECT 'Long Lasting Fragrance'
    UNION ALL SELECT 'Hand Rolled Sticks'
    UNION ALL SELECT 'No Harmful Chemicals'
    UNION ALL SELECT 'Eco Friendly Packaging'
    UNION ALL SELECT 'Premium Gift Packaging'
    -- Group D — selling and delivery
    UNION ALL SELECT 'Bulk & Wholesale Supply'
    UNION ALL SELECT 'Temple & Ashram Supply'
    UNION ALL SELECT 'Custom Private Label Branding'
    UNION ALL SELECT 'Doorstep Delivery'
    UNION ALL SELECT 'Pan India Shipping'
    UNION ALL SELECT 'WhatsApp Ordering'
) AS t
WHERE bc.category_name = 'Agarbatti & Pooja Items'
  AND NOT EXISTS (
      SELECT 1 FROM facilities f WHERE f.facility_name = t.facility_name
  );
