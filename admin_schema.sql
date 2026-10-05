-- BalangayLog administrator console support
-- Import this file into the existing balangaylog_db database.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS system_puroks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_puroks_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS incident_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_incident_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS equipment_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    category ENUM('PATROL_EQUIPMENT','COMMUNICATION','VEHICLE') NOT NULL DEFAULT 'PATROL_EQUIPMENT',
    serial_number VARCHAR(120) NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    condition_label VARCHAR(60) NOT NULL DEFAULT 'Good',
    status ENUM('AVAILABLE','IN_USE','UNDER_MAINTENANCE','DAMAGED','LOST') NOT NULL DEFAULT 'AVAILABLE',
    assigned_user_id INT(11) NULL,
    vehicle_type VARCHAR(100) NULL,
    plate_number VARCHAR(30) NULL,
    acquired_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_equipment_status (status),
    KEY idx_equipment_assigned_user (assigned_user_id),
    CONSTRAINT fk_equipment_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS equipment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    equipment_id INT UNSIGNED NOT NULL,
    actor_user_id INT(11) NULL,
    action VARCHAR(80) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_equipment_history_item (equipment_id, created_at),
    CONSTRAINT fk_equipment_history_item FOREIGN KEY (equipment_id) REFERENCES equipment_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_equipment_history_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE system_puroks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE incident_categories CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE equipment_items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE equipment_history CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT IGNORE INTO system_puroks (name) VALUES
    ('Purok 1'), ('Purok 2'), ('Purok 3'), ('Purok 4'), ('Sitio Masaya');

INSERT IGNORE INTO incident_categories (name) VALUES
    ('Theft'), ('Assault'), ('Vandalism'), ('Domestic Dispute'),
    ('Noise Complaint'), ('Road/Traffic Incident'), ('Fire'), ('Flood'), ('Other'),
    ('Noise Disturbance'), ('Property Dispute'), ('Physical Altercation'),
    ('Illegal Dumping'), ('Others');
