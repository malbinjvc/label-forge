<?php

declare(strict_types=1);

namespace LabelForge\Tests;

use LabelForge\App;
use LabelForge\DatasetService;
use LabelForge\MockClaudeClient;
use LabelForge\LabelService;
use LabelForge\AgreementService;
use LabelForge\ExportService;
use LabelForge\StatsService;
use LabelForge\Label;
use LabelForge\Sample;
use LabelForge\Dataset;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

class AppTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = new App();
    }

    private function createRequest(
        string $method,
        string $uri,
        ?array $body = null
    ): \Psr\Http\Message\ServerRequestInterface {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $uri);

        if ($body !== null) {
            $streamFactory = new StreamFactory();
            $stream = $streamFactory->createStream(json_encode($body));
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream)
                ->withParsedBody($body);
        }

        return $request;
    }

    private function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return $this->app->getSlimApp()->handle($request);
    }

    private function getResponseData(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string)$response->getBody(), true);
    }

    // ─── Health Check ────────────────────────────────────────────────────

    public function testHealthCheck(): void
    {
        $request = $this->createRequest('GET', '/health');
        $response = $this->handle($request);
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('healthy', $data['status']);
        $this->assertEquals('LabelForge', $data['service']);
    }

    // ─── Dataset CRUD ────────────────────────────────────────────────────

    public function testCreateDataset(): void
    {
        $request = $this->createRequest('POST', '/api/datasets', [
            'name' => 'Sentiment Analysis',
            'description' => 'A sentiment dataset',
            'label_set' => ['positive', 'negative', 'neutral'],
        ]);
        $response = $this->handle($request);
        $data = $this->getResponseData($response);

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('Sentiment Analysis', $data['data']['name']);
        $this->assertCount(3, $data['data']['label_set']);
        $this->assertStringStartsWith('ds_', $data['data']['id']);
    }

    public function testCreateDatasetMissingName(): void
    {
        $request = $this->createRequest('POST', '/api/datasets', [
            'label_set' => ['a', 'b'],
        ]);
        $response = $this->handle($request);
        $data = $this->getResponseData($response);

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateDatasetMissingLabelSet(): void
    {
        $request = $this->createRequest('POST', '/api/datasets', [
            'name' => 'Test',
        ]);
        $response = $this->handle($request);

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testListDatasets(): void
    {
        // Create two datasets
        $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'DS1', 'label_set' => ['a'],
        ]));
        $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'DS2', 'label_set' => ['b'],
        ]));

        $response = $this->handle($this->createRequest('GET', '/api/datasets'));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $data['count']);
        $this->assertCount(2, $data['data']);
    }

    public function testGetDatasetDetails(): void
    {
        // Create dataset
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'Test DS', 'description' => 'desc', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        // Get details
        $response = $this->handle($this->createRequest('GET', "/api/datasets/{$dsId}"));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Test DS', $data['data']['name']);
        $this->assertEquals(0, $data['data']['sample_count']);
    }

    public function testGetDatasetNotFound(): void
    {
        $response = $this->handle($this->createRequest('GET', '/api/datasets/nonexistent'));
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testDeleteDataset(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'To Delete', 'label_set' => ['x'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $response = $this->handle($this->createRequest('DELETE', "/api/datasets/{$dsId}"));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Dataset deleted', $data['data']['message']);

        // Verify it's gone
        $getResp = $this->handle($this->createRequest('GET', "/api/datasets/{$dsId}"));
        $this->assertEquals(404, $getResp->getStatusCode());
    }

    public function testDeleteDatasetNotFound(): void
    {
        $response = $this->handle($this->createRequest('DELETE', '/api/datasets/nonexistent'));
        $this->assertEquals(404, $response->getStatusCode());
    }

    // ─── Sample Management ───────────────────────────────────────────────

    public function testAddSample(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'SampleDS', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $response = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This is a great product',
            'ground_truth' => 'positive',
        ]));
        $data = $this->getResponseData($response);

        $this->assertEquals(201, $response->getStatusCode());
        $this->assertEquals('This is a great product', $data['data']['text']);
        $this->assertEquals('positive', $data['data']['ground_truth']);
        $this->assertNull($data['data']['label']);
        $this->assertStringStartsWith('smp_', $data['data']['id']);
    }

    public function testAddSampleMissingText(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'SDS', 'label_set' => ['a'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $response = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'ground_truth' => 'positive',
        ]));
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAddSampleToNonexistentDataset(): void
    {
        $response = $this->handle($this->createRequest('POST', '/api/datasets/fake/samples', [
            'text' => 'hello',
        ]));
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testListSamples(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'ListDS', 'label_set' => ['a'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Sample 1',
        ]));
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Sample 2',
        ]));

        $response = $this->handle($this->createRequest('GET', "/api/datasets/{$dsId}/samples"));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $data['count']);
    }

    public function testListSamplesDatasetNotFound(): void
    {
        $response = $this->handle($this->createRequest('GET', '/api/datasets/fake/samples'));
        $this->assertEquals(404, $response->getStatusCode());
    }

    // ─── Auto-Labeling ──────────────────────────────────────────────────

    public function testAutoLabel(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'AutoDS', 'label_set' => ['positive', 'negative', 'neutral'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This is a great day',
        ]));
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This is terrible',
        ]));
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This is fine',
        ]));

        $response = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(3, $data['data']['labeled']);

        // Verify labels were assigned
        $samplesResp = $this->handle($this->createRequest('GET', "/api/datasets/{$dsId}/samples"));
        $samplesData = $this->getResponseData($samplesResp);

        foreach ($samplesData['data'] as $sample) {
            $this->assertNotNull($sample['label']);
            $this->assertEquals('ai', $sample['label']['source']);
            $this->assertEquals('pending', $sample['label']['status']);
        }
    }

    public function testAutoLabelKeywordMatching(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'KeywordDS', 'label_set' => ['positive', 'negative', 'neutral'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This product is really good',
        ]));

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));
        $samplesResp = $this->handle($this->createRequest('GET', "/api/datasets/{$dsId}/samples"));
        $samples = $this->getResponseData($samplesResp)['data'];

        $this->assertEquals('positive', $samples[0]['label']['value']);
    }

    public function testAutoLabelDefaultsToUnknown(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'UnknownDS', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Lorem ipsum dolor sit amet',
        ]));

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));
        $samplesResp = $this->handle($this->createRequest('GET', "/api/datasets/{$dsId}/samples"));
        $samples = $this->getResponseData($samplesResp)['data'];

        $this->assertEquals('unknown', $samples[0]['label']['value']);
    }

    public function testAutoLabelDatasetNotFound(): void
    {
        $response = $this->handle($this->createRequest('POST', '/api/datasets/fake/auto-label'));
        $this->assertEquals(404, $response->getStatusCode());
    }

    // ─── Review ─────────────────────────────────────────────────────────

    public function testReviewApprove(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'ReviewDS', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $sampleResp = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This is positive text',
        ]));
        $sampleId = $this->getResponseData($sampleResp)['data']['id'];

        // Auto-label first
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        // Approve
        $response = $this->handle($this->createRequest('PUT', "/api/samples/{$sampleId}/review", [
            'action' => 'approve',
        ]));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('approved', $data['data']['label']['status']);
        $this->assertEquals('human', $data['data']['label']['reviewed_by']);
    }

    public function testReviewReject(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'RejectDS', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $sampleResp = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Bad product',
        ]));
        $sampleId = $this->getResponseData($sampleResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        $response = $this->handle($this->createRequest('PUT', "/api/samples/{$sampleId}/review", [
            'action' => 'reject',
        ]));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('rejected', $data['data']['label']['status']);
    }

    public function testReviewRelabel(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'RelabelDS', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $sampleResp = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Ambiguous text here',
        ]));
        $sampleId = $this->getResponseData($sampleResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        $response = $this->handle($this->createRequest('PUT', "/api/samples/{$sampleId}/review", [
            'action' => 'relabel',
            'new_label' => 'positive',
        ]));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('relabeled', $data['data']['label']['status']);
        $this->assertEquals('positive', $data['data']['label']['value']);
        $this->assertEquals('human', $data['data']['label']['source']);
    }

    public function testReviewRelabelMissingNewLabel(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'NoLabelDS', 'label_set' => ['a'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $sampleResp = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Some text',
        ]));
        $sampleId = $this->getResponseData($sampleResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        $response = $this->handle($this->createRequest('PUT', "/api/samples/{$sampleId}/review", [
            'action' => 'relabel',
        ]));

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testReviewSampleNotFound(): void
    {
        $response = $this->handle($this->createRequest('PUT', '/api/samples/fake/review', [
            'action' => 'approve',
        ]));
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testReviewNoLabel(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'NoLblDS', 'label_set' => ['a'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $sampleResp = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Unlabeled',
        ]));
        $sampleId = $this->getResponseData($sampleResp)['data']['id'];

        // Try to review without auto-labeling first
        $response = $this->handle($this->createRequest('PUT', "/api/samples/{$sampleId}/review", [
            'action' => 'approve',
        ]));

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testReviewMissingAction(): void
    {
        $response = $this->handle($this->createRequest('PUT', '/api/samples/fake/review', []));
        $this->assertEquals(400, $response->getStatusCode());
    }

    // ─── Agreement ──────────────────────────────────────────────────────

    public function testAgreementPerfect(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'AgreeDS', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        // Samples where AI keywords match ground truth
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This is good', 'ground_truth' => 'positive',
        ]));
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This is bad', 'ground_truth' => 'negative',
        ]));

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        $response = $this->handle($this->createRequest('GET', "/api/datasets/{$dsId}/agreement"));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1.0, $data['data']['kappa']);
        $this->assertEquals(1.0, $data['data']['observed_agreement']);
        $this->assertEquals(2, $data['data']['comparable_samples']);
    }

    public function testAgreementNoComparableSamples(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'NoAgreeDS', 'label_set' => ['a'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        // Sample without ground truth
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'No ground truth',
        ]));

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        $response = $this->handle($this->createRequest('GET', "/api/datasets/{$dsId}/agreement"));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNull($data['data']['kappa']);
        $this->assertEquals(0, $data['data']['comparable_samples']);
    }

    public function testAgreementDatasetNotFound(): void
    {
        $response = $this->handle($this->createRequest('GET', '/api/datasets/fake/agreement'));
        $this->assertEquals(404, $response->getStatusCode());
    }

    // ─── Export ─────────────────────────────────────────────────────────

    public function testExportJson(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'ExportDS', 'label_set' => ['positive'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Good stuff', 'ground_truth' => 'positive',
        ]));
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        $response = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/export", [
            'format' => 'json',
        ]));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('json', $data['data']['format']);
        $this->assertEquals(1, $data['data']['count']);
        $this->assertIsArray($data['data']['data']);
    }

    public function testExportCsv(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'CsvDS', 'label_set' => ['positive'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Great product', 'ground_truth' => 'positive',
        ]));
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        $response = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/export", [
            'format' => 'csv',
        ]));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('csv', $data['data']['format']);
        $this->assertStringContainsString('id,text,ground_truth,label,label_source,label_status', $data['data']['data']);
    }

    public function testExportJsonl(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'JsonlDS', 'label_set' => ['negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Terrible experience',
        ]));
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        $response = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/export", [
            'format' => 'jsonl',
        ]));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('jsonl', $data['data']['format']);
        $this->assertIsString($data['data']['data']);
        // Verify it's valid JSONL
        $decoded = json_decode($data['data']['data'], true);
        $this->assertIsArray($decoded);
    }

    public function testExportInvalidFormat(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'BadFmtDS', 'label_set' => ['a'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $response = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/export", [
            'format' => 'xml',
        ]));

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testExportDatasetNotFound(): void
    {
        $response = $this->handle($this->createRequest('POST', '/api/datasets/fake/export', [
            'format' => 'json',
        ]));
        $this->assertEquals(404, $response->getStatusCode());
    }

    // ─── Stats ──────────────────────────────────────────────────────────

    public function testStatsEmpty(): void
    {
        $response = $this->handle($this->createRequest('GET', '/api/stats'));
        $data = $this->getResponseData($response);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(0, $data['data']['total_datasets']);
        $this->assertEquals(0, $data['data']['total_samples']);
        $this->assertEquals(0, $data['data']['labeled_count']);
        $this->assertEquals(0, $data['data']['reviewed_count']);
        $this->assertNull($data['data']['auto_label_accuracy']);
    }

    public function testStatsWithData(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'StatsDS', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $s1 = $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'This is good', 'ground_truth' => 'positive',
        ]));
        $sampleId = $this->getResponseData($s1)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Terrible stuff', 'ground_truth' => 'negative',
        ]));

        // Auto-label
        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"));

        // Approve one
        $this->handle($this->createRequest('PUT', "/api/samples/{$sampleId}/review", [
            'action' => 'approve',
        ]));

        $response = $this->handle($this->createRequest('GET', '/api/stats'));
        $data = $this->getResponseData($response);

        $this->assertEquals(1, $data['data']['total_datasets']);
        $this->assertEquals(2, $data['data']['total_samples']);
        $this->assertEquals(2, $data['data']['labeled_count']);
        $this->assertEquals(1, $data['data']['reviewed_count']);
        $this->assertEquals(1.0, $data['data']['auto_label_accuracy']);
    }

    // ─── MockClaudeClient Unit Tests ────────────────────────────────────

    public function testMockClaudeClientPositive(): void
    {
        $client = new MockClaudeClient();
        $labels = ['positive', 'negative', 'neutral'];

        $this->assertEquals('positive', $client->classify('This is a great movie', $labels));
        $this->assertEquals('positive', $client->classify('That was GOOD', $labels));
        $this->assertEquals('positive', $client->classify('I feel positive about this', $labels));
    }

    public function testMockClaudeClientNegative(): void
    {
        $client = new MockClaudeClient();
        $labels = ['positive', 'negative', 'neutral'];

        $this->assertEquals('negative', $client->classify('This is terrible work', $labels));
        $this->assertEquals('negative', $client->classify('Really bad experience', $labels));
        $this->assertEquals('negative', $client->classify('Very negative review', $labels));
    }

    public function testMockClaudeClientNeutral(): void
    {
        $client = new MockClaudeClient();
        $labels = ['positive', 'negative', 'neutral'];

        $this->assertEquals('neutral', $client->classify('This is fine I guess', $labels));
        $this->assertEquals('neutral', $client->classify('It was okay', $labels));
        $this->assertEquals('neutral', $client->classify('A neutral response', $labels));
    }

    public function testMockClaudeClientUnknown(): void
    {
        $client = new MockClaudeClient();
        $labels = ['positive', 'negative', 'neutral'];

        $this->assertEquals('unknown', $client->classify('Lorem ipsum dolor sit amet', $labels));
    }

    // ─── Delete cascades samples ────────────────────────────────────────

    public function testDeleteDatasetCascadesSamples(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'CascadeDS', 'label_set' => ['a'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Sample',
        ]));

        // Stats before
        $statsBefore = $this->getResponseData(
            $this->handle($this->createRequest('GET', '/api/stats'))
        );
        $this->assertEquals(1, $statsBefore['data']['total_samples']);

        // Delete
        $this->handle($this->createRequest('DELETE', "/api/datasets/{$dsId}"));

        // Stats after
        $statsAfter = $this->getResponseData(
            $this->handle($this->createRequest('GET', '/api/stats'))
        );
        $this->assertEquals(0, $statsAfter['data']['total_samples']);
    }

    // ─── Auto-label idempotency ─────────────────────────────────────────

    public function testAutoLabelSkipsAlreadyLabeled(): void
    {
        $createResp = $this->handle($this->createRequest('POST', '/api/datasets', [
            'name' => 'IdempDS', 'label_set' => ['positive', 'negative'],
        ]));
        $dsId = $this->getResponseData($createResp)['data']['id'];

        $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/samples", [
            'text' => 'Good day',
        ]));

        // First auto-label
        $r1 = $this->getResponseData(
            $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"))
        );
        $this->assertEquals(1, $r1['data']['labeled']);

        // Second auto-label (should label 0 since all are labeled)
        $r2 = $this->getResponseData(
            $this->handle($this->createRequest('POST', "/api/datasets/{$dsId}/auto-label"))
        );
        $this->assertEquals(0, $r2['data']['labeled']);
    }
}
