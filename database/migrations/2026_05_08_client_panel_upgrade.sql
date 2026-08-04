USE smart_review_system;

ALTER TABLE clients
    ADD COLUMN custom_ai_api_key VARCHAR(255) NULL AFTER google_review_url;
