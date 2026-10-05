<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Analysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\AstraGisService;

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
            'layer_names'     => 'sometimes|array|min:1|max:7',
            'layer_names.*'   => 'required|string|in:FloodRisk,Rainfall,Elevation,Distance,TPI,NDVI,NDWI',
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
                    'component_exports' => $resData['component_exports'] ?? [],
                    'saved_layers'  => [],
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
                    'component_exports' => $resData['component_exports'] ?? [],
                    'saved_layers'  => [],
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

        $validated = $request->validate([
            'analysis_id' => 'required|integer',
            'components' => 'sometimes|array|max:6',
            'components.*' => 'required|string|in:Rainfall,Elevation,Distance,TPI,NDVI,NDWI',
        ]);
        $analysisId = $validated['analysis_id'];
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

        $astragis = app(AstraGisService::class);
        $sldXml = $statsData['style_sld'] ?? null;
        $componentName = $statsData['component'] ?? str_replace('component_', '', $analysis->analysis_type);
        $technicalComponent = preg_replace('/[^a-zA-Z0-9_-]/', '_', $componentName);
        $layerName = 'analysis_' . $technicalComponent . '_' . $analysis->id;
        $analysisParams = is_array($analysis->parameters) ? $analysis->parameters : (json_decode($analysis->parameters, true) ?? []);
        $selectedComponents = array_values(array_unique($validated['components'] ?? []));
        $isFloodRisk = $analysis->analysis_type === 'flood_risk';
        $savedLayers = is_array($statsData['saved_layers'] ?? null) ? $statsData['saved_layers'] : [];
        $componentExports = is_array($statsData['component_exports'] ?? null) ? $statsData['component_exports'] : [];

        if (!$isFloodRisk && !empty($statsData['wms_layer'])) {
            return response()->json([
                'message' => 'Analysis result has already been saved to project.',
                'data' => ['wms_layer' => $statsData['wms_layer'], 'is_saved' => true],
            ], 200);
        }

        $pendingComponents = array_values(array_filter(
            $selectedComponents,
            fn ($component) => empty($savedLayers[$component])
        ));
        if ($pendingComponents !== [] && !$isFloodRisk) {
            return response()->json(['message' => 'Selected component exports are unavailable for this analysis.'], 422);
        }

        $needsMainLayer = empty($statsData['wms_layer']);
        if ($needsMainLayer && empty($downloadUrl)) {
            return response()->json(['message' => 'Download URL / GeoTIFF is not ready to be saved.'], 422);
        }
        if ($isFloodRisk && !$needsMainLayer && $pendingComponents === []) {
            return response()->json([
                'message' => 'Selected analysis layers have already been saved to project.',
                'data' => [
                    'wms_layer' => $statsData['wms_layer'] ?? null,
                    'saved_layers' => array_intersect_key($savedLayers, array_flip($selectedComponents)),
                    'is_saved' => true,
                ],
            ], 200);
        }

        $layerMetadata = [
            'analysis_id'   => (int)$analysis->id,
            'analysis_type' => $analysis->analysis_type,
            'component'     => $componentName,
            'aoi_id'        => $analysis->aoi_id,
            'parameters'    => $analysis->parameters,
        ];
        $workspaceName = null;
        $publishMainLayer = function (string $url) use ($astragis, $layerName, &$workspaceName, $sldXml, $layerMetadata, $analysisParams, $project, $isFloodRisk, $componentName) {
            $layer = $astragis->publishFromUrl(
                $url,
                $layerName,
                $workspaceName,
                $sldXml,
                $layerMetadata
            );

            if (empty($layer)) {
                throw new \RuntimeException('AstraGIS did not return the published analysis layer.');
            }

            $layer['metadata'] = array_merge($layer['metadata'] ?? [], $layerMetadata);
            $layer['layer_name'] = $layer['layer_name'] ?? ($layer['store_name'] ?? $layerName);
            $layer['display_name'] = $isFloodRisk
                ? AstraGisService::buildAnalysisDisplayName('Flood Risk', $analysisParams)
                : ($layer['display_name'] ?? AstraGisService::buildAnalysisDisplayName($componentName, $analysisParams));
            $layer['flowgis_project_id'] = (string) $project->id;

            return $layer;
        };

        try {
            $workspaceName = $astragis->getOwnedWorkspaceName();

            if ($isFloodRisk) {
                if ($needsMainLayer) {
                    $statsData['wms_layer'] = $publishMainLayer($downloadUrl);
                    $statsData['flowgis_project_id'] = (string) $project->id;
                    $analysis->statistics = $statsData;
                    $analysis->save();
                }

                $componentResult = ['saved' => [], 'errors' => []];
                if ($pendingComponents !== []) {
                    $componentResult = $astragis->publishAnalysisComponents(
                        $componentExports,
                        $pendingComponents,
                        (int) $analysis->id,
                        $analysisParams,
                        $workspaceName
                    );
                }
                $savedLayers = array_merge($savedLayers, $componentResult['saved']);
                $statsData['saved_layers'] = $savedLayers;
                $analysis->statistics = $statsData;
                $analysis->save();

                if ($componentResult['errors'] !== []) {
                    return response()->json([
                        'message' => 'Some analysis layers could not be published. Successful layers were saved; retry to publish the remaining layers.',
                        'data' => [
                            'wms_layer' => $statsData['wms_layer'] ?? null,
                            'saved_layers' => array_intersect_key($savedLayers, array_flip($selectedComponents)),
                            'failed_components' => $componentResult['errors'],
                            'is_saved' => false,
                        ],
                    ], 200);
                }

                return response()->json([
                    'message' => 'Flood risk analysis successfully saved to project.',
                    'data' => [
                        'wms_layer' => $statsData['wms_layer'] ?? null,
                        'saved_layers' => array_intersect_key($savedLayers, array_flip($selectedComponents)),
                        'is_saved' => true,
                    ],
                ], 200);
            }

            $publishedWmsLayer = $publishMainLayer($downloadUrl);

            // Keep project ownership only in FlowGIS; GeoServer API v1 has no Project entity.
            $statsData['flowgis_project_id'] = (string) $project->id;
            $statsData['wms_layer'] = $publishedWmsLayer;
            $analysis->statistics = $statsData;
            $analysis->save();

            return response()->json([
                'message' => 'Analysis result successfully saved to project.',
                'data'    => [
                    'wms_layer' => $publishedWmsLayer,
                    'saved_layers' => array_intersect_key($savedLayers, array_flip($selectedComponents)),
                    'failed_components' => [],
                    'is_saved'  => true
                ]
            ], 200);
        } catch (\Exception $e) {
            \Log::error("AstraGIS publishFromUrl failed: " . $e->getMessage());
            $errBody = $e->getMessage();
            $isExpiredUrl = AstraGisService::isExpiredDownloadError($errBody);

            if ($isFloodRisk && !empty($statsData['wms_layer'])) {
                $componentResult = ['saved' => [], 'errors' => []];
                if ($pendingComponents !== []) {
                    try {
                        $componentResult = $astragis->publishAnalysisComponents(
                            $componentExports,
                            $pendingComponents,
                            (int) $analysis->id,
                            $analysisParams,
                            $workspaceName
                        );
                        $savedLayers = array_merge($savedLayers, $componentResult['saved']);
                    } catch (\Exception $componentException) {
                        \Log::error("Flood Risk component publishing failed: " . $componentException->getMessage());
                        $componentResult['errors'] = array_fill_keys($pendingComponents, $componentException->getMessage());
                    }
                }

                $statsData['saved_layers'] = $savedLayers;
                $analysis->statistics = $statsData;
                $analysis->save();

                return response()->json([
                    'message' => $componentResult['errors'] === []
                        ? 'Flood risk analysis successfully saved to project.'
                        : 'FloodRisk was saved, but some selected components could not be published. Retry to publish the remaining layers.',
                    'data' => [
                        'wms_layer' => $statsData['wms_layer'],
                        'saved_layers' => array_intersect_key($savedLayers, array_flip($selectedComponents)),
                        'failed_components' => $componentResult['errors'],
                        'is_saved' => $componentResult['errors'] === [],
                    ],
                ], 200);
            }

            // Jika URL unduhan dari GEE telah kadaluarsa (HTTP 401), coba lakukan auto-refresh via Flask
            if ($isExpiredUrl) {
                $flaskUrl = config('services.flask.url');
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
                                $freshExports = $freshData['component_exports'] ?? $componentExports;
                                $statsData['component_exports'] = $freshExports;
                                $analysis->file_path = $newDownloadUrl;
                                $analysis->statistics = $statsData;
                                $analysis->save();

                                $publishedWmsLayer = $publishMainLayer($newDownloadUrl);
                                $statsData['flowgis_project_id'] = (string) $project->id;
                                $statsData['wms_layer'] = $publishedWmsLayer;

                                $freshResult = ['saved' => [], 'errors' => []];
                                if ($isFloodRisk && $pendingComponents !== []) {
                                    $freshResult = $astragis->publishAnalysisComponents(
                                        $freshExports,
                                        $pendingComponents,
                                        (int) $analysis->id,
                                        $analysisParams,
                                        $workspaceName
                                    );
                                    $savedLayers = array_merge($savedLayers, $freshResult['saved']);
                                }

                                if ($isFloodRisk) {
                                    $componentErrors = $freshResult['errors'] ?? [];
                                    $statsData['saved_layers'] = $savedLayers;
                                    $analysis->statistics = $statsData;
                                    $analysis->save();
                                    return response()->json([
                                        'message' => $componentErrors === []
                                            ? 'Flood risk analysis successfully refreshed and saved to project.'
                                            : 'Flood risk was saved, but some selected components could not be published. Retry to publish the remaining layers.',
                                        'data' => [
                                            'wms_layer' => $publishedWmsLayer,
                                            'saved_layers' => array_intersect_key($savedLayers, array_flip($selectedComponents)),
                                            'failed_components' => $componentErrors,
                                            'is_saved' => !empty($publishedWmsLayer) && $componentErrors === [],
                                        ],
                                    ], 200);
                                }

                                $analysis->statistics = $statsData;
                                $analysis->save();

                                return response()->json([
                                    'message' => 'Analysis result successfully refreshed and saved to project.',
                                    'data'    => [
                                        'wms_layer' => $publishedWmsLayer,
                                        'saved_layers' => array_intersect_key($savedLayers, array_flip($selectedComponents)),
                                        'is_saved'  => true
                                    ]
                                ], 200);
                            }
                        }
                    } catch (\Exception $recompErr) {
                        \Log::warning("Auto-refresh download_url failed: " . $recompErr->getMessage());
                    }
                }

                return response()->json([
                    'message' => 'Berkas unduhan satelit dari Google Earth Engine telah kadaluarsa (sesi analisis lama). Silakan klik "Run Spatial Analysis" untuk memperbarui hasil sebelum menyimpan.',
                    'is_expired' => true,
                    'error'   => $e->getMessage()
                ], 422);
            }

            return response()->json([
                'message' => 'Gagal memublikasikan layer ke GeoServer. Periksa autentikasi API key dan status layanan GeoServer.',
                'error'   => $e->getMessage()
            ], 502);
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
                'wms_layer' => $statsData['wms_layer'] ?? null,
                'maps' => $statsData['maps'] ?? null, // Tersedia untuk component layers
                'legends' => $statsData['legends'] ?? null,
                'statistics' => $statsData['statistics'] ?? null,
                'component_exports' => $statsData['component_exports'] ?? [],
                'saved_layers' => $statsData['saved_layers'] ?? [],
                'is_saved' => !empty($statsData['wms_layer']) || !empty($statsData['saved_layers']),
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
        $ownedProjects = Project::where('user_id', $userId);
        if ($projectId) {
            // Validasi bahwa project memang milik user ini
            $project = (clone $ownedProjects)->where('id', $projectId)->first();
            if (!$project) {
                return response()->json([
                    'success' => true,
                    'data'    => [],
                    'pagination' => ['total' => 0, 'page' => 1, 'size' => 50, 'total_pages' => 0]
                ]);
            }
            $ownedProjects->where('id', $projectId);
        }

        $ownedProjectIds = $ownedProjects->pluck('id');
        $ownedProjectIdValues = $ownedProjectIds->map(fn ($id) => (string) $id)->all();
        $savedAnalyses = Analysis::whereIn('project_id', $ownedProjectIds)
            ->get(['id', 'project_id', 'analysis_type', 'parameters', 'statistics']);

        $publishedLayers = [];
        foreach ($savedAnalyses as $savedAnalysis) {
            $statistics = is_array($savedAnalysis->statistics)
                ? $savedAnalysis->statistics
                : (json_decode($savedAnalysis->statistics, true) ?? []);
            $wmsLayer = $statistics['wms_layer'] ?? null;
            $savedLayers = is_array($statistics['saved_layers'] ?? null) ? $statistics['saved_layers'] : [];
            $analysisLayers = array_merge(is_array($wmsLayer) ? [$wmsLayer] : [], array_values($savedLayers));

            foreach ($analysisLayers as $publishedLayer) {
                $metadata = $publishedLayer['metadata'] ?? [];
                $metadata['analysis_id'] = (int) ($metadata['analysis_id'] ?? $savedAnalysis->id);
                $metadata['analysis_type'] = $metadata['analysis_type'] ?? $savedAnalysis->analysis_type;
                $metadata['parameters'] = $metadata['parameters'] ?? $savedAnalysis->parameters;
                $displayName = $metadata['display_name'] ?? null;
                $componentName = $metadata['component'] ?? null;
                $baseName = $componentName
                    ? (strtolower($componentName) === 'flood_risk' ? 'Flood Risk' : $componentName)
                    : ($savedAnalysis->analysis_type === 'flood_risk' ? 'Flood Risk' : 'Analysis');
                $displayName = AstraGisService::buildAnalysisDisplayName($baseName, (array) $savedAnalysis->parameters);
                $metadata['display_name'] = $displayName;
                $publishedLayers[] = [
                    'project_id' => (string) $savedAnalysis->project_id,
                    'analysis_type' => $metadata['analysis_type'],
                    'parameters' => $metadata['parameters'],
                    'metadata' => $metadata,
                    'workspace_name' => $publishedLayer['workspace_name'] ?? null,
                    'layer_names' => array_values(array_filter([
                        $publishedLayer['layer_name'] ?? null,
                        $publishedLayer['table_name'] ?? null,
                        $publishedLayer['store_name'] ?? null,
                        $publishedLayer['geoserver_name'] ?? null,
                    ])),
                ];
            }
        }

        try {
            $astragis = app(AstraGisService::class);
            $resJson = $astragis->getLayers([
                'layer_type' => $request->query('layer_type'),
            ]);

            $items = $resJson['data'] ?? [];

            // Normalisasi metadata: unpack metadata.extra agar project_id, aoi_id dsb dapat diakses langsung
            $items = array_map(function($layer) use ($publishedLayers, $projectId) {
                $matchedPublishedLayer = null;
                if (isset($layer['metadata']['extra']) && is_array($layer['metadata']['extra'])) {
                    $layer['metadata'] = array_merge($layer['metadata']['extra'], $layer['metadata']);
                }

                foreach ($publishedLayers as $publishedLayer) {
                    if ($projectId && $publishedLayer['project_id'] !== (string) $projectId) {
                        continue;
                    }

                    $layerWorkspace = $layer['workspace_name'] ?? null;
                    $workspaceMatches = !$publishedLayer['workspace_name']
                        || $publishedLayer['workspace_name'] === $layerWorkspace;
                    $layerNames = array_filter([
                        $layer['layer_name'] ?? null,
                        $layer['table_name'] ?? null,
                        $layer['store_name'] ?? null,
                        $layer['geoserver_name'] ?? null,
                    ]);
                    $nameMatches = array_intersect($publishedLayer['layer_names'], $layerNames) !== [];

                    if ($workspaceMatches && $nameMatches) {
                        $layer['metadata'] = array_merge($layer['metadata'] ?? [], $publishedLayer['metadata'], [
                            'project_id' => $publishedLayer['project_id'],
                            'analysis_type' => $publishedLayer['analysis_type'],
                            'parameters' => $publishedLayer['parameters'],
                        ]);
                        $identifier = AstraGisService::resolveLayerIdentifier($layer);
                        if ($identifier !== null) {
                            $layer['id'] = $identifier;
                        }
                        $layer['display_name'] = $publishedLayer['metadata']['display_name']
                            ?? $layer['display_name'] ?? $layer['title'] ?? $layer['layer_name'];
                        $layer['project_id'] = $publishedLayer['project_id'];
                        $matchedPublishedLayer = $publishedLayer;
                        break;
                    }
                }

                if ($matchedPublishedLayer) {
                    $identifier = AstraGisService::resolveLayerIdentifier($layer);
                    if ($identifier !== null) {
                        $layer['id'] = $identifier;
                        $layer['display_name'] = $matchedPublishedLayer['metadata']['display_name']
                            ?? $layer['display_name'] ?? $layer['title'] ?? $layer['layer_name'];
                    }
                }

                return $layer;
            }, $items);

            // Hanya kembalikan layer yang terhubung ke proyek milik pengguna ini.
            $items = array_values(array_filter($items, function ($layer) use ($projectId, $ownedProjectIdValues) {
                $meta = $layer['metadata'] ?? [];
                $pid = $layer['project_id'] ?? $meta['project_id'] ?? ($meta['extra']['project_id'] ?? null);

                if ($pid === null || !in_array((string) $pid, $ownedProjectIdValues, true)) {
                    return false;
                }

                return !$projectId || (string) $pid === (string) $projectId;
            }));

            $resJson['data'] = $items;
            $resJson['total'] = count($items);

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

        try {
            $astragis = app(AstraGisService::class);
            $groups = $astragis->getLayerGroupsForOwnedWorkspace();
            return response()->json([
                'success' => true,
                'total' => count($groups),
                'data' => $groups,
            ], 200);
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

        try {
            $astragis = app(AstraGisService::class);
            $workspaceName = $astragis->getOwnedWorkspaceName();
            $res = $astragis->createLayerGroup([
                'workspace_name' => $workspaceName,
                'workspace_id'   => $workspaceName,
                'name'           => $validated['name'],
                'title'          => $validated['title'] ?? $validated['name'],
                'abstract_text'  => $validated['abstract_text'] ?? '',
                'layer_ids'      => array_map('strval', $validated['layer_ids']),
            ]);

            return response()->json($res, 201);
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
        try {
            $astragis = app(AstraGisService::class);
            $success = $astragis->deleteLayerGroup((string) $id);
            return response()->json(['success' => $success, 'message' => 'Layer group deleted'], $success ? 200 : 500);
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
        try {
            $astragis = app(AstraGisService::class);
            $success = $astragis->deleteLayer((string) $id);
            return response()->json(['success' => $success, 'message' => 'Layer deleted'], $success ? 200 : 500);
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
        $validated = $request->validate([
            'title'         => 'nullable|string|max:150',
            'abstract_text' => 'nullable|string',
            'layer_ids'     => 'nullable|array|min:1',
        ]);

        try {
            $payload = [];
            if (isset($validated['title'])) $payload['title'] = $validated['title'];
            if (isset($validated['abstract_text'])) $payload['abstract_text'] = $validated['abstract_text'];
            if (isset($validated['layer_ids'])) $payload['layer_ids'] = array_map('strval', $validated['layer_ids']);

            $astragis = app(AstraGisService::class);
            $res = $astragis->updateLayerGroup((string) $id, $payload);

            return response()->json($res, 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}

