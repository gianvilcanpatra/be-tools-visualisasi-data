<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Datasource;
use App\Models\Visualization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Crypt;

class ApiVisualizationController extends Controller
{
    public function convertSql(Request $request)
    {
        try {
            $validated = $request->validate([
                'sql' => 'required|string',
                'id_datasource' => 'required|integer|exists:datasources,id_datasource',
            ]);

            $dbConfig = $this->getConnectionDetails($validated['id_datasource']);
            $connectionName = "dynamic_convert_{$validated['id_datasource']}";
            config(["database.connections.{$connectionName}" => $dbConfig]);
            $connection = DB::connection($connectionName);

            $data = $connection->select($validated['sql']);

            if (empty($data)) {
                return response()->json(['success' => false, 'message' => 'Tidak ada data ditemukan.'], 404);
            }

            $categories = [];
            $seriesData = [];

            foreach ($data as $row) {
                $row = (array) $row;
                $keys = array_keys($row);

                $category = $row[$keys[0]] ?? 'Tanpa Keterangan';
                if (is_null($category) || $category === '') {
                    $category = 'Tanpa Keterangan';
                }
                if (!in_array($category, $categories)) {
                    $categories[] = $category;
                }

                $value = $row[$keys[1]] ?? 0;
                if (is_null($value) || $value === '') {
                    $value = 0;
                }
                $seriesData[] = $value;
            }

            return response()->json([
                'success' => true,
                'categories' => $categories,
                'series' => [[
                    'name' => 'Total',
                    'data' => $seriesData
                ]]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Query tidak valid: ' . $e->getMessage()], 400);
        }
    }

    private function getConnectionDetails($idDatasource)
    {
        $datasource = Datasource::findOrFail($idDatasource);

        return [
            'driver'    => $datasource->type,
            'host'      => $datasource->host,
            'port'      => $datasource->port,
            'database'  => $datasource->database_name,
            'username'  => $datasource->username,
            // 'password'  => Crypt::decrypt($datasource->password),
            'password' => $datasource->password,
            'charset'   => 'utf8',
            'prefix'    => '',
            'schema'    => 'public',
        ];
    }

    public function saveVisualization(Request $request)
    {
        try {
            $validated = $request->validate([
                'id_canvas' => 'nullable|integer',
                'id_datasource' => 'required|integer|exists:datasources,id_datasource',
                'name' => 'required|string',
                'visualization_type' => 'required|string',
                'query' => 'required|string',
                'config' => 'nullable|array',
                'width' => 'nullable',
                'height' => 'nullable',
                'position_x' => 'nullable',
                'position_y' => 'nullable',
                'builder_payload' => 'nullable|array',
                'created_by' => 'nullable',
                'modified_by' => 'nullable',
            ]);

            $configInput = $request->input('config', []);
            $visualizationConfig = $this->prepareConfig($configInput, $validated['name']);

            $visualization = null;
            if ($request->has('id_canvas') && $validated['id_canvas'] !== null) {
                $visualization = Visualization::where('id_canvas', $validated['id_canvas'])
                    ->where('query', $validated['query'])
                    ->first();
            }

            $isPositionUpdate = $request->has('position_x') || $request->has('position_y') || $request->has('width') || $request->has('height');
            $userId = 1;

            if ($visualization) {
                $updateData = $validated;
                $updateData['modified_by'] = $userId;
                $updateData['modified_time'] = now();

                if (!$isPositionUpdate) {
                    $updateData['config'] = $visualizationConfig;
                } else {
                    unset($updateData['config']);
                    unset($updateData['query']);
                    unset($updateData['name']);
                    unset($updateData['visualization_type']);
                    unset($updateData['id_datasource']);
                }

                $visualization->update($updateData);
            } else {
                if (!$request->has('id_canvas') || $validated['id_canvas'] === null) {
                    return response()->json(['success' => false, 'message' => 'id_canvas is required for creating new visualization'], 422);
                }

                $createData = $validated;
                $createData['config'] = $visualizationConfig;
                $createData['width'] = $validated['width'] ?? 800;
                $createData['height'] = $validated['height'] ?? 350;
                $createData['position_x'] = $validated['position_x'] ?? 0;
                $createData['position_y'] = $validated['position_y'] ?? 0;
                $createData['created_time'] = now();
                $createData['modified_time'] = now();
                $createData['created_by'] = $userId;
                $createData['modified_by'] = $userId;
                $visualization = Visualization::create($createData);
            }

            return response()->json(['success' => true, 'message' => 'Visualisasi berhasil disimpan', 'data' => $visualization], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error saving visualization: ' . $e->getMessage(), ['trace' => $e->getTraceAsString(), 'request' => $request->all()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat menyimpan visualization', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateVisualization(Request $request, $id)
    {
        try {
            $visualization = Visualization::findOrFail($id);
            $userId = 1;

            $validated = $request->validate([
                'id_datasource' => 'sometimes|integer|exists:datasources,id_datasource',
                'name' => 'sometimes|string',
                'visualization_type' => 'sometimes|string',
                'query' => 'sometimes|string',
                'config' => 'sometimes|array',
                'width' => 'sometimes|nullable',
                'height' => 'sometimes|nullable',
                'position_x' => 'sometimes|nullable',
                'position_y' => 'sometimes|nullable',
                'builder_payload' => 'sometimes|nullable|array',
            ]);

            if ($request->has('config')) {
                $validated['config'] = $this->prepareConfig($validated['config'], $validated['name'] ?? $visualization->name);
            }
            
            $validated['modified_by'] = $userId;
            $validated['modified_time'] = now();
            
            $visualization->update($validated);

            return response()->json(['success' => true, 'message' => 'visualization updated successfully', 'data' => $visualization]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to update visualization: ' . $e->getMessage()], 500);
        }
    }

    public function deleteVisualization($id)
    {
        try {
            $visualization = Visualization::findOrFail($id);
            $visualization->delete();
            return response()->json(['success' => true, 'message' => 'visualization deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete visualization: ' . $e->getMessage()], 500);
        }
    }

    public function getVisualizationById($id)
    {
        try {
            $visualization = Visualization::findOrFail($id);
            return response()->json(['success' => true, 'message' => 'visualization retrieved successfully', 'data' => $visualization]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to retrieve visualization: ' . $e->getMessage()], 500);
        }
    }

    private function prepareConfig(array $configInput, string $defaultName): array
    {
        $config = [
            'colors' => $configInput['colors'] ?? ['#4CAF50', '#FF9800', '#2196F3'],
            'backgroundColor' => $configInput['backgroundColor'] ?? '#ffffff',
            'pattern' => $configInput['pattern'] ?? 'solid',
            'title' => $configInput['title'] ?? $defaultName,
            'titleFontSize' => $configInput['titleFontSize'] ?? 18,
            'titleFontColor' => $configInput['titleFontColor'] ?? '#333333',
            'titleFontFamily' => $configInput['titleFontFamily'] ?? 'Arial',
            'titlePosition' => $configInput['titlePosition'] ?? 'center',
            'titleBackgroundColor' => $configInput['titleBackgroundColor'] ?? '#ffffff',
            'titleFontStyle' => $configInput['titleFontStyle'] ?? 'normal',
            'subtitle' => $configInput['subtitle'] ?? '',
            'subtitleFontSize' => $configInput['subtitleFontSize'] ?? 14,
            'subtitleFontFamily' => $configInput['subtitleFontFamily'] ?? 'Arial',
            'subtitleFontColor' => $configInput['subtitleFontColor'] ?? '#333333',
            'subtitlePosition' => $configInput['subtitlePosition'] ?? 'center',
            'subtitleBackgroundColor' => $configInput['subtitleBackgroundColor'] ?? '#ffffff',
            'subtitleTextStyle' => $configInput['subtitleTextStyle'] ?? 'normal',
            'fontSize' => $configInput['fontSize'] ?? 14,
            'fontFamily' => $configInput['fontFamily'] ?? 'Arial',
            'fontColor' => $configInput['fontColor'] ?? '#000000',
            'gridColor' => $configInput['gridColor'] ?? '#E0E0E0',
            'gridType' => $configInput['gridType'] ?? 'solid',
            'xAxisFontSize' => $configInput['xAxisFontSize'] ?? 12,
            'xAxisFontFamily' => $configInput['xAxisFontFamily'] ?? 'Arial',
            'xAxisFontColor' => $configInput['xAxisFontColor'] ?? '#000000',
            'yAxisFontSize' => $configInput['yAxisFontSize'] ?? 12,
            'yAxisFontFamily' => $configInput['yAxisFontFamily'] ?? 'Arial',
            'yAxisFontColor' => $configInput['yAxisFontColor'] ?? '#000000',
            'categoryTitle' => $configInput['categoryTitle'] ?? 'Kategori',
            'categoryTitleFontSize' => $configInput['categoryTitleFontSize'] ?? 14,
            'categoryTitleFontFamily' => $configInput['categoryTitleFontFamily'] ?? 'Arial',
            'categoryTitleFontColor' => $configInput['categoryTitleFontColor'] ?? '#000000',
            'categoryTitlePosition' => $configInput['categoryTitlePosition'] ?? 'center',
            'categoryTitleBackgroundColor' => $configInput['categoryTitleBackgroundColor'] ?? '#ffffff',
            'categoryTitleTextStyle' => $configInput['categoryTitleTextStyle'] ?? 'normal',
            'showValue' => $configInput['showValue'] ?? true,
            'valuePosition' => $configInput['valuePosition'] ?? 'top',
            'valueFontColor' => $configInput['valueFontColor'] ?? '#000000',
            'valueOrientation' => $configInput['valueOrientation'] ?? 'horizontal',
            'borderColor' => $configInput['borderColor'] ?? '#000000',
            'borderWidth' => $configInput['borderWidth'] ?? 1,
            'borderType' => $configInput['borderType'] ?? 'solid',
        ];

        if (isset($configInput['visualizationOptions']) && is_array($configInput['visualizationOptions'])) {
            $config['visualizationOptions'] = $configInput['visualizationOptions'];
        }

        return $config;
    }
}