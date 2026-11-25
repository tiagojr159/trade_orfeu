CREATE TABLE orf_fixed_watchlist (
 version VARCHAR(40) NOT NULL, symbol VARCHAR(16) NOT NULL,
 position_no TINYINT UNSIGNED NOT NULL, instrument_id BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL, identity_verified_at DATETIME(6) NULL,
 evidence_ref VARCHAR(255) NULL,
 PRIMARY KEY(version,symbol), UNIQUE KEY uq_watchlist_order(version,position_no),
 UNIQUE KEY uq_watchlist_instrument(version,instrument_id),
 FOREIGN KEY(instrument_id) REFERENCES orf_instrument(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
