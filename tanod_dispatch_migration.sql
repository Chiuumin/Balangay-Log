CREATE TABLE IF NOT EXISTS tanod_dispatch_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_id INT NOT NULL,
    unit VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'DISPATCHED',
    directive_notes TEXT DEFAULT NULL,
    dispatched_by_user_id INT DEFAULT NULL,
    dispatched_by_name VARCHAR(120) NOT NULL,
    dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at DATETIME DEFAULT NULL,
    acknowledged_by_name VARCHAR(120) DEFAULT NULL,
    arrived_at DATETIME DEFAULT NULL,
    arrived_by_name VARCHAR(120) DEFAULT NULL,
    findings TEXT DEFAULT NULL,
    action_taken TEXT DEFAULT NULL,
    updated_by_user_id INT DEFAULT NULL,
    updated_by_name VARCHAR(120) DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tanod_dispatch_incident (incident_id, id),
    KEY idx_tanod_dispatch_status (status, dispatched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tanod_dispatch_record_responders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispatch_id BIGINT UNSIGNED NOT NULL,
    tanod_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tanod_dispatch_record_responder (dispatch_id, tanod_id),
    KEY idx_tanod_dispatch_record_responder (tanod_id, dispatch_id),
    CONSTRAINT fk_tanod_dispatch_responder_log
        FOREIGN KEY (dispatch_id) REFERENCES tanod_dispatch_records (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
