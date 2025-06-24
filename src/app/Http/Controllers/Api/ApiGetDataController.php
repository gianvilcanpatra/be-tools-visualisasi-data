<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Datasource;
use App\Models\Visualization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

class ApiGetDataController extends Controller
{
    private $warehouseConnectionName = 'pgsql2';
    private $schemaMetadata = null;

    private function _getSchemaMetadata()
    {
        if ($this->schemaMetadata !== null) {
            return $this->schemaMetadata;
        }

        $connection = DB::connection($this->warehouseConnectionName);
        $schema = 'public';

        $columnsResult = $connection->select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, ordinal_position", [$schema]);
        $tableColumns = [];
        foreach ($columnsResult as $col) {
            $tableColumns[strtolower($col->table_name)][] = strtolower($col->column_name);
        }

        $pkResult = $connection->select("SELECT tc.table_name, kcu.column_name FROM information_schema.table_constraints AS tc JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ?", [$schema]);
        $primaryKeys = [];
        foreach ($pkResult as $pk) {
            $primaryKeys[strtolower($pk->table_name)] = strtolower($pk->column_name);
        }

        $fkResult = $connection->select("SELECT tc.table_name AS referencing_table, kcu.column_name AS referencing_column, ccu.table_name AS referenced_table, ccu.column_name AS referenced_column FROM information_schema.table_constraints AS tc JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ?", [$schema]);

        $this->schemaMetadata = [
            'tableColumns' => $tableColumns,
            'primaryKeys' => $primaryKeys,
            'foreignKeys' => array_map(function ($fk) {
                $fk->referencing_table = strtolower($fk->referencing_table);
                $fk->referencing_column = strtolower($fk->referencing_column);
                $fk->referenced_table = strtolower($fk->referenced_table);
                $fk->referenced_column = strtolower($fk->referenced_column);
                return $fk;
            }, $fkResult)
        ];

        return $this->schemaMetadata;
    }

    private function getForeignKey($tableA, $tableB, $metadata)
    {
        $tableA = strtolower($tableA);
        $tableB = strtolower($tableB);

        foreach ($metadata['foreignKeys'] as $fk) {
            if (($fk->referencing_table === $tableA && $fk->referenced_table === $tableB) ||
                ($fk->referencing_table === $tableB && $fk->referenced_table === $tableA)
            ) {
                return $fk;
            }
        }

        $pkOfA = $metadata['primaryKeys'][$tableA] ?? null;
        if ($pkOfA && isset($metadata['tableColumns'][$tableB]) && in_array($pkOfA, $metadata['tableColumns'][$tableB])) {
            return (object) [
                'referencing_table' => $tableB,
                'referencing_column' => $pkOfA,
                'referenced_table' => $tableA,
                'referenced_column' => $pkOfA
            ];
        }

        $pkOfB = $metadata['primaryKeys'][$tableB] ?? null;
        if ($pkOfB && isset($metadata['tableColumns'][$tableA]) && in_array($pkOfB, $metadata['tableColumns'][$tableA])) {
            return (object) [
                'referencing_table' => $tableA,
                'referencing_column' => $pkOfB,
                'referenced_table' => $tableB,
                'referenced_column' => $pkOfB
            ];
        }

        return null;
    }

