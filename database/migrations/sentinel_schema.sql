CREATE TABLE IF NOT EXISTS sentinel_schemas (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint_key  VARCHAR(512) NOT NULL UNIQUE,
    schema_version VARCHAR(80) NOT NULL,
    json_schema   LONGTEXT NOT NULL,
    sample_count  INT UNSIGNED NOT NULL DEFAULT 0,
    hardened_at   DATETIME NOT NULL,
    INDEX idx_endpoint_key (endpoint_key)
);

CREATE TABLE IF NOT EXISTS sentinel_samples (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint_key  VARCHAR(512) NOT NULL,
    payload       LONGTEXT NOT NULL,
    created_at    DATETIME NOT NULL,
    INDEX idx_samples_endpoint (endpoint_key)
);

CREATE TABLE IF NOT EXISTS sentinel_schema_history (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint_key   VARCHAR(512) NOT NULL,
    schema_version VARCHAR(80) NOT NULL,
    json_schema    LONGTEXT NOT NULL,
    archived_at    DATETIME NOT NULL,
    INDEX idx_history_endpoint (endpoint_key)
);
