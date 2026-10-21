<?php
/**
 * Tense-aware verb and affix patterns for triage.php.
 * Handles Filipino affixation (unlapi, gitlapi, hulapi, kabilaan, laguhan)
 * including nasal assimilation (mang-/nang-/pam-/pan-/nam-) and English inflections.
 */

/** 
 * Comprehensive Filipino verb and derivative generator.
 * Expands all aspect forms, affixes, nasal assimilation, and root-shifted conjugations.
 */
function fil_verb(string $root, array $extra = [], bool $shift = true): string {
    preg_match('/^([^aeiou]*)([aeiou])/u', $root, $m);
    $c    = $m[1] ?? '';
    $v    = $m[2] ?? '';
    $cv   = $c . $v;                                    // Initial syllable (e.g., "ba", "sa", "ha")
    $rest = mb_substr($root, mb_strlen($c));            // Remainder without initial consonant
    $alt  = $shift ? preg_replace('/o(?=[^aeiou]*$)/u', '[ou]', $root) : $root; // Morphophonemic o -> u

    $assimilated = [];
    if (in_array($c, ['b', 'p'], true)) {
        // b, p -> m assimilation (e.g., baril -> mamaril, namaril, mamamaril, pamamaril)
        $assimilated[] = '(?:na|ma)m(?:' . $v . ')?' . $rest;
        $assimilated[] = '(?:nang|mang|pang|ipang|ipinam|ipangma)\s?' . $root;
        $assimilated[] = '(?:pam|nam|mam)(?:' . $v . ')?' . $rest . '(?:an|in)?';
    } elseif (in_array($c, ['d', 's', 't'], true)) {
        // d, s, t -> n assimilation (e.g., saksak -> manaksak, silip -> naninilip)
        $assimilated[] = '(?:na|ma)n(?:' . $v . ')?' . $rest;
        $assimilated[] = '(?:nang|mang|pang|ipang|ipinan)\s?' . $root;
        $assimilated[] = '(?:pan|nan|man)(?:' . $v . ')?' . $rest . '(?:an|in)?';
    } elseif (in_array($c, ['k', 'g', 'h', 'm', 'n', 'ng', 'w', 'y', 'r', 'l', ''], true)) {
        // k, g, h, vowels -> ng assimilation (e.g., halik -> nanghahalik, gahasa -> nanggagahasa)
        $assimilated[] = '(?:na|ma)ng(?:' . $v . ')?' . $rest;
        $assimilated[] = '(?:nang|mang|pang)\s?' . $root;
        $assimilated[] = '(?:nang|mang|pang)[-]?s?' . $root; // Common hyphenated/misspelled forms (nang-hahalik, nang-aaway)
        $assimilated[] = '(?:pang|nang|mang)(?:' . $v . ')?' . $rest . '(?:an|in)?';
    }

    $forms = [
        $root,                                   // Bare root (baril, saksak, patay)
        $c . 'um' . $rest,                       // bumaril, sumaksak, pumalo
        $c . 'um' . $v . $root,                  // bumabaril, sumasaksak, pumapalo
        $c . 'in' . $rest,                       // binaril, sinaksak, pinatay
        $c . 'in' . $v . $root,                  // binabaril, sinasaksak, pinapatay
        $cv . $alt . '(?:in|an|hon|hen)?',       // babarilin, sasaksakin, papatayin
        $alt . '(?:in|an|hin|han)',              // barilin, saksakin, patayin, sugatan
        'i' . $c . 'in' . $rest,                 // ibinaril, isinaksak
        'i' . $cv . $alt,                        // ibabaril, isasaksak
        '(?:i|ipa|ipag|ipina|ipinag)\s?' . $root,// ipabaril, ipinasaksak, ipinagpatay
        '(?:maka|naka|makapag|nakapag|makapang|nakapang)\s?' . $root, // nakabaril, nakasaksak
        '(?:pinag|nag|mag|pag|pagka)\s?(?:' . $cv . ')?' . $root,
        '(?:nag|mag|pag|pinag)\s?(?:' . $cv . ')?' . $alt . '(?:an|han)', // nagbarilan, barilan, saksakan, patayan
        '(?:taga|tagapag|taga' . $cv . ')\s?' . $root, // tagabaril, tagasaksak
    ];

    $allForms = array_merge($forms, $assimilated, $extra);
    return '(?:' . implode('|', array_filter($allForms)) . ')\w*';
}

