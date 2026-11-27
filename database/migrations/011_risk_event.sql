CREATE TABLE orf_risk_event (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 account_id BIGINT UNSIGNED NOT NULL,
 session_date DATE NOT NULL,
 as_of DATETIME(6) NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 severity VARCHAR(24) NOT NULL,
 payload LONGTEXT NOT NULL,
 UNIQUE(account_id,session_date,event_type),
 INDEX(as_of,event_type),
 FOREIGN KEY(account_id) REFERENCES orf_account(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
