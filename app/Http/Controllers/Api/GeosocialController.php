<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeosocialLayer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\AstraGisService;

class GeosocialController extends Controller
{
    /**
     * [GET] /api/geosocial/layers
     * Mengambil daftar layer geosial (katalog WMS/Vector) untuk dirender secara dinamis di Leaflet.
     * Parameter ?all=true digunakan oleh Admin Dashboard untuk melihat seluruh layer.
     */
    public function index(Request $request)
    {
        try {
            $query = GeosocialLayer::query();

            // Jika bukan request untuk seluruh layer (Admin), filter yang aktif saja
            if (!$request->boolean('all', false) && !$request->has('all')) {
                $query->where('is_active', true);
            }

            $layers = $query->select([
                    'id',
                    'layer_key as layer_name', // Contoh: 'geosocial:vulnerability_index'
                    'name as display_name',    // Contoh: 'Vulnerability Index'
                    'type',                    // 'wms' atau 'vector'
                    'url',                     // URL WMS
                    'legend_url',              // URL legenda dari GeoServer 
                    'is_active',
                    'created_at',
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            // Jika database kosong dan bukan mode admin, berikan fallback default
            if ($layers->isEmpty() && !$request->has('all')) {
                $layers = [
                    [
                        "id" => 1,
                        "layer_name" => "nakhon_pathom:administrative_line",
                        "display_name" => "Administrative Line",
                        "type" => "wms",
                        "url" => config('services.geoserver.wms_url', 'http://localhost:8080/geoserver/wms'),
                        "legend_url" => null,
                        "is_active" => true,
                        "created_at" => now()->toISOString()
                    ]
                ];
            }

            return response()->json([
                'message' => 'Geosocial layers loaded successfully.',
                'data'    => $layers
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to load the layer catalog.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * [POST] /api/geosocial/layers/upload
     * Endpoint untuk admin mengupload layer vektor / raster langsung dengan opsi penyederhanaan geometri.
     */
    public function uploadLayer(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file',
                'name' => 'required|string|max:255',
            ]);

            $file = $request->file('file');
            $name = $request->input('name');
            $layerKeySlug = 'geosocial:' . Str::slug($name, '_');

            $ext = strtolower($file->getClientOriginalExtension());
            $isRaster = in_array($ext, ['tif', 'tiff']);

            $astragis = app(AstraGisService::class);
            $fileHandle = fopen($file->getRealPath(), 'r');
            $publishedData = null;
            $lastError = null;

            try {
                if ($isRaster) {
                    $publishedData = $astragis->publishRaster(
                        $fileHandle,
                        $file->getClientOriginalName(),
                        $name
                    );
                } else {
                    $publishedData = $astragis->publishVector(
                        $fileHandle,
                        $file->getClientOriginalName(),
                        $name
                    );
                }
            } catch (\Exception $ex) {
                $lastError = $ex->getMessage();
            } finally {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
            }

            if (!$publishedData) {
                \Log::error("Geosocial upload error: publishing failed via AstraGisService. Details: " . ($lastError ?: "No response from backend"));
                return response()->json([
                    'message' => "Gagal mempublikasikan layer ke AstraGIS: " . ($lastError ?: "Koneksi ke backend GIS gagal atau waktu pemrosesan habis."),
                    'error' => $lastError
                ], 500);
            }

            if (isset($publishedData['data']) && is_array($publishedData['data'])) {
                $publishedData = array_merge($publishedData, $publishedData['data']);
            }

            $ws = $publishedData['workspace_name'] ?? config('services.astragis.workspace_name', 'ws_flood_92cd23');
            $realLayerName = $publishedData['layer_name'] ?? ($publishedData['store_name'] ?? null);
            $layerKey = $realLayerName ? (str_contains($realLayerName, ':') ? $realLayerName : "{$ws}:{$realLayerName}") : $layerKeySlug;
            $wmsUrl = $publishedData['wms_url'] ?? config('services.geoserver.wms_url', 'http://localhost:8080/geoserver/wms');
            $ext = strtolower($file->getClientOriginalExtension());
            // Semua layer spasial yang diterbitkan ke GeoServer dirender sebagai WMS oleh Leaflet
            $type = 'wms';

            try {
                $layer = GeosocialLayer::updateOrCreate(
                    ['layer_key' => $layerKey],
                    [
                        'name' => $name,
                        'type' => $type,
                        'url' => $wmsUrl,
                        'is_active' => true,
                    ]
                );
            } catch (\Exception $dbEx) {
                // Fallback jika migrasi tabel belum berjalan
                $layer = (object) [
                    'id' => time(),
                    'layer_key' => $layerKey,
                    'name' => $name,
                    'type' => $type,
                    'url' => $wmsUrl,
                    'is_active' => true,
                    'created_at' => now()->toIso8601String(),
                ];
            }

            return response()->json([
                'message' => 'Layer uploaded and published successfully!',
                'data' => [
                    'id' => $layer->id,
                    'layer_name' => $layer->layer_key,
                    'display_name' => $layer->name,
                    'type' => $layer->type,
                    'url' => $layer->url,
                    'workspace_name' => $ws,
                    'store_name' => $publishedData['store_name'] ?? null,
                    'bbox' => $publishedData['bbox'] ?? null,
                    'epsg' => $publishedData['epsg'] ?? null,
                    'is_active' => $layer->is_active,
                    'created_at' => $layer->created_at,
                    'simplification' => $publishedData['simplification'] ?? null,
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload and publish layer.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * [PUT] /api/geosocial/layers/{id}
     * Update nama layer
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $layer = GeosocialLayer::findOrFail($id);
        $layer->name = $request->input('name');
        $layer->save();

        return response()->json([
            'message' => 'Layer updated successfully.',
            'data' => $layer
        ]);
    }

    /**
     * [PATCH] /api/geosocial/layers/{id}/toggle
     * Toggle status aktif layer
     */
    public function toggleActive($id)
    {
        $layer = GeosocialLayer::findOrFail($id);
        $layer->is_active = !$layer->is_active;
        $layer->save();

        return response()->json([
            'message' => 'Layer status updated successfully.',
            'data' => $layer
        ]);
    }

    /**
     * [DELETE] /api/geosocial/layers/{id}
     * Hapus layer geosocial
     */
    public function destroy($id)
    {
        $layer = GeosocialLayer::findOrFail($id);
        $layerKey = $layer->layer_key;

        // Bersihkan tabel fisik di PostGIS dan featuretype di GeoServer via Microservice
        try {
            $astragis = app(AstraGisService::class);
            $targetSlug = str_contains($layerKey, ':') ? explode(':', $layerKey)[1] : $layerKey;
            $ws = str_contains($layerKey, ':') ? explode(':', $layerKey)[0] : null;
            $astragis->deleteLayer($targetSlug, $ws);
        } catch (\Exception $ex) {
            \Log::warning("Gagal menghapus layer spasial di GeoServer via Microservice: " . $ex->getMessage());
        }

        $layer->delete();

        return response()->json([
            'message' => 'Layer dan tabel spasial berhasil dihapus.',
            'deleted_id' => $id,
            'layer_key' => $layerKey
        ]);
    }

    /**
     * [POST] /api/geosocial/layers/sync
     * Endpoint Webhook untuk menerima pendaftaran layer otomatis dari Pipeline FastAPI.
     */
    public function storeFromPipeline(Request $request)
    {
        try {
            // Validasi data yang dikirim oleh FastAPI Yudi
            $validated = $request->validate([
                'layer_key'   => 'required|string|unique:geosocial_layers,layer_key',
                'name'        => 'required|string|max:255',
                'type'        => 'required|string|in:wms,vector',
                'url'         => 'required|url',
                'secret_key'  => 'required|string', // Kunci pengaman komunikasi antar microservice
            ]);

            // Validasi Secret Key untuk keamanan akses API
            $expectedSecret = env('PIPELINE_SECRET_KEY', 'default_secret_gis');
            if ($validated['secret_key'] !== $expectedSecret) {
                return response()->json([
                    'message' => 'Access denied: Invalid secret key.'
                ], 401);
            }

            // Simpan layer baru ke database pakai Model Eloquent
            $layer = GeosocialLayer::create([
                'layer_key'  => $validated['layer_key'],
                'name'       => $validated['name'],
                'type'       => $validated['type'],
                'url'        => $validated['url'],
                'is_active'  => true,
            ]);

            return response()->json([
                'message' => 'A new layer has been successfully synced from the FastAPI pipeline!',
                'data'    => $layer
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred on the server.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * [GET] /api/geosocial/layers/points/{pointKey}
     * Mengembalikan data GeoJSON titik fasilitas untuk MarkerCluster di frontend.
     */
    public function getPointGeoJson($pointKey)
    {
        $safeKey = basename($pointKey);
        $filePath = storage_path("app/geosocial_points/{$safeKey}.geojson");

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Point layer not found.'], 404);
        }

        return response()->file($filePath, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * [POST] /api/geosocial/layers/{id}/style
     * Mengatur atau memperbarui style visual (SLD) layer spasial di GeoServer.
     */
    public function updateStyle(Request $request, $id)
    {
        $layer = GeosocialLayer::findOrFail($id);

        $validated = $request->validate([
            'fill_color'   => 'nullable|string',
            'stroke_color' => 'nullable|string',
            'stroke_width' => 'nullable|numeric|min:0.5|max:20',
            'fill_opacity' => 'nullable|numeric|min:0|max:1',
            'point_size'   => 'nullable|numeric|min:1|max:50',
            'style_sld'    => 'nullable|string',
        ]);

        $fillColor   = $validated['fill_color'] ?? '#0d9488';
        $strokeColor = $validated['stroke_color'] ?? '#0f766e';
        $strokeWidth = $validated['stroke_width'] ?? 2;
        $fillOpacity = $validated['fill_opacity'] ?? 0.65;
        $pointSize   = $validated['point_size'] ?? 8;

        // Parse workspace & nama layer dari layer_key (misal: "ws_30cad3c4:vec_s2s_vec_vvvdfgd_cb6b8b4d" atau "geosocial:geo_main_river")
        $parts = explode(':', $layer->layer_key);
        $workspace = count($parts) > 1 ? $parts[0] : 'geosocial';
        $layerName = count($parts) > 1 ? $parts[1] : $layer->layer_key;

        // Nama style unik untuk layer ini
        $cleanLayerName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $layerName);
        $styleName = "style_" . $cleanLayerName;

        // Susun XML SLD 1.0.0 universal (mendukung Polygon, Line, & Point di GeoServer)
        $sldXml = $validated['style_sld'] ?? null;
        if (empty($sldXml)) {
            $sldXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<StyledLayerDescriptor version="1.0.0" 
    xmlns="http://www.opengis.net/sld" 
    xmlns:ogc="http://www.opengis.net/ogc" 
    xmlns:xlink="http://www.w3.org/1999/xlink" 
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <NamedLayer>
    <Name>{$layerName}</Name>
    <UserStyle>
      <Title>{$layer->name} Custom Style</Title>
      <FeatureTypeStyle>
        <Rule>
          <Name>rule1</Name>
          <Title>{$layer->name}</Title>
          <PolygonSymbolizer>
            <Fill>
              <CssParameter name="fill">{$fillColor}</CssParameter>
              <CssParameter name="fill-opacity">{$fillOpacity}</CssParameter>
            </Fill>
            <Stroke>
              <CssParameter name="stroke">{$strokeColor}</CssParameter>
              <CssParameter name="stroke-width">{$strokeWidth}</CssParameter>
            </Stroke>
          </PolygonSymbolizer>
          <LineSymbolizer>
            <Stroke>
              <CssParameter name="stroke">{$strokeColor}</CssParameter>
              <CssParameter name="stroke-width">{$strokeWidth}</CssParameter>
              <CssParameter name="stroke-opacity">{$fillOpacity}</CssParameter>
            </Stroke>
          </LineSymbolizer>
          <PointSymbolizer>
            <Graphic>
              <Mark>
                <WellKnownName>circle</WellKnownName>
                <Fill>
                  <CssParameter name="fill">{$fillColor}</CssParameter>
                  <CssParameter name="fill-opacity">{$fillOpacity}</CssParameter>
                </Fill>
                <Stroke>
                  <CssParameter name="stroke">{$strokeColor}</CssParameter>
                  <CssParameter name="stroke-width">{$strokeWidth}</CssParameter>
                </Stroke>
              </Mark>
              <Size>{$pointSize}</Size>
            </Graphic>
          </PointSymbolizer>
        </Rule>
      </FeatureTypeStyle>
    </UserStyle>
  </NamedLayer>
</StyledLayerDescriptor>
XML;
        }

        // Kirim SLD ke GeoServer via Microservice
        $appliedToGeoServer = false;
        $geoLastError = null;

        try {
            $astragis = app(AstraGisService::class);
            $astragis->applyStyle($layerName, $workspace, $sldXml);
            $appliedToGeoServer = true;
        } catch (\Exception $ex) {
            $geoLastError = $ex->getMessage();
            \Log::warning("Gagal menerapkan style visual di GeoServer via Microservice: " . $geoLastError);
        }

        if (!$appliedToGeoServer) {
            return response()->json([
                'message' => 'Gagal menerapkan style visual ke GeoServer: ' . ($geoLastError ?: 'Koneksi gagal.'),
                'error'   => $geoLastError
            ], 422);
        }

        // Simpan konfigurasi warna ke kolom legend_url
        $layer->legend_url = $fillColor;
        $layer->save();

        $styleConfig = [
            'fill_color'   => $fillColor,
            'stroke_color' => $strokeColor,
            'stroke_width' => (float)$strokeWidth,
            'fill_opacity' => (float)$fillOpacity,
            'point_size'   => (float)$pointSize,
            'style_name'   => $styleName,
            'updated_at'   => now()->toISOString(),
        ];

        return response()->json([
            'message' => 'Style layer berhasil diperbarui!',
            'data' => [
                'id'               => $layer->id,
                'name'             => $layer->name,
                'layer_key'        => $layer->layer_key,
                'style_name'       => $styleName,
                'style_config'     => $styleConfig,
                'geoserver_synced' => true,
                'updated_at'       => $styleConfig['updated_at'],
            ]
        ], 200);
    }
}