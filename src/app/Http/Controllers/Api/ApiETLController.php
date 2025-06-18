<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class ApiETLController extends Controller
{
    private $warehouseConnectionName;
    private $batchSize = 1000;
    private $chunkSize = 5000;

    public function __construct()
    {
        $this->warehouseConnectionName = 'pgsql2';
    }

    public function run(Request $request)
    {
        $warehouseConnection = $this->warehouseConnectionName;

        $validated = $request->validate([
            'host' => 'required|string',
            'port' => 'required|numeric',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'connection_name' => [
                'required', 'string', 'alpha_dash',
                function ($attribute, $value, $fail) use ($warehouseConnection) {
                    $existing = collect(DB::connection($warehouseConnection)->select("
                        SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?
                    ", ["{$value}__%"]));
                    if ($existing->isNotEmpty()) {
                        $fail("Connection name '{$value}' sudah pernah digunakan. Gunakan Full Refresh untuk memperbarui.");
                    }
                }
            ]
        ]);

        return $this->performEtlProcess($validated);
    }

    public function refresh(Request $request)
    {
        $warehouseConnection = $this->warehouseConnectionName;

        try {
            $validated = $request->validate([
                'host' => 'required|string', 'port' => 'required|numeric',
                'database' => 'required|string', 'username' => 'required|string',
                'password' => 'required|string', 'connection_name' => 'required|string|alpha_dash'
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'validation_error', 'errors' => $e->errors()], 422);
        }

        config(["database.connections.{$validated['connection_name']}" => [
            'driver' => 'pgsql', 'host' => $validated['host'], 'port' => $validated['port'],
            'database' => $validated['database'], 'username' => $validated['username'],
            'password' => $validated['password'], 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public',
        ]]);

        $sourceConnection = $validated['connection_name'];

        try {
            $tables = DB::connection($sourceConnection)->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'");
            $refreshedTables = [];

            foreach ($tables as $table) {
                $tableName = $table->table_name;
                $warehouseTable = $sourceConnection . '__' . $tableName;

                if (!Schema::connection($warehouseConnection)->hasTable($warehouseTable)) {
                    continue;
                }
                
                $this->disableConstraints($warehouseConnection, $warehouseTable);
                
                DB::connection($warehouseConnection)->table($warehouseTable)->truncate();
                
                $totalRows = $this->bulkRefreshTable($sourceConnection, $warehouseConnection, $tableName, $warehouseTable);
                
                $this->enableConstraints($warehouseConnection, $warehouseTable);

                $refreshedTables[] = [
                    'source_table' => $tableName, 
                    'warehouse_table' => $warehouseTable, 
                    'rows_refreshed' => $totalRows
                ];
            }

            return response()->json([
                'status' => 'success', 
                'message' => 'Data warehouse berhasil diperbarui dari sumber: ' . $sourceConnection, 
                'refreshed_tables' => $refreshedTables
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Refresh gagal: ' . $e->getMessage()], 500);
        }
    }

    public function fullRefresh(Request $request)
    {
        try {
            $validated = $request->validate([
                'host' => 'required|string', 'port' => 'required|numeric',
                'database' => 'required|string', 'username' => 'required|string',
                'password' => 'required|string', 'connection_name' => 'required|string|alpha_dash'
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'validation_error', 'errors' => $e->errors()], 422);
        }

        $connectionName = $validated['connection_name'];
        
        $tablesToDrop = DB::connection($this->warehouseConnectionName)->select("
            SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?
        ", ["{$connectionName}__%"]);

        foreach ($tablesToDrop as $table) {
            Schema::connection($this->warehouseConnectionName)->dropIfExists($table->tablename);
        }

        return $this->performEtlProcess($validated);
    }

    public function delete(Request $request)
    {
        try {
            $validated = $request->validate([
                'connection_name' => 'required|string|alpha_dash'
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'validation_error', 'errors' => $e->errors()], 422);
        }

        $connectionName = $validated['connection_name'];
        $droppedTables = [];
        
        try {
            $tablesToDrop = DB::connection($this->warehouseConnectionName)->select("
                SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?
            ", ["{$connectionName}__%"]);

            foreach ($tablesToDrop as $table) {
                Schema::connection($this->warehouseConnectionName)->dropIfExists($table->tablename);
                $droppedTables[] = $table->tablename;
            }

            return response()->json([
                'status' => 'success',
                'message' => "Datasource '{$connectionName}' and its associated tables have been deleted from the warehouse.",
                'deleted_tables' => $droppedTables,
                'deleted_count' => count($droppedTables)
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    }

    private function performEtlProcess(array $validated)
    {
        config(["database.connections.{$validated['connection_name']}" => [
            'driver' => 'pgsql', 'host' => $validated['host'], 'port' => $validated['port'],
            'database' => $validated['database'], 'username' => $validated['username'],
            'password' => $validated['password'], 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public',
        ]]);
        
        $sourceConnection = $validated['connection_name'];
        $warehouseConnection = $this->warehouseConnectionName;
    
        try {
            $this->optimizeConnections($sourceConnection, $warehouseConnection);
            
            DB::connection($sourceConnection)->getPdo();
            $tables = DB::connection($sourceConnection)->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'");
            $processedTables = [];

            foreach ($tables as $table) {
                $tableName = $table->table_name;
                $startTime = microtime(true);
                
                $columns = DB::connection($sourceConnection)->select("
                    SELECT column_name, data_type, is_nullable, column_default, 
                           character_maximum_length, numeric_precision, numeric_scale, ordinal_position,
                           CASE WHEN data_type IN ('date', 'timestamp', 'timestamptz', 'time', 'timetz') THEN true ELSE false END as is_date_type,
                           CASE WHEN data_type IN ('integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision', 'smallint') THEN true ELSE false END as is_numeric_type 
                    FROM information_schema.columns 
                    WHERE table_name = ? 
                    ORDER BY ordinal_position
                ", [$tableName]);

                if (empty($columns)) continue;

                $primaryKeyColumns = $this->getPrimaryKeyColumns($sourceConnection, $tableName);
                $warehouseTable = $sourceConnection . '__' . $tableName;
                
                Schema::connection($warehouseConnection)->create($warehouseTable, function (Blueprint $table) use ($columns, $primaryKeyColumns) {
                    foreach ($columns as $col) {
                        $this->addColumnWithProperType($table, $col);
                    }
                    $table->timestamp('_etl_created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                    $table->timestamp('_etl_updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                });

                $totalRows = $this->bulkTransferData($sourceConnection, $warehouseConnection, $tableName, $warehouseTable, collect($columns)->pluck('column_name')->toArray());
                
                $this->addConstraintsAndIndexes($warehouseConnection, $warehouseTable, $primaryKeyColumns, $columns);
                
                $endTime = microtime(true);
                $processingTime = round($endTime - $startTime, 2);

                $processedTables[] = [
                    'source_table' => $tableName, 
                    'warehouse_table' => $warehouseTable, 
                    'columns_count' => count($columns),
                    'rows_count' => $totalRows, 
                    'primary_key_columns' => $primaryKeyColumns,
                    'processing_time_seconds' => $processingTime,
                    'rows_per_second' => $totalRows > 0 ? round($totalRows / max($processingTime, 0.001)) : 0,
                    'date_columns' => collect($columns)->where('is_date_type', true)->pluck('column_name')->toArray(),
                    'numeric_columns' => collect($columns)->where('is_numeric_type', true)->pluck('column_name')->toArray()
                ];
            }
            
            return response()->json([
                'status' => 'success', 
                'message' => 'ETL berhasil dijalankan dari koneksi: ' . $sourceConnection, 
                'processed_tables' => $processedTables, 
                'total_tables' => count($processedTables),
                'total_rows' => collect($processedTables)->sum('rows_count'),
                'average_speed' => collect($processedTables)->where('rows_per_second', '>', 0)->avg('rows_per_second')
            ]);
        } catch (\Exception $e) {
            Log::error("ETL Error: " . $e->getMessage(), [
                'connection' => $sourceConnection, 
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error', 'message' => 'ETL gagal: ' . $e->getMessage()], 500);
        }
    }

    private function optimizeConnections($sourceConnection, $warehouseConnection)
    {
        try {
            DB::connection($sourceConnection)->statement("SET work_mem = '256MB'");
            DB::connection($sourceConnection)->statement("SET maintenance_work_mem = '512MB'");
            
            DB::connection($warehouseConnection)->statement("SET synchronous_commit = OFF");
            DB::connection($warehouseConnection)->statement("SET wal_buffers = '16MB'");
            DB::connection($warehouseConnection)->statement("SET checkpoint_completion_target = 0.9");
            DB::connection($warehouseConnection)->statement("SET work_mem = '256MB'");
            DB::connection($warehouseConnection)->statement("SET maintenance_work_mem = '1GB'");
            DB::connection($warehouseConnection)->statement("SET shared_buffers = '256MB'");
        } catch (\Exception $e) {
            Log::warning("Failed to optimize connections: " . $e->getMessage());
        }
    }

    private function bulkTransferData($sourceConnection, $warehouseConnection, $sourceTable, $warehouseTable, $columnNames)
    {
        $totalRows = 0;
        $currentTime = now();
        
        $this->disableConstraints($warehouseConnection, $warehouseTable);
        
        try {
            DB::connection($warehouseConnection)->beginTransaction();
            
            DB::connection($sourceConnection)
                ->table($sourceTable)
                ->orderBy($columnNames[0] ?? 'id')
                ->chunk($this->chunkSize, function ($chunk) use ($warehouseConnection, $warehouseTable, $columnNames, &$totalRows, $currentTime) {
                    $batchData = [];
                    
                    foreach ($chunk as $row) {
                        $insertData = [];
                        foreach ($columnNames as $colName) {
                            $insertData[$colName] = $row->$colName ?? null;
                        }
                        $insertData['_etl_created_at'] = $currentTime;
                        $insertData['_etl_updated_at'] = $currentTime;
                        
                        $batchData[] = $insertData;
                        
                        if (count($batchData) >= $this->batchSize) {
                            DB::connection($warehouseConnection)->table($warehouseTable)->insert($batchData);
                            $totalRows += count($batchData);
                            $batchData = [];
                        }
                    }
                    
                    if (!empty($batchData)) {
                        DB::connection($warehouseConnection)->table($warehouseTable)->insert($batchData);
                        $totalRows += count($batchData);
                    }
                });
                
            DB::connection($warehouseConnection)->commit();
            
        } catch (\Exception $e) {
            DB::connection($warehouseConnection)->rollBack();
            throw $e;
        } finally {
            $this->enableConstraints($warehouseConnection, $warehouseTable);
        }
        
        return $totalRows;
    }

    private function bulkRefreshTable($sourceConnection, $warehouseConnection, $sourceTable, $warehouseTable)
    {
        $columnNames = Schema::connection($sourceConnection)->getColumnListing($sourceTable);
        return $this->bulkTransferData($sourceConnection, $warehouseConnection, $sourceTable, $warehouseTable, $columnNames);
    }

    private function disableConstraints($connectionName, $tableName)
    {
        try {
            DB::connection($connectionName)->statement("ALTER TABLE \"{$tableName}\" DISABLE TRIGGER ALL");
        } catch (\Exception $e) {
            Log::warning("Failed to disable constraints for {$tableName}: " . $e->getMessage());
        }
    }

    private function enableConstraints($connectionName, $tableName)
    {
        try {
            DB::connection($connectionName)->statement("ALTER TABLE \"{$tableName}\" ENABLE TRIGGER ALL");
        } catch (\Exception $e) {
            Log::warning("Failed to enable constraints for {$tableName}: " . $e->getMessage());
        }
    }

    private function addConstraintsAndIndexes($connectionName, $tableName, $primaryKeyColumns, $columns)
    {
        try {
            if (!empty($primaryKeyColumns)) {
                $pkColumns = implode('", "', $primaryKeyColumns);
                DB::connection($connectionName)->statement("ALTER TABLE \"{$tableName}\" ADD PRIMARY KEY (\"{$pkColumns}\")");
            }
            
            $this->createOptimalIndexes($tableName, $columns, $connectionName);
            
        } catch (\Exception $e) {
            Log::warning("Failed to add constraints/indexes for {$tableName}: " . $e->getMessage());
        }
    }

    private function getPrimaryKeyColumns($connectionName, $tableName)
    {
        $pkQuery = "
            SELECT kcu.column_name 
            FROM information_schema.table_constraints AS tc 
            JOIN information_schema.key_column_usage AS kcu 
                ON tc.constraint_name = kcu.constraint_name 
                AND tc.table_schema = kcu.table_schema 
            WHERE tc.constraint_type = 'PRIMARY KEY' 
                AND tc.table_schema = 'public' 
                AND tc.table_name = ?
            ORDER BY kcu.ordinal_position
        ";
        
        $primaryKeyResults = DB::connection($connectionName)->select($pkQuery, [$tableName]);
        return collect($primaryKeyResults)->pluck('column_name')->toArray();
    }

    public function fetchColumnMetadata($tableName)
    {
        try {
            $columns = DB::connection($this->warehouseConnectionName)->select("
                SELECT column_name, data_type, is_nullable, column_default, 
                       character_maximum_length, numeric_precision, numeric_scale, ordinal_position,
                       CASE WHEN data_type IN ('date', 'timestamp', 'timestamptz', 'time', 'timetz', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset') THEN true ELSE false END as is_date_type,
                       CASE WHEN data_type IN ('integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision', 'smallint', 'float', 'money') THEN true ELSE false END as is_numeric_type,
                       CASE WHEN data_type IN ('text', 'varchar', 'char', 'character varying', 'character') THEN true ELSE false END as is_text_type 
                FROM information_schema.columns 
                WHERE table_name = ? 
                ORDER BY ordinal_position
            ", [$tableName]);
            
            return response()->json([
                'success' => true, 
                'data' => $columns, 
                'summary' => [
                    'total_columns' => count($columns), 
                    'date_columns' => collect($columns)->where('is_date_type', true)->count(), 
                    'numeric_columns' => collect($columns)->where('is_numeric_type', true)->count(), 
                    'text_columns' => collect($columns)->where('is_text_type', true)->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function addColumnWithProperType(Blueprint $table, $columnInfo)
    {
        $colName = $columnInfo->column_name;
        $dataType = strtolower($columnInfo->data_type);
        $isNullable = $columnInfo->is_nullable === 'YES';

        try {
            switch ($dataType) {
                case 'smallint': case 'int2': $column = $table->smallInteger($colName); break;
                case 'integer': case 'int': case 'int4': $column = $table->integer($colName); break;
                case 'bigint': case 'int8': $column = $table->bigInteger($colName); break;
                case 'numeric': case 'decimal': 
                    $precision = $columnInfo->numeric_precision ?? 10; 
                    $scale = $columnInfo->numeric_scale ?? 2; 
                    $column = $table->decimal($colName, $precision, $scale); 
                    break;
                case 'real': case 'float4': $column = $table->float($colName); break;
                case 'double precision': case 'float8': $column = $table->double($colName); break;
                case 'money': $column = $table->decimal($colName, 19, 4); break;
                case 'date': $column = $table->date($colName); break;
                case 'time': case 'time without time zone': $column = $table->time($colName); break;
                case 'timetz': case 'time with time zone': $column = $table->timeTz($colName); break;
                case 'timestamp': case 'timestamp without time zone': $column = $table->timestamp($colName); break;
                case 'timestamptz': case 'timestamp with time zone': $column = $table->timestampTz($colName); break;
                case 'boolean': case 'bool': $column = $table->boolean($colName); break;
                case 'json': $column = $table->json($colName); break;
                case 'jsonb': $column = $table->jsonb($colName); break;
                case 'uuid': $column = $table->uuid($colName); break;
                case 'inet': $column = $table->ipAddress($colName); break;
                case 'character varying': case 'varchar': 
                    $maxLength = $columnInfo->character_maximum_length; 
                    if ($maxLength && $maxLength <= 255) { 
                        $column = $table->string($colName, $maxLength); 
                    } else { 
                        $column = $table->text($colName); 
                    } 
                    break;
                case 'character': case 'char': 
                    $maxLength = $columnInfo->character_maximum_length ?? 255; 
                    $column = $table->char($colName, $maxLength); 
                    break;
                case 'text': default: $column = $table->text($colName);
            }
            if ($isNullable) { $column->nullable(); }
        } catch (\Exception $e) {
            Log::warning("Failed to map column type for {$colName} ({$dataType}), falling back to text", ['error' => $e->getMessage()]);
            $column = $table->text($colName);
            if ($isNullable) { $column->nullable(); }
        }
    }

    private function createOptimalIndexes($tableName, $columns, $connectionName)
    {
        try {
            foreach ($columns as $col) {
                $colName = $col->column_name;
                
                if ($col->is_date_type) {
                    DB::connection($connectionName)->statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS \"idx_{$tableName}_{$colName}_date\" ON \"{$tableName}\" (\"{$colName}\")");
                }
                if ($col->is_numeric_type) {
                    DB::connection($connectionName)->statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS \"idx_{$tableName}_{$colName}_num\" ON \"{$tableName}\" (\"{$colName}\")");
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to create indexes for table {$tableName}: " . $e->getMessage());
        }
    }

    public function getWarehouseStats()
    {
        $warehouseConnection = $this->warehouseConnectionName;
        try {
            $warehouseTables = DB::connection($warehouseConnection)->select("
                SELECT tablename as table_name, schemaname as schema_name 
                FROM pg_tables 
                WHERE schemaname = 'public' AND tablename LIKE '%__%' 
                ORDER BY tablename
            ");
            
            $stats = [];
            foreach ($warehouseTables as $table) {
                $tableName = $table->table_name;
                $rowCount = DB::connection($warehouseConnection)->table($tableName)->count();
                $columns = DB::connection($warehouseConnection)->select("
                    SELECT COUNT(*) as total_columns,
                           COUNT(CASE WHEN data_type IN ('date', 'timestamp', 'timestamptz', 'time') THEN 1 END) as date_columns,
                           COUNT(CASE WHEN data_type IN ('integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision') THEN 1 END) as numeric_columns 
                    FROM information_schema.columns 
                    WHERE table_name = ?
                ", [$tableName]);
                
                $stats[] = [
                    'table_name' => $tableName, 
                    'connection_name' => explode('__', $tableName)[0], 
                    'source_table' => explode('__', $tableName)[1] ?? '', 
                    'row_count' => $rowCount, 
                    'total_columns' => $columns[0]->total_columns ?? 0, 
                    'date_columns' => $columns[0]->date_columns ?? 0, 
                    'numeric_columns' => $columns[0]->numeric_columns ?? 0
                ];
            }
            
            return response()->json([
                'status' => 'success', 
                'warehouse_stats' => $stats, 
                'summary' => [
                    'total_tables' => count($stats), 
                    'total_rows' => collect($stats)->sum('row_count'), 
                    'connections' => collect($stats)->pluck('connection_name')->unique()->values()->toArray()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to get warehouse stats: ' . $e->getMessage()], 500);
        }
    }
}
