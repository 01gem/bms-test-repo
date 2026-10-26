-- Additive retry counter for pickup-notice SMS failures. Existing `failed`
-- rows keep retry_count=0 and become eligible for the next dispatcher retry
-- subject to the 5-minute backoff.
--
-- After 4 requeues the row stays in `failed` for operator reconciliation.
-- Backoff per requeue: 5m, 15m, 60m, 180m (~4h20m total, including the
-- original attempt).

ALTER TABLE request_notification_events
  ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD KEY notification_retry (state, retry_count, finished_at);
