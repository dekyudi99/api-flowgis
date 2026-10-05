<?php

namespace Tests\Unit;

use App\Services\AstraGisService;
use PHPUnit\Framework\TestCase;

class AstraGisServiceTest extends TestCase
{
    public function test_only_geotiff_download_401_is_classified_as_an_expired_download(): void
    {
        $this->assertTrue(AstraGisService::isExpiredDownloadError(
            'Failed to download GeoTIFF from URL: HTTP 401'
        ));
        $this->assertTrue(AstraGisService::isExpiredDownloadError(
            'Failed to download GeoTIFF from URL: HTTP 401 Unauthorized'
        ));

        $this->assertFalse(AstraGisService::isExpiredDownloadError(
            'Microservice publish raster failed [401]: API key rejected'
        ));
        $this->assertFalse(AstraGisService::isExpiredDownloadError(
            'GeoServer workspace request failed [401]: API key rejected'
        ));
        $this->assertFalse(AstraGisService::isExpiredDownloadError(
            'Failed to download GeoTIFF from URL: HTTP 403'
        ));
    }

    public function test_publish_filename_has_a_geotiff_extension(): void
    {
        $this->assertSame(
            'analysis_flood_123.tif',
            AstraGisService::resolveRasterUploadFilename(
                'https://download.example/result?signature=redacted',
                'analysis_flood_123'
            )
        );

        $this->assertSame(
            'result.TIFF',
            AstraGisService::resolveRasterUploadFilename(
                'https://download.example/result.TIFF?signature=redacted',
                'analysis_flood_123'
            )
        );
    }

    public function test_analysis_display_name_includes_the_analysis_year_or_range(): void
    {
        $this->assertSame('Rainfall 2024', AstraGisService::buildAnalysisDisplayName('Rainfall', [
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
        ]));

        $this->assertSame('Elevation 2023-2024', AstraGisService::buildAnalysisDisplayName('Elevation', [
            'start_date' => '2023-12-01',
            'end_date' => '2024-02-01',
        ]));

        $this->assertSame('Flood Risk', AstraGisService::buildAnalysisDisplayName('Flood Risk', []));
    }

    public function test_layer_identifier_uses_a_stable_non_colliding_layer_field(): void
    {
        $this->assertSame('workspace:rainfall_42', AstraGisService::resolveLayerIdentifier([
            'layer_name' => 'workspace:rainfall_42',
        ]));
        $this->assertSame('external-uuid', AstraGisService::resolveLayerIdentifier([
            'id' => 'external-uuid',
            'layer_name' => 'workspace:rainfall_42',
        ]));
        $this->assertNull(AstraGisService::resolveLayerIdentifier(['title' => 'Rainfall 2024']));
    }
}