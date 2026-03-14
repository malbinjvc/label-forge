<?php

declare(strict_types=1);

namespace LabelForge;

class MockClaudeClient
{
    /**
     * Assign a label to text based on keyword matching.
     *
     * @param string $text The text to classify
     * @param string[] $labelSet Available labels for the dataset
     * @return string The predicted label
     */
    public function classify(string $text, array $labelSet): string
    {
        $lower = strtolower($text);

        $keywordMap = [
            'positive' => ['positive', 'good', 'great'],
            'negative' => ['negative', 'bad', 'terrible'],
            'neutral'  => ['neutral', 'okay', 'fine'],
        ];

        foreach ($keywordMap as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    // Only return the label if it is in the dataset's label set
                    if (in_array($label, $labelSet, true)) {
                        return $label;
                    }
                }
            }
        }

        return 'unknown';
    }
}
