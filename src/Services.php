<?php

declare(strict_types=1);

namespace LabelForge;

class DatasetService
{
    /** @var array<string, Dataset> */
    private array $datasets = [];

    /** @var array<string, Sample> */
    private array $samples = [];

    public function createDataset(string $name, string $description, array $labelSet): Dataset
    {
        $dataset = new Dataset($name, $description, $labelSet);
        $this->datasets[$dataset->id] = $dataset;
        return $dataset;
    }

    public function getDataset(string $id): ?Dataset
    {
        return $this->datasets[$id] ?? null;
    }

    /**
     * @return Dataset[]
     */
    public function listDatasets(): array
    {
        return array_values($this->datasets);
    }

    public function deleteDataset(string $id): bool
    {
        if (!isset($this->datasets[$id])) {
            return false;
        }
        // Remove all samples belonging to this dataset
        foreach ($this->samples as $sampleId => $sample) {
            if ($sample->datasetId === $id) {
                unset($this->samples[$sampleId]);
            }
        }
        unset($this->datasets[$id]);
        return true;
    }

    public function addSample(string $datasetId, string $text, ?string $groundTruth = null): ?Sample
    {
        if (!isset($this->datasets[$datasetId])) {
            return null;
        }
        $sample = new Sample($datasetId, $text, $groundTruth);
        $this->samples[$sample->id] = $sample;
        return $sample;
    }

    /**
     * @return Sample[]
     */
    public function listSamples(string $datasetId): array
    {
        return array_values(array_filter(
            $this->samples,
            fn(Sample $s) => $s->datasetId === $datasetId
        ));
    }

    public function getSample(string $id): ?Sample
    {
        return $this->samples[$id] ?? null;
    }

    /**
     * @return Sample[] Unlabeled samples for the given dataset
     */
    public function getUnlabeledSamples(string $datasetId): array
    {
        return array_values(array_filter(
            $this->samples,
            fn(Sample $s) => $s->datasetId === $datasetId && $s->label === null
        ));
    }

    /**
     * @return Sample[] All samples across all datasets
     */
    public function getAllSamples(): array
    {
        return array_values($this->samples);
    }

    public function getDatasetCount(): int
    {
        return count($this->datasets);
    }

    public function getDatasetWithStats(string $id): ?array
    {
        $dataset = $this->getDataset($id);
        if ($dataset === null) {
            return null;
        }
        $samples = $this->listSamples($id);
        $labeledCount = count(array_filter($samples, fn(Sample $s) => $s->label !== null));
        $reviewedCount = count(array_filter(
            $samples,
            fn(Sample $s) => $s->label !== null && in_array($s->label->status, ['approved', 'rejected', 'relabeled'], true)
        ));

        $data = $dataset->toArray();
        $data['sample_count'] = count($samples);
        $data['labeled_count'] = $labeledCount;
        $data['reviewed_count'] = $reviewedCount;

        return $data;
    }
}

class LabelService
{
    private DatasetService $datasetService;
    private MockClaudeClient $client;

    public function __construct(DatasetService $datasetService, MockClaudeClient $client)
    {
        $this->datasetService = $datasetService;
        $this->client = $client;
    }

    /**
     * Auto-label all unlabeled samples in a dataset.
     *
     * @return array{labeled: int, skipped: int}|null
     */
    public function autoLabel(string $datasetId): ?array
    {
        $dataset = $this->datasetService->getDataset($datasetId);
        if ($dataset === null) {
            return null;
        }

        $unlabeled = $this->datasetService->getUnlabeledSamples($datasetId);
        $labeled = 0;
        $skipped = 0;

        foreach ($unlabeled as $sample) {
            $predictedLabel = $this->client->classify($sample->text, $dataset->labelSet);
            $sample->label = new Label($sample->id, $predictedLabel, 'ai');
            $labeled++;
        }

        return ['labeled' => $labeled, 'skipped' => $skipped];
    }

    /**
     * Review a sample's label.
     *
     * @param string $sampleId
     * @param string $action "approve", "reject", or "relabel"
     * @param string|null $newLabel Required when action is "relabel"
     * @return array{success: bool, message: string, sample?: array}
     */
    public function reviewLabel(string $sampleId, string $action, ?string $newLabel = null): array
    {
        $sample = $this->datasetService->getSample($sampleId);
        if ($sample === null) {
            return ['success' => false, 'message' => 'Sample not found'];
        }
        if ($sample->label === null) {
            return ['success' => false, 'message' => 'Sample has no label to review'];
        }

        $validActions = ['approve', 'reject', 'relabel'];
        if (!in_array($action, $validActions, true)) {
            return ['success' => false, 'message' => 'Invalid action. Must be: approve, reject, or relabel'];
        }

        switch ($action) {
            case 'approve':
                $sample->label->status = 'approved';
                $sample->label->reviewedAt = date('c');
                $sample->label->reviewedBy = 'human';
                break;
            case 'reject':
                $sample->label->status = 'rejected';
                $sample->label->reviewedAt = date('c');
                $sample->label->reviewedBy = 'human';
                break;
            case 'relabel':
                if ($newLabel === null || $newLabel === '') {
                    return ['success' => false, 'message' => 'new_label is required for relabel action'];
                }
                $sample->label->value = $newLabel;
                $sample->label->status = 'relabeled';
                $sample->label->source = 'human';
                $sample->label->reviewedAt = date('c');
                $sample->label->reviewedBy = 'human';
                break;
        }

        return ['success' => true, 'message' => 'Review recorded', 'sample' => $sample->toArray()];
    }
}

class AgreementService
{
    private DatasetService $datasetService;

    public function __construct(DatasetService $datasetService)
    {
        $this->datasetService = $datasetService;
    }

