<?php

use App\Http\Controllers\Api\ApiCanvasController;
use App\Http\Controllers\Api\ApiConnectDatabaseController;
use App\Http\Controllers\Api\ApiETLController;
use App\Http\Controllers\Api\ApiGetDataController;
use App\Http\Controllers\Api\ApiVisualizationController;
use App\Http\Controllers\Api\ApiOtentikasiController;
use App\Http\Controllers\Api\ETLController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Route::middleware('auth:sanctum')->group(function () {
//     Route::post('/logout', [ApiOtentikasiController::class, 'logoutUser']);
// });
// Route::middleware('auth:sanctum')->post('/logout', [ApiOtentikasiController::class, 'logoutUser']);


Route::prefix('otentikasi')->group(function () {
    Route::post('/register', [ApiOtentikasiController::class, 'registerUser']);
    Route::post('/login', [ApiOtentikasiController::class, 'loginUser']);
    Route::get('/logout', [ApiOtentikasiController::class, 'logoutUser'])->middleware('auth:sanctum');
    Route::get('/get-user', [ApiOtentikasiController::class, 'getUser'])->middleware('auth:sanctum');
    Route::post('/update-access', [ApiOtentikasiController::class, 'updateAccess'])->middleware('auth:sanctum');
});

Route::prefix('kelola-dashboard')->group(function () {
    // Route::post('/convert-sql', [ApiKelolaDashboardController::class, 'convertSql']);
    Route::get('/tables', [ApiGetDataController::class, 'getAllTables']);
    // Route::post('/tables', [ApiGetDataController::class, 'getAllTables']);
    Route::get('/columns/{table}', [ApiGetDataController::class, 'getTableColumns']);
    Route::post('/execute-query', [ApiGetDataController::class, 'executeQuery']);

    Route::post('/fetch-database', [ApiConnectDatabaseController::class, 'connectDatasource']);
    Route::get('/fetch-table/{id}', [ApiConnectDatabaseController::class, 'fetchTables']);
    Route::get('/fetch-column/{table}', [ApiConnectDatabaseController::class, 'fetchTableColumns']);
    // Route::post('/fetch-data/{table}', [ApiConnectDatabaseController::class, 'getTableDataByColumns']);

    Route::post('/fetch-data', [ApiGetDataController::class, 'getTableDataByColumns']);
    Route::post('/check-date', [ApiGetDataController::class, 'checkDateColumn']);

    Route::post('/check-foreign-key', [ApiConnectDatabaseController::class, 'checkIfForeignKey']);
    // Route::post('/table-data', [ApiGetDataController::class, 'getTableDataByColumns']);
    Route::post('/visualisasi-data', [ApiGetDataController::class, 'getVisualisasiData']);
    Route::post('/convert-sql', [ApiVisualizationController::class, 'convertSql']);
    Route::post('/get-joinable-tables', [ApiGetDataController::class, 'getJoinableTables']);

    // Route::post('/save-chart', [ApiGetDataController::class, 'saveChart']);
    Route::get('/latest', [ApiCanvasController::class, 'getLatestVisualization']);

    // Route::post('/visualisasi-data', [VisualisasiController::class, 'getData']);

    // Route::get('/visualizations', [ApiVisualizationController::class, 'getAllVisualizations']);
    Route::get('/visualizations/{id}', [ApiVisualizationController::class, 'getVisualizationById']);
    Route::post('/save-visualization', [ApiVisualizationController::class, 'saveVisualization']);
    Route::put('/visualizations/{id}', [ApiVisualizationController::class, 'updateVisualization']);
    Route::delete('/delete-visualization/{id}', [ApiVisualizationController::class, 'deleteVisualization']);

    // Get Visualization
    // Route::get('/get-visualizations', [ApiCanvasController::class, 'getAllVisualizations']);
    Route::get('/canvas/{id_canvas}/visualizations', [ApiCanvasController::class, 'getAllVisualizations']);
    Route::post('/canvas', [ApiCanvasController::class, 'createCanvas']);
    Route::put('/canvas/update/{id_canvas}', [ApiCanvasController::class, 'updateCanvas']);
    Route::put('/canvas/delete/{id_canvas}', [ApiCanvasController::class, 'deleteCanvas']);
    Route::get('/canvas/{id_canvas}', [ApiCanvasController::class, 'getCanvas']);
    Route::get('/project/{id_project}/canvases', [ApiCanvasController::class, 'getCanvasByProject']);
    Route::get('/first-canvas', [ApiCanvasController::class, 'getFirstCanvas']);

    Route::post('/etl/run', [ApiETLController::class, 'run']);
    Route::post('/etl/refresh', [ApiETLController::class, 'refresh']);
    Route::post('/etl/full-refresh', [ApiETLController::class, 'fullRefresh']);
});