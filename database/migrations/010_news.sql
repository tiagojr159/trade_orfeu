CREATE TABLE orf_news (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 content_hash CHAR(64) NOT NULL UNIQUE,
 source VARCHAR(120) NOT NULL,
 symbol VARCHAR(16) NULL,
 title VARCHAR(500) NOT NULL,
 description TEXT NULL,
 url VARCHAR(2048) NOT NULL,
 published_at DATETIME(6) NULL,
 available_at DATETIME(6) NOT NULL,
 ingested_at DATETIME(6) NOT NULL,
 sentiment DECIMAL(6,5) NOT NULL,
 sentiment_label VARCHAR(16) NOT NULL,
 INDEX(published_at), INDEX(symbol,published_at), INDEX(available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
