CREATE TABLE IF NOT EXISTS cd_supplier_section_map (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  store_id      INT NOT NULL,
  supplier_name VARCHAR(255) NOT NULL,
  section       ENUM('korean','local','fixed','others') NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_store_supplier (store_id, supplier_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
