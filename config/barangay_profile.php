<?php
return [
    'puroks' => ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5', 'Purok 6'],

    // Which puroks border each other (EDIT: used to estimate tanod response time)
    'adjacent' => [
        'Purok 1' => ['Purok 2', 'Purok 6'],
        'Purok 2' => ['Purok 1', 'Purok 3'],
        'Purok 3' => ['Purok 2', 'Purok 4'],
        'Purok 4' => ['Purok 3', 'Purok 5'],
        'Purok 5' => ['Purok 4', 'Purok 6'],
        'Purok 6' => ['Purok 5', 'Purok 1'],
    ],
    // Rough estimates only (no GPS)
    'eta' => ['same' => '2-3 min', 'adjacent' => '5-8 min', 'far' => '10-15 min'],

    // type: school | daycare | market | chapel | health | hall | court | river
    'landmarks' => [
        ['name' => 'Barangay Hall',           'type' => 'hall',    'aliases' => ['barangay hall', 'bgy hall']],
        ['name' => 'Covered Court',           'type' => 'court',   'aliases' => ['covered court', 'basketball court']],
        ['name' => 'Duale Elementary School', 'type' => 'school',  'aliases' => ['duale elementary', 'elementary school', 'paaralan', 'eskwelahan']],
        ['name' => 'Daycare Center',          'type' => 'daycare', 'aliases' => ['daycare', 'day care']],
        ['name' => 'Public Market',           'type' => 'market',  'aliases' => ['palengke', 'talipapa', 'public market']],
        ['name' => 'River',                   'type' => 'river',   'aliases' => ['ilog', 'tabing ilog', 'tabing-ilog', 'river']],
        ['name' => 'Chapel / Church',         'type' => 'chapel',  'aliases' => ['simbahan', 'chapel', 'kapilya', 'church']],
        ['name' => 'Health Center',           'type' => 'health',  'aliases' => ['health center', 'health station', 'rhu', 'bhs']],
    ],

    // Your tanod roster. base_purok = their usual patrol beat. on_duty false = off duty.
    'tanods' => [
        ['name' => 'Ramon Diaz',   'station' => 'Barangay Base', 'unit' => 'Tanod Patrol 1', 'base_purok' => 'Purok 1', 'on_duty' => true],
        ['name' => 'Elena Flores', 'station' => 'Patrol 1',      'unit' => 'Tanod Patrol 2', 'base_purok' => 'Purok 2', 'on_duty' => true],
        ['name' => 'Nestor Lim',   'station' => 'Purok 2',       'unit' => 'Tanod Patrol 3', 'base_purok' => 'Purok 3', 'on_duty' => true],
        ['name' => 'Lito Navarro', 'station' => 'Zone 3',        'unit' => 'Tanod Patrol 4', 'base_purok' => 'Purok 4', 'on_duty' => true],
        ['name' => 'Mario Aquino', 'station' => 'Base Station',  'unit' => 'Reserve',        'base_purok' => 'Purok 5', 'on_duty' => false],
    ],
];