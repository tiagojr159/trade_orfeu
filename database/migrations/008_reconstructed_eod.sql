CREATE TABLE orf_reconstructed_eod (
 bar_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 instrument_id BIGINT UNSIGNED NOT NULL, session_date DATE NOT NULL, market CHAR(3) NOT NULL,
 assumed_at DATETIME(6) NOT NULL, approved_at DATETIME(6) NOT NULL,
 archive_sha256 CHAR(64) NOT NULL, evidence_ref VARCHAR(255) NOT NULL, policy VARCHAR(32) NOT NULL,
 UNIQUE KEY uq_reconstructed_series(instrument_id,session_date,market),
 FOREIGN KEY(bar_id) REFERENCES orf_bar(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
