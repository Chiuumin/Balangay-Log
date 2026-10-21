<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/triage.php';

function barangay_profile(): array {
    static $p = null;
    if ($p === null) {
        $f = __DIR__ . '/../config/barangay_profile.php';
        $p = is_file($f) ? (array) include $f : [];
    }
    return $p + ['puroks' => [], 'adjacent' => [], 'eta' => ['same' => '2-3 min', 'adjacent' => '5-8 min', 'far' => '10-15 min'],
                 'landmarks' => [], 'tanods' => []];
}

/** The stored analysis if it is current, otherwise a fresh rules analysis (not saved). */
function case_analysis_for(array $row): array {
    $stored = !empty($row['ai_analysis_json']) ? json_decode((string) $row['ai_analysis_json'], true) : null;
    if (is_array($stored) && ($stored['engine_version'] ?? '') === ENGINE_VERSION && isset($stored['priority_level'], $stored['kp_status'])) {
        return $stored;
    }
    return analyze_narrative((string) ($row['narrative_description'] ?? ''), (string) ($row['incident_type'] ?? ''));
}

// ---------- Pillar 3: duplicates (keyword similarity, no embeddings) ----------
function sim_tokens(string $text): array {
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(['ang', 'ng', 'sa', 'na', 'at', 'ay', 'si', 'ni', 'kay', 'ko', 'mo', 'ako', 'siya', 'sila', 'kami', 'kayo', 'ito', 'iyon',
            'yung', 'yong', 'may', 'mga', 'para', 'pero', 'kaya', 'dahil', 'daw', 'raw', 'po', 'lang', 'din', 'rin', 'nang', 'nung', 'noong',
            'kanina', 'ngayon', 'pa', 'ba', 'naman', 'kung', 'habang', 'mula', 'hanggang', 'dito', 'doon', 'diyan', 'namin', 'nila', 'niya',
            'kanya', 'nasa', 'the', 'and', 'was', 'were', 'that', 'this', 'with', 'from', 'have', 'has', 'had', 'for', 'but', 'are', 'his',
            'her', 'their', 'they', 'them', 'him', 'she', 'about', 'after', 'before']);
    }
    preg_match_all('/\p{L}{3,}/u', mb_strtolower($text), $m);
    $out = [];
    foreach ($m[0] as $w) {
        $w = (string) preg_replace('/^(pinag|nag|mag|pag)(?=\p{L}{4,})/u', '', $w);
        if (isset($stop[$w])) continue;
        $out[$w] = ($out[$w] ?? 0) + 1;
    }
    return $out;
}

function sim_score(array $a, array $b): array {
    if (!$a || !$b) return [0.0, []];
    $dot = 0.0;
    $shared = [];
    foreach ($a as $t => $c) {
        if (isset($b[$t])) { $dot += $c * $b[$t]; $shared[] = $t; }
    }
    $na = sqrt(array_sum(array_map(fn($x) => $x * $x, $a)));
    $nb = sqrt(array_sum(array_map(fn($x) => $x * $x, $b)));
    return [($na > 0 && $nb > 0) ? $dot / ($na * $nb) : 0.0, array_slice($shared, 0, 6)];
}

