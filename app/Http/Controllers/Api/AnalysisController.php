<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AnalysisController extends Controller
{
    /**
     * [9] POST /api/projects/{id}/analyze
     */
    public function test_flusk() 
    {
        $flaskUrl = config('services.flask.url');
        try {
            $response = Http::timeout(10)->get("{$flaskUrl}/test");
            return response()->json([
                "status" => "Connected successfully!",
                "flask_response" => $response->json()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "status" => "Failed to connect",
                "target_url" => $flaskUrl,
                "error" => $e->getMessage()
            ], 500);
        }
    }


    public function analyze(Request $request, $id)
    {
        $userId = $request->user() ? $request->user()->id : null;
        $projectQuery = Project::where('id', $id);
        if ($userId) {
            $projectQuery->where('user_id', $userId);
        }
        $project = $projectQuery->first();
        if (!$project) {
            return response()->json(['message' => 'Project not found or unauthorized.'], 404);
        }

        $validated = $request->validate([
            'analysis_type'   => 'required|string|in:ndvi,ndwi,land_cover,flood_risk,flood_event,rainfall,elevation,distance,tpi,component_rainfall,component_elevation,component_distance,component_tpi,component_ndvi,component_ndwi,component',
            'component'       => 'nullable|string',
            'start_date'      => 'nullable|date',
            'end_date'        => 'nullable|date|after_or_equal:start_date',
            'after_startDate' => 'nullable|date',
            'after_endDate'   => 'nullable|date|after_or_equal:after_startDate',
            'startYear'       => 'nullable|integer',
            'endYear'         => 'nullable|integer',
            'mode'            => 'nullable|string',
            'weights'         => 'nullable|array', 
            'aoi_id'          => 'nullable|exists:aois,id',
            'geometry'        => 'nullable',
            'aoi_type'        => 'nullable|string',
        ]);

        $geometry = $validated['geometry'] ?? null;
        $aoiType = $validated['aoi_type'] ?? 'polygon';
        $aoiId = $validated['aoi_id'] ?? null;

        // Susun parameter input untuk disimpan ke history
        $analysisParams = [
            'start_date'      => $validated['start_date'] ?? null,
            'end_date'        => $validated['end_date'] ?? null,
            'after_startDate' => $validated['after_startDate'] ?? null,
            'after_endDate'   => $validated['after_endDate'] ?? null,
            'startYear'       => $validated['startYear'] ?? null,
            'endYear'         => $validated['endYear'] ?? null,
            'mode'            => $validated['mode'] ?? 'daily',
            'weights'         => $validated['weights'] ?? [],
        ];

        if ($aoiId) {
            // User memilih AOI yang sudah ada dari Dropdown
            $selectedAoi = DB::table('aois')
                ->select(['id', DB::raw("ST_AsGeoJSON(geometry)::json AS geometry")])
                ->where('id', $aoiId)
                ->where('project_id', $id)
                ->first();

            if (!$selectedAoi) {
                return response()->json(['message' => 'Selected AOI was not found.'], 404);
            }
            $geometry = json_decode($selectedAoi->geometry);

            } elseif (!empty($geometry)) {
            // User menggambar AOI baru di peta
            try {
                $geoJsonType = ucfirst($aoiType);
                if (in_array(strtolower($aoiType), ['polygon', 'rectangle'])) {
                    $geoJsonType = 'Polygon';
                    if (is_array($geometry) && isset($geometry[0]) && is_array($geometry[0])) {
                        $firstPoint = $geometry[0][0];
                        $lastPoint = end($geometry[0]);
                        if ($firstPoint !== $lastPoint) {
                            $geometry[0][] = $firstPoint;
                        }
                    }
                }

                $geoJsonData = (strtolower($aoiType) === 'circle') 
                    ? ['type' => 'Point', 'coordinates' => $geometry['coordinates'] ?? $geometry]
                    : ['type' => $geoJsonType, 'coordinates' => $geometry];

                $quotedGeoJson = DB::getPdo()->quote(json_encode($geoJsonData));

                // Simpan AOI Baru ke DB
                $newAoiId = DB::table('aois')->insertGetId([
                    'project_id' => $project->id,
                    'name'       => 'AOI - ' . $project->name . ' (' . now()->format('H:i:s') . ')',
                    'area_ha'    => DB::raw("ST_Area(ST_SetSRID(ST_GeomFromGeoJSON({$quotedGeoJson}), 4326)::geography) / 10000"),
                    'geometry'   => DB::raw("ST_SetSRID(ST_GeomFromGeoJSON({$quotedGeoJson}), 4326)"),
                    'source_type'=> 'drawn_polygon',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                $aoiId = $newAoiId;
            } catch (\Exception $e) {
                \Log::error("Failed to save AOI to database: " . $e->getMessage());
                return response()->json([
                    'message' => 'Failed to save the AOI area to the database.',
                    'error'   => $e->getMessage()
                ], 500);
            }
        } else {
            // Fallback ke AOI terbaru kalau tidak ada pilihan
            $latestAoi = DB::table('aois')
                ->select(['id', DB::raw("ST_AsGeoJSON(geometry)::json AS geometry")])
                ->where('project_id', $id)
                ->latest()
                ->first();

            if (!$latestAoi) {
                return response()->json(['message' => 'The project does not yet have an AOI. Please select or mark an area first.'], 422);
            }
            $geometry = json_decode($latestAoi->geometry);
            $aoiId = $latestAoi->id;
        }

        // if (empty($geometry)) {
        //     $aoi = DB::table('aois')
        //         ->select([
        //             'id',
        //             'name',
        //             'area_ha',
        //             DB::raw("ST_AsGeoJSON(geometry)::json AS geometry")
        //         ])
        //         ->where('project_id', $id)
        //         ->latest()
        //         ->first();

        //     if (!$aoi) {
        //         return response()->json(['message' => 'The project does not yet have an AOI. Please mark the area on the map or upload an AOI first.'], 422);
        //     }
        //     $geometry = json_decode($aoi->geometry);
        // } else {
        //     try {
        //         $geoJsonType = ucfirst($aoiType);
                
        //         if (in_array(strtolower($aoiType), ['polygon', 'rectangle'])) {
        //             $geoJsonType = 'Polygon';
                    
        //             if (is_array($geometry) && isset($geometry[0]) && is_array($geometry[0])) {
        //                 $firstPoint = $geometry[0][0];
        //                 $lastPoint = end($geometry[0]);
                        
        //                 if ($firstPoint !== $lastPoint) {
        //                     $geometry[0][] = $firstPoint;
        //                 }
        //             }
        //         }

        //         $geoJsonData = [];
        //         if (strtolower($aoiType) === 'circle') {
        //             $geoJsonData = [
        //                 'type' => 'Point',
        //                 'coordinates' => $geometry['coordinates'] ?? $geometry
        //             ];
        //         } else {
        //             $geoJsonData = [
        //                 'type' => $geoJsonType,
        //                 'coordinates' => $geometry
        //             ];
        //         }

        //         $geoJsonString = json_encode($geoJsonData);

        //         // Gunakan DB::getPdo()->quote() untuk escape karakter khusus (termasuk single-quote)
        //         // agar tidak terjadi SQL error saat koordinat polygon mengandung nilai desimal
        //         $quotedGeoJson = DB::getPdo()->quote($geoJsonString);

        //         DB::table('aois')->updateOrInsert(
        //             ['project_id' => $project->id],
        //             [
        //                 'name'       => 'AOI - ' . $project->name,
        //                 'area_ha'    => DB::raw("ST_Area(ST_SetSRID(ST_GeomFromGeoJSON({$quotedGeoJson}), 4326)::geography) / 10000"),
        //                 'geometry'   => DB::raw("ST_SetSRID(ST_GeomFromGeoJSON({$quotedGeoJson}), 4326)"),
        //                 'source_type'=> 'drawn_polygon',
        //                 'created_at' => now(),
        //                 'updated_at' => now()
        //             ]
        //         );
        //     } catch (\Exception $e) {
        //         return response()->json([
        //             'message' => 'Failed to save the AOI area to the database (Format Rejected).',
        //             'error'   => $e->getMessage()
        //         ], 500);
        //     }
        // }

        $flaskUrl = config('services.flask.url');

        try {
            $componentName = $validated['component'] ?? str_replace('component_', '', $validated['analysis_type']);

            $response = Http::timeout(180000)->post("{$flaskUrl}/api/v1/compute", [
                'project_id'      => $project->id,
                'analysis_type'   => $validated['analysis_type'],
                'component'       => $componentName,
                'startDate'       => $validated['start_date'] ?? null,
                'endDate'         => $validated['end_date'] ?? null,
                'after_startDate' => $validated['after_startDate'] ?? null,
                'after_endDate'   => $validated['after_endDate'] ?? null,
                'startYear'       => $validated['startYear'] ?? null,
                'endYear'         => $validated['endYear'] ?? null,
                'mode'            => $validated['mode'] ?? 'daily',
                'aoi_type'        => $aoiType, 
                'geometry'        => $geometry,
                'weights'         => $validated['weights'] ?? [], 
            ]);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Failed to process the analysis in the GEE Engine.',
                    'error'   => $response->json() ?? $response->body()
                ], 502);
            }

            $resData = $response->json();

            // Simpan data analisis ke database (termasuk download_url & maps untuk preview langsung)
            $analysis = Analysis::create([
                'project_id'    => $project->id,
                'aoi_id'        => $aoiId,
                'type'          => $validated['analysis_type'],
                'analysis_type' => $validated['analysis_type'],
                'parameters'    => $analysisParams, // Menyimpan riwayat input tanggal dan bobot
                'status'        => 'completed',
                'statistics'    => [
                    'style_sld'     => $resData['style_sld'] ?? null,
                    'maps'          => $resData['maps'] ?? null, // GEE direct XYZ Tile URLs
                    'wms_layer'     => null, // Belum disimpan ke GeoServer/AstraGIS
                    'wms'           => $resData['wms'] ?? null,
                    'legends'       => $resData['legends'] ?? null,
                    'statistics'    => $resData['statistics'] ?? null,
                    'download_url'  => $resData['download_url'] ?? null,
                    'component'     => $componentName,
                    'aoi_id'        => $aoiId,
                    'geometry'      => $geometry,
                ],
                'file_path'     => $resData['download_url'] ?? null, 
            ]);

            return response()->json([
                'message' => 'The analysis was successfully run directly from GEE.',
                'data'    => [
                    'id'            => $analysis->id,
                    'project_id'    => $analysis->project_id,
                    'analysis_type' => $analysis->analysis_type,
                    'status'        => $analysis->status,
                    'file_path'     => $analysis->file_path,
                    'parameters'    => $analysisParams,
                    'style_sld'     => $resData['style_sld'] ?? null,
                    'wms_layer'     => null,
                    'maps'          => $resData['maps'] ?? null, // URL Tile GEE langsung untuk Leaflet
                    'legends'       => $resData['legends'] ?? null,
                    'statistics'    => $resData['statistics'] ?? null,
                    'is_saved'      => false,
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error("Flask compute exception [{$flaskUrl}]: " . $e->getMessage());
            return response()->json([
                'message' => 'An error occurred while connecting to the Flask microservice.',
                'target'  => $flaskUrl,
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/projects/{id}/save-analysis
     * Menyimpan hasil analisis aktif ke AstraGIS / GeoServer sehingga tercatat resmi di Analysis Results.
     */
    public function saveAnalysis(Request $request, $id)
    {
        $user = $request->user();
        $userId = $user ? (string) $user->id : null;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $project = Project::where('id', $id)->where('user_id', $userId)->first();
        if (!$project) {
            return response()->json(['message' => 'Project not found or unauthorized.'], 404);
        }

        $analysisId = $request->input('analysis_id');
        $analysisQuery = Analysis::where('project_id', $project->id);
        if ($analysisId) {
            $analysisQuery->where('id', $analysisId);
        }
        $analysis = $analysisQuery->latest()->first();

        if (!$analysis) {
            return response()->json(['message' => 'No analysis result found to save.'], 404);
        }

        $statsData = is_array($analysis->statistics) ? $analysis->statistics : (json_decode($analysis->statistics, true) ?? []);
        $downloadUrl = $analysis->file_path ?? ($statsData['download_url'] ?? null);

        // Jika sudah pernah disimpan sebelumnya ke AstraGIS, langsung kembalikan wms_layer
        if (!empty($statsData['wms_layer'])) {
            return response()->json([
                'message' => 'Analysis result has already been saved to project.',
                'data'    => [
                    'wms_layer' => $statsData['wms_layer'],
                    'is_saved'  => true
                ]
            ], 200);
        }

        if (empty($downloadUrl)) {
            return response()->json(['message' => 'Download URL / GeoTIFF is not ready to be saved.'], 422);
        }

        $astragisUrl = config('services.astragis.base_url', 'http://fastapi_backend:8000');
        $apiKey = config('services.astragis.api_key', 'agis_sk_flowgis_production_key_2026');
        $workspaceId = config('services.astragis.workspace_id', '7');

        $aoiName = null;
        if ($analysis->aoi_id) {
            $aoiRecord = DB::table('aois')->where('id', $analysis->aoi_id)->first();
            if ($aoiRecord && !empty($aoiRecord->name)) {
                $aoiName = $aoiRecord->name;
            }
        }
        $aoiTag = $aoiName ? " ({$aoiName})" : ($analysis->aoi_id ? " (AOI #{$analysis->aoi_id})" : "");
        $cleanTypeName = ucwords(str_replace(['component_', '_'], ['', ' '], $analysis->analysis_type));
        $layerName = "{$cleanTypeName}{$aoiTag} - {$project->name} (#" . time() . ")";

        try {
            $s2sResp = Http::withHeaders([
                'X-API-Key' => $apiKey,
            ])->timeout(180)->post("{$astragisUrl}/s2s/publish-from-url", [
                'workspace_id'      => (string) $workspaceId,
                'layer_name'        => $layerName,
                'download_url'      => $downloadUrl,
                'client_user_id'    => (string) $userId,
                'client_user_name'  => $user->name,
                'client_user_email' => $user->email,
                'style_sld'         => $statsData['style_sld'] ?? null,
                'legends'           => $statsData['legends'] ?? null,
                'statistics'        => $statsData['statistics'] ?? null,
                'metadata'          => [
                    'project_id'    => $project->id,
                    'project_name'  => $project->name,
                    'analysis_id'   => $analysis->id,
                    'analysis_type' => $analysis->analysis_type,
                    'component'     => $statsData['component'] ?? null,
                    'aoi_id'        => $analysis->aoi_id,
                    'aoi_name'      => $aoiName,
                    'geometry'      => $statsData['geometry'] ?? null,
                    'legends'       => $statsData['legends'] ?? null,
                    'statistics'    => $statsData['statistics'] ?? null,
                    'parameters'    => $analysis->parameters,
                ]
            ]);

            if ($s2sResp->successful()) {
                $publishedWmsLayer = $s2sResp->json('data');
                $statsData['wms_layer'] = $publishedWmsLayer;
                $analysis->statistics = $statsData;
                $analysis->save();

                return response()->json([
                    'message' => 'Analysis result successfully saved to project.',
                    'data'    => [
                        'wms_layer' => $publishedWmsLayer,
                        'is_saved'  => true
                    ]
                ], 200);
            } else {
                \Log::error("AstraGIS S2S publish failed [{$s2sResp->status()}]: " . $s2sResp->body());
                $errBody = $s2sResp->body();
                $isExpiredUrl = str_contains($errBody, '401') || str_contains($errBody, 'download_url');

                // Jika URL unduhan dari GEE telah kadaluarsa (HTTP 401), coba lakukan auto-refresh via Flask
                if ($isExpiredUrl) {
                    $flaskUrl = config('services.flask.url');
                    $analysisParams = is_array($analysis->parameters) ? $analysis->parameters : (json_decode($analysis->parameters, true) ?? []);
                    $geom = $statsData['geometry'] ?? null;
                    if (!$geom && $analysis->aoi_id) {
                        $aoiRec = DB::table('aois')->where('id', $analysis->aoi_id)->first();
                        if ($aoiRec) {
                            $geom = json_decode($aoiRec->geometry);
                        }
                    }

                    if ($geom && $flaskUrl) {
                        try {
                            \Log::info("Re-computing fresh download_url from Flask for analysis ID: {$analysis->id}");
                            $componentName = $statsData['component'] ?? str_replace('component_', '', $analysis->analysis_type);
                            $refreshResp = Http::timeout(180000)->post("{$flaskUrl}/api/v1/compute", [
                                'project_id'      => $project->id,
                                'analysis_type'   => $analysis->analysis_type,
                                'component'       => $componentName,
                                'startDate'       => $analysisParams['start_date'] ?? null,
                                'endDate'         => $analysisParams['end_date'] ?? null,
                                'after_startDate' => $analysisParams['after_startDate'] ?? null,
                                'after_endDate'   => $analysisParams['after_endDate'] ?? null,
                                'startYear'       => $analysisParams['startYear'] ?? null,
                                'endYear'         => $analysisParams['endYear'] ?? null,
                                'mode'            => $analysisParams['mode'] ?? 'daily',
                                'aoi_type'        => 'polygon',
                                'geometry'        => $geom,
                                'weights'         => $analysisParams['weights'] ?? [],
                            ]);

                            if ($refreshResp->successful()) {
                                $freshData = $refreshResp->json();
                                $newDownloadUrl = $freshData['download_url'] ?? null;
                                if ($newDownloadUrl) {
                                    $statsData['download_url'] = $newDownloadUrl;
                                    $statsData['maps'] = $freshData['maps'] ?? $statsData['maps'];
                                    $statsData['style_sld'] = $freshData['style_sld'] ?? $statsData['style_sld'];
                                    $analysis->file_path = $newDownloadUrl;
                                    $analysis->statistics = $statsData;
                                    $analysis->save();

                                    // Coba publish ulang ke AstraGIS dengan URL baru
                                    $retryS2s = Http::withHeaders(['X-API-Key' => $apiKey])->timeout(180)->post("{$astragisUrl}/s2s/publish-from-url", [
                                        'workspace_id'      => (string) $workspaceId,
                                        'layer_name'        => $layerName,
                                        'download_url'      => $newDownloadUrl,
                                        'client_user_id'    => (string) $userId,
                                        'client_user_name'  => $user->name,
                                        'client_user_email' => $user->email,
                                        'style_sld'         => $statsData['style_sld'] ?? null,
                                        'legends'           => $statsData['legends'] ?? null,
                                        'statistics'        => $statsData['statistics'] ?? null,
                                        'metadata'          => [
                                            'project_id'    => $project->id,
                                            'project_name'  => $project->name,
                                            'analysis_id'   => $analysis->id,
                                            'analysis_type' => $analysis->analysis_type,
                                            'component'     => $statsData['component'] ?? null,
                                            'aoi_id'        => $analysis->aoi_id,
                                            'aoi_name'      => $aoiName,
                                            'geometry'      => $statsData['geometry'] ?? null,
                                            'legends'       => $statsData['legends'] ?? null,
                                            'statistics'    => $statsData['statistics'] ?? null,
                                            'parameters'    => $analysis->parameters,
                                        ]
                                    ]);

                                    if ($retryS2s->successful()) {
                                        $publishedWmsLayer = $retryS2s->json('data');
                                        $statsData['wms_layer'] = $publishedWmsLayer;
                                        $analysis->statistics = $statsData;
                                        $analysis->save();

                                        return response()->json([
                                            'message' => 'Analysis result successfully refreshed and saved to project.',
                                            'data'    => [
                                                'wms_layer' => $publishedWmsLayer,
                                                'is_saved'  => true
                                            ]
                                        ], 200);
                                    }
                                }
                            }
                        } catch (\Exception $recompErr) {
                            \Log::warning("Auto-refresh download_url failed: " . $recompErr->getMessage());
                        }
                    }

                    return response()->json([
                        'message' => 'Berkas unduhan satelit dari Google Earth Engine telah kadaluarsa (sesi analisis lama). Silakan klik "Run Spatial Analysis" untuk memperbarui hasil sebelum menyimpan.',
                        'is_expired' => true,
                        'error'   => $s2sResp->json() ?? $s2sResp->body()
                    ], 422);
                }

                return response()->json([
                    'message' => 'Gagal menyimpan layer analisis ke AstraGIS.',
                    'error'   => $s2sResp->json() ?? $s2sResp->body()
                ], 502);
            }
        } catch (\Exception $e) {
            \Log::error("Save Analysis S2S exception: " . $e->getMessage());
            return response()->json([
                'message' => 'Error saving analysis layer: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [10] GET /api/projects/{id}/result untuk mengambil data statistik hasil analisis
     */
    public function result(Request $request, $id)
    {
        $userId = $request->user() ? $request->user()->id : null;
        $projectQuery = Project::where('id', $id);
        if ($userId) {
            $projectQuery->where('user_id', $userId);
        }
        $project = $projectQuery->first();
        if (!$project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        $analysis = Analysis::where('project_id', $id)->latest()->first();

        $aoi = DB::table('aois')
            ->select([
                DB::raw("ST_AsGeoJSON(geometry)::json AS geometry")
            ])
            ->where('project_id', $id)
            ->latest()
            ->first();

        if (!$analysis) {
            return response()->json([
                'message' => 'No analysis history found for this project.',
                'data'    => null
            ], 200);
        }

        $statsData = is_string($analysis->statistics) ? json_decode($analysis->statistics, true) : $analysis->statistics;
        $paramsData = is_string($analysis->parameters) ? json_decode($analysis->parameters, true) : $analysis->parameters;

        return response()->json([
            'data' => [
                'id'            => $analysis->id,
                'project_id'    => $analysis->project_id,
                'analysis_type' => $analysis->analysis_type,
                'status'        => $analysis->status,
                'file_path'     => $analysis->file_path,
                'parameters'    => $paramsData, 
                'aoi'           => $aoi ? json_decode($aoi->geometry) : null,
                'style_sld'     => $statsData['style_sld'] ?? null,
                'wms_layer'     => $statsData['wms_layer'] ?? null,
                'maps'          => $statsData['maps'] ?? null, // Tersedia untuk component layers
                'legends'       => $statsData['legends'] ?? null,
                'statistics'    => $statsData['statistics'] ?? null,
            ]
        ]);
    }


    /**
     * [11] GET /api/projects/{id}/download download file GeoTIFF raster
     */
    public function download($id)
    {
        $analysis = Analysis::where('project_id', $id)->latest()->first();

        if (!$analysis || empty($analysis->file_path)) {
            return response()->json([
                'message' => 'The download URL was not found. Please perform the analysis first.'
            ], 404);
        }

        $url = $analysis->file_path;
        if (str_contains($url, ':5000')) {
            $url = str_replace(':5000', ':5001', $url);
        }

        return redirect()->away($url);
    }

    /**
     * [12] GET /api/user/layers
     * Mengambil daftar layer hasil analisis milik pengguna saat ini dari AstraGIS S2S.
     */
    public function userLayers(Request $request)
    {
        $user = $request->user();
        $userId = $user ? (string) $user->id : null;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $projectId = $request->query('project_id');
        if ($projectId) {
            // Validasi bahwa project memang milik user ini
            $project = Project::where('id', $projectId)->where('user_id', $userId)->first();
            if (!$project) {
                return response()->json([
                    'success' => true,
                    'data'    => [],
                    'pagination' => ['total' => 0, 'page' => 1, 'size' => 50, 'total_pages' => 0]
                ]);
            }
        }

        $astragisUrl = config('services.astragis.base_url', 'http://fastapi_backend:8000');
        $apiKey = config('services.astragis.api_key', 'agis_sk_flowgis_production_key_2026');

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $apiKey,
            ])->timeout(15)->get("{$astragisUrl}/s2s/layers", [
                'client_user_id' => $userId,
                'page' => $request->query('page', 1),
                'size' => $request->query('size', 100),
            ]);

            if ($response->failed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengambil layer dari AstraGIS.',
                    'error'   => $response->json() ?? $response->body()
                ], $response->status());
            }

            $resJson = $response->json();
            $items = $resJson['data'] ?? [];

            // Normalisasi metadata: unpack metadata.extra agar project_id, aoi_id dsb dapat diakses langsung
            $items = array_map(function($layer) {
                if (isset($layer['metadata']['extra']) && is_array($layer['metadata']['extra'])) {
                    $layer['metadata'] = array_merge($layer['metadata']['extra'], $layer['metadata']);
                }
                return $layer;
            }, $items);

            // Filter ketat berdasarkan project_id agar layer project lain tidak muncul
            if ($projectId) {
                $items = array_values(array_filter($items, function($layer) use ($projectId) {
                    $meta = $layer['metadata'] ?? [];
                    $pid = $meta['project_id'] ?? ($meta['extra']['project_id'] ?? null);
                    return $pid !== null && (string)$pid === (string)$projectId;
                }));
                $resJson['data'] = $items;
                if (isset($resJson['pagination'])) {
                    $resJson['pagination']['total'] = count($items);
                }
            } else {
                $resJson['data'] = $items;
            }

            return response()->json($resJson);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungi server AstraGIS: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [13] GET /api/user/layer-groups
     * Mengambil daftar layer group milik pengguna saat ini dari AstraGIS.
     */
    public function userLayerGroups(Request $request)
    {
        $user = $request->user();
        $userId = $user ? (string) $user->id : null;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $astragisUrl = config('services.astragis.base_url', 'http://fastapi_backend:8000');
        $apiKey = config('services.astragis.api_key', 'agis_sk_flowgis_production_key_2026');

        try {
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->timeout(15)
                ->get("{$astragisUrl}/s2s/layer-groups", [
                    'client_user_id' => $userId,
                ]);

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * [14] POST /api/user/layer-groups
     * Membuat layer group baru di AstraGIS untuk pengguna saat ini.
     */
    public function createUserLayerGroup(Request $request)
    {
        $user = $request->user();
        $userId = $user ? (string) $user->id : null;
        if (!$userId) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'name'          => 'required|string|max:150',
            'title'         => 'nullable|string|max:200',
            'abstract_text' => 'nullable|string',
            'layer_ids'     => 'required|array|min:1',
            'layer_ids.*'   => 'required',
        ]);

        $astragisUrl = config('services.astragis.base_url', 'http://fastapi_backend:8000');
        $apiKey = config('services.astragis.api_key', 'agis_sk_flowgis_production_key_2026');
        $workspaceId = config('services.astragis.workspace_id', '7');

        try {
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->timeout(30)
                ->post("{$astragisUrl}/s2s/layer-groups", [
                    'workspace_id'   => (string) $workspaceId,
                    'name'           => $validated['name'],
                    'title'          => $validated['title'] ?? $validated['name'],
                    'abstract_text'  => $validated['abstract_text'] ?? '',
                    'client_user_id' => $userId,
                    'layer_ids'      => $validated['layer_ids'],
                ]);

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * [15] DELETE /api/user/layer-groups/{id}
     * Menghapus layer group dari AstraGIS.
     */
    public function deleteUserLayerGroup(Request $request, $id)
    {
        $astragisUrl = config('services.astragis.base_url', 'http://fastapi_backend:8000');
        $apiKey = config('services.astragis.api_key', 'agis_sk_flowgis_production_key_2026');

        try {
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->timeout(15)
                ->delete("{$astragisUrl}/s2s/layer-groups/{$id}");

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * [16] DELETE /api/user/layers/{id}
     * Menghapus layer hasil analisis dari AstraGIS.
     */
    public function deleteUserLayer(Request $request, $id)
    {
        $userId = (string)($request->user() ? $request->user()->id : 1);
        $astragisUrl = config('services.astragis.base_url', 'http://fastapi_backend:8000');
        $apiKey = config('services.astragis.api_key', 'agis_sk_flowgis_production_key_2026');

        try {
            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->timeout(20)
                ->delete("{$astragisUrl}/s2s/layers/{$id}", [
                    'client_user_id' => $userId,
                ]);

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * [17] PUT /api/user/layer-groups/{id}
     * Mengupdate layer group di AstraGIS.
     */
    public function updateUserLayerGroup(Request $request, $id)
    {
        $userId = (string)($request->user() ? $request->user()->id : 1);
        $validated = $request->validate([
            'title'         => 'nullable|string|max:150',
            'abstract_text' => 'nullable|string',
            'layer_ids'     => 'nullable|array|min:1',
        ]);

        $astragisUrl = config('services.astragis.base_url', 'http://fastapi_backend:8000');
        $apiKey = config('services.astragis.api_key', 'agis_sk_flowgis_production_key_2026');

        try {
            $payload = [
                'client_user_id' => $userId,
            ];
            if (isset($validated['title'])) $payload['title'] = $validated['title'];
            if (isset($validated['abstract_text'])) $payload['abstract_text'] = $validated['abstract_text'];
            if (isset($validated['layer_ids'])) $payload['layer_ids'] = $validated['layer_ids'];

            $response = Http::withHeaders(['X-API-Key' => $apiKey])
                ->timeout(30)
                ->put("{$astragisUrl}/s2s/layer-groups/{$id}", $payload);

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}

