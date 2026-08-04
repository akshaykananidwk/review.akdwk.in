USE smart_review_system;

ALTER TABLE clients
    ADD COLUMN seo_keywords VARCHAR(255) NULL AFTER review_tone;
