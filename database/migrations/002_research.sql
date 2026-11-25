CREATE TABLE orf_feature_snapshot (
 instrument_id BIGINT UNSIGNED NOT NULL, as_of DATETIME(6) NOT NULL, feature_hash CHAR(64) NOT NULL,
 payload LONGTEXT NOT NULL, PRIMARY KEY(instrument_id,as_of,feature_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_model_version (
 id CHAR(64) PRIMARY KEY, horizon VARCHAR(8) NOT NULL, status VARCHAR(16) NOT NULL,
 trained_until DATETIME(6) NOT NULL, effective_at DATETIME(6) NOT NULL, payload LONGTEXT NOT NULL,
 INDEX(horizon,status,effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_prediction (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, instrument_id BIGINT UNSIGNED NOT NULL,
 as_of DATETIME(6) NOT NULL, target_end DATETIME(6) NOT NULL, model_version CHAR(64) NOT NULL,
 horizon VARCHAR(8) NOT NULL, feature_hash CHAR(64) NOT NULL, payload LONGTEXT NOT NULL,
 UNIQUE(instrument_id,as_of,horizon,model_version,feature_hash), INDEX(as_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_universe_snapshot (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, as_of DATETIME(6) NOT NULL,
 available_at DATETIME(6) NOT NULL, effective_at DATETIME(6) NOT NULL,
 status VARCHAR(24) NOT NULL, model_version CHAR(64) NULL,
 fingerprint CHAR(64) NOT NULL UNIQUE, INDEX(effective_at,as_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_universe_member (
 snapshot_id BIGINT UNSIGNED NOT NULL, instrument_id BIGINT UNSIGNED NOT NULL,
 rank_no INT UNSIGNED NULL, status VARCHAR(24) NOT NULL, payload LONGTEXT NOT NULL,
 PRIMARY KEY(snapshot_id,instrument_id), FOREIGN KEY(snapshot_id) REFERENCES orf_universe_snapshot(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orf_research_run (
 id CHAR(64) PRIMARY KEY, kind VARCHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL,
 payload LONGTEXT NOT NULL, INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
