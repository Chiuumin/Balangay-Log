-- Apply to existing databases to enable rejecting reports directly from triage.
ALTER TABLE `incident_reports`
  MODIFY `status` enum('PENDING','IN_PROGRESS','DISPATCHED','FOR_RESOLUTION','RESOLVED','CLOSED','CRITICAL','REJECTED') DEFAULT 'PENDING';
