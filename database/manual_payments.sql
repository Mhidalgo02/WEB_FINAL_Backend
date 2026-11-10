-- Additive setup for an existing BPLO database. Safe to run again.
CREATE TABLE IF NOT EXISTS bplo_payment_assessments (
 application_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 amount_cents BIGINT UNSIGNED NOT NULL,
 purpose VARCHAR(500) NOT NULL,
 method VARCHAR(30) NOT NULL DEFAULT '',
 account_name VARCHAR(180) NOT NULL DEFAULT '',
 account_number VARCHAR(100) NOT NULL DEFAULT '',
 instructions TEXT NOT NULL,
 assessed_by BIGINT UNSIGNED NOT NULL,
 assessed_at DATETIME NOT NULL,
 FOREIGN KEY (application_id) REFERENCES bplo_applications(id) ON DELETE RESTRICT,
 FOREIGN KEY (assessed_by) REFERENCES bplo_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bplo_payment_submissions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 method VARCHAR(30) NOT NULL,
 payment_reference VARCHAR(100) NOT NULL,
 reference_key CHAR(64) DEFAULT NULL UNIQUE,
 paid_on DATE NOT NULL,
 file_name VARCHAR(200) NOT NULL,
 storage_name CHAR(48) NOT NULL,
 mime_type VARCHAR(90) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 proof_hash CHAR(64) DEFAULT NULL UNIQUE,
 review_status VARCHAR(20) NOT NULL DEFAULT 'Pending',
 review_remarks TEXT,
 reviewed_by BIGINT UNSIGNED DEFAULT NULL,
 reviewed_at DATETIME DEFAULT NULL,
 submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY (application_id),
 FOREIGN KEY (application_id) REFERENCES bplo_applications(id) ON DELETE RESTRICT,
 FOREIGN KEY (reviewed_by) REFERENCES bplo_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO bplo_settings (setting_key, setting_json) VALUES ('manual_payments_schema', '{"version":1}')
ON DUPLICATE KEY UPDATE setting_json = '{"version":1}';
