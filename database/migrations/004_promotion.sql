CREATE TABLE orf_promotion (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, horizon VARCHAR(8) NOT NULL,
 model_id CHAR(64) NULL, evidence_id CHAR(64) NULL, effective_at DATETIME(6) NOT NULL,
 INDEX(horizon,effective_at), FOREIGN KEY(model_id) REFERENCES orf_model_version(id),
 FOREIGN KEY(evidence_id) REFERENCES orf_research_run(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
