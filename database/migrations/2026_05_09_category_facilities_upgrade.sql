USE smart_review_system;

ALTER TABLE facilities
    ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER facility_name,
    ADD CONSTRAINT fk_facilities_category FOREIGN KEY (category_id) REFERENCES business_categories(id) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE INDEX idx_facilities_category_active ON facilities(category_id, is_active);
