<?php

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\AoiController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\GeosocialController;
use App\Http\Controllers\Api\CctvController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']); 
    Route::post('/login', [AuthController::class, 'login']); // Endpoint #1
});

Route::get('/projects/{id}/download', [AnalysisController::class, 'download']);

Route::get('/geosocial/layers', [GeosocialController::class, 'index']);
Route::post('/geosocial/layers/upload', [GeosocialController::class, 'uploadLayer']);
Route::put('/geosocial/layers/{id}', [GeosocialController::class, 'update']);
Route::patch('/geosocial/layers/{id}/toggle', [GeosocialController::class, 'toggleActive']);
Route::delete('/geosocial/layers/{id}', [GeosocialController::class, 'destroy']);
Route::post('/geosocial/layers/{id}/style', [GeosocialController::class, 'updateStyle']);
Route::get('/geosocial/layers/points/{pointKey}', [GeosocialController::class, 'getPointGeoJson']);
Route::post('/geosocial/layers/sync', [GeosocialController::class, 'storeFromPipeline']);

// Admin Management: User Management
Route::prefix('admin/users')->group(function () {
    Route::get('/', [AdminUserController::class, 'index']);
    Route::post('/', [AdminUserController::class, 'store']);
    Route::put('/{id}', [AdminUserController::class, 'update']);
    Route::patch('/{id}/role', [AdminUserController::class, 'toggleRole']);
    Route::delete('/{id}', [AdminUserController::class, 'destroy']);
});

// Admin Management: CCTV Management
Route::prefix('admin/cctv')->group(function () {
    Route::get('/', [CctvController::class, 'index']);
    Route::post('/', [CctvController::class, 'store']);
    Route::put('/{id}', [CctvController::class, 'update']);
    Route::patch('/{id}/toggle', [CctvController::class, 'toggleActive']);
    Route::delete('/{id}', [CctvController::class, 'destroy']);
});

// Admin Management: Facilities Management
Route::prefix('admin/facilities')->group(function () {
    Route::get('/', [FacilityController::class, 'all']);
    Route::post('/', [FacilityController::class, 'store']);
    Route::put('/{id}', [FacilityController::class, 'update']);
    Route::delete('/{id}', [FacilityController::class, 'destroy']);
});

// Facilities & CCTV Public Endpoints
Route::prefix('facilities')->group(function () {
    Route::get('/all', [FacilityController::class, 'all']);
    Route::get('/hospitals', [FacilityController::class, 'hospitals']);
    Route::get('/water-stations', [FacilityController::class, 'waterStations']);
    Route::get('/cctv/{stationCode}/stream', [CctvController::class, 'stream']);
    Route::get('/cctv/{stationCode}/snapshot', [CctvController::class, 'snapshot']);
});

Route::middleware('auth:sanctum')->group(function () {
    
    // Auth (Logout & Cek User)
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']); // Endpoint #2
    });

    Route::prefix('aois')->group(function () {
        Route::get('/user', [AoiController::class, 'indexByUser']); //dropdown index yang diambil dari pengguna
        Route::post('/', [AoiController::class, 'store']); // Endpoint #8
        Route::get('/{id}', [AoiController::class, 'show']);
    });

    // Projects & Analysis Endpoints
    Route::prefix('projects')->group(function () {
        // CRUD Project
        Route::get('/', [ProjectController::class, 'index']);       // Endpoint #3
        Route::post('/', [ProjectController::class, 'store']);      // Endpoint #4
        Route::get('/{id}', [ProjectController::class, 'show']);    // Endpoint #5
        Route::put('/{id}', [ProjectController::class, 'update']);  // Endpoint #6
        Route::delete('/{id}', [ProjectController::class, 'destroy']); // Endpoint #7
        
        Route::get('/{id}/aois', [AoiController::class, 'indexByProject']); // Untuk Dropdown AOI React
        // Analysis & Download
        Route::post('/{id}/analyze', [AnalysisController::class, 'analyze']);   // Endpoint #9
        Route::post('/{id}/save-analysis', [AnalysisController::class, 'saveAnalysis']); // Simpan hasil analisis ke proyek
        Route::get('/{id}/result', [AnalysisController::class, 'result']);      // Endpoint #10
        // Route::get('/{id}/download', [AnalysisController::class, 'download']);  // Endpoint #11
    });

    // AstraGIS User Layers & Layer Groups Endpoints
    Route::get('/user/layers', [AnalysisController::class, 'userLayers']);
    Route::delete('/user/layers/{id}', [AnalysisController::class, 'deleteUserLayer']);
    Route::get('/user/layer-groups', [AnalysisController::class, 'userLayerGroups']);
    Route::post('/user/layer-groups', [AnalysisController::class, 'createUserLayerGroup']);
    Route::put('/user/layer-groups/{id}', [AnalysisController::class, 'updateUserLayerGroup']);
    Route::delete('/user/layer-groups/{id}', [AnalysisController::class, 'deleteUserLayerGroup']);
});


Route::get('/test_flusk', [AnalysisController::class, 'test_flusk']);