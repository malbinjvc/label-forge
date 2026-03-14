<?php

declare(strict_types=1);

namespace LabelForge;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\App as SlimApp;

class App
{
    private SlimApp $app;
    private DatasetService $datasetService;
    private LabelService $labelService;
    private AgreementService $agreementService;
    private ExportService $exportService;
    private StatsService $statsService;

    public function __construct(?DatasetService $datasetService = null)
    {
        $this->datasetService = $datasetService ?? new DatasetService();
        $client = new MockClaudeClient();
        $this->labelService = new LabelService($this->datasetService, $client);
        $this->agreementService = new AgreementService($this->datasetService);
        $this->exportService = new ExportService($this->datasetService);
        $this->statsService = new StatsService($this->datasetService);

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->addErrorMiddleware(true, true, true);

        $this->registerRoutes();
    }

    public function getSlimApp(): SlimApp
    {
        return $this->app;
    }

    public function getDatasetService(): DatasetService
    {
        return $this->datasetService;
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    private function errorResponse(Response $response, string $message, int $status = 400): Response
    {
        return $this->jsonResponse($response, ['error' => ['message' => $message]], $status);
    }

    private function registerRoutes(): void
    {
        $self = $this;

        // Health check
        $this->app->get('/health', function (Request $request, Response $response) use ($self) {
            return $self->jsonResponse($response, [
                'status' => 'healthy',
                'service' => 'LabelForge',
                'version' => '1.0.0',
            ]);
        });

        // Create dataset
        $this->app->post('/api/datasets', function (Request $request, Response $response) use ($self) {
            $body = $request->getParsedBody();

            if (empty($body['name']) || empty($body['label_set']) || !is_array($body['label_set'])) {
                return $self->errorResponse($response, 'name and label_set (array) are required', 400);
            }

            $dataset = $self->datasetService->createDataset(
                $body['name'],
                $body['description'] ?? '',
                $body['label_set']
            );

            return $self->jsonResponse($response, ['data' => $dataset->toArray()], 201);
        });

        // List datasets
        $this->app->get('/api/datasets', function (Request $request, Response $response) use ($self) {
            $datasets = $self->datasetService->listDatasets();
            $data = array_map(fn(Dataset $d) => $d->toArray(), $datasets);
            return $self->jsonResponse($response, ['data' => $data, 'count' => count($data)]);
        });

        // Get dataset details
        $this->app->get('/api/datasets/{id}', function (Request $request, Response $response, array $args) use ($self) {
            $data = $self->datasetService->getDatasetWithStats($args['id']);
            if ($data === null) {
                return $self->errorResponse($response, 'Dataset not found', 404);
            }
            return $self->jsonResponse($response, ['data' => $data]);
        });

        // Delete dataset
        $this->app->delete('/api/datasets/{id}', function (Request $request, Response $response, array $args) use ($self) {
            $deleted = $self->datasetService->deleteDataset($args['id']);
            if (!$deleted) {
                return $self->errorResponse($response, 'Dataset not found', 404);
            }
            return $self->jsonResponse($response, ['data' => ['message' => 'Dataset deleted']]);
        });

        // Add sample to dataset
        $this->app->post('/api/datasets/{id}/samples', function (Request $request, Response $response, array $args) use ($self) {
            $body = $request->getParsedBody();

            if (empty($body['text'])) {
                return $self->errorResponse($response, 'text is required', 400);
            }

            $sample = $self->datasetService->addSample(
                $args['id'],
                $body['text'],
                $body['ground_truth'] ?? null
            );

            if ($sample === null) {
                return $self->errorResponse($response, 'Dataset not found', 404);
            }

            return $self->jsonResponse($response, ['data' => $sample->toArray()], 201);
        });

        // List samples in dataset
        $this->app->get('/api/datasets/{id}/samples', function (Request $request, Response $response, array $args) use ($self) {
            $dataset = $self->datasetService->getDataset($args['id']);
            if ($dataset === null) {
                return $self->errorResponse($response, 'Dataset not found', 404);
            }

            $samples = $self->datasetService->listSamples($args['id']);
            $data = array_map(fn(Sample $s) => $s->toArray(), $samples);
            return $self->jsonResponse($response, ['data' => $data, 'count' => count($data)]);
        });

        // Auto-label samples in dataset
        $this->app->post('/api/datasets/{id}/auto-label', function (Request $request, Response $response, array $args) use ($self) {
            $result = $self->labelService->autoLabel($args['id']);
            if ($result === null) {
                return $self->errorResponse($response, 'Dataset not found', 404);
            }
            return $self->jsonResponse($response, ['data' => $result]);
        });

        // Review a sample's label
        $this->app->put('/api/samples/{id}/review', function (Request $request, Response $response, array $args) use ($self) {
            $body = $request->getParsedBody();

            if (empty($body['action'])) {
                return $self->errorResponse($response, 'action is required', 400);
            }

            $result = $self->labelService->reviewLabel(
                $args['id'],
                $body['action'],
                $body['new_label'] ?? null
            );

            if (!$result['success']) {
                $status = ($result['message'] === 'Sample not found') ? 404 : 400;
                return $self->errorResponse($response, $result['message'], $status);
            }

            return $self->jsonResponse($response, ['data' => $result['sample']]);
        });

        // Calculate inter-annotator agreement
        $this->app->get('/api/datasets/{id}/agreement', function (Request $request, Response $response, array $args) use ($self) {
            $result = $self->agreementService->calculateAgreement($args['id']);
            if ($result === null) {
                return $self->errorResponse($response, 'Dataset not found', 404);
            }
            return $self->jsonResponse($response, ['data' => $result]);
        });

        // Export dataset
        $this->app->post('/api/datasets/{id}/export', function (Request $request, Response $response, array $args) use ($self) {
            $body = $request->getParsedBody();
            $format = $body['format'] ?? 'json';

            $result = $self->exportService->export($args['id'], $format);
            if ($result === null) {
                return $self->errorResponse($response, 'Dataset not found', 404);
            }
            if (isset($result['error'])) {
                return $self->errorResponse($response, $result['error'], 400);
            }

            return $self->jsonResponse($response, ['data' => $result]);
        });

        // Global stats
        $this->app->get('/api/stats', function (Request $request, Response $response) use ($self) {
            $stats = $self->statsService->getStats();
            return $self->jsonResponse($response, ['data' => $stats]);
        });
    }

    public function run(): void
    {
        $this->app->run();
    }
}
