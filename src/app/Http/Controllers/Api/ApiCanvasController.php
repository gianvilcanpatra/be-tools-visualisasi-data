<?php

namespace App\Http\Controllers\Api;

use Exception;
use App\Models\Canvas;
use Illuminate\Http\Request;
use App\Models\Visualization;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class ApiCanvasController extends Controller
{
    // public function getAllVisualizations(Request $request)
    // {
    //     try {
    //         // You may want to filter by canvas ID or user
    //         $visualizations = Visualization::where('is_deleted', 0)
    //             ->orderBy('created_time', 'desc')
    //             ->get();

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Visualizations retrieved successfully',
    //             'data' => $visualizations
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Failed to retrieve visualizations: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }
    // public function getAllVisualizations(Request $request)
    // {
    //     try {
    //         // Get all non-deleted visualizations
    //         $visualizations = Visualization::where('is_deleted', 0)
    //             ->orderBy('created_time', 'desc')
    //             ->get();

    //         // Process each visualization to execute its query and update config
    //         foreach ($visualizations as $visualization) {
    //             // Execute the query
    //             try {
    //                 $queryResults = DB::select($visualization->query);

    //                 // Convert results to appropriate format based on visualization type
    //                 $formattedData = $this->formatDataForVisualization($queryResults, $visualization->visualization_type);

    //                 // Get a copy of the config array
    //                 $config = is_array($visualization->config) ? $visualization->config : [];

    //                 // Update visualization-type specific data in config
    //                 switch ($visualization->visualization_type) {
    //                     case 'pie':
    //                         if (!isset($config['visualizationOptions'])) {
    //                             $config['visualizationOptions'] = [];
    //                         }
    //                         $config['visualizationOptions']['labels'] = array_column($formattedData, 'label');
    //                         $config['visualizationOptions']['series'] = array_column($formattedData, 'value');
    //                         break;

    //                     case 'bar':
    //                     case 'line':
    //                     case 'area':
    //                         if (!isset($config['visualizationOptions'])) {
    //                             $config['visualizationOptions'] = [];
    //                         }
    //                         if (!isset($config['visualizationOptions']['series'])) {
    //                             $config['visualizationOptions']['series'] = [];
    //                         }
    //                         if (!isset($config['visualizationOptions']['xaxis'])) {
    //                             $config['visualizationOptions']['xaxis'] = [];
    //                         }

    //                         // Determine categories and series from formatted data
    //                         $categories = array_column($formattedData, 'x');
    //                         $seriesData = array_column($formattedData, 'y');

    //                         $config['visualizationOptions']['xaxis']['categories'] = $categories;
    //                         $config['visualizationOptions']['series'] = [
    //                             [
    //                                 'name' => $visualization->name,
    //                                 'data' => $seriesData
    //                             ]
    //                         ];
    //                         break;

    //                     default:
    //                         // Generic data update
    //                         if (!isset($config['visualizationOptions'])) {
    //                             $config['visualizationOptions'] = [];
    //                         }
    //                         $config['visualizationOptions']['data'] = $formattedData;
    //                 }

    //                 // Update the latest data timestamp
    //                 $config['lastDataUpdate'] = Carbon::now()->format('Y-m-d H:i:s');

    //                 // Assign the updated config back to the visualization properly
    //                 $visualization->config = $config;
    //             } catch (\Exception $queryException) {
    //                 // Get a copy of the config array
    //                 $config = is_array($visualization->config) ? $visualization->config : [];

    //                 // If query execution fails, add error info to config
    //                 $config['queryError'] = $queryException->getMessage();
    //                 $config['lastQueryAttempt'] = Carbon::now()->format('Y-m-d H:i:s');

    //                 // Assign the updated config back to the visualization
    //                 $visualization->config = $config;
    //             }
    //         }

    //         // Save the updated configurations before returning the data
    //         foreach ($visualizations as $visualization) {
    //             $visualization->save();
    //         }

    //         return response()->json([
    //             'status' => 'success',
    //             'message' => 'Visualizations retrieved successfully with updated data',
    //             'data' => $visualizations
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Failed to retrieve visualizations: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function getAllVisualizations(Request $request, $id_canvas)
    {
        try {
            // Get all non-deleted visualizations for the specific canvas ID
            $visualizations = Visualization::where('is_deleted', 0)
                ->where('id_canvas', $id_canvas) // Filter based on id_canvas
                ->orderBy('created_time', 'desc')
                ->get();

            // Process each visualization to execute its query and update config
            foreach ($visualizations as $visualization) {
                // Execute the query
                try {
                    $queryResults = DB::select($visualization->query);

                    // Convert results to appropriate format based on visualization type
                    $formattedData = $this->formatDataForVisualization($queryResults, $visualization->visualization_type);

                    // Get a copy of the config array
                    $config = is_array($visualization->config) ? $visualization->config : [];

                    // Update visualization-type specific data in config
                    switch ($visualization->visualization_type) {
                        case 'pie':
                            if (!isset($config['visualizationOptions'])) {
                                $config['visualizationOptions'] = [];
                            }
                            $config['visualizationOptions']['labels'] = array_column($formattedData, 'label');
                            $config['visualizationOptions']['series'] = array_column($formattedData, 'value');
                            break;

                        case 'bar':
                        case 'line':
                        case 'area':
                            if (!isset($config['visualizationOptions'])) {
                                $config['visualizationOptions'] = [];
                            }
                            if (!isset($config['visualizationOptions']['series'])) {
                                $config['visualizationOptions']['series'] = [];
                            }
                            if (!isset($config['visualizationOptions']['xaxis'])) {
                                $config['visualizationOptions']['xaxis'] = [];
                            }

                            // Determine categories and series from formatted data
                            $categories = array_column($formattedData, 'x');
                            $seriesData = array_column($formattedData, 'y');

                            $config['visualizationOptions']['xaxis']['categories'] = $categories;
                            $config['visualizationOptions']['series'] = [
                                [
                                    'name' => $visualization->name,
                                    'data' => $seriesData
                                ]
                            ];
                            break;

                        default:
                            // Generic data update
                            if (!isset($config['visualizationOptions'])) {
                                $config['visualizationOptions'] = [];
                            }
                            $config['visualizationOptions']['data'] = $formattedData;
                    }

                    // Update the latest data timestamp
                    $config['lastDataUpdate'] = Carbon::now()->format('Y-m-d H:i:s');

                    // Assign the updated config back to the visualization properly
                    $visualization->config = $config;
                } catch (\Exception $queryException) {
                    // Get a copy of the config array
                    $config = is_array($visualization->config) ? $visualization->config : [];

                    // If query execution fails, add error info to config
                    $config['queryError'] = $queryException->getMessage();
                    $config['lastQueryAttempt'] = Carbon::now()->format('Y-m-d H:i:s');

                    // Assign the updated config back to the visualization
                    $visualization->config = $config;
                }
            }

            // Save the updated configurations before returning the data
            foreach ($visualizations as $visualization) {
                $visualization->save();
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Visualizations retrieved successfully with updated data',
                'data' => $visualizations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve visualizations: ' . $e->getMessage()
            ], 500);
        }
    }


    public function getCanvas(Request $request, $id_canvas)
    {
        try {
            // Retrieve the canvas with the given ID along with its related visualizations
            $canvas = Canvas::with('visualizations')->find($id_canvas);

            if (!$canvas) {
                return response()->json([
                    'success' => false,
                    'message' => 'Canvas with this ID not found.',
                ], 404);
            }

            // Prepare the response
            $canvasData = [
                'name' => $canvas->name,
                'visualizations' => $canvas->visualizations, // Includes all visualizations of the canvas
            ];

            return response()->json([
                'success' => true,
                'message' => 'Canvas retrieved successfully.',
                'canvas' => $canvasData
            ], 200);
        } catch (\Exception $e) {
            // Handle any exceptions that occur
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve canvas: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getCanvasByProject(Request $request)
    {
        try {
            // Mencari canvas berdasarkan id_project = 1 secara statis
            $id_project = 1; // ID Project statis
            $canvases = Canvas::where('id_project', $id_project)
                ->where('is_deleted', false)
                ->orderBy('id_canvas', 'asc') // Menyaring canvas yang tidak dihapus
                ->get();

            // Cek apakah canvas ditemukan
            if ($canvases->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Canvas dengan ID Project tersebut tidak ditemukan.',
                ], 404);
            }

            // Mengambil total canvas
            $totalCanvases = $canvases->count();

            // Mempersiapkan response
            $canvasData = $canvases->map(function ($canvas) {
                return [
                    'id' => $canvas->id_canvas,
                    'name' => $canvas->name,
                    'created_by' => $canvas->created_by,
                    'created_time' => $canvas->created_time,
                    'modified_by' => $canvas->modified_by,
                    'modified_time' => $canvas->modified_time,
                    // 'visualizations' => $canvas->visualizations, // Menampilkan visualisasi terkait dengan canvas
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Canvas berdasarkan ID Project berhasil ditemukan.',
                'canvases' => $canvasData,
                'total_canvases' => $totalCanvases, // Menyertakan jumlah total canvas
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data canvas: ' . $e->getMessage(),
            ], 500);
        }
    }




    public function createCanvas(Request $request)
    {
        try {
            // Validasi data request dengan pesan kustom
            $validatedData = $request->validate([
                'id_project' => 'required|exists:public.projects,id_project',
                'name' => 'required|string|max:255',
                'created_by' => 'nullable|string|max:255',
                'created_time' => 'nullable|date',
                'modified_by' => 'nullable|string|max:255',
                'modified_time' => 'nullable|date',
                'is_deleted' => 'nullable|boolean',
            ], [
                'name.max' => 'Nama kanvas tidak boleh melebihi 255 karakter.',
            ]);

            // Membuat canvas baru
            $canvas = Canvas::create([
                'id_project' => $validatedData['id_project'],
                'name' => $validatedData['name'],
                'created_by' => $validatedData['created_by'] ?? null,
                'created_time' => $validatedData['created_time'] ?? now(),
                'modified_by' => $validatedData['modified_by'] ?? null,
                'modified_time' => $validatedData['modified_time'] ?? now(),
                'is_deleted' => $validatedData['is_deleted'] ?? false,
            ]);

            // Response sukses
            return response()->json([
                'success' => true,
                'message' => 'Canvas berhasil ditambahkan.',
                'canvas' => $canvas
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Response jika validasi gagal
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            // Response jika terjadi error lain
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambah canvas.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function updateCanvas(Request $request, $id_canvas)
    {
        try {
            // Validasi input dengan pesan kustom
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
            ], [
                'name.max' => 'Nama kanvas tidak boleh melebihi 255 karakter.',
            ]);

            // Cari canvas berdasarkan id_canvas
            $canvas = Canvas::find($id_canvas);

            if (!$canvas) {
                return response()->json([
                    'success' => false,
                    'message' => 'Canvas dengan ID tersebut tidak ditemukan.',
                ], 404);
            }

            // Update nama canvas
            $canvas->name = $validatedData['name'];
            $canvas->modified_by = $request->user() ? $request->user()->name : 'admin';
            $canvas->modified_time = now();

            // Simpan perubahan ke database
            $canvas->save();

            return response()->json([
                'success' => true,
                'message' => 'Nama canvas berhasil diperbarui.',
                'canvas' => $canvas,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui nama canvas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function deleteCanvas($id_canvas)
    {
        try {
            // Cari canvas berdasarkan id_canvas
            $canvas = Canvas::find($id_canvas);

            if (!$canvas) {
                return response()->json([
                    'success' => false,
                    'message' => 'Canvas dengan ID tersebut tidak ditemukan.',
                ], 404);
            }

            // Tandai canvas sebagai deleted (soft delete)
            $canvas->is_deleted = true;  // Set is_deleted field to true
            $canvas->modified_by = request()->user() ? request()->user()->name : 'admin'; // Optional: Store the user who made the change
            $canvas->modified_time = now();  // Optional: Store the time of the update

            // Simpan perubahan ke database
            $canvas->save();

            return response()->json([
                'success' => true,
                'message' => 'Canvas berhasil dihapus.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus canvas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getFirstCanvas()
    {
        try {
            $canvas = Canvas::where('is_deleted', false)->first();

            if (!$canvas) {
                return response()->json([
                    'success' => false,
                    'message' => 'Canvas tidak ditemukan.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Canvas berhasil ditemukan.',
                'data' => $canvas
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil canvas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    /**
     * Format database results for different visualization types
     * 
     * @param array $queryResults The results from the database query
     * @param string $visualizationType The type of visualization
     * @return array Formatted data for the visualization
     */
    private function formatDataForVisualization($queryResults, $visualizationType)
    {
        if (empty($queryResults)) {
            return [];
            $datasource = DB::table('datasources')->where('id_datasource', $idDatasource)->first();

            if (!$datasource) {
                throw new Exception("Datasource dengan ID {$idDatasource} tidak ditemukan.");
            }

            $formattedData = [];

            switch ($visualizationType) {
                case 'pie':
                    // For pie charts, we need label-value pairs
                    foreach ($queryResults as $row) {
                        $rowData = (array) $row;
                        // Assuming first column is label, second is value
                        $keys = array_keys($rowData);
                        $labelColumn = $keys[0];
                        $valueColumn = $keys[1];

                        $formattedData[] = [
                            'label' => $rowData[$labelColumn],
                            'value' => (float) $rowData[$valueColumn]
                        ];
                    }
                    break;

                case 'bar':
                case 'line':
                case 'area':
                    // For cartesian charts, we need x-y pairs
                    foreach ($queryResults as $row) {
                        $rowData = (array) $row;
                        // Assuming first column is x-axis, second is y-axis
                        $keys = array_keys($rowData);
                        $xColumn = $keys[0];
                        $yColumn = $keys[1];

                        $formattedData[] = [
                            'x' => $rowData[$xColumn],
                            'y' => (float) $rowData[$yColumn]
                        ];
                    }
                    break;

                default:
                    // Generic formatter for other chart types
                    $formattedData = json_decode(json_encode($queryResults), true);
            }

            return $formattedData;
        }
    }
}
