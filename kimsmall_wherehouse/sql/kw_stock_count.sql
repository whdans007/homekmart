CREATE TABLE IF NOT EXISTS kw_stock_count_control (
 id TINYINT PRIMARY KEY,
 open_session_id BIGINT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO kw_stock_count_control (id, open_session_id) VALUES (1, NULL);

CREATE TABLE IF NOT EXISTS kw_stock_count_sessions (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 status ENUM('open','finalized','cancelled') NOT NULL DEFAULT 'open',
 opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 opened_by INT NOT NULL,
 inbound_closed_ack_at DATETIME NULL,
 inbound_closed_ack_by INT NULL,
 finalized_at DATETIME NULL,
 finalized_by INT NULL,
 cancelled_at DATETIME NULL,
 cancelled_by INT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kw_stock_count_baseline (
 session_id BIGINT NOT NULL,
 product_id INT NOT NULL,
 unit ENUM('BOX','PACK','PCS') NOT NULL,
 quantity BIGINT NOT NULL,
 pieces_per_box INT NOT NULL,
 PRIMARY KEY (session_id, product_id, unit),
 FOREIGN KEY (session_id) REFERENCES kw_stock_count_sessions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kw_stock_count_entries (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT NOT NULL,
 product_id INT NOT NULL,
 barcode VARCHAR(100) NOT NULL,
 unit ENUM('BOX','PACK','PCS') NOT NULL,
 quantity INT NOT NULL,
 pieces_per_box INT NOT NULL,
 scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 entered_by INT NOT NULL,
 voided_at DATETIME NULL,
 voided_by INT NULL,
 void_reason ENUM('cancel','correction') NULL,
 corrected_from_entry_id BIGINT NULL,
 INDEX (session_id, product_id, unit),
 INDEX (session_id, scanned_at),
 INDEX (corrected_from_entry_id),
 FOREIGN KEY (session_id) REFERENCES kw_stock_count_sessions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kw_stock_count_adjustments (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 session_id BIGINT NOT NULL,
 product_id INT NOT NULL,
 unit ENUM('BOX','PACK','PCS') NOT NULL,
 inventory_id INT NOT NULL,
 before_quantity INT NOT NULL,
 after_quantity INT NOT NULL,
 delta INT NOT NULL,
 applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX (session_id, product_id),
 FOREIGN KEY (session_id) REFERENCES kw_stock_count_sessions(id)
) ENGINE=InnoDB;
