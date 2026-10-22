<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/triage.php';
require_once __DIR__ . '/override_gate.php';

function intake_pick(array $in, array $keys): string {
    foreach ($keys as $k) {
        if (isset($in[$k]) && !is_array($in[$k]) && trim((string) $in[$k]) !== '') return trim((string) $in[$k]);
    }
    return '';
}

/**
 * Shared "encode a case" logic for api/encode_case.php and api/officer_console.php.
 * Returns ['status' => HTTP code, 'body' => array]. Never prints anything.
 */
function encode_case_run(array $input, array $actor, array $opts = []): array {
    date_default_timezone_set('Asia/Manila');
    $online = !empty($opts['online']);   // true for reports filed by residents through the portal
    $err = fn(int $code, string $msg, array $extra = []) =>
        ['status' => $code, 'body' => array_merge(['success' => false, 'message' => $msg], $extra)];

    $actorId   = (int) $actor['id'];
    $actorName = (string) (($actor['full_name'] ?? '') ?: 'Desk Officer');
    $name      = intake_pick($input, ['complainantName', 'complainant_name']);
    $phoneRaw  = intake_pick($input, ['complainantPhone', 'complainant_phone']);
    $purok     = intake_pick($input, ['purok']);
    $street    = intake_pick($input, ['streetAddress', 'street_address']);
    $narrative = intake_pick($input, ['incidentNarrative', 'narrative_description', 'narrative']);
    $hintType  = intake_pick($input, ['incidentCategory', 'incident_type', 'category']);
    $chosen    = intake_pick($input, ['priorityLevel', 'priority_level', 'priority']);   // optional: officer-chosen priority
    if ($online) $chosen = '';   // residents never choose the priority: the triage decides it from the narrative

    // ---- Validation ----
    if ($name === '') $name = 'Walk-in Resident';
    if (mb_strlen($name) > 120)      return $err(422, 'The complainant name is too long (max 120 characters).');
    if ($purok === '' || $purok === 'Select Purok') return $err(422, 'Please select a valid Purok / Zone.');
    if (mb_strlen($purok) > 60)      return $err(422, 'The Purok value is too long.');
    if (mb_strlen($street) > 255)    return $err(422, 'The street address is too long (max 255 characters).');
    if (mb_strlen($narrative) < 10)  return $err(422, 'Please describe what happened (at least 10 characters).');
    if (mb_strlen($narrative) > 5000) return $err(422, 'The narrative is too long (max 5000 characters).');
    if ($chosen !== '' && !isset(SEV_RANK[$chosen])) return $err(422, 'Invalid priority level specified.');

    $phone = 'N/A';
    if ($phoneRaw !== '' && strtoupper($phoneRaw) !== 'N/A') {
        $digits = (string) preg_replace('/[\s\-()]/', '', $phoneRaw);
        if (preg_match('/^(?:\+?63|0)(9\d{9})$/', $digits, $pm)) $phone = '0' . $pm[1];
        elseif (!$online) return $err(422, 'The contact number must be a valid Philippine mobile number (09XXXXXXXXX), or leave it blank.');
    }

    $channel = (string) ($input['intakeChannel'] ?? '');
    $scenarioType = strtoupper(str_replace(' ', '_', (string) preg_replace('/[^A-Za-z0-9 ]/', '', $channel)));
    if (!in_array($scenarioType, ['WALK_IN', 'ONLINE_PORTAL', 'EMERGENCY'], true)) $scenarioType = 'WALK_IN';
    if (!empty($opts['scenario'])) $scenarioType = (string) $opts['scenario'];

    // ---- Agent 1: analysis (any network call happens BEFORE the transaction) ----
    $t = triage_case($narrative, $hintType);
    $a = $t['analysis'];
    $type = $a['incident_type'];

    $review = 'NONE';
    $notice = null;
    $ov     = null;
    $justification = $verifiedBy = $evidenceType = '';
    $aiInfo = ['source' => $t['source'], 'priority' => $t['priority'], 'confidence' => $t['confidence'],
               'rationale' => $t['rationale'], 'triggers' => $t['triggers'],
               // Officer guidance (suggestions only; a person decides). 'disagreement' is null when the AI was not used.
               'summary'      => (string) ($a['summary'] ?? ''),
               'next_steps'   => array_values((array) ($a['ai_next_steps'] ?? [])),
               'questions'    => array_values((array) ($a['questions_for_resident'] ?? [])),
               'disagreement' => $a['disagreement'] ?? null];

    // ---- Downgrade gate (only when the officer explicitly chose a lower priority) ----
    if ($chosen === '' || sev_rank($chosen) >= sev_rank($t['priority'])) {
        $finalPriority = ($chosen === '') ? $t['priority'] : $chosen;
    } else {
        $justification = intake_pick($input, ['overrideJustification']);
        $verifiedBy    = intake_pick($input, ['overrideVerifiedBy']);
        $evidenceType  = strtoupper(intake_pick($input, ['overrideEvidenceType']));

        if ($justification === '' && $verifiedBy === '' && $evidenceType === '') {
            return $err(409, "The analysis rated this case {$t['priority']}. Lowering it to {$chosen} requires a justification.", [
                'requires_override' => true,
                'required_fields'   => ['overrideJustification', 'overrideVerifiedBy', 'overrideEvidenceType'],
                'evidence_types'    => OVERRIDE_EVIDENCE_TYPES,
                'ai'                => $aiInfo,
            ]);
        }
        $ov = evaluate_override([
            'narrative' => $narrative, 'original' => $t['priority'], 'proposed' => $chosen,
            'justification' => $justification, 'verified_by' => $verifiedBy, 'evidence_type' => $evidenceType,
        ]);
        if ($ov['verdict'] === 'REJECTED') {
            logAudit('SEVERITY_OVERRIDE_REJECTED', [
                'predicted' => $t['priority'], 'requested' => $chosen, 'justification' => mb_substr($justification, 0, 500),
                'verified_by' => $verifiedBy, 'evidence_type' => $evidenceType, 'reason' => $ov['reason'], 'model' => $ov['model'],
            ], $actorId);
            return $err(422, 'Justification rejected: ' . $ov['reason'], ['override_rejected' => true, 'ai' => $aiInfo]);
        }
        if ($ov['verdict'] === 'ACCEPTED') {
            $finalPriority = $chosen;
        } else {
            $finalPriority = $t['priority'];
            $review = 'PENDING_CAPTAIN';
            $notice = "Downgrade to {$chosen} is pending Captain approval. The case stays {$finalPriority} until then.";
        }
    }
    if ($t['source'] !== 'AI+RULES' && empty($t['ai_disabled'])) {   // AI is configured but failed
        $review = 'PENDING_CAPTAIN';
        $notice = trim(($notice ?? '') . ' AI second opinion was unavailable; rules-only result. Flagged for Captain review.');
    }

    $initialStatus = ($finalPriority === 'Critical') ? 'CRITICAL' : 'PENDING';
    $tmpRef = 'TMP-' . bin2hex(random_bytes(8));
    $score  = $t['score'];

    // ---- One transaction: case + parties + milestone + audit + outbox ----
    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO incident_reports
            (reference_number, complainant_name, complainant_phone, incident_type, narrative_description, incident_datetime,
             purok, ai_urgency_score, priority_level, ai_recommendation, status,
             assigned_officer_id, assigned_officer_name)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tmpRef, $name, $phone, $type, $narrative, $purok,
                       $score, $finalPriority, $t['recommendation'], $initialStatus,
                       $online ? null : ($actorId ?: null), $online ? 'Unassigned' : $actorName]);
        $incidentId = (int) $pdo->lastInsertId();

        $trackingId = sprintf('TRK-%s-%03d', date('Y'), $incidentId);
        $pdo->prepare('UPDATE incident_reports SET reference_number = ? WHERE id = ?')->execute([$trackingId, $incidentId]);

    try {
            $party = $pdo->prepare('INSERT INTO case_parties (incident_id, role, full_name, is_minor) VALUES (?, ?, ?, 0)');
            if ($name !== 'Walk-in Resident') $party->execute([$incidentId, 'COMPLAINANT', $name]);
            foreach (array_slice((array) ($a['entities']['respondents'] ?? []), 0, 3) as $rn) {
                $party->execute([$incidentId, 'RESPONDENT', mb_substr($rn, 0, 120)]);
            }
        } catch (Throwable $ignore) {
            // Skips if table doesn't exist
        }

        // Neutral note only: Agent 2 reads milestones, so no justification or analysis text goes here.
        $pdo->prepare('INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$incidentId, $trackingId, $initialStatus, $online ? 'Online Portal' : $actorName,
                       ($online ? 'Online report received and logged' : 'Initial report logged') . " as {$type}. Priority: {$finalPriority}."]);
        $milestoneId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO severity_audit_logs
            (incident_id, action, actor_user_id, original_priority, proposed_priority, final_priority, ai_urgency_score, ai_confidence,
             verdict, verdict_reason, model, latency_ms)
            VALUES (?, 'AI_TRIAGE', ?, ?, NULL, ?, ?, ?, 'N/A', ?, ?, ?)")
            ->execute([$incidentId, $actorId, $t['priority'], $t['priority'], $score, $t['confidence'],
                       mb_substr($t['rationale'], 0, 500), $t['model'], $t['latency_ms']]);

        if ($ov !== null) {
            $pdo->prepare("INSERT INTO severity_audit_logs
                (incident_id, action, actor_user_id, original_priority, proposed_priority, final_priority, ai_urgency_score, ai_confidence,
                 justification_text, verified_by, evidence_type, verdict, verdict_reason, model, latency_ms)
                VALUES (?, 'OVERRIDE_REQUEST', ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$incidentId, $actorId, $t['priority'], $chosen, $finalPriority, $ov['confidence'], $justification, $verifiedBy,
                           $evidenceType, $ov['verdict'], mb_substr($ov['reason'], 0, 500), $ov['model'], $ov['latency_ms']]);
        }

        $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('CASE_ENCODED', ?, ?)")
            ->execute([$incidentId, json_encode(['milestone_id' => $milestoneId])]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return $err(500, 'DB Error: ' . $e->getMessage());
    }

    logAudit('ENCODE_INCIDENT', ['reference' => $trackingId, 'type' => $type, 'priority' => $finalPriority], $actorId);

    return ['status' => 200, 'body' => [
        'success'          => true,
        'message'          => "Case {$trackingId} recorded as {$type} ({$finalPriority}).",
        'reference_number' => $trackingId,
        'priority'         => $finalPriority,
        'data'             => [
            'reference_number'    => $trackingId,
            'category'            => $type,
            'priority'            => $finalPriority,
            'status'              => $initialStatus,
            'urgency_score'       => $score,
            'recommendation'      => $t['recommendation'],
            'legal_routing'       => $a['legal_routing'],
            'kp_status'           => $a['kp_status'],
            'recommended_actions' => $a['recommended_actions'],
            'ai'                  => $aiInfo + ['notice' => $notice],
            'override'            => $ov === null ? null : [
                'verdict' => $ov['verdict'], 'reason' => $ov['reason'], 'pending_captain' => $ov['verdict'] === 'ESCALATED',
            ],
        ],
    ]];
}

/**
 * Called by api/resident_console.php when a resident files a report online.
 * Runs the same triage as the desk officer path. The resident's own priority is ignored, and the category is only a hint.
 * Returns ['status' => HTTP code, 'body' => array] like encode_case_run().
 */
function online_case_run(array $in, int $userId): array {
    $name = trim((string) ($in['complainant'] ?? ''));
    return encode_case_run([
        'complainant_name'      => $name,
        'complainant_phone'     => (string) ($in['phone'] ?? ''),
        'purok'                 => (string) ($in['purok'] ?? ''),
        'street_address'        => (string) ($in['street'] ?? $in['street_address'] ?? ''),
        'narrative_description' => (string) ($in['narrative'] ?? ''),
        'incident_type'         => (string) ($in['category'] ?? ''),
    ], ['id' => $userId, 'full_name' => $name !== '' ? $name : 'Online Resident'], ['online' => true, 'scenario' => 'ONLINE_PORTAL']);
}