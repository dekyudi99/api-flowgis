<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CctvStation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CctvController extends Controller
{
    /**
     * [GET] /api/admin/cctv
     * Daftar seluruh CCTV di database
     */
    public function index(Request $request)
    {
        try {
            CctvStation::ensureTableAndDefaults();

            $query = CctvStation::query();

            if (!$request->boolean('all', false) && !$request->has('all')) {
                $query->where('is_active', true);
            }

            $stations = $query->orderBy('id', 'asc')->get();

            return response()->json([
                'status' => 'success',
                'data'   => $stations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load CCTV stations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [POST] /api/admin/cctv
     * Tambah kamera CCTV baru ke database
     */
    public function store(Request $request)
    {
        try {
            CctvStation::ensureTableAndDefaults();

            $validated = $request->validate([
                'station_code' => 'required|string|max:50|unique:cctv_stations,station_code',
                'name'         => 'required|string|max:255',
                'location'     => 'nullable|string',
                'stream_url'   => 'required|url',
                'snapshot_url' => 'nullable|url',
                'username'     => 'nullable|string',
                'password'     => 'nullable|string',
                'lat'          => 'required|numeric',
                'lng'          => 'required|numeric',
                'is_active'    => 'nullable|boolean',
            ]);

            $station = CctvStation::create([
                'station_code' => strtoupper(trim($validated['station_code'])),
                'name'         => $validated['name'],
                'location'     => $validated['location'] ?? null,
                'stream_url'   => $validated['stream_url'],
                'snapshot_url' => $validated['snapshot_url'] ?? null,
                'username'     => $validated['username'] ?? 'live',
                'password'     => $validated['password'] ?? 'Live2025!',
                'lat'          => $validated['lat'],
                'lng'          => $validated['lng'],
                'is_active'    => $validated['is_active'] ?? true,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'CCTV camera added to database successfully!',
                'data'    => $station
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation error',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to save CCTV camera: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [PUT] /api/admin/cctv/{id}
     * Edit data kamera CCTV di database
     */
    public function update(Request $request, $id)
    {
        try {
            $station = CctvStation::findOrFail($id);

            $validated = $request->validate([
                'station_code' => 'nullable|string|max:50|unique:cctv_stations,station_code,' . $id,
                'name'         => 'required|string|max:255',
                'location'     => 'nullable|string',
                'stream_url'   => 'required|url',
                'snapshot_url' => 'nullable|url',
                'username'     => 'nullable|string',
                'password'     => 'nullable|string',
                'lat'          => 'required|numeric',
                'lng'          => 'required|numeric',
                'is_active'    => 'nullable|boolean',
            ]);

            if (!empty($validated['station_code'])) {
                $station->station_code = strtoupper(trim($validated['station_code']));
            }
            $station->name         = $validated['name'];
            $station->location     = $validated['location'] ?? $station->location;
            $station->stream_url   = $validated['stream_url'];
            $station->snapshot_url = $validated['snapshot_url'] ?? $station->snapshot_url;
            if (isset($validated['username'])) $station->username = $validated['username'];
            if (isset($validated['password'])) $station->password = $validated['password'];
            $station->lat          = $validated['lat'];
            $station->lng          = $validated['lng'];
            if (isset($validated['is_active'])) $station->is_active = $validated['is_active'];

            $station->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'CCTV camera updated successfully!',
                'data'    => $station
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update CCTV camera: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [PATCH] /api/admin/cctv/{id}/toggle
     * Toggle status aktif/nonaktif CCTV
     */
    public function toggleActive($id)
    {
        try {
            $station = CctvStation::findOrFail($id);
            $station->is_active = !$station->is_active;
            $station->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'CCTV status updated successfully.',
                'data'    => $station
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to toggle CCTV status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [DELETE] /api/admin/cctv/{id}
     * Hapus kamera CCTV dari database
     */
    public function destroy($id)
    {
        try {
            $station = CctvStation::findOrFail($id);
            $station->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'CCTV camera removed successfully.',
                'deleted_id' => $id
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to delete CCTV camera: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [GET] /api/facilities/cctv/{stationCode}/snapshot
     * Snapshot gambar dari kamera CCTV
     */
    public function snapshot($stationCode)
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
     * [GET] /api/facilities/cctv/{stationCode}/stream
     * Proxy stream MJPEG dari kamera CCTV
     */
    public function stream($stationCode)
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