    public function getJoinableTables(Request $request)
    {
        $validated = $request->validate(['existing_tables' => 'present|array']);
        $existingTables = array_unique($validated['existing_tables']);

        $metadata = $this->_getSchemaMetadata();
        $allTablesInWarehouse = array_keys($metadata['tableColumns']);

        if (empty($existingTables)) {
            return response()->json(['success' => true, 'data' => $allTablesInWarehouse]);
        }

        $lastSelectedTable = Arr::last($existingTables);
        if (!$lastSelectedTable) {
            return response()->json(['success' => true, 'data' => $allTablesInWarehouse]);
        }

        $joinableTables = [];
        foreach ($allTablesInWarehouse as $candidateTable) {
            if (strtolower($candidateTable) === strtolower($lastSelectedTable)) continue;

            if ($this->getForeignKey($lastSelectedTable, $candidateTable, $metadata)) {
                $joinableTables[] = $candidateTable;
            }
        }

        $finalList = array_unique(array_merge($existingTables, $joinableTables));

        $lastTablePrefix = explode('__', $lastSelectedTable)[0];

        usort($finalList, function ($a, $b) use ($lastTablePrefix, $lastSelectedTable) {
            if ($a === $lastSelectedTable) return 1;
            if ($b === $lastSelectedTable) return -1;

            $aMatchesPrefix = (explode('__', $a)[0] === $lastTablePrefix);
            $bMatchesPrefix = (explode('__', $b)[0] === $lastTablePrefix);

            if ($aMatchesPrefix && !$bMatchesPrefix) return -1;
            if (!$aMatchesPrefix && $bMatchesPrefix) return 1;

            return strcasecmp($a, $b);
        });

        return response()->json(['success' => true, 'data' => $finalList]);
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
            $metadata = null;

            $previousTable = $table;
            if (!empty($tabelJoin)) {
                $metadata = $this->_getSchemaMetadata();
                foreach ($tabelJoin as $join) {
                    $joinTable = isset($join['tabel']) ? $join['tabel'] : null;
                    $joinType = strtoupper($join['join_type'] ?? 'INNER');
                    if ($joinTable) {
                        if ($joinType === 'CROSS') {
                            $query->crossJoin($joinTable);
                        } else {
                            $foreignKey = $this->getForeignKey($previousTable, $joinTable, $metadata);
                            if ($foreignKey) {
                                $query->join($joinTable, "{$foreignKey->referencing_table}.{$foreignKey->referencing_column}", '=', "{$foreignKey->referenced_table}.{$foreignKey->referenced_column}", $joinType);
                            } else {
                                return response()->json(['success' => false, 'message' => "Foreign key not found for join between {$previousTable} and {$joinTable}."], 400);
                            }
                        }
                        $previousTable = $joinTable;
                    }
                }
            }

            $filters = $request->input('filters', []);
            $granularity = $request->input('granularity');
            $dateFilterDetails = $request->input('date_filter_details');
            $topN = $request->input('topN');
            $topNMetric = $request->input('topN_metric');
            $displayFormat = $request->input('display_format', 'auto');
            $sortBy = $request->input('sortBy');
            $orderByDirection = $request->input('orderBy', 'asc');
            $selects = [];
            $groupBy = [];
            $orderBy = [];
            $rawGroupByExpressions = [];
            $granularityDateColumn = null;
            if ($granularity && $granularity !== 'asis' && $dateFilterDetails && isset($dateFilterDetails['column'])) {
                $granularityDateColumn = $dateFilterDetails['column'];
                $colParts = explode('.', $granularityDateColumn);
                $actualDateColumnForExpr = count($colParts) > 1 ? $granularityDateColumn : $table . '.' . $granularityDateColumn;
                $groupingExpr = null;
                $periodAlias = '';
                $labelExpr = null;
                switch (strtolower($granularity)) {
                    case 'daily':
                        $periodAlias = 'day_start';
                        $groupingExpr = DB::raw("DATE_TRUNC('day', {$actualDateColumnForExpr})");
                        $labelFormat = "YYYY-MM-DD";
                        switch ($displayFormat) {
                            case 'week_number':
                                $labelFormat = 'IYYY-"Week"-IW';
                                break;
                            case 'month_name':
                                $labelFormat = 'YYYY-Mon-DD';
                                break;
                        }
                        $labelExpr = DB::raw("TO_CHAR(DATE_TRUNC('day', {$actualDateColumnForExpr}), '{$labelFormat}')");
                        break;
                    case 'weekly':
                        $periodAlias = 'week_start';
                        $groupingExpr = DB::raw("DATE_TRUNC('week', {$actualDateColumnForExpr})");
                        $labelFormat = 'IYYY-"Week"-IW';
                        switch ($displayFormat) {
                            case 'month_name':
                            case 'year':
                            case 'original':
                                $labelFormat = 'YYYY-MM-DD';
                                break;
                        }
                        $labelExpr = DB::raw("TO_CHAR(DATE_TRUNC('week', {$actualDateColumnForExpr}), '{$labelFormat}')");
                        break;
                    case 'monthly':
                        $periodAlias = 'month_start';
                        $groupingExpr = DB::raw("DATE_TRUNC('month', {$actualDateColumnForExpr})");
                        $labelFormat = 'YYYY-Month';
                        switch ($displayFormat) {
                            case 'original':
                                $labelFormat = 'YYYY-MM';
                                break;
                        }
                        $labelExpr = DB::raw("TRIM(TO_CHAR(DATE_TRUNC('month', {$actualDateColumnForExpr}), '{$labelFormat}'))");
                        break;
                }
                if ($groupingExpr && $labelExpr && $periodAlias) {
                    $selects[] = new Expression($labelExpr->getValue($connection->getQueryGrammar()) . " AS period_label");
                    $selects[] = new Expression($groupingExpr->getValue($connection->getQueryGrammar()) . " AS {$periodAlias}");
                    $rawGroupByExpressions[] = $groupingExpr;
                    $orderBy[] = new Expression("{$periodAlias} ASC");
                }
            }
            foreach ($userInputDimensi as $dim) {
                if ($granularityDateColumn && $dim === $granularityDateColumn && $granularity !== 'asis') {
                    continue;
                }
                $selects[] = $dim;
                $groupBy[] = $dim;
            }
            $selects = array_unique($selects, SORT_REGULAR);
            $groupBy = array_unique($groupBy, SORT_REGULAR);
            $hasAggregations = false;
            if (!empty($metriks)) {
                foreach ($metriks as $metrikColumn) {
                    $parts = explode('|', $metrikColumn);
                    $columnName = $parts[0];
                    $aggregationType = isset($parts[1]) ? strtoupper($parts[1]) : 'COUNT';
                    $hasAggregations = true;

                    $columnParts = explode('.', $columnName);
                    $justTheColumnName = end($columnParts);
                    $columnAliasBase = str_replace('*', 'all', $justTheColumnName);
                    $columnAliasBase = preg_replace('/[^a-zA-Z0-9_]/', '', $columnAliasBase);

                    switch ($aggregationType) {
                        case 'SUM':
                            $selects[] = DB::raw("SUM({$columnName}) AS sum_{$columnAliasBase}");
                            break;
                        case 'AVERAGE':
                            $selects[] = DB::raw("AVG({$columnName}) AS avg_{$columnAliasBase}");
                            break;
                        case 'MIN':
                            $selects[] = DB::raw("MIN({$columnName}) AS min_{$columnAliasBase}");
                            break;
                        case 'MAX':
                            $selects[] = DB::raw("MAX({$columnName}) AS max_{$columnAliasBase}");
                            break;
                        case 'COUNT':
                        default:
                            if ($columnName === '*') {
                                $selects[] = DB::raw("COUNT(*) AS count_star");
                            } else {
                                $selects[] = DB::raw("COUNT({$columnName}) AS count_{$columnAliasBase}");
                            }
                            break;
                    }
                }
            }
            if (empty($selects)) {
                $query->selectRaw("1 AS placeholder_if_no_selects_error");
            } else {
                $query->select($selects);
            }
            $this->applyFilters($query, $filters);
            if (!empty($groupBy) || !empty($rawGroupByExpressions)) {
                foreach ($groupBy as $gbItem) {
                    $query->groupBy($gbItem);
                }
                foreach ($rawGroupByExpressions as $gbExpr) {
                    $query->groupBy($gbExpr);
                }
            } elseif ($hasAggregations && !empty($userInputDimensi)) {
                foreach ($userInputDimensi as $gbItem) {
                    $query->groupBy($gbItem);
                }
            }

            if ($topN && is_numeric($topN) && $topN > 0 && $hasAggregations) {
                $orderByMetric = $topNMetric ?? ($metriks[0] ?? null);
                if ($orderByMetric) {
                    $parts = explode('|', $orderByMetric);
                    $columnName = $parts[0];
                    $aggregationType = isset($parts[1]) ? strtoupper($parts[1]) : 'COUNT';

                    $columnParts = explode('.', $columnName);
                    $justTheColumnName = end($columnParts);
                    $columnAliasBase = str_replace('*', 'all', $justTheColumnName);
                    $columnAliasBase = preg_replace('/[^a-zA-Z0-9_]/', '', $columnAliasBase);

                    $orderColumn = '';
                    switch ($aggregationType) {
                        case 'SUM':
                            $orderColumn = "sum_{$columnAliasBase}";
                            break;
                        case 'AVERAGE':
                            $orderColumn = "avg_{$columnAliasBase}";
                            break;
                        case 'MIN':
                            $orderColumn = "min_{$columnAliasBase}";
                            break;
                        case 'MAX':
                            $orderColumn = "max_{$columnAliasBase}";
                            break;
                        case 'COUNT':
                        default:
                            $orderColumn = ($columnName === '*') ? 'count_star' : "count_{$columnAliasBase}";
                            break;
                    }
                    $query->orders = null;
                    $query->orderBy($orderColumn, 'DESC');
                }
                $query->limit((int)$topN);
            } else if (!empty($sortBy)) {
                $query->orders = null;
                $query->orderBy($sortBy, $orderByDirection);
            } else if (!empty($orderBy)) {
                foreach ($orderBy as $obItem) {
                    if ($obItem instanceof Expression) {
                        $query->orderByRaw($obItem->getValue($connection->getQueryGrammar()));
                    } else {
                        $parts = explode(' ', $obItem);
                        $query->orderBy($parts[0], $parts[1] ?? 'asc');
                    }
                }
            } elseif (empty($orderBy) && $hasAggregations && !empty($userInputDimensi)) {
                $query->orderBy($userInputDimensi[0], 'asc');
            }

            $sqlForDebug = vsprintf(str_replace(['%', '?'], ['%%', "'%s'"], $query->toSql()), $query->getBindings());
            $data = $query->get();
            return response()->json(['success' => true, 'message' => 'Data berhasil di-query.', 'data' => $data, 'query' => $sqlForDebug], 200);
        } catch (\Exception $e) {
            $sqlForDebug = isset($sqlForDebug) ? $sqlForDebug : 'Query not fully built or error before build';
            Log::error("Error in getTableDataByColumns: " . $e->getMessage() . " Stack: " . $e->getTraceAsString() . " SQL: " . $sqlForDebug);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil data: ' . $e->getMessage(), 'error_detail' => $e->getMessage(), 'query_attempted' => $sqlForDebug], 500);
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

    private function getConnectionDetails($idDatasource)
    {
        $datasource = Datasource::findOrFail($idDatasource);
        return [
            'driver' => $datasource->type,
            'host' => $datasource->host,
            'port' => $datasource->port,
            'database' => $datasource->database_name,
            'username' => $datasource->username,
            // 'password' => Crypt::decrypt($datasource->password),
            'password' => $datasource->password,
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ];
    }

    public function executeQuery(Request $request)
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string',
                'id_datasource' => 'required|integer|exists:datasources,id_datasource',
            ]);

            $dbConfig = $this->getConnectionDetails($validated['id_datasource']);
            $connectionName = "dynamic_exec_{$validated['id_datasource']}";
            config(["database.connections.{$connectionName}" => $dbConfig]);
            $connection = DB::connection($connectionName);

            $result = $connection->select($validated['query']);

            return response()->json(['success' => true, 'message' => 'Query berhasil dijalankan.', 'data' => $result], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat menjalankan query.', 'error' => $e->getMessage()], 500);
        }
    }

    public function applyFilters($query, $filters)
    {
        if (!is_array($filters) || empty($filters)) return $query;
        $query->where(function ($q) use ($filters) {
            foreach ($filters as $filter) {
                $column = $filter['column'] ?? null;
                $operator = strtolower($filter['operator'] ?? '=');
                $value = $filter['value'] ?? null;
                $logic = strtolower($filter['logic'] ?? 'and');
                $mode = strtolower($filter['mode'] ?? 'include');
                if (!$column || $value === null) continue;
                if ($operator === 'between') {
                    if (is_array($value) && count($value) === 2) {
                        $method = ($mode === 'exclude') ? 'whereNotBetween' : 'whereBetween';
                        if ($logic === 'or') $method = 'or' . ucfirst($method);
                        $q->{$method}($column, $value);
                    }
                } else {
                    $condition = [$column, $operator, $value];
                    if ($operator === 'like') $condition = [$column, 'LIKE', "%{$value}%"];
                    if ($mode === 'exclude') {
                        $logic === 'or' ? $q->orWhereNot(...$condition) : $q->whereNot(...$condition);
                    } else {
                        $logic === 'or' ? $q->orWhere(...$condition) : $q->where(...$condition);
                    }
                }
            }
        });
        return $query;
    }

    public function checkDateColumn(Request $request)
    {
        try {
            $validated = $request->validate([
                'tabel' => 'required|string',
                'kolom' => 'required|string',
            ]);

            $tableWithPrefix = $validated['tabel'];
            $column = $validated['kolom'];

            if (strpos($tableWithPrefix, '__') === false) {
                return response()->json(['success' => false, 'message' => 'Nama tabel tidak valid, harus mengandung prefix.'], 400);
            }

            list($prefix, $originalTableName) = explode('__', $tableWithPrefix, 2);
            $datasource = Datasource::where('name', $prefix)->where('is_deleted', false)->firstOrFail();

            $dbConfig = $this->getConnectionDetails($datasource->id_datasource);
            $connectionName = "dynamic_check_{$datasource->id_datasource}";
            config(["database.connections.{$connectionName}" => $dbConfig]);
            $connection = DB::connection($connectionName);

            $tableExists = $connection->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?", [$originalTableName]);
            if (empty($tableExists)) {
                return response()->json(['success' => false, 'message' => "Tabel '{$originalTableName}' tidak ditemukan di datasource '{$prefix}'."], 404);
            }

            $columns = $connection->select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = ?", [$originalTableName]);

            $dateColumns = [];
            foreach ($columns as $col) {
                $colName = $col->column_name;
                $dataType = strtolower($col->data_type);

                if ($colName !== $column && in_array($dataType, ['date', 'timestamp', 'timestamp without time zone', 'timestamp with time zone'])) {
                    $dateColumns[] = $colName;
                }
            }

            return response()->json(['success' => true, 'has_date_column' => count($dateColumns) > 0, 'date_columns' => $dateColumns], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Datasource tidak ditemukan untuk tabel yang diberikan.'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat memeriksa kolom tanggal.', 'error' => $e->getMessage()], 500);
        }
    }

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
                $logic = strtoupper($filter['logic'] ?? 'AND');
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

            $config = $request->input('config', []);

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

            if (isset($config['visualizationOptions']) && is_array($config['visualizationOptions'])) {
                $visualizationConfig['visualizationOptions'] = $config['visualizationOptions'];
            }

            $visualization = Visualization::where('id_canvas', $validated['id_canvas'])
                ->where('query', $validated['query'])
                ->first();

            $isPositionUpdate = $request->has('position_x') || $request->has('position_y') ||
                $request->has('width') || $request->has('height');

            if ($visualization) {
                $updateData = [
                    'modified_by' => 1,
                    'modified_time' => now(),
                ];

                if ($request->has('id_datasource')) {
                    $updateData['id_datasource'] = $validated['id_datasource'];
                }

                if ($request->has('name')) {
                    $updateData['name'] = $validated['name'];
                }

                if ($request->has('visualization_type')) {
                    $updateData['visualization_type'] = $validated['visualization_type'];
                }

                if (!$isPositionUpdate) {
                    $updateData['config'] = $visualizationConfig;
                    $updateData['query'] = $validated['query'];
                }

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

            } else {
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
                    'created_by' => 1,
                    'modified_by' => 1,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Visualisasi berhasil disimpan',
                'data' => $visualization
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Error saving visualization: ' . $e->getMessage(), ['trace' => $e->getTraceAsString(), 'request' => $request->all()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat menyimpan visualization', 'error' => $e->getMessage()], 500);
        }
    }

    public function getvisualizationPosition(Request $request)
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string',
                'visualization_type' => 'required|string',
            ]);

            $visualization = Visualization::where('query', $validated['query'])
                ->where('visualization_type', $validated['visualization_type'])
                ->first();

            if (!$visualization) {
                return response()->json(['success' => false, 'message' => 'Visualisasi tidak ditemukan'], 404);
            }

            return response()->json(['success' => true, 'message' => 'Data posisi visualization berhasil diambil', 'data' => ['width' => $visualization->width, 'height' => $visualization->height, 'position_x' => $visualization->position_x, 'position_y' => $visualization->position_y]], 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving visualization position: ' . $e->getMessage(), ['trace' => $e->getTraceAsString(), 'request' => $request->all()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil data posisi visualization', 'error' => $e->getMessage()], 500);
        }
    }

    public function getVisualisasiData(Request $request)
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string',
                'id_datasource' => 'required|integer|exists:datasources,id_datasource',
            ]);

            $dbConfig = $this->getConnectionDetails($validated['id_datasource']);
            $connectionName = "dynamic_viz_{$validated['id_datasource']}";
            config(["database.connections.{$connectionName}" => $dbConfig]);
            $connection = DB::connection($connectionName);

            $rawResults = $connection->select($validated['query']);

            if (empty($rawResults)) {
                return response()->json(['success' => true, 'message' => 'Query berhasil dijalankan, namun tidak ada data.', 'data' => []], 200);
            }

            $formattedData = $this->formatVisualizationData($rawResults);

            return response()->json(['success' => true, 'message' => 'Query berhasil dijalankan.', 'data' => $formattedData], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat menjalankan query.', 'error' => $e->getMessage()], 500);
        }
    }

    private function formatVisualizationData($rawResults)
    {
        $firstRow = (array)$rawResults[0];
        $columns = array_keys($firstRow);

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

            foreach ((array)$row as $key => $value) {
                if (in_array($key, $numericColumns)) {
                    $formattedRow[$key] = is_null($value) || $value === '' || $value === 'null'
                        ? 0
                        : (is_numeric($value) ? floatval($value) : 0);
                } elseif (in_array($key, $dateColumns)) {
                    $formattedRow[$key] = $this->standardizeDateFormat($value);
                } else {
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

    private function isDateColumn($columnName, $sampleValue)
    {
        $dateKeywords = ['date', 'tanggal', 'period', 'month', 'year', 'week', 'quarter', 'time'];
        $columnLower = strtolower($columnName);

        foreach ($dateKeywords as $keyword) {
            if (strpos($columnLower, $keyword) !== false) {
                return true;
            }
        }

        if (is_string($sampleValue)) {
            $patterns = [
                '/^\d{4}-\d{1,2}$/',
                '/^\d{1,2}-\d{4}$/',
                '/^[A-Za-z]+-\d{2,4}$/',
                '/^Week\s+\d+\s+\d{4}$/i',
                '/^Q\d\s+\d{4}$/i',
                '/^\d{4}-\d{2}-\d{2}$/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, trim($sampleValue))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function standardizeDateFormat($dateValue)
    {
        if (is_null($dateValue) || trim($dateValue) === '') {
            return 'Tidak Diketahui';
        }

        $value = trim($dateValue);

        return $value;
    }

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
            'suggested_type' => 'simple'
        ];

        if (count($columns) >= 3 && count($numericColumns) >= 1 && count($textColumns) >= 2) {
            $structure['suggested_type'] = 'grouped';
            $structure['suggested_label'] = $textColumns[0];
            $structure['suggested_category'] = $textColumns[1] ?? null;
            $structure['suggested_value'] = end($numericColumns);
        }

        return $structure;
    }
}
