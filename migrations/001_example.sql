-- Example migration; copy this file, increment the prefix, and never edit it after applying.
CREATE TABLE sqlschematic_example (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
