<?php

namespace App\Support;

class EvaluationType
{
    public const LABELS = [
        'SET' => 'Student Evaluation (SET)',
        'SEF' => 'Supervisor Evaluation (SEF)',
        'PEER' => 'Faculty / Peer Evaluation',
        'SELF' => 'Self Evaluation',
        'OTHER' => 'Other / Unspecified',
    ];

    public static function resolve(?string $assignmentType, ?string $formType, array $evaluatorRoles = []): string
    {
        $type = $assignmentType ?: $formType;

        if (! $type && $evaluatorRoles === ['student']) {
            return 'SET';
        }

        return isset(self::LABELS[$type ?? '']) ? $type : 'OTHER';
    }
}
