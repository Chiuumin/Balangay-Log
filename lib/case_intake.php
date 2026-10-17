<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/triage.php';
require_once __DIR__ . '/override_gate.php';

/**
 * Shared "encode a case" logic. Used by api/encode_case.php and api/officer_console.php.
 * $input: the request fields. $actor: the verified user row (needs 'id').
 * Returns ['status' => HTTP code, 'body' => array to send as JSON]. Never prints anything.
 */
function encode_case_run(array $input, array $actor): array {
    date_default_timezone_set('Asia/Manila');
    $err = fn(int $code, string $msg, array $extra = []) =>
        ['status' => $code, 'body' => array_merge(['success' => false, 'message' => $msg], $extra)];
    $get = fn(string $k) => trim((string) ($input[$k] ?? ''));

    $actorId      = (int) $actor['id'];
    $name         = $get('complainantName');
    $phone        = $get('complainantPhone');
    $purok        = $get('purok');
    $type         = $get('incidentCategory') !== '' ? $get('incidentCategory') : $get('category');
    $title        = $get('incidentTitle');                    // optional: the desk form has no title field
    $narrative    = $get('incidentNarrative') !== '' ? $get('incidentNarrative') : $get('narrative');
    $userPriority = $get('priorityLevel') !== '' ? $get('priorityLevel') : $get('priority');
    if ($title === '') $title = $type;
    // aiDetectedPriority / isPriorityOverridden / overrideConfirmed from the browser are ignored.

    // ---- Validation ----
    if (mb_strlen($name) < 3)                        return $err(422, 'A valid complainant name is required (minimum 3 characters).');
    if (!preg_match('/^09\d{9}$/', $phone))          return $err(422, 'A valid 11-digit Philippine contact number (09XXXXXXXXX) is required.');
    if ($purok === '' || $purok === 'Select Purok')  return $err(422, 'Please select a valid Purok / Zone.');
    if ($type === '' || $type === 'General Incident') return $err(422, 'Please select a valid incident category.');
    if (mb_strlen($narrative) < 20)                  return $err(422, 'Narrative description must provide substantial detail (at least 20 characters).');
    if (!isset(SEV_RANK[$userPriority]))             return $err(422, 'Invalid priority level specified.');

    $lat = (float) ($input['lat'] ?? 14.545300);  if ($lat == 0.0) $lat = 14.545300;
    $lng = (float) ($input['lng'] ?? 120.573900); if ($lng == 0.0) $lng = 120.573900;

    $channel      = (string) ($input['intakeChannel'] ?? 'Walk-In Desk Blotter');
    $scenarioType = strtoupper(str_replace(' ', '_', (string) preg_replace('/[^A-Za-z0-9 ]/', '', $channel)));
    if (!in_array($scenarioType, ['WALK_IN', 'ONLINE_PORTAL', 'EMERGENCY'], true)) $scenarioType = 'WALK_IN';

    // ---- AGENT 1: triage (any network call happens BEFORE the transaction) ----
    $t      = triage_case($type, $narrative);
    $review = 'NONE';
    $notice = null;
    $ov     = null;
    $aiInfo = ['source' => $t['source'], 'priority' => $t['priority'], 'confidence' => $t['confidence'],
               'rationale' => $t['rationale'], 'triggers' => $t['triggers']];
    $justification = $verifiedBy = $evidenceType = '';

    // ---- Downgrade gate ----
    if (sev_rank($userPriority) >= sev_rank($t['priority'])) {
        $finalPriority = $userPriority;            // officer agrees with or raises the rating
    } else {
        $justification = $get('overrideJustification');
        $verifiedBy    = $get('overrideVerifiedBy');
        $evidenceType  = strtoupper($get('overrideEvidenceType'));

        if ($justification === '' && $verifiedBy === '' && $evidenceType === '') {
            $label = ($t['source'] === 'AI+RULES') ? 'AI triage' : 'Safety keyword rules';
            return $err(409, "{$label} assessed this case as {$t['priority']}. Lowering it to {$userPriority} requires a justification.", [
                'requires_override' => true,
                'required_fields'   => ['overrideJustification', 'overrideVerifiedBy', 'overrideEvidenceType'],
                'evidence_types'    => OVERRIDE_EVIDENCE_TYPES,
                'ai'                => $aiInfo,
            ]);
        }

        $ov = evaluate_override([
            'narrative'     => $narrative,
            'original'      => $t['priority'],
            'proposed'      => $userPriority,
            'justification' => $justification,
            'verified_by'   => $verifiedBy,
            'evidence_type' => $evidenceType,
        ]);

        if ($ov['verdict'] === 'REJECTED') {
            logAudit('SEVERITY_OVERRIDE_REJECTED', [
                'complainant_phone_last4' => substr($phone, -4),
                'predicted' => $t['priority'], 'requested' => $userPriority,
                'justification' => mb_substr($justification, 0, 500),
                'verified_by' => $verifiedBy, 'evidence_type' => $evidenceType,
                'reason' => $ov['reason'], 'model' => $ov['model'],
            ], $actorId);
            return $err(422, 'Justification rejected: ' . $ov['reason'], ['override_rejected' => true, 'ai' => $aiInfo]);
        }

        if ($ov['verdict'] === 'ACCEPTED') {
            $finalPriority = $userPriority;        // approved downgrade
        } else {                                   // ESCALATED: keep the higher rating until the Captain decides
            $finalPriority = $t['priority'];
            $review = 'PENDING_CAPTAIN';
            $notice = "Downgrade to {$userPriority} is pending Captain approval. The case stays {$finalPriority} until then.";
        }
    }

    if ($t['source'] !== 'AI+RULES') {
        if (empty($t['ai_disabled'])) {            // AI crashed: a human should look at it
            $review = 'PENDING_CAPTAIN';
            $notice = ($notice ? $notice . ' ' : '') . 'AI assessment was unavailable; rules-only result. Case flagged for Captain review.';
        } elseif ($notice === null) {
            $notice = 'AI is turned off; keyword-rules result.';
        }
    }

    $initialStatus = ($finalPriority === 'Critical') ? 'CRITICAL' : 'PENDING';
    $dt     = date('Y-m-d H:i:s');
    $tmpRef = 'TMP-' . bin2hex(random_bytes(8));
    $score  = $t['score'];

    // ---- Single transaction: case + milestone + audit rows + outbox event ----
    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO incident_reports
            (reference_number, complainant_name, complainant_phone, incident_type, narrative_description,
             incident_datetime, purok, latitude, longitude, scenario_type, verification_level,
             ai_urgency_score, priority_level, ai_recommendation, status, original_ai_priority, severity_review_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'VERIFIED', ?, ?, ?, ?, ?, ?)");
        $st->execute([$tmpRef, $name, $phone, $type, $narrative, $dt, $purok, $lat, $lng, $scenarioType,
                      $score, $finalPriority, $t['recommendation'], $initialStatus, $t['ai_priority'], $review]);
        $incidentId = (int) $pdo->lastInsertId();

        $trackingId = sprintf('TRK-%s-%03d', date('Y'), $incidentId);
        $pdo->prepare('UPDATE incident_reports SET reference_number = ? WHERE id = ?')
            ->execute([$trackingId, $incidentId]);

        // Neutral note only: Agent 2 reads milestones, so no justification text goes here.
        $actionNote = "Initial report logged as {$type}. Priority: {$finalPriority} (Score: {$score}).";
        $pdo->prepare('INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, action_note) VALUES (?, ?, ?, ?)')
            ->execute([$incidentId, $trackingId, $initialStatus, $actionNote]);
        $milestoneId = (int) $pdo->lastInsertId();

        // Audit row 1: what triage concluded
        $pdo->prepare("INSERT INTO severity_audit_logs
            (incident_id, action, actor_user_id, original_priority, proposed_priority, final_priority,
             ai_urgency_score, ai_confidence, verdict, verdict_reason, model, latency_ms)
            VALUES (?, 'AI_TRIAGE', ?, ?, NULL, ?, ?, ?, 'N/A', ?, ?, ?)")
            ->execute([$incidentId, $actorId, $t['ai_priority'] ?? $t['priority'], $t['priority'],
                       $score, $t['confidence'], mb_substr($t['rationale'], 0, 500), $t['model'], $t['latency_ms']]);

        // Audit row 2: the downgrade request, if there was one
        if ($ov !== null) {
            $pdo->prepare("INSERT INTO severity_audit_logs
                (incident_id, action, actor_user_id, original_priority, proposed_priority, final_priority,
                 ai_urgency_score, ai_confidence, justification_text, verified_by, evidence_type,
                 verdict, verdict_reason, model, latency_ms)
                VALUES (?, 'OVERRIDE_REQUEST', ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$incidentId, $actorId, $t['priority'], $userPriority, $finalPriority, $ov['confidence'],
                           $justification, $verifiedBy, $evidenceType, $ov['verdict'],
                           mb_substr($ov['reason'], 0, 500), $ov['model'], $ov['latency_ms']]);
        }

        // Event for Agent 2 (pointers only, never copies of text)
        $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('CASE_ENCODED', ?, ?)")
            ->execute([$incidentId, json_encode(['milestone_id' => $milestoneId])]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[encode_case] save failed: ' . $e->getMessage());
        return $err(500, 'Could not save the incident. Nothing was recorded.');
    }

    return ['status' => 200, 'body' => [
        'success' => true,
        'message' => 'Incident filed successfully.',
        'data' => [
            'reference_number' => $trackingId,
            'title'            => $title,
            'complainant'      => $name,
            'category'         => $type,
            'priority'         => $finalPriority,
            'status'           => $initialStatus,
            'urgency_score'    => $score,
            'recommendation'   => $t['recommendation'],
            'ai'               => $aiInfo + ['notice' => $notice],
            'override'         => $ov === null ? null : [
                'verdict'         => $ov['verdict'],
                'reason'          => $ov['reason'],
                'pending_captain' => $ov['verdict'] === 'ESCALATED',
            ],
        ],
    ]];
}

/* ------------------------------------------------------------------
   Resident online reports. Added to lib/case_intake.php so no extra file is needed.
------------------------------------------------------------------ */

/** Must be a value allowed by incident_reports.scenario_type. The old resident code used 'ONLINE'. */
const ONLINE_SCENARIO_TYPE = 'ONLINE';

/**
 * Resident online report intake.
 * Triage runs on every report. The final priority is the HIGHER of the resident's own rating and
 * the triage result, so triage can raise a report but never lowers what the resident chose.
 *
 * $in keys: complainant, phone, purok, category, narrative, priority (the resident's own rating)
 * $residentId: the verified users.id of the resident filing the report
 * Returns ['status' => HTTP code, 'body' => array]. Never prints anything.
 */
function online_case_run(array $in, int $residentId): array {
    date_default_timezone_set('Asia/Manila');
    $err = fn(int $code, string $msg) => ['status' => $code, 'body' => ['success' => false, 'message' => $msg]];

    $name      = (string) $in['complainant'];
    $phone     = (string) $in['phone'];
    $purok     = (string) $in['purok'];
    $type      = (string) $in['category'];
    $narrative = (string) $in['narrative'];
    $claimed   = (string) ($in['priority'] ?? 'High');
    if (!isset(SEV_RANK[$claimed])) $claimed = 'High';   // unknown value: fall back to the old default

    // ---- Triage (any network call happens BEFORE the transaction) ----
    $t     = triage_case($type, $narrative);
    $final = sev_rank($claimed) > sev_rank($t['priority']) ? $claimed : $t['priority'];
    [$lo, $hi] = SEV_BAND[$final];
    $score  = (float) max($lo, min($hi, (int) round($t['score'])));
    // AI crashed (not just switched off): flag for a human to look at it
    $review = ($t['source'] !== 'AI+RULES' && empty($t['ai_disabled'])) ? 'PENDING_CAPTAIN' : 'NONE';
    $tmpRef = 'TMP-' . bin2hex(random_bytes(8));

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO incident_reports
            (reference_number, complainant_name, complainant_phone, incident_type, narrative_description,
             incident_datetime, purok, scenario_type, verification_level, ai_urgency_score, priority_level,
             ai_recommendation, status, original_ai_priority, severity_review_status, created_at)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, 'UNVERIFIED', ?, ?, ?, 'PENDING', ?, ?, NOW())")
            ->execute([$tmpRef, $name, $phone, $type, $narrative, $purok, ONLINE_SCENARIO_TYPE,
                       $score, $final, $t['recommendation'], $t['ai_priority'], $review]);
        $incidentId = (int) $pdo->lastInsertId();

        $trackingId = sprintf('TRK-%s-%03d', date('Y'), $incidentId);
        $pdo->prepare('UPDATE incident_reports SET reference_number = ? WHERE id = ?')
            ->execute([$trackingId, $incidentId]);

        // Neutral note only: Agent 2 and the resident both read milestones.
        $pdo->prepare("INSERT INTO case_milestones
                (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at)
                VALUES (?, ?, 'PENDING', 'System Desk', 'Citizen online report submitted and queued for verification.', NOW())")
            ->execute([$incidentId, $trackingId]);
        $milestoneId = (int) $pdo->lastInsertId();

        $reason = $t['rationale'] . ($final !== $t['priority'] ? " Resident-selected priority {$claimed} was kept." : '');
        $pdo->prepare("INSERT INTO severity_audit_logs
            (incident_id, action, actor_user_id, original_priority, proposed_priority, final_priority,
             ai_urgency_score, ai_confidence, verdict, verdict_reason, model, latency_ms)
            VALUES (?, 'AI_TRIAGE', ?, ?, NULL, ?, ?, ?, 'N/A', ?, ?, ?)")
            ->execute([$incidentId, $residentId, $t['ai_priority'] ?? $t['priority'], $final,
                       $score, $t['confidence'], mb_substr($reason, 0, 500), $t['model'], $t['latency_ms']]);

        $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('CASE_ENCODED', ?, ?)")
            ->execute([$incidentId, json_encode(['milestone_id' => $milestoneId])]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[online_case_run] save failed: ' . $e->getMessage());
        return $err(500, 'Could not submit the report. Please try again or contact the barangay hall.');
    }

    return ['status' => 200, 'body' => ['success' => true, 'reference_number' => $trackingId]];
}