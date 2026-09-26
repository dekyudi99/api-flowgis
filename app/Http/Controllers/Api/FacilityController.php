<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Infrastructure;
use App\Models\CctvStation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacilityController extends Controller
{
    /**
     * Daftar rumah sakit di Nakhon Pathom
     */
    private array $defaultHospitals = [
        [
            'name'         => 'Nakhon Pathom Hospital (โรงพยาบาลนครปฐม)',
            'type'         => 'hospital',
            'address'      => '196 Thetsaban Rd, Phra Pathom Chedi, Mueang Nakhon Pathom, Nakhon Pathom 73000',
            'phone_number' => '+66 34 254 150',
            'capacity'     => 750,
            'lng'          => 100.0617,
            'lat'          => 13.8189,
        ],
        [
            'name'         => 'Sanam Chandra Hospital (โรงพยาบาลสนามจันทร์)',
            'type'         => 'hospital',
            'address'      => '1154 Phetkasem Rd, Sanam Chan, Mueang Nakhon Pathom, Nakhon Pathom 73000',
            'phone_number' => '+66 34 219 600',
            'capacity'     => 300,
            'lng'          => 100.0450,
            'lat'          => 13.8160,
        ],
        [
            'name'         => 'Kamphaeng Saen Hospital (โรงพยาบาลกำแพงแสน)',
            'type'         => 'hospital',
            'address'      => '111 Malaiman Rd, Thung Kraphang Hom, Kamphaeng Saen, Nakhon Pathom 73140',
            'phone_number' => '+66 34 351 163',
            'capacity'     => 120,
            'lng'          => 99.9880,
            'lat'          => 14.0040,
        ],
        [
            'name'         => 'Sam Phran Hospital (โรงพยาบาลสามพราน)',
            'type'         => 'hospital',
            'address'      => '45 Moo 7, Petkasem Rd, Sam Phran, Nakhon Pathom 73110',
            'phone_number' => '+66 34 322 045',
            'capacity'     => 150,
            'lng'          => 100.2150,
            'lat'          => 13.7270,
        ],
        [
            'name'         => 'Bang Len Hospital (โรงพยาบาลบางเลน)',
            'type'         => 'hospital',
            'address'      => '8 Moo 8, Bang Len, Bang Len District, Nakhon Pathom 73130',
            'phone_number' => '+66 34 391 216',
            'capacity'     => 90,
            'lng'          => 100.1700,
            'lat'          => 14.0200,
        ],
        [
            'name'         => 'Nakhon Chai Si Hospital (โรงพยาบาลนครชัยศรี)',
            'type'         => 'hospital',
            'address'      => '88 Moo 3, Nakhon Chai Si, Nakhon Chai Si District, Nakhon Pathom 73120',
            'phone_number' => '+66 34 331 465',
            'capacity'     => 100,
            'lng'          => 100.1900,
            'lat'          => 13.7950,
        ],
        [
            'name'         => 'Metta Pracharak (Wat Rai Khing) Hospital (โรงพยาบาลเมตตาประชารักษ์)',
            'type'         => 'hospital',
            'address'      => '52 Moo 2, Rai Khing, Sam Phran District, Nakhon Pathom 73210',
            'phone_number' => '+66 34 388 700',
            'capacity'     => 400,
            'lng'          => 100.2720,
            'lat'          => 13.7280,
        ],
        [
            'name'         => 'Phutthamonthon Hospital (โรงพยาบาลพุทธมณฑล)',
            'type'         => 'hospital',
            'address'      => '99 Moo 4, Salaya, Phutthamonthon District, Nakhon Pathom 73170',
            'phone_number' => '+66 2 441 9091',
            'capacity'     => 120,
            'lng'          => 100.3200,
            'lat'          => 13.7900,
        ],
        [
            'name'         => 'Don Tum Hospital (โรงพยาบาลดอนตูม)',
            'type'         => 'hospital',
            'address'      => '90 Moo 1, Sam Ngam, Don Tum District, Nakhon Pathom 73150',
            'phone_number' => '+66 34 381 213',
            'capacity'     => 60,
            'lng'          => 100.0800,
            'lat'          => 13.9500,
        ]
    ];

    /**
     * GET /api/facilities/hospitals
     * Mengembalikan daftar rumah sakit di Nakhon Pathom (GeoJSON FeatureCollection)
     */
    public function hospitals()
    {
        // Cek apakah tabel infrastructures sudah berisi data hospital
        $count = Infrastructure::where('type', 'hospital')->count();
        if ($count === 0) {
            // Auto-populate default hospitals
            foreach ($this->defaultHospitals as $h) {
                try {
                    $pointWkt = "POINT({$h['lng']} {$h['lat']})";
                    DB::table('infrastructures')->insert([
                        'name'         => $h['name'],
                        'type'         => 'hospital',
                        'address'      => $h['address'],
                        'phone_number' => $h['phone_number'],
                        'capacity'     => $h['capacity'],
                        'geom'         => DB::raw("ST_SetSRID(ST_GeomFromText('{$pointWkt}'), 4326)"),
                    ]);
                } catch (\Exception $e) {
                    \Log::warning("Could not insert hospital to DB: " . $e->getMessage());
                }
            }
        }

        $records = DB::table('infrastructures')
            ->select([
                'id',
                'name',
                'type',
                'address',
                'phone_number',
                'capacity',
                DB::raw("ST_AsGeoJSON(geom)::json AS geometry")
            ])
            ->where('type', 'hospital')
            ->get();

        $features = [];
        foreach ($records as $r) {
            $geom = json_decode($r->geometry);
            $features[] = [
                'type' => 'Feature',
                'geometry' => $geom,
                'properties' => [
                    'id'           => $r->id,
                    'name'         => $r->name,
                    'type'         => $r->type,
                    'address'      => $r->address,
                    'phone_number' => $r->phone_number,
                    'capacity'     => $r->capacity,
                ]
            ];
        }

        // Fallback jika database belum memiliki geometri
        if (empty($features)) {
            foreach ($this->defaultHospitals as $idx => $h) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$h['lng'], $h['lat']]
                    ],
                    'properties' => [
                        'id'           => $idx + 1,
                        'name'         => $h['name'],
                        'type'         => $h['type'],
                        'address'      => $h['address'],
                        'phone_number' => $h['phone_number'],
                        'capacity'     => $h['capacity'],
                    ]
                ];
            }
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * GET /api/facilities/all
     * Mengembalikan fasilitas publik murni (Rumah Sakit, Posko, dll.)
     * Kirim ?include_cctv=true jika ingin menggabungkan dengan stasiun CCTV
     */
    public function all(Request $request)
    {
        try {
            $includeCctv = $request->boolean('include_cctv', false);

            $infras = Infrastructure::all()->map(function ($inf) {
                $lng = null;
                $lat = null;
                try {
                    $point = DB::selectOne("SELECT ST_X(geom) as lng, ST_Y(geom) as lat FROM infrastructures WHERE id = ?", [$inf->id]);
                    if ($point) {
                        $lng = (float)$point->lng;
                        $lat = (float)$point->lat;
                    }
                } catch (\Exception $e) {}

                $rawType = strtolower($inf->type ?: 'hospital');
                $categoryName = 'Hospital';
                if ($rawType === 'water_station' || $rawType === 'water station') {
                    $categoryName = 'Water Station';
                } elseif ($rawType === 'evacuation_center' || $rawType === 'evacuation center') {
                    $categoryName = 'Evacuation Center';
                } elseif ($rawType === 'fire_station' || $rawType === 'fire station') {
                    $categoryName = 'Fire Station';
                } elseif ($rawType !== 'hospital') {
                    $categoryName = ucfirst(str_replace('_', ' ', $rawType));
                }

                return [
                    'id'           => 'infra_' . $inf->id,
                    'raw_id'       => $inf->id,
                    'name'         => $inf->name,
                    'category'     => $categoryName,
                    'lat'          => $lat,
                    'lng'          => $lng,
                    'address'      => $inf->address,
                    'phone_number' => $inf->phone_number,
                    'capacity'     => $inf->capacity,
                    'is_active'    => true,
                ];
            });

            if ($includeCctv) {
                CctvStation::ensureTableAndDefaults();
                $cctvs = CctvStation::all()->map(function ($c) {
                    return [
                        'id'           => 'cctv_' . $c->id,
                        'raw_id'       => $c->id,
                        'name'         => $c->name,
                        'category'     => 'CCTV Station',
                        'station_code' => $c->station_code,
                        'lat'          => (float) $c->lat,
                        'lng'          => (float) $c->lng,
                        'cctvLink'     => url("/api/facilities/cctv/{$c->station_code}/stream"),
                        'stream_url'   => $c->stream_url,
                        'location'     => $c->location,
                        'is_active'    => $c->is_active,
                    ];
                });
                $result = $infras->concat($cctvs)->values();
            } else {
                $result = $infras->values();
            }

            return response()->json([
                'status' => 'success',
                'data'   => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load facilities: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/admin/facilities
     * Tambah fasilitas publik baru
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'         => 'required|string|max:255',
                'category'     => 'required|string',
                'lat'          => 'required|numeric',
                'lng'          => 'required|numeric',
                'address'      => 'nullable|string',
                'phone_number' => 'nullable|string',
                'capacity'     => 'nullable|integer',
            ]);

            $category = $validated['category'];
            $pointWkt = "POINT({$validated['lng']} {$validated['lat']})";
            $infraId = DB::table('infrastructures')->insertGetId([
                'name'         => $validated['name'],
                'type'         => strtolower(str_replace(' ', '_', $category)),
                'address'      => $validated['address'] ?? null,
                'phone_number' => $validated['phone_number'] ?? null,
                'capacity'     => $validated['capacity'] ?? null,
                'geom'         => DB::raw("ST_SetSRID(ST_GeomFromText('{$pointWkt}'), 4326)"),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Public facility saved successfully.',
                'data'    => [
                    'id'           => 'infra_' . $infraId,
                    'raw_id'       => $infraId,
                    'name'         => $validated['name'],
                    'category'     => $category,
                    'lat'          => (float)$validated['lat'],
                    'lng'          => (float)$validated['lng'],
                    'address'      => $validated['address'] ?? null,
                    'phone_number' => $validated['phone_number'] ?? null,
                    'capacity'     => $validated['capacity'] ?? null,
                    'is_active'    => true,
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to save facility: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/admin/facilities/{id}
     * Update data fasilitas publik
     */
    public function update(Request $request, $id)
    {
        try {
            $rawId = str_replace('infra_', '', $id);
            $validated = $request->validate([
                'name'         => 'required|string|max:255',
                'category'     => 'required|string',
                'lat'          => 'required|numeric',
                'lng'          => 'required|numeric',
                'address'      => 'nullable|string',
                'phone_number' => 'nullable|string',
                'capacity'     => 'nullable|integer',
            ]);

            $category = $validated['category'];
            $pointWkt = "POINT({$validated['lng']} {$validated['lat']})";
            DB::table('infrastructures')->where('id', $rawId)->update([
                'name'         => $validated['name'],
                'type'         => strtolower(str_replace(' ', '_', $category)),
                'address'      => $validated['address'] ?? null,
                'phone_number' => $validated['phone_number'] ?? null,
                'capacity'     => $validated['capacity'] ?? null,
                'geom'         => DB::raw("ST_SetSRID(ST_GeomFromText('{$pointWkt}'), 4326)"),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Public facility updated successfully.',
                'data'    => [
                    'id'           => 'infra_' . $rawId,
                    'raw_id'       => $rawId,
                    'name'         => $validated['name'],
                    'category'     => $category,
                    'lat'          => (float)$validated['lat'],
                    'lng'          => (float)$validated['lng'],
                    'address'      => $validated['address'] ?? null,
                    'phone_number' => $validated['phone_number'] ?? null,
                    'capacity'     => $validated['capacity'] ?? null,
                    'is_active'    => true,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update facility: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/facilities/{id}
     * Hapus fasilitas
     */
    public function destroy($id)
    {
        try {
            if (str_starts_with($id, 'cctv_')) {
                $rawId = str_replace('cctv_', '', $id);
                CctvStation::where('id', $rawId)->delete();
            } elseif (str_starts_with($id, 'infra_')) {
                $rawId = str_replace('infra_', '', $id);
                DB::table('infrastructures')->where('id', $rawId)->delete();
            } else {
                CctvStation::where('id', $id)->delete();
                DB::table('infrastructures')->where('id', $id)->delete();
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Facility deleted successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to delete facility: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/facilities/water-stations
     * Mengembalikan daftar stasiun pemantauan air & CCTV di database
     */
    public function waterStations()
    {
        CctvStation::ensureTableAndDefaults();
        $stations = CctvStation::where('is_active', true)->get();

        $features = [];
        foreach ($stations as $s) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float)$s->lng, (float)$s->lat]
                ],
                'properties' => [
                    'id'           => $s->id,
                    'stationCode'  => $s->station_code,
                    'stationName'  => $s->name,
                    'location'     => $s->location,
                    'streamUrl'    => url("/api/facilities/cctv/{$s->station_code}/stream"),
                    'snapshotUrl'  => url("/api/facilities/cctv/{$s->station_code}/snapshot"),
                ]
            ];
        }

        return response()->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
        ]);
    }

    /**
     * GET /api/facilities/cctv/{stationCode}/snapshot
     * Mengambil gambar snapshot JPEG terkini dari kamera Axis dari konfigurasi database
     */
    public function cctvSnapshot($stationCode)
    {
        CctvStation::ensureTableAndDefaults();
        $code = strtoupper(trim($stationCode));
        $station = CctvStation::where('station_code', $code)->first();

        $targetUrl = null;
        $username = 'live';
        $password = 'Live2025!';

        if ($station) {
            $targetUrl = $station->snapshot_url ?: str_replace('/mjpg/video.cgi', '/jpg/image.cgi', $station->stream_url);
            $username  = $station->username ?: 'live';
            $password  = $station->password ?: 'Live2025!';
        } else {
            $stationsConfig = config('services.cctv.stations', []);
            $targetUrl = $stationsConfig[$code] ?? null;
            if ($targetUrl) {
                $targetUrl = str_replace('/mjpg/video.cgi', '/jpg/image.cgi', $targetUrl);
                $username = config('services.cctv.username', 'live');
                $password = config('services.cctv.password', 'Live2025!');
            }
        }

        if (!$targetUrl) {
            return response()->json(['error' => 'Station not found'], 404);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($imageData)) {
            return response($imageData, 200, [
                'Content-Type'  => 'image/jpeg',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma'        => 'no-cache',
                'Expires'       => '0',
            ]);
        }

        return response()->json([
            'error'   => 'Failed to capture snapshot from CCTV camera',
            'status'  => $httpCode,
            'station' => $code,
        ], 502);
    }

    /**
     * GET /api/facilities/cctv/{stationCode}/stream
     * Proxy video MJPEG stream dari kamera Axis CCTV dengan database configuration
     */
    public function cctvStream($stationCode)
    {
        CctvStation::ensureTableAndDefaults();
        $code = strtoupper(trim($stationCode));
        $station = CctvStation::where('station_code', $code)->first();

        $targetUrl = null;
        $username = 'live';
        $password = 'Live2025!';

        if ($station) {
            $targetUrl = $station->stream_url;
            $username  = $station->username ?: 'live';
            $password  = $station->password ?: 'Live2025!';
        } else {
            $stationsConfig = config('services.cctv.stations', []);
            $targetUrl = $stationsConfig[$code] ?? null;
            if ($targetUrl) {
                $username = config('services.cctv.username', 'live');
                $password = config('services.cctv.password', 'Live2025!');
            }
        }

        if (!$targetUrl) {
            return response()->json(['error' => 'Station not found'], 404);
        }

        return new StreamedResponse(function () use ($targetUrl, $username, $password) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $targetUrl);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_BUFFERSIZE, 64000);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
                if (connection_aborted()) {
                    return 0;
                }
                echo $data;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                return strlen($data);
            });

            curl_exec($ch);
            curl_close($ch);
        }, 200, [
            'Content-Type'  => 'multipart/x-mixed-replace; boundary=myboundary',
            'Cache-Control' => 'no-cache, private, no-store, must-revalidate',
            'Connection'    => 'close',
            'Pragma'        => 'no-cache',
        ]);
    }
}

