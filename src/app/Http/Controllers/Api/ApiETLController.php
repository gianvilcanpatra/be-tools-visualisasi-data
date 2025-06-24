<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Datasource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class ApiETLController extends Controller
{
    private $warehouseConnectionName;
    private $batchSize = 1000;
    private $chunkSize = 5000;

    public function __construct()
    {
        $this->warehouseConnectionName = 'pgsql2';
    }

    private function getDatasourceConnectionConfig(string $connectionName): array
    {
        $datasource = Datasource::where('name', $connectionName)->where('is_deleted', false)->firstOrFail();
        
        return [
            'driver'   => $datasource->type,
            'host'     => $datasource->host,
            'port'     => $datasource->port,
            'database' => $datasource->database_name,
            'username' => $datasource->username,
            'password' => $datasource->password,
            'charset'  => 'utf8',
            'prefix'   => '',
        ];
    }

    public function connectDatasource(Request $request)
    {
        $warehouseConnection = $this->warehouseConnectionName;

        $validated = $request->validate([
            'id_project' => 'nullable|integer',
            'driver' => 'required|string|in:pgsql,mysql,sqlsrv',
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
        $validated = $request->validate(['connection_name' => 'required|string|alpha_dash']);
        $sourceConnectionName = $validated['connection_name'];

        try {
            $connectionConfig = $this->getDatasourceConnectionConfig($sourceConnectionName);
            config(["database.connections.{$sourceConnectionName}" => $connectionConfig]);

            $warehouseConnection = DB::connection($this->warehouseConnectionName);
            $sourceConnection = DB::connection($sourceConnectionName);
            $schema = $this->getSourceSchema($sourceConnection);
            $tables = $this->getSourceTables($sourceConnection, $schema);
            $refreshedTables = [];

            foreach ($tables as $table) {
                $tableName = $table->table_name;
                $warehouseTable = $sourceConnectionName . '__' . $tableName;

                if (!Schema::connection($warehouseConnection->getName())->hasTable($warehouseTable)) {
                    continue;
                }

                $this->disableConstraints($warehouseConnection, $warehouseTable, 'pgsql');
                $warehouseConnection->table($warehouseTable)->truncate();
                $totalRows = $this->bulkRefreshTable($sourceConnection, $warehouseConnection, $tableName, $warehouseTable);
                $this->enableConstraints($warehouseConnection, $warehouseTable, 'pgsql');

                $refreshedTables[] = [
                    'source_table' => $tableName,
                    'warehouse_table' => $warehouseTable,
                    'rows_refreshed' => $totalRows
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Data warehouse berhasil diperbarui dari sumber: ' . $sourceConnectionName,
                'refreshed_tables' => $refreshedTables
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Refresh gagal: ' . $e->getMessage()], 500);
        } finally {
            DB::disconnect($sourceConnectionName);
        }
    }

    public function fullRefresh(Request $request)
    {
        $validated = $request->validate(['connection_name' => 'required|string|alpha_dash']);
        $connectionName = $validated['connection_name'];

        try {
            $connectionConfig = $this->getDatasourceConnectionConfig($connectionName);
            
            $tablesToDrop = DB::connection($this->warehouseConnectionName)->select("
                SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?
            ", ["{$connectionName}__%"]);

            foreach ($tablesToDrop as $table) {
                Schema::connection($this->warehouseConnectionName)->dropIfExists($table->tablename);
            }
            
            $etlPayload = array_merge($connectionConfig, ['connection_name' => $connectionName]);
            return $this->performEtlProcess($etlPayload);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Full Refresh gagal: ' . $e->getMessage()], 500);
        }
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

            $datasource = Datasource::where('name', $connectionName)->first();
            if ($datasource) {
                $datasource->is_deleted = true;
                $datasource->modified_time = now();
                $datasource->modified_by = Auth::id() ?? 1;
                $datasource->save();
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
        $sourceConnectionName = $validated['connection_name'];
        $driver = $validated['driver'];
        
        config(["database.connections.{$sourceConnectionName}" => [
            'driver' => $driver, 'host' => $validated['host'], 'port' => $validated['port'],
            'database' => $validated['database'], 'username' => $validated['username'],
            'password' => $validated['password'], 'charset' => 'utf8', 'prefix' => '',
        ]]);

        $warehouseConnection = DB::connection($this->warehouseConnectionName);

        try {
            $sourceConnection = DB::connection($sourceConnectionName);
            $sourceConnection->getPdo();
            
            $this->optimizeConnections($sourceConnection, $warehouseConnection, $driver, 'pgsql');
            
            $schema = $this->getSourceSchema($sourceConnection);
            $tables = $this->getSourceTables($sourceConnection, $schema);
            $processedTables = [];

            foreach ($tables as $table) {
                $tableName = $table->table_name;
                $startTime = microtime(true);

                $columns = $this->getSourceColumns($sourceConnection, $tableName, $schema);
                if (empty($columns)) continue;

                $primaryKeyColumns = $this->getSourcePrimaryKeyColumns($sourceConnection, $tableName, $schema);
                $warehouseTable = $sourceConnectionName . '__' . $tableName;

                Schema::connection($warehouseConnection->getName())->create($warehouseTable, function (Blueprint $table) use ($columns, $driver) {
                    foreach ($columns as $col) {
                        $this->addColumnWithProperType($table, $col, $driver);
                    }
                    $table->timestamp('_etl_created_at')->useCurrent();
                    $table->timestamp('_etl_updated_at')->useCurrent();
                });

                $warehouseColumnsInfo = $this->getWarehouseColumnsInfo($warehouseTable);

                $totalRows = $this->bulkTransferData($sourceConnection, $warehouseConnection, $tableName, $warehouseTable, collect($columns)->pluck('column_name')->toArray(), $warehouseColumnsInfo);
                $this->addConstraintsAndIndexes($warehouseConnection, $warehouseTable, $primaryKeyColumns, $columns);
                
                $endTime = microtime(true);
                $processingTime = round($endTime - $startTime, 2);

                $processedTables[] = [
                    'source_table' => $tableName, 'warehouse_table' => $warehouseTable,
                    'columns_count' => count($columns), 'rows_count' => $totalRows,
                    'primary_key_columns' => $primaryKeyColumns, 'processing_time_seconds' => $processingTime,
                    'rows_per_second' => $totalRows > 0 ? round($totalRows / max($processingTime, 0.001)) : 0,
                ];
            }

            $datasource = Datasource::firstOrNew(['name' => $sourceConnectionName, 'id_project' => $validated['id_project'] ?? 1]);
            $datasource->fill([
                'type'            => $driver,
                'host'            => $validated['host'],
                'port'            => $validated['port'],
                'database_name'   => $validated['database'],
                'username'        => $validated['username'],
                'password'        => $validated['password'],
                'modified_by'     => Auth::id() ?? 1,
                'modified_time'   => now(),
                'is_deleted'      => false
            ]);

            if (!$datasource->exists) {
                $datasource->created_by = Auth::id() ?? 1;
                $datasource->created_time = now();
            }
            $datasource->save();

            return response()->json([
                'status' => 'success', 'message' => 'ETL berhasil dijalankan dari koneksi: ' . $sourceConnectionName,
                'processed_tables' => $processedTables, 'total_tables' => count($processedTables),
                'total_rows' => collect($processedTables)->sum('rows_count'),
            ]);
        } catch (\Exception $e) {
            Log::error("ETL Error: " . $e->getMessage(), ['connection' => $sourceConnectionName, 'trace' => $e->getTraceAsString()]);
            DB::disconnect($sourceConnectionName);
            return response()->json(['status' => 'error', 'message' => 'ETL gagal: ' . $e->getMessage()], 500);
        } finally {
            DB::disconnect($sourceConnectionName);
        }
    }
    
    private function optimizeConnections($sourceConnection, $warehouseConnection, $sourceDriver, $warehouseDriver)
    {
        try {
            if ($sourceDriver === 'pgsql') {
                $sourceConnection->statement("SET work_mem = '256MB'");
            }
            if ($warehouseDriver === 'pgsql') {
                $warehouseConnection->statement("SET synchronous_commit = OFF");
                $warehouseConnection->statement("SET work_mem = '256MB'");
                $warehouseConnection->statement("SET maintenance_work_mem = '1GB'");
            }
        } catch (\Exception $e) {
            Log::warning("Gagal melakukan optimasi koneksi: " . $e->getMessage());
        }
    }

    private function bulkTransferData($sourceConnection, $warehouseConnection, $sourceTable, $warehouseTable, $columnNames, $warehouseColumnsInfo)
    {
        $totalRows = 0;
        $currentTime = now()->toDateTimeString();
        $sourceDriver = $sourceConnection->getDriverName();

        $this->disableConstraints($warehouseConnection, $warehouseTable, 'pgsql');
        $this->disableConstraints($sourceConnection, $sourceTable, $sourceDriver);

        try {
            $sourceConnection->table($sourceTable)
                ->orderBy(DB::raw('1'))
                ->chunk($this->chunkSize, function ($chunk) use ($warehouseConnection, $warehouseTable, &$totalRows, $currentTime, $warehouseColumnsInfo) {
                    $dataToInsert = [];
                    foreach ($chunk as $row) {
                        $insertData = [];
                        $sourceRowArray = (array)$row;

                        foreach ($sourceRowArray as $key => $value) {
                             $columnInfo = $warehouseColumnsInfo[$key] ?? null;
                            if ($value === null) {
                                $insertData[$key] = ($columnInfo && $columnInfo['is_nullable']) ? null : $this->getDefaultValueForType($columnInfo['type'] ?? 'text');
                                continue;
                            }

                            if(is_string($value) && trim($value) === ''){
                                $insertData[$key] = ($columnInfo && $columnInfo['is_nullable']) ? null : $this->getDefaultValueForType($columnInfo['type'] ?? 'text');
                                continue;
                            }
                            
                            $insertData[$key] = $value;
                        }

                        $insertData['_etl_created_at'] = $currentTime;
                        $insertData['_etl_updated_at'] = $currentTime;
                        $dataToInsert[] = $insertData;
                    }

                    if (!empty($dataToInsert)) {
                        $warehouseConnection->beginTransaction();
                        try {
                            foreach (array_chunk($dataToInsert, $this->batchSize) as $insertBatch) {
                                $warehouseConnection->table($warehouseTable)->insert($insertBatch);
                            }
                            $warehouseConnection->commit();
                            $totalRows += count($dataToInsert);
                        } catch (\Exception $e) {
                            $warehouseConnection->rollBack();
                            throw $e;
                        }
                    }
                });
        } finally {
            $this->enableConstraints($warehouseConnection, $warehouseTable, 'pgsql');
            $this->enableConstraints($sourceConnection, $sourceTable, $sourceDriver);
        }
        return $totalRows;
    }

    private function getDefaultValueForType(string $type)
    {
        $type = strtolower($type);
        if (str_contains($type, 'int') || str_contains($type, 'numeric') || str_contains($type, 'decimal') || str_contains($type, 'double') || str_contains($type, 'real')) {
            return 0;
        }
        if (str_contains($type, 'uuid')) {
            return '00000000-0000-0000-0000-000000000000';
        }
        if (str_contains($type, 'date') || str_contains($type, 'timestamp')) {
            return '1970-01-01 00:00:00';
        }
        if (str_contains($type, 'bool')) {
            return false;
        }
        return 'Tidak Diketahui';
    }

    private function bulkRefreshTable($sourceConnection, $warehouseConnection, $sourceTable, $warehouseTable)
    {
        $columnNames = Schema::connection($sourceConnection->getName())->getColumnListing($sourceTable);
        $warehouseColumnsInfo = $this->getWarehouseColumnsInfo($warehouseTable);
        return $this->bulkTransferData($sourceConnection, $warehouseConnection, $sourceTable, $warehouseTable, $columnNames, $warehouseColumnsInfo);
    }

    private function getWarehouseColumnsInfo(string $tableName): array
    {
        $columns = DB::connection($this->warehouseConnectionName)->select(
            "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?",
            [$tableName]
        );

        $info = [];
        foreach ($columns as $column) {
            $info[$column->column_name] = [
                'type' => $column->data_type,
                'is_nullable' => strtoupper($column->is_nullable) === 'YES',
            ];
        }
        return $info;
    }

    private function disableConstraints($connection, $tableName, $driver)
    {
        try {
            switch ($driver) {
                case 'pgsql': $connection->statement("ALTER TABLE \"{$tableName}\" DISABLE TRIGGER ALL"); break;
                case 'mysql': $connection->statement("SET FOREIGN_KEY_CHECKS=0;"); break;
                case 'sqlsrv': $connection->statement("ALTER TABLE \"{$tableName}\" NOCHECK CONSTRAINT ALL"); break;
            }
        } catch (\Exception $e) {
            Log::warning("Gagal menonaktifkan constraint untuk {$tableName}: " . $e->getMessage());
        }
    }

    private function enableConstraints($connection, $tableName, $driver)
    {
        try {
            switch ($driver) {
                case 'pgsql': $connection->statement("ALTER TABLE \"{$tableName}\" ENABLE TRIGGER ALL"); break;
                case 'mysql': $connection->statement("SET FOREIGN_KEY_CHECKS=1;"); break;
                case 'sqlsrv': $connection->statement("ALTER TABLE \"{$tableName}\" CHECK CONSTRAINT ALL"); break;
            }
        } catch (\Exception $e) {
            Log::warning("Gagal mengaktifkan constraint untuk {$tableName}: " . $e->getMessage());
        }
    }
    
    private function addConstraintsAndIndexes($connection, $tableName, $primaryKeyColumns, $columns)
    {
        try {
            if (!empty($primaryKeyColumns)) {
                Schema::connection($connection->getName())->table($tableName, function (Blueprint $table) use ($primaryKeyColumns) {
                    $table->primary($primaryKeyColumns);
                });
            }
            $this->createOptimalIndexes($connection, $tableName, $columns);
        } catch (\Exception $e) {
            Log::warning("Gagal menambahkan constraint/index untuk {$tableName}: " . $e->getMessage());
        }
    }

    private function getSourceSchema($connection)
    {
        return match ($connection->getDriverName()) {
            'mysql' => $connection->select('SELECT DATABASE() as dbname')[0]->dbname,
            'sqlsrv' => 'dbo',
            'pgsql' => 'public',
            default => 'public',
        };
    }

    private function getSourceTables($connection, $schema)
    {
        return $connection->select("
            SELECT table_name FROM information_schema.tables 
            WHERE table_schema = ? AND table_type = 'BASE TABLE'
        ", [$schema]);
    }
    
    private function getSourceColumns($connection, $tableName, $schema)
    {
        $query = "SELECT column_name, data_type, is_nullable, character_maximum_length, numeric_precision, numeric_scale 
                  FROM information_schema.columns WHERE table_name = ? AND table_schema = ? ORDER BY ordinal_position";
        return $connection->select($query, [$tableName, $schema]);
    }

    private function getSourcePrimaryKeyColumns($connection, $tableName, $schema)
    {
        $pkQuery = "
            SELECT kcu.column_name 
            FROM information_schema.table_constraints AS tc 
            JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema 
            WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ? AND tc.table_name = ?
            ORDER BY kcu.ordinal_position";
        $results = $connection->select($pkQuery, [$schema, $tableName]);
        return collect($results)->pluck('column_name')->toArray();
    }

    private function addColumnWithProperType(Blueprint $table, $columnInfo, $sourceDriver)
    {
        $colName = $columnInfo->column_name;
        $dataType = strtolower($columnInfo->data_type);
        $isNullable = in_array(strtoupper($columnInfo->is_nullable), ['YES', 'TRUE', '1']);

        try {
            $column = null;
            switch ($dataType) {
                case 'int': case 'integer': case 'int4': $column = $table->integer($colName); break;
                case 'bigint': case 'int8': $column = $table->bigInteger($colName); break;
                case 'smallint': case 'int2': $column = $table->smallInteger($colName); break;
                case 'tinyint': $column = $table->tinyInteger($colName); break;
                case 'mediumint': $column = $table->mediumInteger($colName); break;
                case 'numeric': case 'decimal': case 'dec':
                    $precision = $columnInfo->numeric_precision ?? 18;
                    $scale = $columnInfo->numeric_scale ?? 2;
                    $column = $table->decimal($colName, $precision, $scale);
                    break;
                case 'money': case 'smallmoney': $column = $table->decimal($colName, 19, 4); break;
                case 'real': case 'float4': $column = $table->float($colName); break;
                case 'float': $column = $table->float($colName); break;
                case 'double precision': case 'float8': case 'double': $column = $table->double($colName); break;
                case 'date': $column = $table->date($colName); break;
                case 'time': case 'time without time zone': $column = $table->time($colName); break;
                case 'timetz': case 'time with time zone': $column = $table->timeTz($colName); break;
                case 'timestamp': case 'timestamp without time zone': case 'datetime': case 'datetime2': case 'smalldatetime': $column = $table->timestamp($colName, 0); break;
                case 'timestamptz': case 'timestamp with time zone': case 'datetimeoffset': $column = $table->timestampTz($colName, 0); break;
                case 'year': $column = $table->year($colName); break;
                case 'boolean': case 'bool': case 'bit': $column = $table->boolean($colName); break;
                case 'json': $column = $table->json($colName); break;
                case 'jsonb': $column = $table->jsonb($colName); break;
                case 'uuid': case 'uniqueidentifier': $column = $table->uuid($colName); break;
                case 'inet': $column = $table->ipAddress($colName); break;
                case 'macaddr': $column = $table->macAddress($colName); break;
                case 'bytea': case 'binary': case 'varbinary': case 'blob': case 'tinyblob': case 'mediumblob': case 'longblob': $column = $table->binary($colName); break;
                case 'character varying': case 'varchar': case 'nvarchar':
                    $maxLength = $columnInfo->character_maximum_length;
                    $column = $maxLength > 0 && $maxLength <= 255 ? $table->string($colName, $maxLength) : $table->text($colName);
                    break;
                case 'character': case 'char': case 'nchar':
                    $maxLength = $columnInfo->character_maximum_length ?? 1;
                    $column = $table->char($colName, $maxLength);
                    break;
                case 'text': case 'ntext': case 'tinytext': case 'mediumtext': case 'longtext': case 'clob': case 'enum':
                    $column = $table->text($colName);
                    break;
                default: $column = $table->text($colName); break;
            }

            if ($isNullable) { $column->nullable(); }
        } catch (\Exception $e) {
            Log::warning("Gagal memetakan tipe kolom {$colName} ({$dataType}), menggunakan tipe text", ['error' => $e->getMessage()]);
            $fallbackColumn = $table->text($colName);
            if ($isNullable) { $fallbackColumn->nullable(); }
        }
    }
    
    private function createOptimalIndexes($connection, $tableName, $columns)
    {
        $driver = $connection->getDriverName();
        $concurrently = $driver === 'pgsql' ? 'CONCURRENTLY' : '';
        
        try {
            foreach ($columns as $col) {
                $colName = $col->column_name;
                $dataType = strtolower($col->data_type);
                $isDateType = in_array($dataType, ['date', 'timestamp', 'timestamptz', 'datetime', 'datetime2']);
                $isNumericType = in_array($dataType, ['integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision', 'int', 'smallint']);

                if (str_contains(strtolower($colName), 'id') || $isDateType || $isNumericType) {
                    $idxName = "idx_{$tableName}_{$colName}";
                    if(strlen($idxName) > 63) {
                        $idxName = substr($idxName, 0, 58) . substr(md5($idxName), 0, 4);
                    }
                    $connection->statement("CREATE INDEX {$concurrently} IF NOT EXISTS \"{$idxName}\" ON public.\"{$tableName}\" (\"{$colName}\")");
                }
            }
        } catch (\Exception $e) {
            Log::warning("Gagal membuat index untuk tabel {$tableName}: " . $e->getMessage());
        }
    }
}