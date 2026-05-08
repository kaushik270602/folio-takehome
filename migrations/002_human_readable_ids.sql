-- Add human-readable slug to documents
-- Format: lowercase-title-slug + short random suffix (e.g. "welcome-packet-3k7x")
-- Complements (not replaces) the existing share token mechanism.
-- Reasoning: share tokens are per-recipient and private; slugs are per-document and semi-public.
ALTER TABLE documents ADD COLUMN slug TEXT DEFAULT NULL;
CREATE UNIQUE INDEX idx_documents_slug ON documents(slug);
