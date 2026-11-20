<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class EmbeddingService
{
    private const DIMENSIONS = 1536;

    public function embedMany(
        array $texts,
        string $taskType = 'RETRIEVAL_DOCUMENT'
    ): array {
        if ($texts === []) {
            return [];
        }

        if (
            !in_array($taskType, [
                'RETRIEVAL_DOCUMENT',
                'RETRIEVAL_QUERY',
            ], true)
        ) {
            throw new InvalidArgumentException(
                'Unsupported embedding task.'
            );
        }

        $key = config('services.gemini.key');
        $model = config(
            'services.gemini.embedding_model',
            'gemini-embedding-001'
        );

        if (!$key) {
            throw new RuntimeException(
                'Gemini API key is missing.'
            );
        }

        $requests = array_map(
            fn(string $text) => [
                'model' => "models/{$model}",
                'content' => [
                    'parts' => [
                        ['text' => $text],
                    ],
                ],
                'taskType' => $taskType,
                'outputDimensionality' => self::DIMENSIONS,
            ],
            array_values($texts)
        );

        $response = Http::withHeaders([
            'x-goog-api-key' => $key,
        ])
            ->acceptJson()
            ->connectTimeout(30)
            ->timeout(90)
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:batchEmbedContents",
                ['requests' => $requests]
            )
            ->throw();

        $embeddings = $response->json('embeddings');

        if (
            !is_array($embeddings) ||
            count($embeddings) !== count($texts)
        ) {
            throw new RuntimeException(
                'Invalid Gemini embedding response.'
            );
        }

        return array_map(function ($item) {
            $values = $item['values'] ?? null;

            if (!is_array($values)) {
                throw new RuntimeException(
                    'Gemini returned invalid embedding data.'
                );
            }

            if (count($values) !== self::DIMENSIONS) {
                throw new RuntimeException(
                    'Invalid embedding dimensions. Expected '
                    . self::DIMENSIONS
                    . ', received '
                    . count($values)
                );
            }

            // Normalize vectors for cosine similarity.
            $norm = sqrt(array_sum(array_map(
                fn($value) => $value * $value,
                $values
            )));

            if ($norm <= 0) {
                throw new RuntimeException(
                    'Invalid zero-length embedding.'
                );
            }

            return array_map(
                fn($value) => $value / $norm,
                $values
            );
        }, $embeddings);
    }

    public function embedQuery(string $query): array
    {
        return $this->embedMany(
            [$query],
            'RETRIEVAL_QUERY'
        )[0];
    }
}
