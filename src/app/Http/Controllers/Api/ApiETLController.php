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
                
                DB::connection($warehouseConnection)->table($warehouseTable)->truncate();
                $data = DB::connection($sourceConnection)->table($tableName)->get();
                $columnNames = Schema::connection($sourceConnection)->getColumnListing($tableName);

                foreach ($data as $row) {
                    $insertData = [];
                    foreach ($columnNames as $col) {
                        $insertData[$col] = $row->$col ?? null;
                    }
                    $insertData['_etl_updated_at'] = now();
                    DB::connection($warehouseConnection)->table($warehouseTable)->insert($insertData);
                }

                $refreshedTables[] = ['source_table' => $tableName, 'warehouse_table' => $warehouseTable, 'rows_refreshed' => $data->count()];
            }

            return response()->json(['status' => 'success', 'message' => 'Data warehouse berhasil diperbarui dari sumber: ' . $sourceConnection, 'refreshed_tables' => $refreshedTables]);
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
        
        // Hapus semua tabel lama dari koneksi ini
        $tablesToDrop = DB::connection($this->warehouseConnectionName)->select("
            SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?
        ", ["{$connectionName}__%"]);

        foreach ($tablesToDrop as $table) {
            Schema::connection($this->warehouseConnectionName)->dropIfExists($table->tablename);
        }

        // Jalankan proses ETL dari awal
        return $this->performEtlProcess($validated);
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
            DB::connection($sourceConnection)->getPdo();
            $tables = DB::connection($sourceConnection)->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'");
            $processedTables = [];

            foreach ($tables as $table) {
                $tableName = $table->table_name;
                $columns = DB::connection($sourceConnection)->select("SELECT column_name, data_type, is_nullable, column_default, character_maximum_length, numeric_precision, numeric_scale, ordinal_position, CASE WHEN data_type IN ('date', 'timestamp', 'timestamptz', 'time', 'timetz') THEN true ELSE false END as is_date_type, CASE WHEN data_type IN ('integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision', 'smallint') THEN true ELSE false END as is_numeric_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position", [$tableName]);

                if (empty($columns)) continue;

                $pkQuery = "SELECT kcu.column_name FROM information_schema.table_constraints AS tc JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public' AND tc.table_name = ?";
                $primaryKeyResult = DB::connection($sourceConnection)->selectOne($pkQuery, [$tableName]);
                $primaryKeyColumn = $primaryKeyResult ? $primaryKeyResult->column_name : null;

                $warehouseTable = $sourceConnection . '__' . $tableName;
                
                Schema::connection($warehouseConnection)->create($warehouseTable, function (Blueprint $table) use ($columns, $primaryKeyColumn) {
                    foreach ($columns as $col) {
                        $this->addColumnWithProperType($table, $col);
                    }
                    $table->timestamp('_etl_created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                    $table->timestamp('_etl_updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                    if ($primaryKeyColumn) {
                        $table->primary($primaryKeyColumn);
                    }
                });

                $this->createOptimalIndexes($warehouseTable, $columns, $warehouseConnection);
                
                $data = DB::connection($sourceConnection)->table($tableName)->get();
                $columnNames = collect($columns)->pluck('column_name')->toArray();

                foreach ($data as $row) {
                    $insertData = [];
                    foreach ($columnNames as $colName) {
                        $insertData[$colName] = $row->$colName ?? null;
                    }
                    DB::connection($warehouseConnection)->table($warehouseTable)->insert($insertData);
                }

                $processedTables[] = [
                    'source_table' => $tableName, 'warehouse_table' => $warehouseTable, 'columns_count' => count($columns),
                    'rows_count' => $data->count(), 'primary_key' => $primaryKeyColumn,
                    'date_columns' => collect($columns)->where('is_date_type', true)->pluck('column_name')->toArray(),
                    'numeric_columns' => collect($columns)->where('is_numeric_type', true)->pluck('column_name')->toArray()
                ];
            }
            return response()->json(['status' => 'success', 'message' => 'ETL berhasil dijalankan dari koneksi: ' . $sourceConnection, 'processed_tables' => $processedTables, 'total_tables' => count($processedTables)]);
        } catch (\Exception $e) {
            Log::error("ETL Error: " . $e->getMessage(), ['connection' => $sourceConnection, 'trace' => $e->getTraceAsString()]);
            return response()->json(['status' => 'error', 'message' => 'ETL gagal: ' . $e->getMessage()], 500);
        }
    }

    public function fetchColumnMetadata($tableName)
    {
        try {
            $columns = DB::connection($this->warehouseConnectionName)->select("SELECT column_name, data_type, is_nullable, column_default, character_maximum_length, numeric_precision, numeric_scale, ordinal_position, CASE WHEN data_type IN ('date', 'timestamp', 'timestamptz', 'time', 'timetz', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset') THEN true ELSE false END as is_date_type, CASE WHEN data_type IN ('integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision', 'smallint', 'float', 'money') THEN true ELSE false END as is_numeric_type, CASE WHEN data_type IN ('text', 'varchar', 'char', 'character varying', 'character') THEN true ELSE false END as is_text_type FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position", [$tableName]);
            return response()->json(['success' => true, 'data' => $columns, 'summary' => ['total_columns' => count($columns), 'date_columns' => collect($columns)->where('is_date_type', true)->count(), 'numeric_columns' => collect($columns)->where('is_numeric_type', true)->count(), 'text_columns' => collect($columns)->where('is_text_type', true)->count()]]);
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
                case 'numeric': case 'decimal': $precision = $columnInfo->numeric_precision ?? 10; $scale = $columnInfo->numeric_scale ?? 2; $column = $table->decimal($colName, $precision, $scale); break;
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
                case 'character varying': case 'varchar': $maxLength = $columnInfo->character_maximum_length; if ($maxLength && $maxLength <= 255) { $column = $table->string($colName, $maxLength); } else { $column = $table->text($colName); } break;
                case 'character': case 'char': $maxLength = $columnInfo->character_maximum_length ?? 255; $column = $table->char($colName, $maxLength); break;
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
            Schema::connection($connectionName)->table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $col) {
                    $colName = $col->column_name;
                    if ($col->is_date_type) { $table->index($colName, "idx_{$colName}_date"); }
                    if ($col->is_numeric_type) { $table->index($colName, "idx_{$colName}_num"); }
                }
            });
        } catch (\Exception $e) {
            Log::warning("Failed to create indexes for table {$tableName}: " . $e->getMessage());
        }
    }

    public function getWarehouseStats()
    {
        $warehouseConnection = $this->warehouseConnectionName;
        try {
            $warehouseTables = DB::connection($warehouseConnection)->select("SELECT tablename as table_name, schemaname as schema_name FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '%__%' ORDER BY tablename");
            $stats = [];
            foreach ($warehouseTables as $table) {
                $tableName = $table->table_name;
                $rowCount = DB::connection($warehouseConnection)->table($tableName)->count();
                $columns = DB::connection($warehouseConnection)->select("SELECT COUNT(*) as total_columns, COUNT(CASE WHEN data_type IN ('date', 'timestamp', 'timestamptz', 'time') THEN 1 END) as date_columns, COUNT(CASE WHEN data_type IN ('integer', 'bigint', 'numeric', 'decimal', 'real', 'double precision') THEN 1 END) as numeric_columns FROM information_schema.columns WHERE table_name = ?", [$tableName]);
                $stats[] = ['table_name' => $tableName, 'connection_name' => explode('__', $tableName)[0], 'source_table' => explode('__', $tableName)[1] ?? '', 'row_count' => $rowCount, 'total_columns' => $columns[0]->total_columns ?? 0, 'date_columns' => $columns[0]->date_columns ?? 0, 'numeric_columns' => $columns[0]->numeric_columns ?? 0];
            }
            return response()->json(['status' => 'success', 'warehouse_stats' => $stats, 'summary' => ['total_tables' => count($stats), 'total_rows' => collect($stats)->sum('row_count'), 'connections' => collect($stats)->pluck('connection_name')->unique()->values()->toArray()]]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to get warehouse stats: ' . $e->getMessage()], 500);
        }
    }
}