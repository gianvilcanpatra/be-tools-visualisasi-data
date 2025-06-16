<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visualization;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Schema;

class ApiGetDataController extends Controller
{
    private $warehouseConnectionName = 'pgsql2';

    private function getPrimaryKeyForTable($tableName)
    {
        try {
            $pkQuery = "SELECT kcu.column_name
                        FROM information_schema.table_constraints AS tc
                        JOIN information_schema.key_column_usage AS kcu
                          ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                        WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public' AND tc.table_name = ?";
            
            $result = DB::connection($this->warehouseConnectionName)->selectOne($pkQuery, [$tableName]);
            return $result ? $result->column_name : null;
        } catch (\Exception $e) {
            Log::error("Could not get primary key for table {$tableName}: " . $e->getMessage());
            return null;
        }
    }

    private function getForeignKey($tableA, $tableB)
    {
        try {
            $connection = DB::connection($this->warehouseConnectionName);
            $schema = 'public';

            // Metode 1: Cek foreign key formal (gold standard)
            $formalFkQuery = "
                SELECT
                    tc.table_name AS referencing_table, kcu.column_name AS referencing_column,
                    ccu.table_name AS referenced_table, ccu.column_name AS referenced_column
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ?
                  AND ((lower(tc.table_name) = lower(?) AND lower(ccu.table_name) = lower(?)) OR (lower(tc.table_name) = lower(?) AND lower(ccu.table_name) = lower(?)))
            ";
            $formalForeignKey = $connection->selectOne($formalFkQuery, [$schema, $tableA, $tableB, $tableB, $tableA]);
            if ($formalForeignKey) {
                return $formalForeignKey;
            }

            // Metode 2 (FALLBACK): Cek relasi berdasarkan nama Primary Key
            $schemaManager = Schema::connection($this->warehouseConnectionName);
            $columnsA = array_map('strtolower', $schemaManager->getColumnListing($tableA));
            $columnsB = array_map('strtolower', $schemaManager->getColumnListing($tableB));

            // Cek 1: Apakah PK dari Tabel A ada sebagai kolom di Tabel B?
            $pkOfA = $this->getPrimaryKeyForTable($tableA);
            if ($pkOfA && in_array(strtolower($pkOfA), $columnsB)) {
                $result = new \stdClass();
                $result->referencing_table = $tableB;
                $result->referencing_column = $pkOfA;
                $result->referenced_table = $tableA;
                $result->referenced_column = $pkOfA;
                return $result;
            }

            // Cek 2: Apakah PK dari Tabel B ada sebagai kolom di Tabel A?
            $pkOfB = $this->getPrimaryKeyForTable($tableB);
            if ($pkOfB && in_array(strtolower($pkOfB), $columnsA)) {
                $result = new \stdClass();
                $result->referencing_table = $tableA;
                $result->referencing_column = $pkOfB;
                $result->referenced_table = $tableB;
                $result->referenced_column = $pkOfB;
                return $result;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error finding foreign key between {$tableA} and {$tableB}: " . $e->getMessage());
            return null;
        }
    }
    
    public function getAllTables()
    {
        try {
            $tables = DB::connection($this->warehouseConnectionName)->select("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public'
            ");
            $excludedTables = ['migrations', 'personal_access_tokens'];
            $tableNames = array_filter(array_map(fn($table) => $table->table_name, $tables), fn($tableName) => !in_array($tableName, $excludedTables));
            return response()->json(['success' => true, 'message' => 'Daftar tabel berhasil diambil dari data warehouse.', 'data' => array_values($tableNames)], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil daftar tabel dari data warehouse.', 'error' => $e->getMessage()], 500);
        }
    }

    public function getTableColumns($table)
    {
        try {
            $connection = DB::connection($this->warehouseConnectionName);
            $tableExists = $connection->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?", [$table]);
            if (empty($tableExists)) {
                return response()->json(['success' => false, 'message' => "Tabel '{$table}' tidak ditemukan di data warehouse."], 404);
            }
            $columns = $connection->select("SELECT column_name, data_type, is_nullable, ordinal_position FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]);
            $formattedColumns = array_map(function ($column) {
                return ['id' => $column->ordinal_position, 'name' => $column->column_name, 'type' => $column->data_type, 'nullable' => $column->is_nullable === 'YES'];
            }, $columns);
            return response()->json(['success' => true, 'message' => "Daftar kolom berhasil diambil dari tabel '{$table}'.", 'data' => $formattedColumns], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil daftar kolom.', 'error' => $e->getMessage()], 500);
        }
    }
    
    public function getTableDataByColumns(Request $request)
    {
        try {
            $connection = DB::connection($this->warehouseConnectionName);
            $table = $request->input('tabel');
            if (empty($table)) {
                return response()->json(['success' => false, 'message' => 'Nama tabel tidak boleh kosong.'], 400);
            }
            $query = $connection->table($table);
            $userInputDimensi = $request->input('dimensi', []);
            $metriks = $request->input('metriks', []);
            $tabelJoin = $request->input('tabel_join', []);

            $previousTable = $table;
            if (!empty($tabelJoin)) {
                foreach ($tabelJoin as $join) {
                    $joinTable = isset($join['tabel']) ? $join['tabel'] : null;
                    $joinType = strtoupper($join['join_type'] ?? 'INNER');
                    if ($joinTable) {
                        if ($joinType === 'CROSS') {
                            $query->crossJoin($joinTable);
                        } else {
                            $foreignKey = $this->getForeignKey($previousTable, $joinTable);
                            if ($foreignKey) {
                                $firstColumn = "{$foreignKey->referencing_table}.{$foreignKey->referencing_column}";
                                $secondColumn = "{$foreignKey->referenced_table}.{$foreignKey->referenced_column}";
                                $query->join($joinTable, $firstColumn, '=', $secondColumn, $joinType);
                            } else {
                                return response()->json([
                                    'success' => false,
                                    'message' => "Foreign key relationship for join between {$previousTable} and {$joinTable} not found. Automatic join is not possible."
                                ], 400);
                            }
                        }
                        $previousTable = $joinTable;
                    }
                }
            }

            // Sisa kode tidak perlu diubah
            $filters = $request->input('filters', []);
            $granularity = $request->input('granularity');
            $dateFilterDetails = $request->input('date_filter_details');
            $topN = $request->input('topN');
            $topNMetric = $request->input('topN_metric');
            $displayFormat = $request->input('display_format', 'auto');
            $selects = []; $groupBy = []; $orderBy = []; $rawGroupByExpressions = [];
            $granularityDateColumn = null;
            if ($granularity && $granularity !== 'asis' && $dateFilterDetails && isset($dateFilterDetails['column'])) {
                $granularityDateColumn = $dateFilterDetails['column'];
                $colParts = explode('.', $granularityDateColumn);
                $actualDateColumnForExpr = count($colParts) > 1 ? $granularityDateColumn : $table . '.' . $granularityDateColumn;
                $groupingExpr = null; $periodAlias = ''; $labelExpr = null;
                switch (strtolower($granularity)) {
                    case 'daily': $periodAlias = 'day_start'; $groupingExpr = DB::raw("DATE_TRUNC('day', {$actualDateColumnForExpr})"); $labelFormat = "YYYY-MM-DD"; switch ($displayFormat) { case 'week_number': $labelFormat = 'IYYY-"Week"-IW'; break; case 'month_name': $labelFormat = 'YYYY-Mon-DD'; break; } $labelExpr = DB::raw("TO_CHAR(DATE_TRUNC('day', {$actualDateColumnForExpr}), '{$labelFormat}')"); break;
                    case 'weekly': $periodAlias = 'week_start'; $groupingExpr = DB::raw("DATE_TRUNC('week', {$actualDateColumnForExpr})"); $labelFormat = 'IYYY-"Week"-IW'; switch ($displayFormat) { case 'month_name': case 'year': case 'original': $labelFormat = 'YYYY-MM-DD'; break; } $labelExpr = DB::raw("TO_CHAR(DATE_TRUNC('week', {$actualDateColumnForExpr}), '{$labelFormat}')"); break;
                    case 'monthly': $periodAlias = 'month_start'; $groupingExpr = DB::raw("DATE_TRUNC('month', {$actualDateColumnForExpr})"); $labelFormat = 'YYYY-Month'; switch ($displayFormat) { case 'original': $labelFormat = 'YYYY-MM'; break; } $labelExpr = DB::raw("TRIM(TO_CHAR(DATE_TRUNC('month', {$actualDateColumnForExpr}), '{$labelFormat}'))"); break;
                }
                if ($groupingExpr && $labelExpr && $periodAlias) {
                    $selects[] = new Expression($labelExpr->getValue($connection->getQueryGrammar()) . " AS period_label");
                    $selects[] = new Expression($groupingExpr->getValue($connection->getQueryGrammar()) . " AS {$periodAlias}");
                    $rawGroupByExpressions[] = $groupingExpr;
                    $orderBy[] = new Expression("{$periodAlias} ASC");
                }
            }
            foreach ($userInputDimensi as $dim) { if ($granularityDateColumn && $dim === $granularityDateColumn && $granularity !== 'asis') { continue; } $selects[] = $dim; $groupBy[] = $dim; }
            $selects = array_unique($selects, SORT_REGULAR); $groupBy = array_unique($groupBy, SORT_REGULAR);
            $hasAggregations = false;
            if (!empty($metriks)) {
                foreach ($metriks as $metrikColumn) {
                    $parts = explode('|', $metrikColumn); $columnName = $parts[0]; $aggregationType = isset($parts[1]) ? strtoupper($parts[1]) : 'COUNT'; $hasAggregations = true;
                    $columnAliasBase = str_replace(['.', '*'], ['_', 'all'], $columnName); $columnAliasBase = preg_replace('/[^a-zA-Z0-9_]/', '', $columnAliasBase);
                    switch ($aggregationType) {
                        case 'SUM': $selects[] = DB::raw("SUM({$columnName}) AS sum_{$columnAliasBase}"); break;
                        case 'AVERAGE': $selects[] = DB::raw("AVG({$columnName}) AS avg_{$columnAliasBase}"); break;
                        case 'MIN': $selects[] = DB::raw("MIN({$columnName}) AS min_{$columnAliasBase}"); break;
                        case 'MAX': $selects[] = DB::raw("MAX({$columnName}) AS max_{$columnAliasBase}"); break;
                        case 'COUNT': default: if ($columnName === '*') { $selects[] = DB::raw("COUNT(*) AS count_star"); } else { $selects[] = DB::raw("COUNT({$columnName}) AS count_{$columnAliasBase}"); } break;
                    }
                }
            }
            if (empty($selects)) { $query->selectRaw("1 AS placeholder_if_no_selects_error"); } else { $query->select($selects); }
            $this->applyFilters($query, $filters);
            if (!empty($groupBy) || !empty($rawGroupByExpressions)) { foreach ($groupBy as $gbItem) { $query->groupBy($gbItem); } foreach ($rawGroupByExpressions as $gbExpr) { $query->groupBy($gbExpr); } } elseif ($hasAggregations && !empty($userInputDimensi)) { foreach ($userInputDimensi as $gbItem) { $query->groupBy($gbItem); } }
            if (!empty($orderBy)) { foreach ($orderBy as $obItem) { if ($obItem instanceof Expression) { $query->orderByRaw($obItem->getValue($connection->getQueryGrammar())); } else { $parts = explode(' ', $obItem); $query->orderBy($parts[0], $parts[1] ?? 'asc'); } } } elseif (empty($orderBy) && $hasAggregations && !empty($userInputDimensi)) { $query->orderBy($userInputDimensi[0], 'asc'); }
            if ($topN && is_numeric($topN) && $topN > 0 && $hasAggregations) {
                $orderByMetric = $topNMetric ?? ($metriks[0] ?? null);
                if ($orderByMetric) {
                    $parts = explode('|', $orderByMetric); $columnName = $parts[0]; $aggregationType = isset($parts[1]) ? strtoupper($parts[1]) : 'COUNT';
                    $columnAliasBase = str_replace(['.', '*'], ['_', 'all'], $columnName); $columnAliasBase = preg_replace('/[^a-zA-Z0-9_]/', '', $columnAliasBase);
                    $orderColumn = '';
                    switch ($aggregationType) {
                        case 'SUM': $orderColumn = "sum_{$columnAliasBase}"; break;
                        case 'AVERAGE': $orderColumn = "avg_{$columnAliasBase}"; break;
                        case 'MIN': $orderColumn = "min_{$columnAliasBase}"; break;
                        case 'MAX': $orderColumn = "max_{$columnAliasBase}"; break;
                        case 'COUNT': default: $orderColumn = ($columnName === '*') ? 'count_star' : "count_{$columnAliasBase}"; break;
                    }
                    $query->orders = null; $query->orderBy($orderColumn, 'DESC');
                }
                $query->limit((int)$topN);
            }
            $sqlForDebug = vsprintf(str_replace(['%', '?'], ['%%', "'%s'"], $query->toSql()), $query->getBindings());
            $data = $query->get();
            return response()->json(['success' => true, 'message' => 'Data berhasil di-query.', 'data' => $data, 'query' => $sqlForDebug], 200);
        } catch (\Exception $e) {
            Log::error("Error in getTableDataByColumns: " . $e->getMessage() . " Stack: " . $e->getTraceAsString() . (isset($sqlForDebug) ? " SQL: " . $sqlForDebug : ""));
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil data: ' . $e->getMessage(), 'error_detail' => $e->getMessage(), 'query_attempted' => isset($sqlForDebug) ? $sqlForDebug : 'Query not fully built or error before build'], 500);
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

    public function getJoinableTables(Request $request)
    {
        $validated = $request->validate([
            'existing_tables' => 'present|array'
        ]);
        
        $existingTables = array_unique($validated['existing_tables']);

        $allTablesInWarehouse = array_map(fn($table) => $table->table_name, DB::connection($this->warehouseConnectionName)->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"));

        if (empty($existingTables)) {
            return response()->json(['success' => true, 'data' => $allTablesInWarehouse]);
        }

        $joinableTables = [];
        foreach ($allTablesInWarehouse as $candidateTable) {
            if (in_array($candidateTable, $existingTables)) {
                $joinableTables[] = $candidateTable;
                continue;
            }

            foreach ($existingTables as $existingTable) {
                if ($this->getForeignKey($candidateTable, $existingTable)) {
                    $joinableTables[] = $candidateTable;
                    break; 
                }
            }
        }
        
        return response()->json(['success' => true, 'data' => array_unique($joinableTables)]);
    }

    public function executeQuery(Request $request)
    {
        try {
            // Ambil query dari input JSON
            $query = $request->input('query');

            // Validasi query untuk memastikan tidak kosong
            if (empty($query)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Query SQL tidak boleh kosong.',
                ], 400);
            }

            // Ambil koneksi database dari datasources (hardcoded untuk ID 1)
            $idDatasource = 1; // Hardcode datasource ID 1
            $dbConfig = $this->getConnectionDetails($idDatasource);

            // Buat koneksi on-the-fly menggunakan konfigurasi yang sudah diambil
            config(["database.connections.dynamic" => $dbConfig]);

            // Gunakan koneksi yang baru dibuat
            $connection = DB::connection('dynamic');

            // Menjalankan query SQL yang diberikan
            $result = $connection->select($query);

            // Mengembalikan hasil query
            return response()->json([
                'success' => true,
                'message' => 'Query berhasil dijalankan.',
                'data' => $result,
            ], 200);
        } catch (\Exception $e) {
            // Menangani error jika ada kesalahan saat menjalankan query
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menjalankan query.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function applyFilters($query, $filters)
    {
        try {
            if (!is_array($filters) || empty($filters)) {
                return $query;
            }

            $query->where(function ($q) use ($filters) {
                foreach ($filters as $filter) {
                    $column = $filter['column'] ?? null;
                    $operator = strtolower($filter['operator'] ?? '=');
                    $value = $filter['value'] ?? null;
                    $logic = strtolower($filter['logic'] ?? 'and'); // and (default) atau or
                    $mode = strtolower($filter['mode'] ?? 'include'); // include atau exclude

                    if (!$column || !$value) {
                        continue;
                    }

                    switch ($operator) {
                        case 'like':
                            $condition = [$column, 'LIKE', "%{$value}%"];
                            break;

                        case 'between':
                            if (is_array($value) && count($value) === 2) {
                                if ($mode === 'exclude') {
                                    if ($logic === 'or') {
                                        $q->orWhereNotBetween($column, $value);
                                    } else {
                                        $q->whereNotBetween($column, $value);
                                    }
                                } else {
                                    if ($logic === 'or') {
                                        $q->orWhereBetween($column, $value);
                                    } else {
                                        $q->whereBetween($column, $value);
                                    }
                                }
                                continue 2;
                            }
                            continue 2;

                        default:
                            $condition = [$column, $operator, $value];
                            break;
                    }

                    if ($mode === 'exclude') {
                        if ($logic === 'or') {
                            $q->orWhereNot(...$condition);
                        } else {
                            $q->whereNot(...$condition);
                        }
                    } else {
                        if ($logic === 'or') {
                            $q->orWhere(...$condition);
                        } else {
                            $q->where(...$condition);
                        }
                    }
                }
            });

            return $query;
        } catch (\Exception $e) {
            Log::error('Error in applyFilters: ' . $e->getMessage());
            return $query;
        }
    }

    public function checkDateColumn(Request $request)
    {
        try {
            $request->validate([
                'tabel' => 'required|string',
                'kolom' => 'required|string',
            ]);

            $table = $request->input('tabel');
            $column = $request->input('kolom');

            // Ambil koneksi dari datasource (contoh: datasource ID 1)
            $idDatasource = 1;
            $dbConfig = $this->getConnectionDetails($idDatasource);

            // Set koneksi dinamis
            config(['database.connections.dynamic' => $dbConfig]);
            $connection = DB::connection('dynamic');

            // Cek apakah tabel ada
            $tableExists = $connection->select("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = ?
            ", [$table]);

            if (empty($tableExists)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tabel '{$table}' tidak ditemukan.",
                ], 404);
            }

            // Cek semua kolom di tabel
            $columns = $connection->select("
                SELECT column_name, data_type
                FROM information_schema.columns
                WHERE table_name = ?
            ", [$table]);

            $dateColumns = [];

            foreach ($columns as $col) {
                $colName = $col->column_name;
                $dataType = strtolower($col->data_type);

                if (
                    $colName !== $column &&
                    in_array($dataType, ['date', 'timestamp', 'timestamp without time zone', 'timestamp with time zone'])
                ) {
                    $dateColumns[] = $colName;
                }
            }

            return response()->json([
                'success' => true,
                'has_date_column' => count($dateColumns) > 0,
                'date_columns' => $dateColumns
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memeriksa kolom tanggal.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Membuat WHERE clause untuk debugging query SQL
     */
    public function buildWhereClause($filters)
    {
        try {
            if (!is_array($filters) || empty($filters)) {
                return '';
            }

            $clauses = [];
            foreach ($filters as $filter) {
                $column = $filter['column'] ?? null;
                $operator = strtoupper($filter['operator'] ?? '=');
                $value = $filter['value'] ?? null;
                $logic = strtoupper($filter['logic'] ?? 'AND'); // AND atau OR
                $mode = strtoupper($filter['mode'] ?? 'INCLUDE');

                if (!$column || $value === null) {
                    continue;
                }

                if ($operator === 'LIKE') {
                    $value = "'%{$value}%'";
                } elseif ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                    $value = "{$value[0]} AND {$value[1]}";
                } else {
                    $value = "'{$value}'";
                }
                if ($mode === 'EXCLUDE') {
                    $clauses[] = "{$logic} NOT {$column} {$operator} {$value}";
                } else {
                    $clauses[] = "{$logic} {$column} {$operator} {$value}";
                }
            }

            return empty($clauses) ? '' : 'WHERE ' . preg_replace('/^AND |^OR /', '', implode(' ', $clauses));
        } catch (\Exception $e) {
            Log::error('Error in buildWhereClause: ' . $e->getMessage());
            return '';
        }
    }

    public function saveVisualization(Request $request)
    {
        try {
            // Validate basic fields
            $validated = $request->validate([
                'id_canvas' => 'required|integer',
                'id_datasource' => 'required|integer',
                'name' => 'required|string',
                'visualization_type' => 'required|string',
                'query' => 'required|string',
                'config' => 'nullable|array',
                'width' => 'nullable',
                'height' => 'nullable',
                'position_x' => 'nullable',
                'position_y' => 'nullable',
            ]);

            // Extract and prepare config data
            $config = $request->input('config', []);

            // Ensure all config values are properly captured
            $visualizationConfig = [
                'colors' => $config['colors'] ?? ['#4CAF50', '#FF9800', '#2196F3'],
                'backgroundColor' => $config['backgroundColor'] ?? '#ffffff',
                'title' => $config['title'] ?? $validated['name'],
                'fontSize' => $config['fontSize'] ?? 14,
                'fontFamily' => $config['fontFamily'] ?? 'Arial',
                'fontColor' => $config['fontColor'] ?? '#333',
                'gridColor' => $config['gridColor'] ?? '#E0E0E0',
                'pattern' => $config['pattern'] ?? 'solid',
                'titleFontSize' => $config['titleFontSize'] ?? 18,
                'titleFontFamily' => $config['titleFontFamily'] ?? 'Arial',
                'xAxisFontSize' => $config['xAxisFontSize'] ?? 12,
                'xAxisFontFamily' => $config['xAxisFontFamily'] ?? 'Arial',
                'yAxisFontSize' => $config['yAxisFontSize'] ?? 12,
                'yAxisFontFamily' => $config['yAxisFontFamily'] ?? 'Arial',
            ];

            // If visualizationOptions exists in config, merge it with our visualizationConfig
            if (isset($config['visualizationOptions']) && is_array($config['visualizationOptions'])) {
                $visualizationConfig['visualizationOptions'] = $config['visualizationOptions'];
            }

            // Try to find existing visualization first by canvas ID and query
            $visualization = Visualization::where('id_canvas', $validated['id_canvas'])
                ->where('query', $validated['query'])
                ->first();

            // Check if this is a position/size update only
            $isPositionUpdate = $request->has('position_x') || $request->has('position_y') ||
                $request->has('width') || $request->has('height');

            if ($visualization) {
                $updateData = [
                    'modified_by' => 1, // Replace with auth user ID
                    'modified_time' => now(),
                ];

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

                $visualization->update($updateData);

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
                ]);

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
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan visualization',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Add this new API endpoint to your controller

    /**
     * Retrieve visualization position data
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getvisualizationPosition(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'query' => 'required|string',
                'visualization_type' => 'required|string',
            ]);

            // Find visualization by query and visualization type
            $visualization = Visualization::where('query', $validated['query'])
                ->where('visualization_type', $validated['visualization_type'])
                ->first();

            if (!$visualization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visualisasi tidak ditemukan'
                ], 404);
            }

            // Return visualization position data
            return response()->json([
                'success' => true,
                'message' => 'Data posisi visualization berhasil diambil',
                'data' => [
                    'width' => $visualization->width,
                    'height' => $visualization->height,
                    'position_x' => $visualization->position_x,
                    'position_y' => $visualization->position_y
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving visualization position: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data posisi visualization',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    // public function getVisualisasiData(Request $request)
    // {
    //     try {
    //         $idDatasource = 1;
    //         $dbConfig = $this->getConnectionDetails($idDatasource);
    //         config(["database.connections.dynamic" => $dbConfig]);
    //         $connection = DB::connection('dynamic');

    //         $table = $request->input('tabel');
    //         $dimensi = $request->input('dimensi', []);
    //         $metriks = $request->input('metriks', []);
    //         $tabelJoin = $request->input('tabel_join', []);
    //         $filters = $request->input('filters', []);

    //         $query = $connection->table($table);
    //         $previousTable = $table;

    //         foreach ($tabelJoin as $join) {
    //             $joinTable = $join['tabel'];
    //             $joinType = strtoupper($join['join_type']);
    //             $foreignKey = $this->getForeignKey($previousTable, $joinTable);

    //             if ($foreignKey) {
    //                 $query->join(
    //                     $joinTable,
    //                     "{$previousTable}.{$foreignKey->foreign_column}",
    //                     '=',
    //                     "{$joinTable}.{$foreignKey->referenced_column}",
    //                     $joinType
    //                 );
    //             }
    //             $previousTable = $joinTable;
    //         }

    //         $query->select(DB::raw(implode(', ', $dimensi)));
    //         foreach ($metriks as $metriksColumn) {
    //             $columnName = last(explode('.', $metriksColumn));
    //             $query->addSelect(DB::raw("COUNT(DISTINCT {$metriksColumn}) AS total_{$columnName}"));
    //         }

    //         $query->groupBy($dimensi);
    //         $query = $this->applyFilters($query, $filters);

    //         $data = $query->get();

    //         return response()->json([
    //             'success' => true,
    //             'data' => $data,
    //             'labels' => $dimensi,
    //             'series' => $metriks,
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error visualisasi data',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function getVisualisasiData(Request $request)
{
    try {
        $query = $request->input('query');

        if (empty($query)) {
            return response()->json([
                'success' => false,
                'message' => 'Query SQL tidak boleh kosong.',
            ], 400);
        }

        $idDatasource = 1;
        $dbConfig = $this->getConnectionDetails($idDatasource);

        config(["database.connections.dynamic" => $dbConfig]);
        $connection = DB::connection('dynamic');

        $rawResults = $connection->select($query);

        if (empty($rawResults)) {
            return response()->json([
                'success' => true,
                'message' => 'Query berhasil dijalankan, namun tidak ada data.',
                'data' => [],
            ], 200);
        }

        // Deteksi struktur data dan format sesuai kebutuhan
        $formattedData = $this->formatVisualizationData($rawResults);

        return response()->json([
            'success' => true,
            'message' => 'Query berhasil dijalankan.',
            'data' => $formattedData,
        ], 200);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan saat menjalankan query.',
            'error' => $e->getMessage(),
        ], 500);
    }
}

private function formatVisualizationData($rawResults)
{
    $firstRow = (array)$rawResults[0];
    $columns = array_keys($firstRow);
    
    // Deteksi apakah ada kolom numerik (untuk menentukan jenis data)
    $numericColumns = [];
    $dateColumns = [];
    
    foreach ($columns as $column) {
        $sampleValue = $firstRow[$column];
        if (is_numeric($sampleValue)) {
            $numericColumns[] = $column;
        } elseif ($this->isDateColumn($column, $sampleValue)) {
            $dateColumns[] = $column;
        }
    }
    
    return collect($rawResults)->map(function ($row) use ($columns, $numericColumns, $dateColumns) {
        $formattedRow = [];
        
        foreach ((array) $row as $key => $value) {
            if (in_array($key, $numericColumns)) {
                // Kolom numerik - pastikan format angka konsisten
                $formattedRow[$key] = is_null($value) || $value === '' || $value === 'null'
                    ? 0
                    : (is_numeric($value) ? floatval($value) : 0);
            } elseif (in_array($key, $dateColumns)) {
                // Kolom tanggal - standardisasi format
                $formattedRow[$key] = $this->standardizeDateFormat($value);
            } else {
                // Kolom non-numerik - bersihkan dan standardisasi
                if (is_null($value) || trim($value) === '' || strtolower(trim($value)) === 'null') {
                    $formattedRow[$key] = 'Tidak Diketahui';
                } else {
                    $formattedRow[$key] = trim($value);
                }
            }
        }
        
        return $formattedRow;
    })->toArray();
}

// Tambahan method untuk deteksi kolom tanggal
private function isDateColumn($columnName, $sampleValue)
{
    // Deteksi berdasarkan nama kolom
    $dateKeywords = ['date', 'tanggal', 'period', 'month', 'year', 'week', 'quarter', 'time'];
    $columnLower = strtolower($columnName);
    
    foreach ($dateKeywords as $keyword) {
        if (strpos($columnLower, $keyword) !== false) {
            return true;
        }
    }
    
    // Deteksi berdasarkan format nilai
    if (is_string($sampleValue)) {
        // Cek berbagai pola tanggal
        $patterns = [
            '/^\d{4}-\d{1,2}$/',           // 2024-12
            '/^\d{1,2}-\d{4}$/',           // 12-2024  
            '/^[A-Za-z]+-\d{2,4}$/',       // December-24
            '/^Week\s+\d+\s+\d{4}$/i',     // Week 1 2024
            '/^Q\d\s+\d{4}$/i',            // Q1 2024
            '/^\d{4}-\d{2}-\d{2}$/',       // 2024-12-01
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($sampleValue))) {
                return true;
            }
        }
    }
    
    return false;
}

// Method untuk standardisasi format tanggal (opsional - untuk konsistensi)
private function standardizeDateFormat($dateValue)
{
    if (is_null($dateValue) || trim($dateValue) === '') {
        return 'Tidak Diketahui';
    }
    
    $value = trim($dateValue);
    
    // Jika sudah dalam format yang diinginkan, kembalikan apa adanya
    // Atau lakukan konversi sesuai kebutuhan
    
    return $value;
}

// Tambahan: Method untuk memberikan hint struktur data (opsional)
private function analyzeDataStructure($data)
{
    if (empty($data)) return null;
    
    $firstRow = (array)$data[0];
    $columns = array_keys($firstRow);
    $numericColumns = [];
    $textColumns = [];
    
    foreach ($columns as $column) {
        $sampleValue = $firstRow[$column];
        if (is_numeric($sampleValue)) {
            $numericColumns[] = $column;
        } else {
            $textColumns[] = $column;
        }
    }
    
    $structure = [
        'total_columns' => count($columns),
        'numeric_columns' => $numericColumns,
        'text_columns' => $textColumns,
        'suggested_type' => 'simple' // default
    ];
    
    // Jika ada 3+ kolom dengan 1+ numerik dan 2+ text, kemungkinan grouped data
    if (count($columns) >= 3 && count($numericColumns) >= 1 && count($textColumns) >= 2) {
        $structure['suggested_type'] = 'grouped';
        $structure['suggested_label'] = $textColumns[0];
        $structure['suggested_category'] = $textColumns[1] ?? null;
        $structure['suggested_value'] = end($numericColumns);
    }
    
    return $structure;
}
}