    /**
     * Calculate Cohen's Kappa for inter-annotator agreement.
     * Compares AI labels vs ground truth labels.
     *
     * @return array|null
     */
    public function calculateAgreement(string $datasetId): ?array
    {
        $dataset = $this->datasetService->getDataset($datasetId);
        if ($dataset === null) {
            return null;
        }

        $samples = $this->datasetService->listSamples($datasetId);

        // Only consider samples that have both a label and a ground truth
        $comparable = array_filter(
            $samples,
            fn(Sample $s) => $s->label !== null && $s->groundTruth !== null
        );

        $n = count($comparable);
        if ($n === 0) {
            return [
                'dataset_id' => $datasetId,
                'kappa' => null,
                'observed_agreement' => null,
                'expected_agreement' => null,
                'comparable_samples' => 0,
                'total_samples' => count($samples),
                'message' => 'No comparable samples (need both AI label and ground truth)',
            ];
        }

        // Collect all unique categories
        $categories = [];
        foreach ($comparable as $sample) {
            $categories[$sample->label->value] = true;
            $categories[$sample->groundTruth] = true;
        }
        $categories = array_keys($categories);

        // Count agreements and marginals
        $observed = 0;
        $aiCounts = array_fill_keys($categories, 0);
        $gtCounts = array_fill_keys($categories, 0);

        foreach ($comparable as $sample) {
            $aiLabel = $sample->label->value;
            $gtLabel = $sample->groundTruth;

            if ($aiLabel === $gtLabel) {
                $observed++;
            }

            $aiCounts[$aiLabel] = ($aiCounts[$aiLabel] ?? 0) + 1;
            $gtCounts[$gtLabel] = ($gtCounts[$gtLabel] ?? 0) + 1;
        }

        $po = $observed / $n;

        // Calculate expected agreement
        $pe = 0.0;
        foreach ($categories as $cat) {
            $pe += (($aiCounts[$cat] ?? 0) / $n) * (($gtCounts[$cat] ?? 0) / $n);
        }

        // Cohen's Kappa
        if (abs(1.0 - $pe) < 1e-10) {
            $kappa = 1.0; // Perfect agreement
        } else {
            $kappa = ($po - $pe) / (1 - $pe);
        }

        return [
            'dataset_id' => $datasetId,
            'kappa' => round($kappa, 4),
            'observed_agreement' => round($po, 4),
            'expected_agreement' => round($pe, 4),
            'comparable_samples' => $n,
            'total_samples' => count($samples),
        ];
    }
}

class ExportService
{
    private DatasetService $datasetService;

    public function __construct(DatasetService $datasetService)
    {
        $this->datasetService = $datasetService;
    }

    /**
     * Export a dataset in the specified format.
     *
     * @param string $datasetId
     * @param string $format "json", "csv", or "jsonl"
     * @return array|null
     */
    public function export(string $datasetId, string $format): ?array
    {
        $dataset = $this->datasetService->getDataset($datasetId);
        if ($dataset === null) {
            return null;
        }

        $samples = $this->datasetService->listSamples($datasetId);

        $validFormats = ['json', 'csv', 'jsonl'];
        if (!in_array($format, $validFormats, true)) {
            return ['error' => 'Invalid format. Must be: json, csv, or jsonl'];
        }

        $rows = [];
        foreach ($samples as $sample) {
            $rows[] = [
                'id' => $sample->id,
                'text' => $sample->text,
                'ground_truth' => $sample->groundTruth,
                'label' => $sample->label?->value,
                'label_source' => $sample->label?->source,
                'label_status' => $sample->label?->status,
            ];
        }

        switch ($format) {
            case 'json':
                return [
                    'format' => 'json',
                    'dataset_id' => $datasetId,
                    'dataset_name' => $dataset->name,
                    'count' => count($rows),
                    'data' => $rows,
                ];

            case 'csv':
                $csvContent = $this->toCsv($rows);
                return [
                    'format' => 'csv',
                    'dataset_id' => $datasetId,
                    'dataset_name' => $dataset->name,
                    'count' => count($rows),
                    'data' => $csvContent,
                ];

            case 'jsonl':
                $lines = array_map(fn($row) => json_encode($row), $rows);
                $jsonlContent = implode("\n", $lines);
                return [
                    'format' => 'jsonl',
                    'dataset_id' => $datasetId,
                    'dataset_name' => $dataset->name,
                    'count' => count($rows),
                    'data' => $jsonlContent,
                ];
        }

        return null;
    }

    private function toCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        // Header
        fputcsv($output, array_keys($rows[0]));
        // Rows
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }
}

class StatsService
{
    private DatasetService $datasetService;

    public function __construct(DatasetService $datasetService)
    {
        $this->datasetService = $datasetService;
    }

    public function getStats(): array
    {
        $allSamples = $this->datasetService->getAllSamples();
        $totalSamples = count($allSamples);
        $labeledCount = 0;
        $reviewedCount = 0;
        $correctCount = 0;
        $comparableCount = 0;

        foreach ($allSamples as $sample) {
            if ($sample->label !== null) {
                $labeledCount++;
                if (in_array($sample->label->status, ['approved', 'rejected', 'relabeled'], true)) {
                    $reviewedCount++;
                }
                if ($sample->groundTruth !== null) {
                    $comparableCount++;
                    if ($sample->label->value === $sample->groundTruth) {
                        $correctCount++;
                    }
                }
            }
        }

        $autoLabelAccuracy = $comparableCount > 0
            ? round($correctCount / $comparableCount, 4)
            : null;

        return [
            'total_datasets' => $this->datasetService->getDatasetCount(),
            'total_samples' => $totalSamples,
            'labeled_count' => $labeledCount,
            'reviewed_count' => $reviewedCount,
            'auto_label_accuracy' => $autoLabelAccuracy,
        ];
    }
}
