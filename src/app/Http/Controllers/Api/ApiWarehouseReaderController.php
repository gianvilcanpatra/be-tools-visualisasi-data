<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Datasource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ApiWarehouseReaderController extends Controller
{
    private $warehouseConnectionName;

    public function __construct()
    {
        $this->warehouseConnectionName = 'pgsql2';
    }

    public function fetchTables()
    {
        try {
            $connection = DB::connection($this->warehouseConnectionName);
            $connection->getPdo();

            $datasources = Datasource::where('is_deleted', false)
                ->get(['id_datasource', 'id_project', 'name', 'type', 'host', 'port', 'database_name', 'created_by', 'created_time', 'modified_by', 'modified_time'])
                ->keyBy('name');

            $tables = $this->getTablesFromDatabase();
            $groupedTables = $this->groupTablesByPrefix($tables);

            $enrichedGroups = [];
            foreach ($groupedTables as $prefix => $group) {
                if ($datasources->has($prefix)) {
                    $datasourceInfo = $datasources->get($prefix);
                    $group['datasource_info'] = $datasourceInfo;
                    $enrichedGroups[$prefix] = $group;
                } else if ($prefix === 'ungrouped') {
                    $group['datasource_info'] = null;
                    $enrichedGroups[$prefix] = $group;
                }
            }
            
            $totalTablesCount = 0;
            foreach($enrichedGroups as $group) {
                $totalTablesCount += $group['table_count'];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'connection_name' => $this->warehouseConnectionName,
                    'tables' => $tables,
                    'grouped_tables' => $enrichedGroups,
                    'total_tables' => $totalTablesCount,
                    'total_groups' => count($enrichedGroups)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Gagal mengambil daftar tabel dari '{$this->warehouseConnectionName}': " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar tabel: ' . $e->getMessage()
            ], 500);
        }
    }

    public function fetchTableColumns($tableName)
    {
        try {
            $connection = DB::connection($this->warehouseConnectionName);
            $schemaBuilder = $connection->getSchemaBuilder();

            if (!$schemaBuilder->hasTable($tableName)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tabel '{$tableName}' tidak ditemukan di koneksi '{$this->warehouseConnectionName}'."
                ], 404);
            }

            $columns = $schemaBuilder->getColumns($tableName);

            $formattedColumns = array_map(function ($column, $index) {
                return [
                    'id'       => $index + 1,
                    'name'     => $column['name'],
                    'type'     => $column['type_name'],
                    'nullable' => $column['nullable'],
                    'default'  => $column['default'],
                ];
            }, $columns, array_keys($columns));

            return response()->json([
                'success' => true,
                'message' => "Daftar kolom berhasil diambil dari tabel '{$tableName}'.",
                'data'    => $formattedColumns,
            ], 200);
        } catch (\Exception $e) {
            Log::error("Gagal mengambil kolom dari tabel '{$tableName}': " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar kolom.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    private function groupTablesByPrefix($tables)
    {
        $grouped = [];

        foreach ($tables as $table) {
            if (strpos($table, '__') !== false) {
                $prefix = explode('__', $table)[0];
                $tableName = explode('__', $table, 2)[1];

                if (!isset($grouped[$prefix])) {
                    $grouped[$prefix] = [
                        'prefix' => $prefix,
                        'tables' => []
                    ];
                }

                $grouped[$prefix]['tables'][] = [
                    'full_name' => $table,
                    'table_name' => $tableName
                ];
            } else {
                if (!isset($grouped['ungrouped'])) {
                    $grouped['ungrouped'] = [
                        'prefix' => 'ungrouped',
                        'tables' => []
                    ];
                }

                $grouped['ungrouped']['tables'][] = [
                    'full_name' => $table,
                    'table_name' => $table
                ];
            }
        }

        ksort($grouped);

        foreach ($grouped as &$group) {
            $group['table_count'] = count($group['tables']);
        }

        return $grouped;
    }

    private function getTablesFromDatabase()
    {
        try {
            $tablesData = Schema::connection($this->warehouseConnectionName)->getTables();
            return array_map(fn($table) => array_values((array)$table)[0], $tablesData);
        } catch (\Exception $e) {
            throw new \Exception('Gagal mengambil daftar tabel dari database: ' . $e->getMessage());
        }
    }
}