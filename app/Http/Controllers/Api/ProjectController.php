<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    /**
     * [3] GET /api/projects menampilkan daftar proyek milik user yang login
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json([
            'data' => Project::with('user')
                ->where('user_id', $userId)
                ->latest()
                ->get()
        ]);
    }

    /**
     * [4] POST /api/projects membuat proyek baru
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // 'user_id'     => 'required|exists:users,id',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|in:draft,active,archived',
        ]);
        $validated['user_id'] = $request->user()->id;

        $project = Project::create($validated);

        return response()->json([
            'message' => 'The project was successfully created.',
            'data'    => $project
        ], 201);
    }

    /**
     * [5] GET /api/projects/{id} menampilkan detail proyek beserta AOI & Analisis
     */
    public function show(Request $request, $id)
    {
        $userId = $request->user()->id;
        $project = Project::with(['user', 'analyses'])
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }

        // Format geometri PostGIS untuk AOI ke GeoJSON
        $aois = DB::table('aois')
            ->select([
                'id',
                'project_id',
                'name',
                'source_type',
                'area_ha',
                'metadata',
                'created_at',
                'updated_at',
                DB::raw("ST_AsGeoJSON(geometry)::json AS geometry")
            ])
            ->where('project_id', $id)
            ->get()
            ->map(function ($aoi) {
                $aoi->geometry = json_decode($aoi->geometry);
                $aoi->metadata = json_decode($aoi->metadata);
                return $aoi;
            });

        $data = $project->toArray();
        $data['aois'] = $aois;

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * [6] PUT /api/projects/{id} perbarui informasi proyek
     */
    public function update(Request $request, $id)
    {
        $userId = $request->user()->id;
        $project = Project::where('id', $id)->where('user_id', $userId)->first();

        if (!$project) {
            return response()->json(['message' => 'Proyek tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'sometimes|required|in:draft,active,archived',
        ]);

        $project->update($validated);

        return response()->json([
            'message' => 'The project was successfully updated.',
            'data'    => $project
        ]);
    }

    /**
     * [7] DELETE /api/projects/{id} hapus proyek
     */
    public function destroy(Request $request, $id)
    {
        $userId = $request->user()->id;
        $project = Project::where('id', $id)->where('user_id', $userId)->first();

        if (!$project) {
            return response()->json(['message' => 'No project found.'], 404);
        }

        $project->delete();

        return response()->json([
            'message' => 'The project was successfully deleted.'
        ]);
    }
}