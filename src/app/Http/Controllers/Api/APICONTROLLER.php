<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Datasource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ApiWarehouseReaderController extends Controller
{
    public function fetchTables($idDatasource)
    {
        try {
            // Ambil konfigurasi database dari tabel `datasources`
            // $staticId = 1;
            $datasource = Datasource::find($idDatasource);

            if (!$datasource) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datasource tidak ditemukan.'
                ], 404);
            }

            // Set konfigurasi koneksi dinamis
            Config::set("database.connections.{$datasource->name}", [
                'driver'    => $datasource->type,
                'host'      => $datasource->host,
                'port'      => $datasource->port,
                'database'  => $datasource->database_name,
                'username'  => $datasource->username,
                'password'  => $datasource->password,
                'charset'   => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix'    => '',
                'schema'    => $datasource->type === 'pgsql' ? 'public' : null,
            ]);

            // Purge & connect ke database
            DB::purge($datasource->name);
            DB::connection($datasource->name)->getPdo();

            // Ambil daftar tabel
            $tables = $this->getTablesFromDatabase($datasource->name, $datasource->type);

            // Kelompokkan tabel berdasarkan prefix
            $groupedTables = $this->groupTablesByPrefix($tables);

            return response()->json([
                'success' => true,
                'data' => [
                    'tables' => $tables,
                    'grouped_tables' => $groupedTables,
                    'total_tables' => count($tables),
                    'total_groups' => count($groupedTables)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar tabel: ' . $e->getMessage()
            ], 500);
        }
    }

    private function groupTablesByPrefix($tables)
    {
        $grouped = [];
        
        foreach ($tables as $table) {
            // Cek apakah tabel mengandung '__'
            if (strpos($table, '__') !== false) {
                // Ambil prefix sebelum '__'
                $prefix = explode('__', $table)[0];
                $tableName = explode('__', $table, 2)[1]; // Ambil nama setelah '__'
                
                // Kelompokkan berdasarkan prefix
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
                // Tabel tanpa '__' masuk ke grup 'ungrouped'
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
        
        // Urutkan grup berdasarkan nama prefix
        ksort($grouped);
        
        // Tambahkan jumlah tabel per grup
        foreach ($grouped as $prefix => &$group) {
            $group['table_count'] = count($group['tables']);
        }
        
        return $grouped;
    }

    private function getTablesFromDatabase($connectionName, $dbType)
    {
        try {
            $tables = [];

            if ($dbType === 'pgsql') {
                $tables = DB::connection($connectionName)
                    ->select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
                $tables = array_map(fn($t) => $t->tablename, $tables);
            } elseif ($dbType === 'mysql' || $dbType === 'mariadb') {
                $tables = DB::connection($connectionName)->select("SHOW TABLES");
                $tables = array_map(fn($t) => array_values((array) $t)[0], $tables);
            } elseif ($dbType === 'sqlsrv') {
                $tables = DB::connection($connectionName)
                    ->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
                $tables = array_map(fn($t) => $t->TABLE_NAME, $tables);
            }

            return $tables;
        } catch (\Exception $e) {
            throw new \Exception('Gagal mengambil daftar tabel dari database: ' . $e->getMessage());
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

    public function fetchTableColumns($table)
    {
        try {
            // Ambil koneksi database dari datasources
            $idDatasource = 1;
            $dbConfig = $this->getConnectionDetails($idDatasource);

            // Buat koneksi on-the-fly
            config(["database.connections.dynamic" => $dbConfig]);

            // Gunakan koneksi yang baru dibuat
            $connection = DB::connection('dynamic');

            // Periksa apakah tabel ada
            $tableExists = $connection->select("
            SELECT table_name FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = ?
        ", [$table]);

            if (empty($tableExists)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tabel '{$table}' tidak ditemukan di database."
                ], 404);
            }

            // Ambil daftar kolom
            $columns = $connection->select("
            SELECT column_name, data_type, is_nullable, ordinal_position
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ?
        ", [$table]);

            $formattedColumns = array_map(function ($column) {
                return [
                    'id'       => $column->ordinal_position,
                    'name'     => $column->column_name,
                    'type'     => $column->data_type,
                    'nullable' => $column->is_nullable === 'YES',
                ];
            }, $columns);

            return response()->json([
                'success' => true,
                'message' => "Daftar kolom berhasil diambil dari tabel '{$table}'.",
                'data'    => $formattedColumns,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar kolom.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // public function connectDatasource(Request $request)
    // {
    //     try {
    //         // Validasi input
    //         $request->validate([
    //             'name' => 'required|string|max:255',
    //             'type' => 'required|string|max:8',
    //             'host' => 'required|string|max:255',
    //             'port' => 'required|integer',
    //             'database_name' => 'required|string|max:255',
    //             'username' => 'required|string|max:255',
    //             'password' => 'required|string|max:255',
    //         ]);

    //         // Simpan koneksi ke database
    //         $datasource = Datasource::create([
    //             'id_project'    => 1,
    //             'name'          => $request->name,
    //             'type'          => strtolower($request->type),
    //             'host'          => $request->host,
    //             'port'          => $request->port,
    //             'database_name' => $request->database_name,
    //             'username'      => $request->username,
    //             'password'      => $request->password,
    //             'created_by'    => $request->createdBy || 1,
    //             'created_time'  => now(),
    //             'is_deleted'    => 0
    //         ]);

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Database telah ditambahkan',
    //             'data' => $datasource
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Gagal menyimpan koneksi database: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }
}
