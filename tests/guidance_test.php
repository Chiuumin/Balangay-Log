<?php
/**
 * Offline tests for the officer-guidance upgrade in lib/triage.php.
 * No network, no database, no API key needed.  Run:  php tests/guidance_test.php
 *
 * It checks the pure functions only (analyze_narrative, triage_merge_ai, triage_clean_list).
 * It does NOT call the real AI, so it cannot tell you how well the AI writes guidance.
 */
require_once __DIR__ . '/../lib/triage.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n"; }
}

/** A typical AI answer; tests override single fields. */
function ai_out(array $over = []): array {
    return array_merge([
        'priority' => 'High', 'category' => 'Threats & Intimidation', 'confidence' => 0.8,
        'key_terms' => [], 'reasoning' => 'Test reasoning.', 'recommended_action' => 'Send a tanod.',
        'summary' => 'A neighbor is said to carry something sharp in a bag.',
        'next_steps' => ['Send a tanod to verify.', 'Keep the complainant away from the person.'],
        'questions_for_resident' => ['What exactly did the person carry?', 'Where is the person now?'],
    ], $over);
}

echo "Rules-side guidance (works offline)\n";
$a = analyze_narrative('Sinuntok ako ng kapitbahay kanina sa kalsada');
check('summary is a non-empty string', is_string($a['summary']) && $a['summary'] !== '');
check('questions list has 1 to 5 items', count($a['questions_for_resident']) >= 1 && count($a['questions_for_resident']) <= 5);
check('asks about a weapon when none is mentioned', (bool) preg_grep('/weapon/i', $a['questions_for_resident']));
check('asks about injury when none is mentioned', (bool) preg_grep('/injured|bleeding/i', $a['questions_for_resident']));
check('guidance_source is rules', $a['guidance_source'] === 'rules');

$a = analyze_narrative('Binaril ng lasing ang tindero, duguan');
check('no weapon question when a firearm is already mentioned', !preg_grep('/weapon/i', $a['questions_for_resident']));
check('no injury question when injury is already mentioned', !preg_grep('/injured|bleeding/i', $a['questions_for_resident']));

$a = analyze_narrative('ok');
check('very short narrative asks for the full story', (bool) preg_grep('/describe who did what/i', $a['questions_for_resident']));

echo "\nAI higher than the rules (the AI caught something the rules missed)\n";
$rules = analyze_narrative('may dala daw siyang matalim sa bag, kinakabahan kami');
$n = 'may dala daw siyang matalim sa bag, kinakabahan kami';
check('precondition: rules say Low', $rules['priority_level'] === 'Low');
$m = triage_merge_ai($rules, ai_out(), $n);
check('priority raised to High', $m['priority_level'] === 'High');
check('disagreement flagged: ai_higher', $m['disagreement']['any'] === true && $m['disagreement']['direction'] === 'ai_higher');
check('flag records rules Low / AI High / final High',
    $m['disagreement']['rules_priority'] === 'Low' && $m['disagreement']['ai_priority'] === 'High' && $m['disagreement']['final_priority'] === 'High');
check('message mentions both priorities', str_contains($m['disagreement']['message'], 'Low') && str_contains($m['disagreement']['message'], 'High'));
check('AI summary replaces the rules summary', str_contains($m['summary'], 'sharp'));
check('rules summary is kept separately', $m['rules_summary'] !== '' && !str_contains($m['rules_summary'], 'sharp'));
check('AI next steps stored', $m['ai_next_steps'] === ['Send a tanod to verify.', 'Keep the complainant away from the person.']);
check('AI questions replace rules questions', $m['questions_for_resident'][0] === 'What exactly did the person carry?');
check('guidance_source is ai', $m['guidance_source'] === 'ai');

echo "\nAI lower than the rules (a rules false alarm): priority must NOT drop\n";
$n = 'may baril sa pelikula na pinanood namin kagabi';
$rules = analyze_narrative($n);
check('precondition: rules say Critical', $rules['priority_level'] === 'Critical');
$m = triage_merge_ai($rules, ai_out(['priority' => 'Low', 'category' => 'Others']), $n);
check('priority stays Critical', $m['priority_level'] === 'Critical');
check('disagreement flagged: ai_lower', $m['disagreement']['direction'] === 'ai_lower' && $m['disagreement']['any'] === true);
check('message says the AI cannot lower it', str_contains($m['disagreement']['message'], 'cannot lower'));

echo "\nAgreement: no warning\n";
$n = 'Maingay ang videoke ng kapitbahay hanggang madaling araw';
$rules = analyze_narrative($n);
$m = triage_merge_ai($rules, ai_out(['priority' => 'Low', 'category' => 'Noise Disturbance']), $n);
check('no disagreement when both say Low / Noise Disturbance', $m['disagreement']['any'] === false && $m['disagreement']['message'] === '');

echo "\nCategory-only disagreement\n";
$m = triage_merge_ai($rules, ai_out(['priority' => 'Low', 'category' => 'Property Dispute']), $n);
check('category disagreement flagged without a priority flag', $m['disagreement']['category'] === true && $m['disagreement']['priority'] === false);
check('rules category kept when the AI did not raise priority', $m['incident_type'] === 'Noise Disturbance');