function review_duplicates(PDO $pdo, array $case): array {
    $st = $pdo->prepare("SELECT id, reference_number, incident_type, narrative_description, complainant_name, street_address, status, created_at,
                                TIMESTAMPDIFF(MINUTE, created_at, ?) AS minutes_apart
                         FROM incident_reports
                         WHERE id <> ? AND purok = ?
                           AND created_at BETWEEN DATE_SUB(?, INTERVAL 4 HOUR) AND DATE_ADD(?, INTERVAL 4 HOUR)
                         ORDER BY created_at DESC LIMIT 25");
    $st->execute([$case['created_at'], $case['id'], $case['purok'], $case['created_at'], $case['created_at']]);
    $rows = $st->fetchAll();

    $linked = [];
    $ls = $pdo->prepare('SELECT primary_incident_id AS a, duplicate_incident_id AS b FROM case_links WHERE primary_incident_id = ? OR duplicate_incident_id = ?');
    $ls->execute([$case['id'], $case['id']]);
    foreach ($ls->fetchAll() as $l) { $linked[(int) $l['a']] = true; $linked[(int) $l['b']] = true; }

    $mine = sim_tokens((string) $case['narrative_description']);
    $out = [];
    foreach ($rows as $r) {
        [$score, $shared] = sim_score($mine, sim_tokens((string) $r['narrative_description']));
        $sameStreet = !empty($case['street_address']) && strcasecmp((string) $case['street_address'], (string) $r['street_address']) === 0;
        if ($sameStreet) $score = min(1.0, $score + 0.10);
        if ($score < 0.40) continue;
        $out[] = [
            'reference_number' => $r['reference_number'],
            'incident_type'    => $r['incident_type'],
            'complainant_name' => $r['complainant_name'],
            'status'           => $r['status'],
            'minutes_apart'    => abs((int) $r['minutes_apart']),
            'similarity'       => (int) round($score * 100),
            'shared_terms'     => $shared,
            'label'            => $score >= 0.65 ? 'LIKELY DUPLICATE' : 'POSSIBLE RELATED REPORT',
            'linked'           => isset($linked[(int) $r['id']]),
        ];
    }
    usort($out, fn($x, $y) => $y['similarity'] <=> $x['similarity']);
    return array_slice($out, 0, 3);
}

// ---------- Pillar 4: recidivism and history ----------
function review_history(PDO $pdo, array $case, array $analysis): array {
    $name  = trim((string) $case['complainant_name']);
    $phone = trim((string) $case['complainant_phone']);
    $addr  = trim((string) ($case['street_address'] ?? ''));
    $resp  = array_slice((array) ($analysis['entities']['respondents'] ?? []), 0, 3);

    $conds = [];
    $params = [];
    if ($name !== '' && strcasecmp($name, 'Walk-in Resident') !== 0) { $conds[] = 'r.complainant_name = ?'; $params[] = $name; }
    if ($phone !== '' && strtoupper($phone) !== 'N/A') { $conds[] = 'r.complainant_phone = ?'; $params[] = $phone; }
    if ($addr !== '') { $conds[] = 'r.street_address = ?'; $params[] = $addr; }
    foreach ($resp as $rn) {
        $conds[] = "EXISTS (SELECT 1 FROM case_parties p WHERE p.incident_id = r.id AND p.role = 'RESPONDENT' AND p.full_name = ?)";
        $params[] = $rn;
        $conds[] = 'r.narrative_description LIKE ?';
        $params[] = '%' . addcslashes($rn, '%_\\') . '%';
    }
    $empty = ['items' => [], 'insights' => [], 'counts' => ['complainant' => 0, 'respondent' => 0, 'address' => 0]];
    if (!$conds) return $empty;

    $sql = "SELECT r.id, r.reference_number, r.incident_type, r.status, r.priority_level, r.created_at, r.complainant_name,
                   r.complainant_phone, r.street_address, r.narrative_description,
                   (SELECT COUNT(*) FROM case_milestones m WHERE m.incident_id = r.id AND m.status_snapshot = 'FOR_RESOLUTION') AS lupon_steps,
                   (SELECT MIN(m.created_at) FROM case_milestones m WHERE m.incident_id = r.id AND m.status_snapshot = 'FOR_RESOLUTION') AS lupon_since,
                   (SELECT COUNT(*) FROM case_milestones m WHERE m.incident_id = r.id AND m.status_snapshot = 'RESOLVED' AND m.action_note LIKE '%settle%') AS settled_marks,
                   (SELECT MAX(m.created_at) FROM case_milestones m WHERE m.incident_id = r.id AND m.status_snapshot = 'RESOLVED' AND m.action_note LIKE '%settle%') AS settled_at
            FROM incident_reports r
            WHERE r.id <> ? AND r.created_at >= DATE_SUB(?, INTERVAL 12 MONTH) AND r.created_at < ?
              AND (" . implode(' OR ', $conds) . ")
            ORDER BY r.created_at DESC LIMIT 15";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$case['id'], $case['created_at'], $case['created_at']], $params));
    $rows = $st->fetchAll();

    $counts = ['complainant' => 0, 'respondent' => 0, 'address' => 0];
    $items = [];
    $insights = [];
    $caseTs = strtotime((string) $case['created_at']) ?: time();
    foreach ($rows as $r) {
        $reasons = [];
        if (($name !== '' && strcasecmp($name, (string) $r['complainant_name']) === 0 && strcasecmp($name, 'Walk-in Resident') !== 0)
            || ($phone !== '' && strtoupper($phone) !== 'N/A' && $phone === $r['complainant_phone'])) {
            $reasons[] = 'same complainant'; $counts['complainant']++;
        }
        foreach ($resp as $rn) {
            if (stripos((string) $r['narrative_description'], $rn) !== false) { $reasons[] = 'same respondent (' . $rn . ')'; $counts['respondent']++; break; }
        }
        if ($addr !== '' && strcasecmp($addr, (string) $r['street_address']) === 0) { $reasons[] = 'same address'; $counts['address']++; }
        $items[] = [
            'reference_number' => $r['reference_number'], 'incident_type' => $r['incident_type'], 'status' => $r['status'],
            'priority_level' => $r['priority_level'], 'created_at' => $r['created_at'], 'reasons' => $reasons ?: ['related'],
        ];

        if ((int) $r['settled_marks'] > 0 && (int) $r['lupon_steps'] > 0 && $r['settled_at']) {
            $months = ($caseTs - (strtotime((string) $r['settled_at']) ?: $caseTs)) / (30 * 86400);
            $insights[] = $months <= 6
                ? ['level' => 'high', 'text' => "Earlier case {$r['reference_number']} was settled at the Lupon on " . date('M j, Y', strtotime((string) $r['settled_at'])) . '. A new complaint within 6 months may indicate a breach of the amicable settlement, which the Lupon can enforce by execution within 6 months (LGC Sec. 417).']
                : ['level' => 'info', 'text' => "Earlier case {$r['reference_number']} was settled more than 6 months ago; enforcing that settlement now needs a court action."];
        }
        if ((int) $r['lupon_steps'] > 0 && $r['status'] === 'FOR_RESOLUTION' && $r['lupon_since']
            && (time() - (strtotime((string) $r['lupon_since']) ?: time())) > 45 * 86400) {
            $insights[] = ['level' => 'info', 'text' => "Case {$r['reference_number']} has been with the Lupon for over 45 days. A Certificate to File Action (CFA) may be requested instead of repeating conciliation, after confirming the mediation periods have lapsed."];
        }
    }
    if ($counts['respondent'] >= 2)  array_unshift($insights, ['level' => 'high', 'text' => "Repeat respondent: named in {$counts['respondent']} earlier reports within 12 months."]);
    elseif ($counts['respondent'] === 1) $insights[] = ['level' => 'info', 'text' => 'The respondent was named in 1 earlier report within 12 months.'];
    if ($counts['address'] >= 2)     array_unshift($insights, ['level' => 'high', 'text' => "Hotspot address: {$counts['address']} earlier reports from the same address."]);
    if ($counts['complainant'] >= 3) $insights[] = ['level' => 'info', 'text' => "The complainant has filed {$counts['complainant']} earlier reports in 12 months."];

    return ['items' => array_slice($items, 0, 6), 'insights' => array_slice($insights, 0, 5), 'counts' => $counts];
}

// ---------- Pillar 5: tanod beat and nearby sensitive sites ----------
function tanod_status_map(PDO $pdo): array {
    $profile = barangay_profile();
    $rows = $pdo->query("SELECT reference_number, purok, deployed_unit FROM incident_reports
                         WHERE deployed_unit IS NOT NULL AND deployed_unit <> '' AND status IN ('DISPATCHED','IN_PROGRESS','CRITICAL','PENDING')
                         ORDER BY created_at DESC LIMIT 200")->fetchAll();
    $out = [];
    foreach ($profile['tanods'] as $i => $t) {
        $row = ['id' => $i + 1, 'name' => $t['name'], 'station' => $t['station'] ?? '', 'unit' => $t['unit'] ?? '',
                'status' => 'AVAILABLE', 'purok' => $t['base_purok'] ?? null, 'case_ref' => null];
        if (empty($t['on_duty'])) {
            $row['status'] = 'OFF_DUTY';
        } else {
            foreach ($rows as $r) {
                if (stripos((string) $r['deployed_unit'], $t['name']) !== false) {
                    $row['status'] = 'DEPLOYED'; $row['purok'] = $r['purok']; $row['case_ref'] = $r['reference_number'];
                    break;
                }
            }
        }
        $out[] = $row;
    }
    return $out;
}

function review_beat(PDO $pdo, array $case): array {
    $profile = barangay_profile();
    $purok = (string) $case['purok'];
    $adj = (array) ($profile['adjacent'][$purok] ?? []);

    // landmarks and sensitive sites mentioned in the narrative or address
    $hay = mb_strtolower(((string) $case['narrative_description']) . ' ' . ((string) ($case['street_address'] ?? '')));
    $found = [];
    foreach ($profile['landmarks'] as $lm) {
        foreach ((array) ($lm['aliases'] ?? []) as $alias) {
            if ($alias !== '' && mb_strpos($hay, mb_strtolower($alias)) !== false) {
                $found[$lm['name']] = ['name' => $lm['name'], 'type' => $lm['type'] ?? '', 'sensitive' => in_array($lm['type'] ?? '', ['school', 'daycare', 'market', 'chapel', 'health'], true)];
                break;
            }
        }
    }
    $found = array_values($found);

    $cands = [];
    foreach (tanod_status_map($pdo) as $t) {
        if ($t['status'] === 'OFF_DUTY') continue;
        $dist = ($t['purok'] === $purok) ? 0 : (in_array($t['purok'], $adj, true) ? 1 : 2);
        $t['distance'] = $dist;
        $t['eta'] = $profile['eta'][['same', 'adjacent', 'far'][$dist]] ?? '';
        $cands[] = $t;
    }
    usort($cands, fn($a, $b) => [$a['status'] === 'DEPLOYED' ? 1 : 0, $a['distance']] <=> [$b['status'] === 'DEPLOYED' ? 1 : 0, $b['distance']]);

    if (!$cands) {
        $summary = 'No tanod is on duty in the roster. Request PNP or neighboring barangay support.';
    } else {
        $b = $cands[0];
        $where = $b['status'] === 'DEPLOYED' ? "currently handling {$b['case_ref']} in {$b['purok']}" : "on patrol in {$b['purok']}";
        $summary = 'Beat match: ' . $b['name'] . ($b['unit'] ? ' (' . $b['unit'] . ')' : '') . ' is ' . $where . ', about ' . $b['eta'] . ' away.';
        if ($b['status'] === 'DEPLOYED') $summary .= ' No free unit is nearby; consider requesting backup.';
    }
    $sens = array_values(array_filter($found, fn($f) => $f['sensitive']));
    if ($sens) $summary .= ' Near a sensitive site: ' . implode(', ', array_column($sens, 'name')) . '.';
    elseif ($found) $summary .= ' Landmark: ' . implode(', ', array_column($found, 'name')) . '.';
    $summary .= ' (Response time is a rough estimate by purok distance, not GPS.)';

    return ['summary' => $summary, 'best' => $cands[0] ?? null, 'alternatives' => array_slice($cands, 1, 2), 'landmarks' => $found];
}

// ---------- The review popup ----------
function case_review_run(PDO $pdo, string $ref): array {
    $st = $pdo->prepare('SELECT * FROM incident_reports WHERE reference_number = ? LIMIT 1');
    $st->execute([$ref]);
    $case = $st->fetch();
    if (!$case) return ['status' => 404, 'body' => ['success' => false, 'message' => 'Case not found.']];

    $analysis  = case_analysis_for($case);
    $recordPri = (string) ($case['priority_level'] ?? 'Low');
    $autoRaised = false;
    $previous  = null;

    $stale = empty($case['ai_analysis_json']) || ($case['analysis_version'] ?? '') !== ENGINE_VERSION;
    if ($stale) {
        $pdo->beginTransaction();
        try {
            $newType = $case['incident_type'];
            if (in_array((string) $newType, ['', 'General Incident', 'Others'], true) && $analysis['incident_type'] !== 'Others') {
                $newType = $analysis['incident_type'];
            }
            $final = $recordPri;
            $status = $case['status'];
            if (sev_rank($analysis['priority_level']) > sev_rank($recordPri)) {   // only ever raise
                $final = $analysis['priority_level'];
                $autoRaised = true;
                $previous = $recordPri;
                if ($final === 'Critical' && $status === 'PENDING') $status = 'CRITICAL';
                $pdo->prepare("INSERT INTO severity_audit_logs
                    (incident_id, action, actor_user_id, original_priority, proposed_priority, final_priority, ai_urgency_score, verdict, verdict_reason, model, latency_ms)
                    VALUES (?, 'SEVERITY_RAISE', NULL, ?, ?, ?, ?, 'N/A', ?, 'auto-review', 0)")
                    ->execute([$case['id'], $recordPri, $final, $final, $analysis['ai_urgency_score'],
                               mb_substr('Raised by automatic review: ' . $analysis['reasoning'], 0, 500)]);
            }
            $pdo->prepare('UPDATE incident_reports SET priority_level = ?, status = ?, incident_type = ?, ai_urgency_score = ?, ai_analysis_json = ?,
                                  analysis_version = ?, legal_routing = ?, weapon_detected = ?, vawc_minor_flag = ?, ai_recommendation = ? WHERE id = ?')
                ->execute([$final, $status, $newType, $analysis['ai_urgency_score'], json_encode($analysis, JSON_UNESCAPED_UNICODE),
                           ENGINE_VERSION, $analysis['legal_routing'], $analysis['weapon_flag'], $analysis['vawc_minor_flag'] ? 1 : 0,
                           $analysis['ai_recommendation'], $case['id']]);
            $pdo->commit();
            $case['priority_level'] = $final;
            $case['status'] = $status;
            $case['incident_type'] = $newType;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[case_review] sync failed: ' . $e->getMessage());
        }
    }

    return ['status' => 200, 'body' => [
        'success'    => true,
        'case'       => [
            'reference_number' => $case['reference_number'], 'complainant_name' => $case['complainant_name'],
            'complainant_phone' => $case['complainant_phone'], 'purok' => $case['purok'], 'street_address' => $case['street_address'] ?? '',
            'incident_type' => $case['incident_type'], 'narrative_description' => $case['narrative_description'],
            'priority_level' => $case['priority_level'], 'status' => $case['status'], 'created_at' => $case['created_at'],
            'deployed_unit' => $case['deployed_unit'] ?? '', 'severity_review_status' => $case['severity_review_status'] ?? 'NONE',
        ],
        'analysis'   => $analysis,
        'severity'   => ['record' => $case['priority_level'], 'analysis' => $analysis['priority_level'], 'auto_raised' => $autoRaised, 'previous' => $previous],
        'duplicates' => review_duplicates($pdo, $case),
        'history'    => review_history($pdo, $case, $analysis),
        'beat'       => review_beat($pdo, $case),
    ]];
}