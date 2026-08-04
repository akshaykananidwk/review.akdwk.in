USE smart_review_system;

ALTER TABLE clients
    ADD COLUMN review_model_version VARCHAR(100) NOT NULL DEFAULT 'gemini-2.0-flash' AFTER custom_ai_api_key,
    ADD COLUMN review_logic_type VARCHAR(50) NOT NULL DEFAULT 'balanced' AFTER review_model_version;
