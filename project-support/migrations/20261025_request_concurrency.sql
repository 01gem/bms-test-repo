-- Additive, one-time migration. Stop request writers/dispatchers first. Select the
-- existing application database explicitly; this file never selects a database.
ALTER TABLE document_requests
  ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN readiness_cycle INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN generation_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD COLUMN generation_expires_at DATETIME NULL;
UPDATE document_requests SET readiness_cycle = 1 WHERE status = 'ready';

CREATE TABLE request_operations (
  operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  official_id INT NOT NULL,
  official_name VARCHAR(110) NOT NULL,
  action VARCHAR(30) NOT NULL,
  request_key VARCHAR(50) NOT NULL,
  fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
  payload_json JSON NOT NULL,
  state ENUM('running','done') NOT NULL DEFAULT 'running',
  http_status SMALLINT NULL,
  outcome_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY request_operation_recovery (state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE request_notification_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  readiness_cycle INT UNSIGNED NOT NULL,
  sequence_no INT UNSIGNED NOT NULL,
  operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  resident_id INT NOT NULL,
  contact VARCHAR(30) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  state ENUM('queued','sending','sent','skipped','failed','uncertain') NOT NULL DEFAULT 'queued',
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL,
  finished_at DATETIME NULL,
  UNIQUE KEY request_cycle_notice (request_id, readiness_cycle, sequence_no),
  UNIQUE KEY notification_operation (operation_id),
  KEY notification_dispatch (state, id),
  CONSTRAINT fk_notice_request FOREIGN KEY (request_id) REFERENCES document_requests(id),
  CONSTRAINT fk_notice_operation FOREIGN KEY (operation_id) REFERENCES request_operations(operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prior Ready records have no reliable per-request delivery ledger. Preserve an
-- uncertain initial intent so rollout cannot silently duplicate a historical SMS.
INSERT INTO request_operations
 (operation_id,official_id,official_name,action,request_key,fingerprint,payload_json,state,http_status,outcome_json,finished_at)
SELECT UUID(),0,'Migration','legacy_notice',reference_no,SHA2(reference_no,256),JSON_OBJECT('migration',true),'done',200,
 JSON_OBJECT('success',true,'changed',false),NOW() FROM document_requests WHERE status='ready';
INSERT INTO request_notification_events
 (request_id,readiness_cycle,sequence_no,operation_id,resident_id,contact,message,state,reason,finished_at)
SELECT r.id,r.readiness_cycle,0,o.operation_id,r.resident_id,'','Legacy Ready request','uncertain',
 'Pre-migration delivery history is unknown. Reconcile or explicitly resend.',NOW()
 FROM document_requests r JOIN request_operations o ON o.request_key=r.reference_no AND o.action='legacy_notice'
 WHERE r.status='ready';
