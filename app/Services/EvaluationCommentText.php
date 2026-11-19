<?php

namespace App\Services;

class EvaluationCommentText
{
    // Include flags, skin tones, keycaps, variation selectors and emoji joiners.
    public const EMOJI_PATTERN = '/[0-9#*]\x{FE0F}?\x{20E3}|[\p{Extended_Pictographic}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}\x{FE0F}\x{200D}\x{E0020}-\x{E007F}]/u';

    public static function clean(string $text): string
    {
        return preg_replace(self::EMOJI_PATTERN, '', $text) ?? $text;
    }
}
