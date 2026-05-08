-- Add publish_at column for scheduled publishing
-- NULL means immediately published (backwards compatible)
ALTER TABLE documents ADD COLUMN publish_at TEXT DEFAULT NULL;
