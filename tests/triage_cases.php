<?php
// Run from the project root:  php tests/triage_cases.php
// Checks the rules engine only (no network, no AI).
require_once __DIR__ . '/../lib/triage.php';

// [sentence, minimum priority, maximum priority]
$cases = [
    // --- firearm: past / ongoing / future, Filipino + English ---
    ['binaril niya ang kapitbahay namin',        'Critical', 'Critical'],
    ['binabaril niya ang kapitbahay namin',      'Critical', 'Critical'],
    ['babarilin ko kayong lahat',                'Critical', 'Critical'],
    ['nagbabaril ang lasing sa kanto',           'Critical', 'Critical'],
    ['he shot at the neighbors',                 'Critical', 'Critical'],
    ['he shoots at people every night',          'Critical', 'Critical'],
    ['he is going to shoot you',                 'Critical', 'Critical'],
    // --- stabbing ---
    ['sinaksak niya si Pedro',                   'Critical', 'Critical'],
    ['sinasaksak niya si Pedro',                 'Critical', 'Critical'],
    ['sasaksakin kita',                          'High',     'Critical'],
    ['he stabbed his brother',                   'Critical', 'Critical'],
    // --- killing ---
    ['pinatay nila ang tindero',                 'Critical', 'Critical'],
    ['pinapatay nila ang tindero',               'Critical', 'Critical'],
    ['papatayin ka namin',                       'High',     'Critical'],
    ['they killed the vendor',                   'Critical', 'Critical'],
    // --- physical (no weapon) ---
    ['sinuntok niya ako sa mukha',               'Moderate', 'High'],
    ['sinusuntok niya ako ngayon',               'Moderate', 'High'],
    ['susuntukin daw niya ako bukas',            'Moderate', 'High'],
    ['he punched me yesterday',                  'Moderate', 'High'],
    ['he punches me every day',                  'Moderate', 'High'],
    // --- sexual ---
    ['ginahasa ang dalagita',                    'Critical', 'Critical'],
    ['ginagahasa siya ng tiyuhin',               'Critical', 'Critical'],
    ['gagahasain daw siya ng lasing',            'Critical', 'Critical'],
    // --- negation / not an incident ---
    ['hindi niya binaril si Pedro',              'Low',      'Moderate'],
    ['walang baril na nakita ng tanod',          'Low',      'Moderate'],
    ['he did not shoot anyone',                  'Low',      'Moderate'],
    ['hindi sila nag-away pero binaril niya si Pedro', 'Critical', 'Critical'],
    ['patay ang ilaw sa kalsada',                'Low',      'Low'],
    // --- nuisance ---
    ['maingay ang karaoke ng kapitbahay',        'Low',      'Moderate'],
];

$rank = ['Low' => 1, 'Moderate' => 2, 'High' => 3, 'Critical' => 4];
$fail = 0;
foreach ($cases as [$text, $min, $max]) {
    $r = analyze_narrative($text);
    $p = $r['priority_level'];
    $ok = $rank[$p] >= $rank[$min] && $rank[$p] <= $rank[$max];
    if (!$ok) $fail++;
    printf("%s  %-9s (want %s..%s)  %s\n", $ok ? 'PASS' : 'FAIL', $p, $min, $max, $text);
}
printf("\n%d of %d passed\n", count($cases) - $fail, count($cases));
exit($fail ? 1 : 0);