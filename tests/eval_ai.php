<?php
// Measures how accurate the triage is on tests/labeled_cases.json.  Run:  php tests/eval_ai.php
// Needs the AI turned on in config/secrets.php. Each case makes one API call (a few cents in total).
// Keep these cases SEPARATE from config/triage_examples.json, otherwise the AI is graded on answers it was shown.
require_once __DIR__ . '/../lib/triage.php';

$cases = json_decode((string) file_get_contents(__DIR__ . '/labeled_cases.json'), true);
if (!is_array($cases)) exit("Could not read tests/labeled_cases.json\n");
$useAi = ai_enabled();
if (!$useAi) echo "AI is off in config/secrets.php: measuring the rules engine only.\n\n";

$n = count($cases);
$rulesOk = $finalOk = $catOk = $under = $over = $aiFail = 0;
foreach ($cases as $c) {
    $a = analyze_narrative($c['narrative']);
    $rulesOk += ($a['priority_level'] === $c['priority']);
    if ($useAi) {
        try { [$a] = ai_refine_analysis($a, $c['narrative']); }
        catch (Throwable $e) { $aiFail++; echo 'AI error: ' . $e->getMessage() . "\n"; }
    }
    $p = $a['priority_level'];
    $finalOk += ($p === $c['priority']);
    $catOk   += ($a['incident_type'] === $c['category']);
    if (sev_rank($p) < sev_rank($c['priority'])) $under++;
    if (sev_rank($p) > sev_rank($c['priority'])) $over++;
    $flag = $p === $c['priority'] ? 'OK  ' : (sev_rank($p) < sev_rank($c['priority']) ? 'LOW!' : 'high');
    printf("%s %-8s (want %-8s) %-32s | %s\n", $flag, $p, $c['priority'], $a['incident_type'], mb_substr($c['narrative'], 0, 60));
}
printf("\nPriority correct, rules only : %d / %d\n", $rulesOk, $n);
printf("Priority correct, final      : %d / %d\n", $finalOk, $n);
printf("Category correct, final      : %d / %d\n", $catOk, $n);
printf("Under-triaged (too LOW)      : %d   <- the dangerous kind; should be 0\n", $under);
printf("Over-triaged (too high)      : %d\n", $over);
if ($aiFail) printf("AI calls that failed         : %d\n", $aiFail);