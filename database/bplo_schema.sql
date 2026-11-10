-- Import into the database selected in your Aiven/Navicat connection.
-- BPLO-specific tables, no DROP or modification of your existing tables.
CREATE TABLE IF NOT EXISTS bplo_accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 role ENUM('applicant','admin') NOT NULL DEFAULT 'applicant',
 name VARCHAR(180) NOT NULL,
 email VARCHAR(254) NOT NULL UNIQUE,
 phone VARCHAR(25) NOT NULL DEFAULT '',
 address VARCHAR(500) NOT NULL DEFAULT '',
 password_hash VARCHAR(255) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bplo_sessions (
 token_hash CHAR(64) NOT NULL PRIMARY KEY,
 account_id BIGINT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 KEY (account_id),
 FOREIGN KEY (account_id) REFERENCES bplo_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bplo_applications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 reference_number VARCHAR(45) NOT NULL UNIQUE,
 account_id BIGINT UNSIGNED NOT NULL,
 details_json JSON NOT NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'Pending',
 remarks TEXT,
 inspection_json JSON DEFAULT NULL,
 submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 KEY (account_id),
 FOREIGN KEY (account_id) REFERENCES bplo_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bplo_documents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 document_type VARCHAR(190) NOT NULL,
 file_name VARCHAR(255) NOT NULL,
 storage_name VARCHAR(100) NOT NULL,
 mime_type VARCHAR(90) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL,
 review_status VARCHAR(40) NOT NULL DEFAULT 'Pending',
 remarks TEXT,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY (application_id),
 FOREIGN KEY (application_id) REFERENCES bplo_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bplo_status_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 application_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(40) NOT NULL,
 remarks TEXT,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY (application_id),
 FOREIGN KEY (application_id) REFERENCES bplo_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bplo_notifications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 account_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(180) NOT NULL,
 message TEXT NOT NULL,
 reference VARCHAR(45),
 is_read TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY (account_id),
 FOREIGN KEY (account_id) REFERENCES bplo_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bplo_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 account_id BIGINT UNSIGNED,
 actor VARCHAR(180) NOT NULL,
 action VARCHAR(190) NOT NULL,
 reference VARCHAR(45),
 details TEXT,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bplo_settings (
 setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
 setting_json JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
