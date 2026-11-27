CREATE TABLE orf_identity_evidence (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 instrument_id BIGINT UNSIGNED NOT NULL, symbol VARCHAR(16) NOT NULL, isin CHAR(12) NOT NULL,
 valid_from DATE NOT NULL, valid_to DATE NULL, available_at DATETIME(6) NOT NULL,
 source VARCHAR(40) NOT NULL, evidence_ref VARCHAR(255) NOT NULL, raw_hash CHAR(64) NOT NULL,
 UNIQUE KEY uq_identity_evidence(instrument_id,symbol,valid_from,available_at,raw_hash),
 INDEX ix_identity_symbol_asof(symbol,valid_from,valid_to,available_at),
 FOREIGN KEY(instrument_id) REFERENCES orf_instrument(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
