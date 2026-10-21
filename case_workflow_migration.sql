-- Apply to databases created before the current case workflow statuses.
ALTER TABLE `incident_reports`
  MODIFY `status` enum('PENDING','IN_PROGRESS','DISPATCHED','FOR_RESOLUTION','RESOLVED','CLOSED','CRITICAL','REJECTED') DEFAULT 'PENDING';

UPDATE incident_reports ir
JOIN (
  SELECT case_id, MAX(id) AS latest_id
  FROM lupon_cases
  GROUP BY case_id
) latest ON latest.case_id = ir.id
JOIN lupon_cases lc ON lc.id = latest.latest_id
SET ir.status = 'RESOLVED'
WHERE UPPER(COALESCE(lc.status, '')) = 'RESOLVED'
  AND ir.status NOT IN ('CLOSED', 'REJECTED');

UPDATE incident_reports ir
JOIN (
  SELECT case_id, MAX(id) AS latest_id
  FROM lupon_cases
  GROUP BY case_id
) latest ON latest.case_id = ir.id
JOIN lupon_cases lc ON lc.id = latest.latest_id
SET ir.status = 'FOR_RESOLUTION'
WHERE UPPER(COALESCE(lc.status, '')) NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
  AND ir.status NOT IN ('RESOLVED', 'CLOSED', 'REJECTED');

CREATE TABLE IF NOT EXISTS `case_findings` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `observations` text NOT NULL,
  `persons_contacted` text DEFAULT NULL,
  `action_taken` text NOT NULL,
  `findings` text NOT NULL,
  `recommended_next_steps` text DEFAULT NULL,
  `submitted_by_user_id` int(11) DEFAULT NULL,
  `submitted_by_name` varchar(120) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_case_findings_incident_created` (`incident_id`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `case_resolutions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `outcome` enum('RESOLVED_THROUGH_INTERVENTION','SETTLED_THROUGH_MEDIATION','REFERRED_TO_ANOTHER_AUTHORITY','WITHDRAWN','OTHER') NOT NULL,
  `findings_reviewed_summary` text NOT NULL,
  `conclusion` text NOT NULL,
  `settlement_terms` text DEFAULT NULL,
  `remaining_obligations` text DEFAULT NULL,
  `follow_up_at` datetime DEFAULT NULL,
  `resulting_status` enum('IN_PROGRESS','RESOLVED','CLOSED') NOT NULL,
  `resolved_by_user_id` int(11) DEFAULT NULL,
  `resolved_by_name` varchar(120) NOT NULL,
  `resolved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_case_resolutions_incident_created` (`incident_id`, `resolved_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `case_workflow_closures` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` int(11) NOT NULL,
  `resolution_id` bigint(20) UNSIGNED NOT NULL,
  `outcome` varchar(50) NOT NULL,
  `findings_reviewed_summary` text NOT NULL,
  `conclusion` text NOT NULL,
  `closed_by_user_id` int(11) DEFAULT NULL,
  `closed_by_name` varchar(120) NOT NULL,
  `closed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_case_workflow_closures_incident_created` (`incident_id`, `closed_at`, `id`),
  KEY `idx_case_workflow_closures_resolution` (`resolution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
