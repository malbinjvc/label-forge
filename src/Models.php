<?php

declare(strict_types=1);

namespace LabelForge;

class Label
{
    public string $id;
    public string $sampleId;
    public string $value;
    public string $source; // "ai" or "human"
    public string $status; // "pending", "approved", "rejected", "relabeled"
    public string $createdAt;
    public ?string $reviewedAt;
    public ?string $reviewedBy;

    public function __construct(string $sampleId, string $value, string $source = 'ai')
    {
        $this->id = self::generateId();
        $this->sampleId = $sampleId;
        $this->value = $value;
        $this->source = $source;
        $this->status = 'pending';
        $this->createdAt = date('c');
        $this->reviewedAt = null;
        $this->reviewedBy = null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sample_id' => $this->sampleId,
            'value' => $this->value,
            'source' => $this->source,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'reviewed_at' => $this->reviewedAt,
            'reviewed_by' => $this->reviewedBy,
        ];
    }

    private static function generateId(): string
    {
        return 'lbl_' . bin2hex(random_bytes(8));
    }
}

class Sample
{
    public string $id;
    public string $datasetId;
    public string $text;
    public ?string $groundTruth;
    public ?Label $label;
    public string $createdAt;

    public function __construct(string $datasetId, string $text, ?string $groundTruth = null)
    {
        $this->id = self::generateId();
        $this->datasetId = $datasetId;
        $this->text = $text;
        $this->groundTruth = $groundTruth;
        $this->label = null;
        $this->createdAt = date('c');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'dataset_id' => $this->datasetId,
            'text' => $this->text,
            'ground_truth' => $this->groundTruth,
            'label' => $this->label?->toArray(),
            'created_at' => $this->createdAt,
        ];
    }

    private static function generateId(): string
    {
        return 'smp_' . bin2hex(random_bytes(8));
    }
}

class Dataset
{
    public string $id;
    public string $name;
    public string $description;
    /** @var string[] */
    public array $labelSet;
    public string $createdAt;

    public function __construct(string $name, string $description, array $labelSet)
    {
        $this->id = self::generateId();
        $this->name = $name;
        $this->description = $description;
        $this->labelSet = $labelSet;
        $this->createdAt = date('c');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'label_set' => $this->labelSet,
            'created_at' => $this->createdAt,
        ];
    }

    private static function generateId(): string
    {
        return 'ds_' . bin2hex(random_bytes(8));
    }
}
