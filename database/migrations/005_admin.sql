CREATE TABLE orf_rate_bucket (
 client_key CHAR(64) NOT NULL, window_start BIGINT UNSIGNED NOT NULL,
 attempts INT UNSIGNED NOT NULL, PRIMARY KEY(client_key,window_start), INDEX(window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