/** 
 * Comprehensive English verb and derivative generator.
 * Expands all tenses, participles, gerunds, agentives, and irregular forms.
 */
function en_verb(string $base, array $irregulars = []): string {
    $regular = '(?:' . $base . '(?:s|es|ed|d|ing|er|ers)?' . ')';
    if ($irregulars) {
        return '(?:' . $regular . '|' . implode('|', $irregulars) . ')';
    }
    return $regular;
}

function triage_verb_groups(): array {
    $R = fn(string $body) => '/\b(' . $body . ')\b/u';
    return [
        'firearm' => $R(implode('|', [
            fil_verb('baril', [
                'namaril', 'namamaril', 'mamaril', 'mamamaril', 'pamaril', 'pamamaril',
                'nagbarilan', 'barilan', 'pagbabaril', 'binaril', 'babarilin'
            ]),
            'pinaputukan|pinapaputukan|(?:nag|mag|pag)\s?(?:pa){1,2}putok\w*|putukan|putok\s+ng\s+(?:baril|bala)',
            'pistol\w*|pistola|armalite|rifle|riple|shotgun|revolver|handgun|firearms?|guns?|gunmen|gunman|gunshots?|gunfire',
            'paltik|sumpak|armado|armadong|kalibre\s?\d*|caliber',
            en_verb('shoot', ['shot', 'shooting', 'shoots', 'shooter', 'shooters']),
            'shot\s+(?:at|him|her|them|me|dead|by)|(?:was|were|got|gets|been|being)\s+shot',
            '(?:fire[ds]?|firing)\s+(?:a\s+)?(?:gun|shots?|weapon|at)',
            'discharg(?:e|ed|ing)\s+(?:a\s+)?(?:firearm|gun)',
        ])),

        'stab' => $R(implode('|', [
            fil_verb('saksak', [
                'nanaksak', 'nananaksak', 'manaksak', 'mananaksak', 'pananaksak',
                'nagsaksakan', 'saksakan', 'sinaksak', 'sasaksakin', 'panaksak'
            ]),
            fil_verb('taga', [
                'tinaga\w*', 'tinataga\w*', 'tagain', 'tatagain', 'tumaga\w*', 'tumataga\w*',
                'nanaga', 'nananaga', 'nagtaga\w*', 'magtaga\w*', 'pananaga'
            ]),
            en_verb('stab', ['stabbed', 'stabbing', 'stabber', 'stabbers']),
            en_verb('slash', ['slashed', 'slashing']),
            'cut\s+(?:his|her|their|my|a)\s+(?:throat|neck|wrist|body)',
            'hiniwa|hiwain|laslas|nirlaslas|nallaslas|nilaslas|lalaslasin',
        ])),

        'strangle' => $R(implode('|', [
            fil_verb('sakal', ['nanakal', 'nananakal', 'manakal', 'sinakal', 'sasakalin', 'sinasakal']),
            en_verb('strangle', ['strangled', 'strangling']),
            en_verb('choke', ['choked', 'choking']),
            en_verb('throttle', ['throttled', 'throttling']),
            'suffocat\w*|asphyxiat\w*|sinupil\s+ang\s+hininga',
        ])),

        'death' => $R(implode('|', [
            fil_verb('patay', [
                '(?:na|nam|mam|ma)(?:ma)?tay', 'namamatay', 'mamamatay', 'pinapatay',
                'ipapatay', 'ipapapatay', 'nagpatayan', 'patayan', 'pumatay', 'pumapatay'
            ]),
            'bangkay|nasawi|binawian(?:\s+ng\s+buhay)?|pinaslang|napagpatay|pagpatay',
            en_verb('kill', ['killed', 'killing', 'killer', 'killers']),
            en_verb('murder', ['murdered', 'murdering', 'murderer', 'murderers']),
            en_verb('slay', ['slew', 'slain', 'slaying']),
            en_verb('die', ['died', 'dying', 'dead', 'dies']),
            'homicide|fatalit\w*|lifeless|shot\s+dead|stabbed\s+to\s+death|beaten\s+to\s+death',
        ])),

        'threat_kill' => $R(implode('|', [
            'papatayin|patayin\s+(?:kita|ka|ko|natin|sila)|babarilin|sasaksakin|susunugin|ipapapatay|ipapatay|ambush|babalikan\s+kita',
            '(?:i|we|he|she|they)\s*(?:will|ll|\'ll|gonna|going\s+to|am\s+going\s+to)\s+(?:kill|shoot|stab|burn|slay)\s+(?:you|him|her|them|everyone)',
            'kill\s+you|will\s+kill|gonna\s+kill|shoot\s+you|stab\s+you|death\s+threat\w*',
        ])),

        'threat' => $R(implode('|', [
            fil_verb('banta', ['binantaan', 'pinagbantaan', 'nagbabanta', 'pagbabanta', 'magbanta']),
            fil_verb('takot', ['tinakot', 'tinatakot', 'tatakutin', 'nananakot', 'mananakot', 'pananakot']),
            en_verb('threaten', ['threatened', 'threatening', 'threat', 'threats']),
            en_verb('intimidate', ['intimidated', 'intimidating', 'intimidation']),
            en_verb('blackmail', ['blackmailed', 'blackmailing']),
            en_verb('harass', ['harassed', 'harassing', 'harassment']),
            'tinatakot|nangha?harass|hinaharas|hinamon|naghahamon|hinahamon',
        ])),

        'sexual' => $R(implode('|', [
            fil_verb('gahasa', ['ginahasa', 'ginagahasa', 'gagahasain', 'panggagahasa', 'nanggagahasa', 'manggagahasa', 'mangahas']),
            fil_verb('hipo', ['hinipuan', 'hinahipuan', 'hihipuan', 'nanghipo', 'panghihipo', 'nanhipo', 'nang-?hihipo']),
            fil_verb('halay', ['hinalay', 'hinahalay', 'hahalayin', 'panghahalay', 'nanghahalay', 'manghahalay']),
            fil_verb('silip', ['naninilip', 'nanilip', 'maninilip', 'paninilip', 'nang-?sisilip']),
            fil_verb('molesya', ['nangmolesya', 'nangmamolesya', 'mangmamolesya']),
            en_verb('rape', ['raped', 'raping', 'rapist', 'rapists']),
            en_verb('molest', ['molested', 'molesting', 'molestation', 'molester']),
            'sexual\s+(?:assault|abuse|harassment)|sekswal\w*|lascivious\w*|acts?\s+of\s+lasciviousness',
        ])),

        'kidnap' => $R(implode('|', [
            fil_verb('dukot', ['dinukot', 'dinudukot', 'dudukutin', 'pandudukot', 'mandukot']),
            fil_verb('bihag', ['binihag', 'binibihag', 'bibihagin', 'pamimihag']),
            en_verb('kidnap', ['kidnapped', 'kidnapping', 'kidnapper', 'kidnappers']),
            en_verb('abduct', ['abducted', 'abducting', 'abduction']),
            'hostage|hostage\s?taking|detained\s+illegally|illegal\s+detention',
        ])),

        'fire' => $R(implode('|', [
            fil_verb('sunog', ['nasusunog', 'masusunog', 'nasunog', 'sinunog', 'nagsunog', 'pagsunog'], false),
            en_verb('burn', ['burned', 'burning', 'burnt']),
            'house\s+fire|structure\s+fire|nagliliyab|arson|arsonist\w*',
        ])),

        'physical' => $R(implode('|', [
            fil_verb('suntok', ['suntukan', 'pinagsusuntok', '(?:na|ma)(?:na)?nuntok', 'nanununtok', 'manuntok', 'pagkasuntok', 'nanunsok']),
            fil_verb('sampal', ['nanampal', 'nananampal', 'mananampal', 'sinampal', 'sasampalin', 'sampalan']),
            fil_verb('sipa', ['sinipa', 'sisipain', 'nanipa', 'manipa', 'sipaan']),
            fil_verb('tadyak', ['tinadyakan', 'tatadyakan', 'nanadyak', 'manadyak']),
            fil_verb('sabunot', ['sinabunot', 'sasabunutan', 'nanabunot', 'sabunutan']),
            fil_verb('gulpi', ['ginulpi', 'gigulpihin', 'nanggulpi', 'manggulpi', 'panggugulpi']),
            fil_verb('bugbog', ['binugbog', 'bubugbugin', 'pambubugbog', 'nagbugbugan', 'bugbugan', 'nambubugbog', 'mambubugbog']),
            fil_verb('hampas', ['hinampas', 'hahampasin', 'nanghampas', 'panghahampas']),
            fil_verb('palo', ['pinalo', 'papaluin', 'namalo', 'pamamalo', 'namamaril']),
            fil_verb('saktan', ['nanakit', 'nananakit', 'mananakit', 'sinaktan', 'sasaktan', 'sinasaktan', 'pananakit']),
            fil_verb('tulak', ['itinulak', 'itutulak', 'tinulak', 'nagtulakan']),
            fil_verb('takbo', ['tumakbo', 'tumatakbo', 'nagtakbuhan', 'patakbo']),
            'suntukan|sampalan|sabunutan|rambol|rumble|riot|kaguluhan|pagkakagulo|karahasan',
            en_verb('punch', ['punched', 'punching']),
            en_verb('slap', ['slapped', 'slapping']),
            en_verb('kick', ['kicked', 'kicking']),
            en_verb('strike', ['struck', 'striking']),
            en_verb('assault', ['assaulted', 'assaulting']),
            en_verb('beat', ['beaten', 'beating']),
            en_verb('hit', ['hitting', 'hits']),
            en_verb('attack', ['attacked', 'attacking']),
            'physical\s+injur\w*|brawling|fist\s?fight\w*',
        ])),

        'robbery' => $R(implode('|', [
            fil_verb('holdap', ['hinoldap', 'hinoholdap', 'hoholdapin', 'panghoholdap', 'nagholdap', 'nangholdap']),
            fil_verb('loob', ['nanloob', 'nanloloob', 'manloob', 'panloloob']),
            fil_verb('agaw', ['inagaw', 'inaagaw', 'aagawin', 'nang-?agaw', 'mang-?agaw', 'pang-?aagaw']),
            'hold\s?up|salisi|akyat\s?bahay|break\s?in|carnap\w*|snatch\w*',
            en_verb('rob', ['robbed', 'robbing', 'robber', 'robbers', 'robbery', 'robberies']),
        ])),

        'theft' => $R(implode('|', [
            fil_verb('nakaw', [
                'ninakaw', 'ninanakaw', 'nanakaw', 'nagnanakaw', 'magnanakaw', 'pagnanakaw', 'ninakawan',
                'nangnanakaw', 'nang-?nakaw', 'mangnanakaw', 'pananakaw'
            ]),
            fil_verb('kupit', ['kinupit', 'kinukupit', 'nangupit', 'pangkukupit']),
            'nawala\s+ang\s+(?:cellphone|pitaka|wallet|motor|bisikleta|pera|gamit)',
            'nawawala\s+ang\s+(?:cellphone|pitaka|wallet|motor|bisikleta|pera|gamit)',
            en_verb('steal', ['stole', 'stolen', 'stealing', 'steals', 'thief', 'thieves', 'theft']),
            en_verb('burgle', ['burglar', 'burglars', 'burglary', 'burglaries']),
            en_verb('pilfer', ['pilfered', 'pilfering']),
            'looting|looted',
        ])),

        'damage' => $R(implode('|', [
            fil_verb('sira', ['nasira', 'sinira', 'sinisira', 'sisirain', 'pagsira', 'manira']),
            fil_verb('basag', ['binasag', 'binabasag', 'babasagin', 'nabasag', 'pamamabasag']),
            fil_verb('wasak', ['winasak', 'nawasak', 'wawasakin', 'pagkawasak']),
            en_verb('destroy', ['destroyed', 'destroying', 'destruction']),
            en_verb('damage', ['damaged', 'damaging']),
            en_verb('smash', ['smashed', 'smashing']),
            en_verb('vandalize', ['vandalized', 'vandalizing', 'vandalism', 'vandal']),
            'pinsala|pininsala',
        ])),
    ];
}

/**
 * Clause-based negation: a negator within the last 3 words of the SAME clause negates the hit.
 * $before is the text before the hit (give it ~60 chars); a clause ends at "|" or a contrast word.
 */
function triage_is_negated(string $before, string $after): bool {
    $parts  = preg_split('/\s*\|\s*|\b(?:pero|ngunit|subalit|kundi|but|although|though|kaya|tapos|then)\b/u', $before);
    $clause = trim((string) end($parts));
    $words  = array_slice(preg_split('/\s+/u', $clause, -1, PREG_SPLIT_NO_EMPTY) ?: [], -3);
    static $neg = [
        'walang', 'wala', 'hindi', 'di', 'wag', 'huwag', 'no', 'not', 'without', 'never',
        "don't", "didn't", "doesn't", "wasn't", "weren't", "isn't", "aren't", "won't", "wouldn't", "didnt", "dont"
    ];
    foreach ($words as $w) if (in_array($w, $neg, true)) return true;
    return (bool) preg_match('/^\s*(?:ay\s+)?(?:hindi|di)\s+totoo/u', $after);
}