CREATE TABLE IF NOT EXISTS cer_saved_state (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  save_date  DATE NOT NULL,
  state_json MEDIUMTEXT NOT NULL COMMENT 'JSON: {sections:{korean:[],local:[],fixed:[],others:[]}}',
  saved_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cer (store_id, save_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
