-- Add phone column to users table if it doesn't exist

-- Check if phone column exists and add it if it doesn't
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email;',
        'SELECT "Phone column already exists" as message;'
    ) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'min' 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'phone'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;