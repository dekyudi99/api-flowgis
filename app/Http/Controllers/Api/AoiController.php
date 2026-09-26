<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Aoi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AoiController extends Controller
{
    public function store(Request $request)
    {
        //Validasi Input
        $validated = $request->validate([
            'project_id'           => 'required|exists:projects,id',
            'name'                 => 'required|string|max:255',
            'source_type'          => 'required|string|in:drawn_circle,drawn_polygon,upload_geojson,upload_kml,upload_shp',
            'geometry'             => 'required_without:file',
            'file'                 => 'required_without:geometry|file|max:20480', // Maks 20MB
            'metadata'             => 'nullable',
        ]);

        $geometryExpression = null;
        $filePath = null;
        
        // Memastikan metadata didecode kalau dikirim sebagai string via FormData
        $metadata = $request->input('metadata') ? (is_string($request->input('metadata')) ? json_decode($request->input('metadata'), true) : $request->input('metadata')) : [];

        // 1. Cek jika request sudah membawa geometry langsung (misalnya hasil parsing client-side shpjs / geojson)
        if ($request->filled('geometry')) {
            $geomInput = $request->input('geometry');
            $geomData = is_string($geomInput) ? json_decode($geomInput, true) : $geomInput;
            $jsonString = json_encode($geomData);
            $quotedJson = DB::getPdo()->quote($jsonString);
            $geometryExpression = "ST_SetSRID(ST_GeomFromGeoJSON({$quotedJson}), 4326)";
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filePath = $file->store('aoi_files', 'public');
            $extension = strtolower($file->getClientOriginalExtension());

            // Hanya lakukan parsing file server-side jika geometry belum dikirim dari client
            if (!$geometryExpression) {
                if (in_array($extension, ['json', 'geojson'])) {
                    // Parse GeoJSON File
                    $jsonContent = file_get_contents($file->getRealPath());
                    $parsed = json_decode($jsonContent, true);

                    // Ambil geometri dari FeatureCollection atau Feature tunggal
                    $geomData = $parsed['geometry'] ?? ($parsed['features'][0]['geometry'] ?? $parsed);
                    $quotedJson = DB::getPdo()->quote(json_encode($geomData));
                    
                    $geometryExpression = "ST_SetSRID(ST_GeomFromGeoJSON({$quotedJson}), 4326)";
                    
                    if (isset($parsed['features'][0]['properties'])) {
                        $metadata = array_merge($metadata, $parsed['features'][0]['properties']);
                    }

                } elseif (in_array($extension, ['kml', 'xml'])) {
                    // Parse KML File via Native PostGIS ST_GeomFromKML
                    $kmlContent = file_get_contents($file->getRealPath());

                    $dom = new \DOMDocument();
                    @$dom->loadXML($kmlContent);

                    $geomTypes = ['Polygon', 'MultiGeometry', 'Point', 'LineString'];
                    $kmlGeometryXml = null;

                    foreach ($geomTypes as $type) {
                        $nodes = $dom->getElementsByTagName($type);
                        if ($nodes->length > 0) {
                            $kmlGeometryXml = $dom->saveXML($nodes->item(0));
                            break;
                        }
                    }

                    if (!$kmlGeometryXml) {
                        return response()->json(['message' => 'Elemen geometri tidak ditemukan di dalam file KML.'], 422);
                    }

                    $quotedKml = DB::getPdo()->quote($kmlGeometryXml);
                    $geometryExpression = "ST_SetSRID(ST_GeomFromKML({$quotedKml}), 4326)";

                } elseif ($extension === 'zip') {
                    // Parse SHP (ZIP) File via ZipArchive / unzip CLI
                    $zipPath = $file->getRealPath();
                    $extractDir = storage_path('app/temp_shp_' . time());
                    @mkdir($extractDir, 0777, true);
                    
                    $extracted = false;
                    if (class_exists(\ZipArchive::class)) {
                        $zip = new \ZipArchive();
                        if ($zip->open($zipPath) === TRUE) {
                            $zip->extractTo($extractDir);
                            $zip->close();
                            $extracted = true;
                        }
                    }
                    
                    if (!$extracted && file_exists('/usr/bin/unzip')) {
                        shell_exec("unzip -o -q " . escapeshellarg($zipPath) . " -d " . escapeshellarg($extractDir));
                        $extracted = true;
                    }
                    
                    if ($extracted) {
                        $shpFile = glob($extractDir . '/*.shp')[0] ?? null;
                        
                        if ($shpFile && shell_exec('which ogr2ogr')) {
                            $jsonOutput = shell_exec("ogr2ogr -t_srs EPSG:4326 -f GeoJSON /vsistdout/ " . escapeshellarg($shpFile));
                            $parsed = json_decode($jsonOutput, true);
                            $geomData = $parsed['features'][0]['geometry'] ?? null;

                            if ($geomData) {
                                $quotedJson = DB::getPdo()->quote(json_encode($geomData));
                                $geometryExpression = "ST_SetSRID(ST_GeomFromGeoJSON({$quotedJson}), 4326)";
                                
                                if (isset($parsed['features'][0]['properties'])) {
                                    $metadata = array_merge($metadata, $parsed['features'][0]['properties']);
                                }
                            }
                        }
                    }
                    
                    @array_map('unlink', glob("$extractDir/*.*"));
                    @rmdir($extractDir);
                } else {
                    return response()->json(['message' => 'The file format is not supported. Please upload a file in .geojson, .kml, or .zip format.'], 422);
                }
            }
        } 
        
        elseif (!$geometryExpression && $request->has('geometry')) {
            $geomInput = $validated['geometry'] ?? $request->input('geometry');
            $geomData = is_string($geomInput) ? json_decode($geomInput, true) : $geomInput;
            
            $jsonString = json_encode($geomData);
            $quotedJson = DB::getPdo()->quote($jsonString);
            $geometryExpression = "ST_SetSRID(ST_GeomFromGeoJSON({$quotedJson}), 4326)";
        }

        if (!$geometryExpression) {
            return response()->json(['message' => 'Failed to process the spatial file geometry. Make sure the geometry format is valid..'], 422);
        }

        //Hitung Luas Area (Hektar) via PostGIS ST_Area
        $areaResult = DB::selectOne("
            SELECT ST_Area(({$geometryExpression})::geography) / 10000 AS area_ha
        ");
        $areaHa = $areaResult->area_ha ?? null;

        // Simpan Ke Database
        $aoi = new Aoi();
        $aoi->project_id  = $validated['project_id'];
        $aoi->name        = $validated['name'];
        $aoi->source_type = $validated['source_type'];
        $aoi->geometry    = DB::raw($geometryExpression);
        $aoi->metadata    = $metadata;
        $aoi->area_ha     = $areaHa;
        $aoi->file_path   = $filePath;
        $aoi->save();

        // Return Response
        return response()->json([
            'message' => 'The AOI was successfully saved.',
            'data'    => $this->getAoiFormatted($aoi->id)
        ], 201);
    }

    /**
     * Helper Function untuk mengonversi Geometri PostGIS ke GeoJSON Standard
     */
    private function getAoiFormatted($id)
    {
        $aoi = DB::table('aois')
            ->select([
                'id',
                'project_id',
                'name',
                'source_type',
                'area_ha',
                'file_path',
                'metadata',
                'created_at',
                'updated_at',
                DB::raw("ST_AsGeoJSON(geometry)::json AS geometry")
            ])
            ->where('id', $id)
            ->first();

        if ($aoi) {
            $aoi->geometry = json_decode($aoi->geometry);
            $aoi->metadata = json_decode($aoi->metadata);
        }

        return $aoi;
    }

    /**
     * Mengambil seluruh daftar AOI milik suatu Project (Untuk Dropdown di React)
     */
    public function indexByProject($projectId)
    {
        $aois = DB::table('aois')
            ->select([
                'id',
                'project_id',
                'name',
                'source_type',
                'area_ha',
                'created_at',
                DB::raw("ST_AsGeoJSON(geometry)::json AS geometry")
            ])
            ->where('project_id', $projectId)
            ->orderBy('created_at', 'desc')
            ->get();

        $formattedAois = $aois->map(function ($aoi) {
            $aoi->geometry = json_decode($aoi->geometry);
            return $aoi;
        });

        return response()->json([
            'data' => $formattedAois
        ], 200);
    }

    /**
     * Mengambil seluruh AOI milik user yang sedang login (Lintas Proyek)
     */
    public function indexByUser(Request $request)
    {
        $userId = $request->user()->id;

        $aois = DB::table('aois')
            ->join('projects', 'aois.project_id', '=', 'projects.id')
            ->select([
                'aois.id',
                'aois.project_id',
                'aois.name',
                'aois.source_type',
                'aois.area_ha',
                'aois.created_at',
                'projects.name as project_name',
                DB::raw("ST_AsGeoJSON(aois.geometry)::json AS geometry")
            ])
            ->where('projects.user_id', $userId)
            ->orderBy('aois.created_at', 'desc')
            ->get();

        $formattedAois = $aois->map(function ($aoi) {
            $aoi->geometry = json_decode($aoi->geometry);
            return $aoi;
        });

        return response()->json([
            'data' => $formattedAois
        ], 200);
    }
}