CREATE TABLE orf_prediction_evaluation (
 prediction_id BIGINT UNSIGNED NOT NULL, policy VARCHAR(32) NOT NULL,
 status VARCHAR(16) NOT NULL, evaluated_at DATETIME(6) NOT NULL,
 next_check_at DATETIME(6) NULL, payload LONGTEXT NOT NULL,
 PRIMARY KEY(prediction_id,policy), INDEX(policy,status,next_check_at),
 FOREIGN KEY(prediction_id) REFERENCES orf_prediction(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
