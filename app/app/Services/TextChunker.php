<?php

namespace App\Services;

use InvalidArgumentException;

class TextChunker
{
    public function split(
        string $text,
        int $chunkSize = 1200,
        int $overlap = 200
    ): array {
        if ($chunkSize <= 0 || $overlap < 0 || $overlap >= $chunkSize) {
            throw new InvalidArgumentException('Invalid chunk settings.');
        }

        $text = trim($text);
        $length = mb_strlen($text);

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $end = min($start + $chunkSize, $length);

            $chunk = trim(
                mb_substr($text, $start, $end - $start)
            );

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($end >= $length) {
                break;
            }

            $start = $end - $overlap;
        }

        return $chunks;
    }
}
