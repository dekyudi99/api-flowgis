<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AstraGisService
{
    protected string $baseUrl;
    protected string $apiPrefix;
    protected ?string $apiKey;
    protected string $workspaceName;
    protected string $workspaceId;
    protected int $timeout;

    public static function isExpiredDownloadError(string $message): bool
    {
        return preg_match('/^Failed to download GeoTIFF from URL: HTTP 401(?:\s|$)/', trim($message)) === 1;
    }

    public static function resolveRasterUploadFilename(string $downloadUrl, string $layerName): string
    {
        $urlPath = parse_url($downloadUrl, PHP_URL_PATH);
        $filename = is_string($urlPath) ? basename($urlPath) : '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, ['tif', 'tiff'], true)
            ? $filename
            : "{$layerName}.tif";
    }

    public static function buildAnalysisDisplayName(string $name, array $parameters): string
    {
        $startDate = $parameters['start_date'] ?? $parameters['startDate'] ?? null;
        $endDate = $parameters['end_date'] ?? $parameters['endDate'] ?? null;
        $startYear = is_string($startDate) && preg_match('/^\d{4}/', $startDate, $matches) ? $matches[0] : null;
        $endYear = is_string($endDate) && preg_match('/^\d{4}/', $endDate, $matches) ? $matches[0] : null;

        if (!$startYear && !$endYear) {
            return $name;
        }

        $period = $startYear && $endYear && $startYear !== $endYear
            ? "{$startYear}-{$endYear}"
            : ($startYear ?? $endYear);

        return "{$name} {$period}";
    }

    public static function resolveLayerIdentifier(array $layer): ?string
    {
        foreach (['id', 'layer_id', 'layer_name', 'table_name', 'store_name', 'geoserver_name', 'name'] as $key) {
            if (isset($layer[$key]) && (is_string($layer[$key]) || is_numeric($layer[$key]))) {
                return (string) $layer[$key];
            }
        }

        return null;
    }

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.astragis.base_url'), '/');
        $version = trim((string) config('services.astragis.api_version', 'v1'), '/');
        $this->apiPrefix = "{$this->baseUrl}/api/{$version}";
        $this->apiKey = config('services.astragis.api_key');
        $this->workspaceName = config('services.astragis.workspace_name', env('ASTRAGIS_WORKSPACE_NAME'));
        $this->workspaceId = config('services.astragis.workspace_id', env('ASTRAGIS_WORKSPACE_ID'));
        $this->timeout = (int) config('services.astragis.timeout', 180);
    }

    /**
     * Create base HTTP client with API Key
     */
    protected function client(int $timeout = null)
    {
        $req = Http::timeout($timeout ?? $this->timeout);
        if ($this->apiKey) {
            $req = $req->withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept'    => 'application/json',
            ]);
        }
        return $req;
    }

    /**
     * Get health status of GeoServer Microservice
     */
    public function healthCheck(): array
    {
        try {
            $res = $this->client(10)->get("{$this->apiPrefix}/health");
            return [
                'status' => $res->successful() ? 'connected' : 'error',
                'url'    => $this->baseUrl,
                'data'   => $res->json(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'url'    => $this->baseUrl,
                'error'  => $e->getMessage(),
            ];
        }
    }

    /**
     * List workspaces from GeoServer Microservice
     */
    public function getWorkspaces(): array
    {
        $res = $this->client()->get("{$this->apiPrefix}/workspaces");
        if ($res->failed()) {
            throw new \RuntimeException("GeoServer workspace request failed [{$res->status()}]: " . $res->body());
        }

        $payload = $res->json();
        if (!is_array($payload)) {
            throw new \RuntimeException('GeoServer workspace response is not a valid JSON list.');
        }

        return $payload;
    }

    /**
     * List layers belonging to the API key
     */
    public function getLayers(array $params = []): array
    {
        $supportedParams = array_intersect_key($params, array_flip(['layer_type']));
        $res = $this->client()->get("{$this->apiPrefix}/layers/my-layers", $supportedParams);
        if ($res->failed()) {
            throw new \RuntimeException("GeoServer layer request failed [{$res->status()}]: " . $res->body());
        }

        $payload = $res->json();
        if (!is_array($payload)) {
            throw new \RuntimeException('GeoServer layer response is not valid JSON.');
        }

        $layers = $payload['data'] ?? (array_is_list($payload) ? $payload : null);
        if (!is_array($layers)) {
            throw new \RuntimeException('GeoServer layer response does not contain a data list.');
        }

        return [
            'success' => true,
            'total' => $payload['total'] ?? count($layers),
            'data' => array_map(function (array $layer): array {
                $technicalName = $layer['table_name']
                    ?? $layer['store_name']
                    ?? $layer['geoserver_name']
                    ?? $layer['layer_name']
                    ?? null;

                return array_merge($layer, [
                    'layer_name' => $technicalName,
                    'display_name' => $layer['display_name'] ?? $layer['title'] ?? $technicalName,
                    'wms_url' => $layer['wms_url'] ?? config('services.geoserver.wms_url'),
                    'wms_layers_param' => $layer['wms_layers_param']
                        ?? (($layer['workspace_name'] ?? null) && $technicalName
                            ? "{$layer['workspace_name']}:{$technicalName}"
                            : $technicalName),
                ]);
            }, $layers),
        ];
    }

    /**
     * Publish vector layer via multipart
     */
    public function publishVector($fileResource, string $filename, string $layerName, ?string $workspace = null): array
    {
        $ws = $workspace ?: $this->workspaceName;
        $res = $this->client()
            ->attach('file', $fileResource, $filename)
            ->post("{$this->apiPrefix}/layers/publish-vector", [
                'workspace_name' => $ws,
                'layer_name'     => $layerName,
            ]);

        if ($res->failed()) {
            throw new \Exception("Microservice publish vector failed [{$res->status()}]: " . $res->body());
        }

        $json = $res->json() ?? [];
        $json['layer_name'] = $json['layer_name'] ?? $json['table_name'] ?? null;
        $json['display_name'] = $json['display_name'] ?? $json['title'] ?? $json['layer_name'];
        return $json;
    }

    /**
     * Publish raster layer via multipart
     */
    public function publishRaster($fileResource, string $filename, string $layerName, ?string $workspace = null, array $metadata = []): array
    {
        $ws = $workspace ?: $this->workspaceName;
        $postData = [
            'workspace_name' => $ws,
            'layer_name'     => $layerName,
        ];
        $res = $this->client()
            ->attach('file', $fileResource, $filename)
            ->post("{$this->apiPrefix}/layers/publish-raster", $postData);

        if ($res->failed()) {
            throw new \Exception("Microservice publish raster failed [{$res->status()}]: " . $res->body());
        }

        $json = $res->json() ?? [];
        // Normalisasi store_name -> layer_name jika layer_name tidak ada di response microservice v1
        if (empty($json['layer_name']) && !empty($json['store_name'])) {
            $json['layer_name'] = $json['store_name'];
        }
        $json['display_name'] = $json['display_name'] ?? $json['title'] ?? $json['layer_name'];
        return $json;
    }

    /**
     * Pipeline to download spatial file from URL (e.g. GEE) and publish to microservice
     */
    public function publishFromUrl(string $downloadUrl, string $layerName, ?string $workspace = null, ?string $sldXml = null, array $metadata = []): array
    {
        $ws = $workspace ?: $this->workspaceName;
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir . '/dl_' . Str::random(16) . '.tif';

        try {
            // 1. Download file stream
            $downloadResp = Http::timeout(180)->withOptions(['sink' => $tempFile])->get($downloadUrl);
            if ($downloadResp->failed() || !file_exists($tempFile) || filesize($tempFile) === 0) {
                throw new \Exception("Failed to download GeoTIFF from URL: HTTP " . $downloadResp->status());
            }

            // 2. Publish to microservice
            $handle = fopen($tempFile, 'r');
            if ($handle === false) {
                throw new \RuntimeException('Downloaded GeoTIFF could not be opened for publishing.');
            }

            try {
                $filename = self::resolveRasterUploadFilename($downloadUrl, $layerName);
                $published = $this->publishRaster($handle, $filename, $layerName, $ws, $metadata);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            // 3. Apply style if provided
            $targetStyleLayer = $published['layer_name'] ?? ($published['store_name'] ?? null);
            if ($sldXml && $targetStyleLayer) {
                try {
                    $this->applyStyle($targetStyleLayer, $ws, $sldXml);
                } catch (\Exception $stEx) {
                    Log::warning("Published layer style could not be applied: " . $stEx->getMessage());
                }
            }

            return $published;
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /** Publish all requested Flood Risk exports without failing successful siblings. */
    public function publishAnalysisComponents(array $exports, array $selectedComponents, int $analysisId, array $parameters, ?string $workspace = null): array
    {
        $saved = [];
        $errors = [];

        foreach (array_unique($selectedComponents) as $component) {
            $export = $exports[$component] ?? null;
            if (!is_array($export) || empty($export['download_url'])) {
                $errors[$component] = 'Component GeoTIFF export is unavailable.';
                continue;
            }

            $technicalComponent = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($component));
            $layerName = "analysis_component_{$technicalComponent}_{$analysisId}";
            $metadata = [
                'analysis_id' => $analysisId,
                'analysis_type' => $export['analysis_type'] ?? "component_{$technicalComponent}",
                'component' => $component,
                'parameters' => $parameters,
                'legend' => $export['legend'] ?? null,
                'display_name' => self::buildAnalysisDisplayName($component, $parameters),
            ];

            try {
                $layer = $this->publishFromUrl(
                    $export['download_url'],
                    $layerName,
                    $workspace,
                    $export['style_sld'] ?? null,
                    $metadata
                );
                $layer['layer_name'] = $layer['layer_name'] ?? ($layer['store_name'] ?? $layerName);
                $layer['display_name'] = self::buildAnalysisDisplayName($component, $parameters);
                $layer['metadata'] = array_merge($layer['metadata'] ?? [], $metadata);
                $saved[$component] = $layer;
            } catch (\Throwable $exception) {
                $errors[$component] = $exception->getMessage();
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Apply SLD Style to layer in GeoServer via microservice
     */
    public function applyStyle(string $layerName, string $workspaceName, string $sldXml): array
    {
        $res = $this->client()->post("{$this->apiPrefix}/styles/apply", [
            'layer_name'     => $layerName,
            'workspace_name' => $workspaceName,
            'sld_xml'        => $sldXml,
            'style_sld'      => $sldXml,
        ]);

        if ($res->failed()) {
            throw new \Exception("Failed to apply style [{$res->status()}]: " . $res->body());
        }

        return $res->json() ?? [];
    }

    /**
     * Delete layer from microservice
     */
    public function deleteLayer(string $layerIdentifier, ?string $workspaceName = null): bool
    {
        $ws = $workspaceName ?: $this->workspaceName;
        
        // 1. Try batch-delete by layer UUID / identifier
        try {
            $batchRes = $this->client()->post("{$this->apiPrefix}/layers/batch-delete", [
                'layer_ids' => [$layerIdentifier]
            ]);
            if ($batchRes->successful() && ($batchRes->json('deleted_count') ?? 0) > 0) {
                return true;
            }
        } catch (\Exception $e) {}

        // 2. Fallback to direct DELETE /layers/{workspace}/{layer_name}
        try {
            $delRes = $this->client()->delete("{$this->apiPrefix}/layers/{$ws}/{$layerIdentifier}?recurse=true");
            return $delRes->successful();
        } catch (\Exception $e) {
            Log::warning("Microservice deleteLayer failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * List layer groups
     */
    public function getLayerGroups(?string $workspaceId = null): array
    {
        $params = $workspaceId ? ['workspace_id' => $workspaceId] : [];
        $res = $this->client()->get("{$this->apiPrefix}/layer-groups", $params);
        if ($res->failed()) {
            throw new \RuntimeException("GeoServer layer-group request failed [{$res->status()}]: " . $res->body());
        }

        $payload = $res->json();
        if (!is_array($payload)) {
            throw new \RuntimeException('GeoServer layer-group response is not valid JSON.');
        }

        $groups = $payload['data'] ?? (array_is_list($payload) ? $payload : null);
        if (!is_array($groups)) {
            throw new \RuntimeException('GeoServer layer-group response does not contain a data list.');
        }

        return $groups;
    }

    /** Resolve a workspace visible to the configured API key. */
    public function getOwnedWorkspace(): array
    {
        $workspaces = $this->getWorkspaces();
        $configuredWorkspace = $this->workspaceName;

        foreach ($workspaces as $workspace) {
            $workspaceName = $workspace['workspace_name'] ?? $workspace['ws_name'] ?? $workspace['name'] ?? null;
            $workspaceId = $workspace['id'] ?? $workspace['hashed_id'] ?? null;
            if ($workspaceName === $configuredWorkspace || (string) $workspaceId === (string) $this->workspaceId) {
                return $workspace;
            }
        }

        if (count($workspaces) === 1 && isset($workspaces[0]) && is_array($workspaces[0])) {
            return $workspaces[0];
        }

        throw new \RuntimeException('API key FlowGIS tidak memiliki workspace yang dapat digunakan.');
    }

    public function getLayerGroupsForOwnedWorkspace(): array
    {
        $workspace = $this->getOwnedWorkspace();
        $workspaceName = $workspace['workspace_name'] ?? $workspace['ws_name'] ?? $workspace['name'] ?? null;
        if (!$workspaceName) {
            throw new \RuntimeException('Workspace milik API key tidak memiliki nama teknis.');
        }

        return $this->getLayerGroups((string) $workspaceName);
    }

    public function getOwnedWorkspaceName(): string
    {
        $workspace = $this->getOwnedWorkspace();
        $workspaceName = $workspace['workspace_name'] ?? $workspace['ws_name'] ?? $workspace['name'] ?? null;
        if (!$workspaceName) {
            throw new \RuntimeException('Workspace milik API key tidak memiliki nama teknis.');
        }

        return (string) $workspaceName;
    }

    /**
     * Create layer group
     */
    public function createLayerGroup(array $payload): array
    {
        $res = $this->client()->post("{$this->apiPrefix}/layer-groups", $payload);
        if ($res->failed()) {
            throw new \Exception("Create layer group failed [{$res->status()}]: " . $res->body());
        }
        return $res->json() ?? [];
    }

    /**
     * Update layer group
     */
    public function updateLayerGroup(string $id, array $payload): array
    {
        $res = $this->client()->put("{$this->apiPrefix}/layer-groups/{$id}", $payload);
        if ($res->failed()) {
            throw new \Exception("Update layer group failed [{$res->status()}]: " . $res->body());
        }
        return $res->json() ?? [];
    }

    /**
     * Delete layer group
     */
    public function deleteLayerGroup(string $id): bool
    {
        $res = $this->client()->delete("{$this->apiPrefix}/layer-groups/{$id}");
        return $res->successful();
    }

    public function getWorkspaceName(): string
    {
        return $this->workspaceName;
    }

    public function getWorkspaceId(): string
    {
        return $this->workspaceId;
    }
}
