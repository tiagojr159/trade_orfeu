CREATE TABLE orf_session (
 session_date DATE PRIMARY KEY, open_at DATETIME(6) NULL, continuous_end DATETIME(6) NULL,
 close_at DATETIME(6) NULL, settles TINYINT NOT NULL, source VARCHAR(140) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_issuer (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, issuer_key VARCHAR(32) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL, cvm_code VARCHAR(16) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_instrument (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, issuer_id BIGINT UNSIGNED NOT NULL,
 isin CHAR(12) NOT NULL UNIQUE, sector VARCHAR(80) NOT NULL, asset_type VARCHAR(12) NOT NULL,
 lot_size INT UNSIGNED NOT NULL, tick_cents INT UNSIGNED NOT NULL,
 FOREIGN KEY(issuer_id) REFERENCES orf_issuer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_symbol_history (
 instrument_id BIGINT UNSIGNED NOT NULL, valid_from DATE NOT NULL, symbol VARCHAR(16) NOT NULL,
 available_at DATETIME(6) NOT NULL, odd_symbol VARCHAR(16) NULL,
 PRIMARY KEY(instrument_id,valid_from,available_at), INDEX(symbol),
 FOREIGN KEY(instrument_id) REFERENCES orf_instrument(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_eligibility_history (
 instrument_id BIGINT UNSIGNED NOT NULL, valid_from DATE NOT NULL, available_at DATETIME(6) NOT NULL,
 status VARCHAR(24) NOT NULL, source VARCHAR(160) NOT NULL,
 PRIMARY KEY(instrument_id,valid_from,available_at), FOREIGN KEY(instrument_id) REFERENCES orf_instrument(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_raw_ingest (
 raw_hash CHAR(64) PRIMARY KEY, provider VARCHAR(24) NOT NULL, received_at DATETIME(6) NOT NULL,
 row_count INT UNSIGNED NOT NULL, rejected_count INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_bar (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, instrument_id BIGINT UNSIGNED NOT NULL,
 session_date DATE NOT NULL, market CHAR(3) NOT NULL, open_at DATETIME(6) NOT NULL,
 close_at DATETIME(6) NOT NULL, available_at DATETIME(6) NOT NULL, received_at DATETIME(6) NOT NULL,
 open_px INT UNSIGNED NOT NULL, high_px INT UNSIGNED NOT NULL, low_px INT UNSIGNED NOT NULL,
 close_px INT UNSIGNED NOT NULL, volume BIGINT UNSIGNED NOT NULL, financial_volume BIGINT UNSIGNED NOT NULL,
 raw_hash CHAR(64) NOT NULL, provider VARCHAR(24) NOT NULL,
 UNIQUE(instrument_id,market,session_date,raw_hash), INDEX(session_date,available_at),
 INDEX(instrument_id,market,session_date,available_at), FOREIGN KEY(instrument_id) REFERENCES orf_instrument(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_quote (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, symbol VARCHAR(16) NOT NULL,
 event_at DATETIME(6) NOT NULL, available_at DATETIME(6) NOT NULL, price_cents INT UNSIGNED NOT NULL,
 delay_seconds INT UNSIGNED NOT NULL, raw_hash CHAR(64) NOT NULL,
 UNIQUE(symbol,event_at,raw_hash), INDEX(symbol,event_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_observation (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(24) NOT NULL, subject VARCHAR(80) NOT NULL,
 ref_date DATE NOT NULL, available_at DATETIME(6) NOT NULL, received_at DATETIME(6) NOT NULL,
 source VARCHAR(40) NOT NULL, raw_hash CHAR(64) NOT NULL, payload LONGTEXT NOT NULL,
 UNIQUE(kind,subject,ref_date,raw_hash), INDEX(kind,subject,ref_date,available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_source_health (
 source VARCHAR(24) PRIMARY KEY, status VARCHAR(32) NOT NULL, last_attempt DATETIME(6) NOT NULL,
 last_success DATETIME(6) NULL, next_attempt DATETIME(6) NOT NULL, request_day DATE NOT NULL,
 requests INT UNSIGNED NOT NULL, detail VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
