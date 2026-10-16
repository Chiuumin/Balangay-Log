<?php
require_once __DIR__ . '/anthropic.php';

class TriageEngine {

    public static function prepareNarrative(string $text, int $wordThreshold = 2500): string {
        $wordCount = str_word_count($text);
        if ($wordCount <= $wordThreshold) {
            return $text;
        }

        $paragraphs = preg_split("/\r\n|\n|\r/", $text);
        $sampled = [];
        $opening = [];

        foreach ($paragraphs as $index => $para) {
            $trimmed = trim($para);
            if (empty($trimmed)) continue;
            if ($index < 2) {
                $opening[] = "[SCENE BACKGROUND]: " . $trimmed;
                continue;
            }
            $sampled[] = $trimmed;
        }

        return mb_substr(implode("\n\n", array_merge($opening, $sampled)), 0, 16000, 'UTF-8');
    }

    public static function evaluateIncident(string $rawNarrative): array {
        $trimmed = trim($rawNarrative);

        if (mb_strlen($trimmed) < 15) {
            return [
                'incident_type'     => 'General Incident',
                'priority_level'    => 'Low',
                'ai_urgency_score'  => 0.05,
                'legal_routing'     => 'Standard Desk Blotter Recording',
                'weapon_flag'       => 'NONE',
                'vawc_minor_flag'   => false,
                'medical_flag'      => 'None indicated.',
                'substance_flag'    => 'None indicated.',
                'detected_keywords' => !empty($trimmed) ? [$trimmed] : [],
                'trigger_excerpts'  => !empty($trimmed) ? [$trimmed] : [],
                'reasoning'         => 'Brief narrative requires verbal follow-up during interview.',
                'ai_recommendation' => 'Gather complete factual statements from complainant.'
            ];
        }

        $cleanNarrative = self::prepareNarrative($trimmed);

        $systemPrompt = "You are the Chief Legal & Dispatch Triage AI for a Philippine Barangay Desk Officer.\n" .
            "Analyze the incident narrative to extract complete operational intelligence.\n\n" .
            "LEGAL ROUTING RULES (Katarungang Pambarangay vs PNP):\n" .
            "1. KP-QUALIFIED: Neighborhood disputes, non-violent quarrels, petty theft, fence disputes, slander.\n" .
            "2. VAWC-PROTECTED: Violence Against Women and Children (RA 9262). Conciliation is STRICTLY FORBIDDEN by law. Route to Barangay VAWC Desk / PNP.\n" .
            "3. PNP-EXEMPT / GRAVE: Brandishing firearms, bladed weapons, severe physical trauma, robbery in progress, grave threats. Exceeds Lupon authority; route to immediate Tanod/PNP dispatch.\n\n" .
            "PRIORITY SCALE:\n" .
            "- Critical (0.86 - 1.00): Lethal weapons, ongoing physical harm, hostage/fire.\n" .
            "- High (0.61 - 0.85): Fistfights, domestic altercation, grave verbal threats.\n" .
            "- Moderate (0.26 - 0.60): Past property loss, property damage, shouting altercations.\n" .
            "- Low (0.00 - 0.25): Noise complaints, petty debt, gossip (chismis), stray pets.\n\n" .
            "MANDATORY DIRECTIVE: If a deadly weapon (e.g., baril, kutsilyo) is actively brandished, priority MUST be Critical.";

        $toolDefinition = [
            'name' => 'analyze_case_details',
            'description' => 'Detailed AI analysis for frontline officer decision-making.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'incident_type' => [
                        'type' => 'string',
                        'enum' => [
                            'Physical Altercation',
                            'Threats & Intimidation',
                            'Noise Disturbance',
                            'Property Dispute',
                            'Domestic Altercation',
                            'Theft & Burglary',
                            'Public Disturbance / Obstruction',
                            'Animal / Environmental Nuisance',
                            'Others'
                        ]
                    ],
                    'priority_level' => [
                        'type' => 'string',
                        'enum' => ['Low', 'Moderate', 'High', 'Critical']
                    ],
                    'ai_urgency_score' => [
                        'type' => 'number'
                    ],
                    'legal_routing' => [
                        'type' => 'string',
                        'enum' => [
                            'Qualified for Lupon Mediation (KP Form 7)',
                            'MANDATORY VAWC ROUTING (Conciliation Prohibited by RA 9262)',
                            'PNP Exemption / Direct Police Endorsement Required',
                            'Standard Desk Blotter Recording'
                        ]
                    ],
                    'weapon_flag' => [
                        'type' => 'string',
                        'enum' => ['NONE', 'FIREARM', 'BLADED_WEAPON', 'IMPROVISED_WEAPON']
                    ],
                    'vawc_minor_flag' => [
                        'type' => 'boolean'
                    ],
                    'medical_flag' => [
                        'type' => 'string'
                    ],
                    'substance_flag' => [
                        'type' => 'string'
                    ],
                    'detected_keywords' => [
                        'type' => 'array',
                        'items' => ['type' => 'string']
                    ],
                    'trigger_excerpts' => [
                        'type' => 'array',
                        'items' => ['type' => 'string']
                    ],
                    'reasoning' => [
                        'type' => 'string'
                    ],
                    'ai_recommendation' => [
                        'type' => 'string'
                    ]
                ],
                'required' => [
                    'incident_type',
                    'priority_level',
                    'ai_urgency_score',
                    'legal_routing',
                    'weapon_flag',
                    'vawc_minor_flag',
                    'medical_flag',
                    'substance_flag',
                    'detected_keywords',
                    'trigger_excerpts',
                    'reasoning',
                    'ai_recommendation'
                ]
            ]
        ];

        try {
            $resp = anthropic_tool_call(
                'claude-3-5-sonnet-20241022',
                $systemPrompt,
                $cleanNarrative,
                $toolDefinition,
                1000,
                60
            );

            return [
                'incident_type'     => $resp['incident_type'] ?? 'Others',
                'priority_level'    => $resp['priority_level'] ?? 'Low',
                'ai_urgency_score'  => (float)($resp['ai_urgency_score'] ?? 0.1),
                'legal_routing'     => $resp['legal_routing'] ?? 'Standard Desk Blotter Recording',
                'weapon_flag'       => $resp['weapon_flag'] ?? 'NONE',
                'vawc_minor_flag'   => (bool)($resp['vawc_minor_flag'] ?? false),
                'medical_flag'      => $resp['medical_flag'] ?? 'None indicated.',
                'substance_flag'    => $resp['substance_flag'] ?? 'None indicated.',
                'detected_keywords' => is_array($resp['detected_keywords'] ?? null) ? $resp['detected_keywords'] : [],
                'trigger_excerpts'  => is_array($resp['trigger_excerpts'] ?? null) ? $resp['trigger_excerpts'] : [],
                'reasoning'         => $resp['reasoning'] ?? 'Automated legal triage applied.',
                'ai_recommendation' => $resp['ai_recommendation'] ?? 'Review details with complainant.'
            ];
        } catch (Throwable $e) {
            error_log('Triage Engine Error: ' . $e->getMessage());
            return [
                'incident_type'     => 'Others',
                'priority_level'    => 'Low',
                'ai_urgency_score'  => 0.10,
                'legal_routing'     => 'Standard Desk Blotter Recording',
                'weapon_flag'       => 'NONE',
                'vawc_minor_flag'   => false,
                'medical_flag'      => 'Unknown (offline fallback)',
                'substance_flag'    => 'Unknown (offline fallback)',
                'detected_keywords' => [],
                'trigger_excerpts'  => [],
                'reasoning'         => 'System fallback applied due to connectivity pause.',
                'ai_recommendation' => 'Proceed with manual desk intake.'
            ];
        }
    }
}