-- Reconstruct a minimal timeline for pre-Phase-1 complaints.
--
-- Two rows per complaint, at most:
--   1. "filed" row, timestamped from complaints.date_filed (parsed).
--   2. "current status" row, timestamped NOW() (migration artifact), only
--      when the complaint has moved past 'open' or carries an official_note.
--
-- Intermediate transitions (e.g. open -> investigating -> resolved) are lost;
-- the migration cannot recover what was never persisted. That is expected.
--
-- Guarded by NOT EXISTS so re-running is a no-op.

-- Filed row (only for complaints with no updates yet)
INSERT INTO `complaint_updates` (`complaint_id`, `actor_type`, `actor_id`, `from_status`, `to_status`, `note`, `created_at`)
SELECT c.id, 'system', NULL, NULL, 'open', 'Complaint filed.',
       COALESCE(STR_TO_DATE(c.date_filed, '%b %d, %Y'), NOW())
  FROM `complaints` c
 WHERE NOT EXISTS (
   SELECT 1 FROM `complaint_updates` u WHERE u.complaint_id = c.id
 );

-- Current-status row for non-open complaints that only have the filed row
INSERT INTO `complaint_updates` (`complaint_id`, `actor_type`, `actor_id`, `from_status`, `to_status`, `note`, `created_at`)
SELECT c.id, 'system', NULL, 'open', c.status, NULLIF(TRIM(c.official_note), ''), NOW()
  FROM `complaints` c
 WHERE (c.status <> 'open' OR TRIM(c.official_note) <> '')
   AND NOT EXISTS (
     SELECT 1 FROM `complaint_updates` u
      WHERE u.complaint_id = c.id AND u.to_status = c.status
   );
