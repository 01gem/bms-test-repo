-- Phase 5 cleanup. The document_requests.resident_email column was never
-- written to: api/requests/create.php does not set it, no endpoint reads
-- it, and no frontend references it. Drop it. Run once.
ALTER TABLE `document_requests` DROP COLUMN `resident_email`;
