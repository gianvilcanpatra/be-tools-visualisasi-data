<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visualization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ApiVisualizationController extends Controller
{
    public function convertSql(Request $request)
    {
        $sql = $request->input('sql');

        try {
            // Ambil koneksi database dari datasources
            $idDatasource = 1; // Ganti dengan input dari user jika mau dinamis
            $dbConfig = $this->getConnectionDetails($idDatasource);

            // Buat koneksi on-the-fly
            config(["database.connections.dynamic" => $dbConfig]);

            // Gunakan koneksi dynamic
            $connection = DB::connection('dynamic');

            // Jalankan query dari input user
            $data = $connection->select($sql);

            if (empty($data)) {
                return response()->json(['error' => 'Tidak ada data ditemukan.'], 400);
            }

            // Konversi data ke array grafik (kategori dan data)
            $categories = [];
            $seriesData = [];

            foreach ($data as $row) {
                $row = (array) $row;
                $keys = array_keys($row);

                // Kolom pertama sebagai kategori
                $category = $row[$keys[0]] ?? 'Tanpa Keterangan';
                if (is_null($category) || $category === '') {
                    $category = 'Tanpa Keterangan';
                }
                if (!in_array($category, $categories)) {
                    $categories[] = $category;
                }

                // Kolom kedua sebagai nilai
                $value = $row[$keys[1]] ?? 0;
                if (is_null($value) || $value === '') {
                    $value = 0;
                }

                $seriesData[] = $value;
            }

            return response()->json([
                'categories' => $categories,
                'series' => [[
                    'name' => 'Total',
                    'data' => $seriesData
                ]]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Query tidak valid: ' . $e->getMessage()], 400);
        }
    }

    private function getConnectionDetails($idDatasource)
    {
        $datasource = DB::table('datasources')->where('id_datasource', $idDatasource)->first();

        if (!$datasource) {
            throw new \Exception("Datasource dengan ID {$idDatasource} tidak ditemukan.");
        }

        return [
            'driver'    => $datasource->type,
            'host'      => $datasource->host,
            'port'      => $datasource->port,
            'database'  => $datasource->database_name,
            'username'  => $datasource->username,
            'password'  => $datasource->password,
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'schema'    => 'public',
        ];
    }

    /**
     * Save a new visualization
     */
    public function saveVisualization(Request $request)
        {
            try {
                // Validate basic fields
                $validated = $request->validate([
                    'id_canvas' => 'nullable|integer',
                    'id_datasource' => 'integer',
                    'name' => 'string',
                    'visualization_type' => 'required|string',
                    'query' => 'string',
                    'config' => 'nullable|array',
                    'width' => 'nullable',
                    'height' => 'nullable',
                    'position_x' => 'nullable',
                    'position_y' => 'nullable',
                    'builder_payload' => 'nullable|array',
            ]);

            // Extract and prepare config data
            $configInput = $request->input('config', []);
            // Extract and prepare config data
            $configInput = $request->input('config', []);

            // Ensure all config values are properly captured
            $visualizationConfig = [
                // General Chart Aesthetics
                'colors' => $configInput['colors'] ?? ['#4CAF50', '#FF9800', '#2196F3'],
                'backgroundColor' => $configInput['backgroundColor'] ?? '#ffffff',
                'pattern' => $configInput['pattern'] ?? 'solid', // Chart pattern (e.g., for bar fills)
            // Ensure all config values are properly captured
            $visualizationConfig = [
                // General Chart Aesthetics
                'colors' => $configInput['colors'] ?? ['#4CAF50', '#FF9800', '#2196F3'],
                'backgroundColor' => $configInput['backgroundColor'] ?? '#ffffff',
                'pattern' => $configInput['pattern'] ?? 'solid', // Chart pattern (e.g., for bar fills)

                // Main Title
                'title' => $configInput['title'] ?? $validated['name'], // Default to visualization name if not provided
                'titleFontSize' => $configInput['titleFontSize'] ?? 18,
                'titleFontColor' => $configInput['titleFontColor'] ?? '#333333',
                'titleFontFamily' => $configInput['titleFontFamily'] ?? 'Arial',
                'titlePosition' => $configInput['titlePosition'] ?? 'center', // e.g., 'left', 'center', 'right'
                'titleBackgroundColor' => $configInput['titleBackgroundColor'] ?? '#ffffff',
                'titleFontStyle' => $configInput['titleFontStyle'] ?? 'normal', // e.g., 'normal', 'italic', 'bold'
                // Main Title
                'title' => $configInput['title'] ?? $validated['name'], // Default to visualization name if not provided
                'titleFontSize' => $configInput['titleFontSize'] ?? 18,
                'titleFontColor' => $configInput['titleFontColor'] ?? '#333333',
                'titleFontFamily' => $configInput['titleFontFamily'] ?? 'Arial',
                'titlePosition' => $configInput['titlePosition'] ?? 'center', // e.g., 'left', 'center', 'right'
                'titleBackgroundColor' => $configInput['titleBackgroundColor'] ?? '#ffffff',
                'titleFontStyle' => $configInput['titleFontStyle'] ?? 'normal', // e.g., 'normal', 'italic', 'bold'

                // Subtitle
                'subtitle' => $configInput['subtitle'] ?? '', // Default to empty if not provided
                'subtitleFontSize' => $configInput['subtitleFontSize'] ?? 14,
                'subtitleFontFamily' => $configInput['subtitleFontFamily'] ?? 'Arial',
                'subtitleFontColor' => $configInput['subtitleFontColor'] ?? '#333333',
                'subtitlePosition' => $configInput['subtitlePosition'] ?? 'center',
                'subtitleBackgroundColor' => $configInput['subtitleBackgroundColor'] ?? '#ffffff',
                'subtitleTextStyle' => $configInput['subtitleTextStyle'] ?? 'normal',
                // Subtitle
                'subtitle' => $configInput['subtitle'] ?? '', // Default to empty if not provided
                'subtitleFontSize' => $configInput['subtitleFontSize'] ?? 14,
                'subtitleFontFamily' => $configInput['subtitleFontFamily'] ?? 'Arial',
                'subtitleFontColor' => $configInput['subtitleFontColor'] ?? '#333333',
                'subtitlePosition' => $configInput['subtitlePosition'] ?? 'center',
                'subtitleBackgroundColor' => $configInput['subtitleBackgroundColor'] ?? '#ffffff',
                'subtitleTextStyle' => $configInput['subtitleTextStyle'] ?? 'normal',

                // Legend (using 'fontSize', 'fontFamily', 'fontColor' as per original naming for these specific legend properties)
                'fontSize' => $configInput['fontSize'] ?? 14,       // Legend Font Size
                'fontFamily' => $configInput['fontFamily'] ?? 'Arial',   // Legend Font Family
                'fontColor' => $configInput['fontColor'] ?? '#000000',    // Legend Font Color
                // Legend (using 'fontSize', 'fontFamily', 'fontColor' as per original naming for these specific legend properties)
                'fontSize' => $configInput['fontSize'] ?? 14,       // Legend Font Size
                'fontFamily' => $configInput['fontFamily'] ?? 'Arial',   // Legend Font Family
                'fontColor' => $configInput['fontColor'] ?? '#000000',    // Legend Font Color

                // Grid
                'gridColor' => $configInput['gridColor'] ?? '#E0E0E0',
                'gridType' => $configInput['gridType'] ?? 'solid', // e.g., 'solid', 'dashed', 'dotted'
                // Grid
                'gridColor' => $configInput['gridColor'] ?? '#E0E0E0',
                'gridType' => $configInput['gridType'] ?? 'solid', // e.g., 'solid', 'dashed', 'dotted'

                // X-Axis Label/Text
                'xAxisFontSize' => $configInput['xAxisFontSize'] ?? 12,
                'xAxisFontFamily' => $configInput['xAxisFontFamily'] ?? 'Arial',
                'xAxisFontColor' => $configInput['xAxisFontColor'] ?? '#000000',
                // X-Axis Label/Text
                'xAxisFontSize' => $configInput['xAxisFontSize'] ?? 12,
                'xAxisFontFamily' => $configInput['xAxisFontFamily'] ?? 'Arial',
                'xAxisFontColor' => $configInput['xAxisFontColor'] ?? '#000000',

                // Y-Axis Label/Text
                'yAxisFontSize' => $configInput['yAxisFontSize'] ?? 12,
                'yAxisFontFamily' => $configInput['yAxisFontFamily'] ?? 'Arial',
                'yAxisFontColor' => $configInput['yAxisFontColor'] ?? '#000000',
                // Y-Axis Label/Text
                'yAxisFontSize' => $configInput['yAxisFontSize'] ?? 12,
                'yAxisFontFamily' => $configInput['yAxisFontFamily'] ?? 'Arial',
                'yAxisFontColor' => $configInput['yAxisFontColor'] ?? '#000000',

                // X-Axis Title (Category Axis Title)
                'categoryTitle' => $configInput['categoryTitle'] ?? 'Kategori',
                'categoryTitleFontSize' => $configInput['categoryTitleFontSize'] ?? 14,
                'categoryTitleFontFamily' => $configInput['categoryTitleFontFamily'] ?? 'Arial',
                'categoryTitleFontColor' => $configInput['categoryTitleFontColor'] ?? '#000000',
                'categoryTitlePosition' => $configInput['categoryTitlePosition'] ?? 'center',
                'categoryTitleBackgroundColor' => $configInput['categoryTitleBackgroundColor'] ?? '#ffffff',
                'categoryTitleTextStyle' => $configInput['categoryTitleTextStyle'] ?? 'normal',
                // X-Axis Title (Category Axis Title)
                'categoryTitle' => $configInput['categoryTitle'] ?? 'Kategori',
                'categoryTitleFontSize' => $configInput['categoryTitleFontSize'] ?? 14,
                'categoryTitleFontFamily' => $configInput['categoryTitleFontFamily'] ?? 'Arial',
                'categoryTitleFontColor' => $configInput['categoryTitleFontColor'] ?? '#000000',
                'categoryTitlePosition' => $configInput['categoryTitlePosition'] ?? 'center',
                'categoryTitleBackgroundColor' => $configInput['categoryTitleBackgroundColor'] ?? '#ffffff',
                'categoryTitleTextStyle' => $configInput['categoryTitleTextStyle'] ?? 'normal',

                // Value Labels (on data points)
                'showValue' => $configInput['showValue'] ?? true,
                'valuePosition' => $configInput['valuePosition'] ?? 'top', // e.g., 'top', 'center', 'bottom', 'inside'
                'valueFontColor' => $configInput['valueFontColor'] ?? '#000000',
                'valueOrientation' => $configInput['valueOrientation'] ?? 'horizontal', // e.g., 'horizontal', 'vertical'
                // Value Labels (on data points)
                'showValue' => $configInput['showValue'] ?? true,
                'valuePosition' => $configInput['valuePosition'] ?? 'top', // e.g., 'top', 'center', 'bottom', 'inside'
                'valueFontColor' => $configInput['valueFontColor'] ?? '#000000',
                'valueOrientation' => $configInput['valueOrientation'] ?? 'horizontal', // e.g., 'horizontal', 'vertical'

                // Chart Border
                'borderColor' => $configInput['borderColor'] ?? '#000000',
                'borderWidth' => $configInput['borderWidth'] ?? 1,
                'borderType' => $configInput['borderType'] ?? 'solid', // e.g., 'solid', 'dashed'
            ];
                // Chart Border
                'borderColor' => $configInput['borderColor'] ?? '#000000',
                'borderWidth' => $configInput['borderWidth'] ?? 1,
                'borderType' => $configInput['borderType'] ?? 'solid', // e.g., 'solid', 'dashed'
            ];

                // If visualizationOptions exists in the input config, merge it.
                // This is for chart-specific options that don't fit the general structure.
                if (isset($configInput['visualizationOptions']) && is_array($configInput['visualizationOptions'])) {
                    $visualizationConfig['visualizationOptions'] = $configInput['visualizationOptions'];
                }

                // Try to find existing visualization first by canvas ID and query
                $visualization = null;
                if ($request->has('id_canvas')) {
                    $visualization = Visualization::where('id_canvas', $validated['id_canvas'])
                        ->where('query', $validated['query'])
                        ->first();
                }

                // Check if this is a position/size update only
                $isPositionUpdate = $request->has('position_x') || $request->has('position_y') ||
                    $request->has('width') || $request->has('height');

                if ($visualization) {
                    $updateData = [
                        'modified_by' => 1, // Replace with auth user ID
                        'modified_time' => now(),
                    ];

                    // Only update id_canvas if explicitly provided
                    if ($request->has('id_canvas')) {
                        $updateData['id_canvas'] = $validated['id_canvas'];
                    }

                    // Only update these fields if explicitly provided
                    if ($request->has('id_datasource')) {
                        $updateData['id_datasource'] = $validated['id_datasource'];
                    }

                    if ($request->has('name')) {
                        $updateData['name'] = $validated['name'];
                    }

                    if ($request->has('visualization_type')) {
                        $updateData['visualization_type'] = $validated['visualization_type'];
                    }

                    // If this is not just a position update, update the config and query
                    if (!$isPositionUpdate) {
                        $updateData['config'] = $visualizationConfig;
                        $updateData['query'] = $validated['query'];
                    }

                    // Always update position and size if provided
                    if ($request->has('width')) {
                        $updateData['width'] = $validated['width'];
                    }

                    if ($request->has('height')) {
                        $updateData['height'] = $validated['height'];
                    }

                    if ($request->has('position_x')) {
                        $updateData['position_x'] = $validated['position_x'];
                    }

                if ($request->has('position_y')) {
                    $updateData['position_y'] = $validated['position_y'];
                }

                if ($request->has('builder_payload')) {
                    $updateData['builder_payload'] = $validated['builder_payload'];
                }

                $visualization->update($updateData);

                $visualization->update($updateData);

                // Log the operation type
                $logMessage = $isPositionUpdate ?
                    'visualization position/size updated' :
                    'visualization fully updated';
                // Log the operation type
                $logMessage = $isPositionUpdate ?
                    'visualization position/size updated' :
                    'visualization fully updated';

                Log::info($logMessage, [
                    'visualization_id' => $visualization->id,
                    'name' => $visualization->name,
                    'position_x' => $visualization->position_x,
                    'position_y' => $visualization->position_y,
                    'width' => $visualization->width,
                    'height' => $visualization->height
                ]);
            } else {
                // For creating new visualization, id_canvas is required
                if (!$request->has('id_canvas')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'id_canvas is required for creating new visualization'
                    ], 422);
                }
                Log::info($logMessage, [
                    'visualization_id' => $visualization->id,
                    'name' => $visualization->name,
                    'position_x' => $visualization->position_x,
                    'position_y' => $visualization->position_y,
                    'width' => $visualization->width,
                    'height' => $visualization->height
                ]);
            } else {
                // For creating new visualization, id_canvas is required
                if (!$request->has('id_canvas')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'id_canvas is required for creating new visualization'
                    ], 422);
                }

                    // Create new visualization with all data
                    $visualization = Visualization::create([
                        'id_canvas' => $validated['id_canvas'],
                        'id_datasource' => $validated['id_datasource'],
                        'name' => $validated['name'],
                        'visualization_type' => $validated['visualization_type'],
                        'query' => $validated['query'],
                        'config' => $visualizationConfig,
                        'width' => $validated['width'] ?? 800,
                        'height' => $validated['height'] ?? 350,
                        'position_x' => $validated['position_x'] ?? 0,
                        'position_y' => $validated['position_y'] ?? 0,
                        'created_time' => now(),
                        'modified_time' => now(),
                        'created_by' => 1, // Replace with auth user ID
                        'modified_by' => 1, // Replace with auth user ID
                    'builder_payload' => $validated['builder_payload'] ?? null,
                    ]);

                Log::info('New visualization created', [
                    'visualization_id' => $visualization->id,
                    'name' => $visualization->name,
                    'visualization_type' => $visualization->visualization_type
                ]);
            }
                Log::info('New visualization created', [
                    'visualization_id' => $visualization->id,
                    'name' => $visualization->name,
                    'visualization_type' => $visualization->visualization_type
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Visualisasi berhasil disimpan',
                'data' => $visualization
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error saving visualization: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Visualisasi berhasil disimpan',
                'data' => $visualization
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error saving visualization: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan visualization',
                'error' => $e->getMessage()
            ], 500);
        }
    }
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan visualization',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    // public function saveVisualization(Request $request)
    // {
    //     try {
    //         $validator = Validator::make($request->all(), [
    //             'id_canvas' => 'required|integer',
    //             'id_datasource' => 'required|integer',
    //             'name' => 'required|string|max:255',
    //             'visualization_type' => 'required|string|max:50',
    //             'query' => 'required|string',
    //             // 'config' => 'required',
    //             'width' => 'nullable',
    //             'height' => 'nullable',
    //             'position_x' => 'nullable',
    //             'position_y' => 'nullable',
    //         ]);

    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => 'Validation failed',
    //                 'errors' => $validator->errors()
    //             ], 422);
    //         }

    //         $userId = 1; // Default to 1 if not authenticated
    //         $now = now();

    //         $visualization = Visualization::create([
    //             'id_canvas' => $request->id_canvas,
    //             'id_datasource' => $request->id_datasource,
    //             'name' => $request->name,
    //             'visualization_type' => $request->visualization_type,
    //             'query' => $request->query,
    //             'config' => $request->config,
    //             'width' => $request->width ?? 800,
    //             'height' => $request->height ?? 400,
    //             'position_x' => $request->position_x ?? 20,
    //             'position_y' => $request->position_y ?? 20,
    //             'created_by' => $userId,
    //             'created_time' => $now,
    //             'modified_by' => $userId,
    //             'modified_time' => $now,
    //             'is_deleted' => 0
    //         ]);

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Visualization saved successfully',
    //             'data' => $visualization
    //         ], 201);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Failed to save visualization: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * Update an existing visualization
     */
    public function updateVisualization(Request $request, $id)
    {
        try {
            $visualization = visualization::findOrFail($id);

            // Validate request data
            $validator = Validator::make($request->all(), [
                'name' => 'string|max:255',
                'visualization_type' => 'string|max:50',
                'query' => 'string',
                'config' => 'array',
                'width' => 'numeric',
                'height' => 'numeric',
                'position_x' => 'numeric',
                'position_y' => 'numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = 1; // Default to 1 if not authenticated

            // Update only fields that are present in the request
            $updateData = array_filter($request->all(), function ($value) {
                return $value !== null;
            });

            $updateData['modified_by'] = $userId;
            $updateData['modified_time'] = now();

            $visualization->update($updateData);

            return response()->json([
                'status' => 'success',
                'message' => 'visualization updated successfully',
                'data' => $visualization
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update visualization: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a visualization (soft delete)
     */
    public function deleteVisualization($id)
    {
        try {
            $visualization = Visualization::findOrFail($id);

            $userId = 1; // Default to 1 if not authenticated

            $visualization->delete();
            // Soft delete
            // $visualization->update([
            //     'is_deleted' => 1,
            //     'modified_by' => $userId,
            //     'modified_time' => now()
            // ]);

            return response()->json([
                'status' => 'success',
                'message' => 'visualization deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete visualization: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific visualization by ID
     */
    public function getVisualizationById($id)
    {
        try {
            $visualization = Visualization::findOrFail($id);

            if ($visualization->is_deleted) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'visualization not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'visualization retrieved successfully',
                'data' => $visualization
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve visualization: ' . $e->getMessage()
            ], 500);
        }
    }
}
