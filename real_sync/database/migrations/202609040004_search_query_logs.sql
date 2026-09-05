CREATE TABLE IF NOT EXISTS search_query_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    query_text VARCHAR(160) NOT NULL,
    expanded_terms_json JSON NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NULL,
    result_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_search_query_logs_query (query_text),
    KEY idx_search_query_logs_created (created_at),
    KEY idx_search_query_logs_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
