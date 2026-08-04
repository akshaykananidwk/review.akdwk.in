-- Per-template QR box (drag-and-drop position + size set by admin).
-- All values are NATIVE pixel coordinates of the uploaded template image.
-- Idempotent on repeated runs.

ALTER TABLE standee_templates
    ADD COLUMN IF NOT EXISTS qr_pos_x INT NOT NULL DEFAULT 0  AFTER image_path,
    ADD COLUMN IF NOT EXISTS qr_pos_y INT NOT NULL DEFAULT 0  AFTER qr_pos_x,
    ADD COLUMN IF NOT EXISTS qr_width INT NOT NULL DEFAULT 0  AFTER qr_pos_y,
    ADD COLUMN IF NOT EXISTS qr_height INT NOT NULL DEFAULT 0 AFTER qr_width,
    ADD COLUMN IF NOT EXISTS native_width INT NOT NULL DEFAULT 0  AFTER qr_height,
    ADD COLUMN IF NOT EXISTS native_height INT NOT NULL DEFAULT 0 AFTER native_width;