echo "\nSafety invariants over every AI priority\n";
$bad = [];
foreach (['may dala daw siyang matalim sa bag', 'may baril sa pelikula', 'Sinuntok ako ng kapitbahay', 'Maingay ang videoke', 'Sinaksak ng mister ang misis niya'] as $n) {
    $rules = analyze_narrative($n);
    foreach (array_keys(SEV_RANK) as $p) {
        $m = triage_merge_ai($rules, ai_out(['priority' => $p]), $n);
        if (sev_rank($m['priority_level']) < sev_rank($rules['priority_level'])) $bad[] = "$n / AI=$p";
        if ($m['legal_routing'] !== $rules['legal_routing'] || $m['kp_status'] !== $rules['kp_status'] || $m['legal_pill'] !== $rules['legal_pill']) $bad[] = "routing changed: $n / AI=$p";
    }
}
check('final priority is never below the rules priority (20 combinations)', !$bad, implode('; ', $bad));
check('legal routing, KP status and pill never changed by the AI (20 combinations)', !$bad);

echo "\nNo mediation advice on cases that cannot be mediated\n";
$n = 'Sinaksak ng mister ang misis niya kagabi sa bahay';
$rules = analyze_narrative($n);
check('precondition: RA 9262 case', $rules['vawc_flag'] === true && $rules['kp_status'] === 'EXEMPT');
$m = triage_merge_ai($rules, ai_out([
    'priority' => 'Critical', 'category' => 'Domestic Altercation',
    'next_steps' => ['Call the PNP now.', 'Mediate at the Lupon tomorrow.', 'Magpa-areglo sila sa barangay.', 'Offer a Barangay Protection Order.'],
    'questions_for_resident' => ['Is the victim safe?', 'Would you like to settle this with your husband?'],
]), $n);
check('mediation / settlement steps removed', $m['ai_next_steps'] === ['Call the PNP now.', 'Offer a Barangay Protection Order.'], json_encode($m['ai_next_steps']));
check('settlement question removed', $m['questions_for_resident'] === ['Is the victim safe?'], json_encode($m['questions_for_resident']));

echo "\nMissing or malformed optional fields\n";
$n = 'Sinuntok ako ng kapitbahay kanina sa kalsada';
$rules = analyze_narrative($n);
$old = ['priority' => 'Moderate', 'category' => 'Physical Altercation', 'confidence' => 0.7, 'reasoning' => 'r', 'recommended_action' => 'a'];
$m = triage_merge_ai($rules, $old, $n);   // an AI answer in the OLD format (no new fields)
check('old-format AI answer still works', $m['priority_level'] === 'Moderate');
check('falls back to rules summary and questions', $m['summary'] === $rules['summary'] && $m['questions_for_resident'] === $rules['questions_for_resident']);
check('guidance_source stays rules', $m['guidance_source'] === 'rules');
check('ai_next_steps is an empty list', $m['ai_next_steps'] === []);

$m = triage_merge_ai($rules, ai_out(['next_steps' => "1. First step\n2) Second step\n- Third step", 'questions_for_resident' => 'one question?']), $n);
check('string instead of list is split on lines and numbering stripped', $m['ai_next_steps'] === ['First step', 'Second step', 'Third step'], json_encode($m['ai_next_steps']));
check('single string question becomes one item', $m['questions_for_resident'] === ['one question?']);

$m = triage_merge_ai($rules, ai_out(['next_steps' => [1, null, ['x'], 'ok', '', '   ', 'OK'], 'summary' => 123]), $n);
check('non-string and duplicate items dropped', $m['ai_next_steps'] === ['ok'], json_encode($m['ai_next_steps']));
check('non-string summary ignored', $m['summary'] === $rules['summary']);

$m = triage_merge_ai($rules, ai_out(['next_steps' => array_map(fn($i) => "Step number $i", range(1, 20))]), $n);
check('at most 5 steps kept', count($m['ai_next_steps']) === 5);
$m = triage_merge_ai($rules, ai_out(['next_steps' => [str_repeat('a', 500)], 'summary' => str_repeat('b', 500)]), $n);
check('long step capped at 220 chars', mb_strlen($m['ai_next_steps'][0]) === 220);
check('long summary capped at 220 chars', mb_strlen($m['summary']) === 220);
$m = triage_merge_ai($rules, ai_out(['next_steps' => ["Line one\x00\x07 with control\ncharacters"]]), $n);
check('control characters removed', !preg_match('/[\x00-\x1F\x7F]/', $m['ai_next_steps'][0]), json_encode($m['ai_next_steps']));

echo "\nInvalid AI priority\n";
try { triage_merge_ai($rules, ai_out(['priority' => 'Urgent!!']), $n); check('invalid priority rejected', false); }
catch (RuntimeException $e) { check('invalid priority throws RuntimeException', true); }

echo "\nResult: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);