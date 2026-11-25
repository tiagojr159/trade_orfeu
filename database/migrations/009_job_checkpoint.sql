CREATE TABLE orf_job_checkpoint (
 scope_key VARCHAR(32) NOT NULL, session_date DATE NOT NULL, phase VARCHAR(32) NOT NULL,
 status VARCHAR(20) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, failures INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME(6) NOT NULL, next_attempt_at DATETIME(6) NULL, detail VARCHAR(120) NOT NULL,
 PRIMARY KEY(scope_key,session_date,phase), INDEX ix_phase_status(scope_key,phase,status,session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
