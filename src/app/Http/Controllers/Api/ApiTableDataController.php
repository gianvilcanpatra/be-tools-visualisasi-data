<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiTableDataController extends Controller
{
    public function getTableDetails($table)
    {
        try {
            // Periksa apakah tabel ada di database
            $tableExists = DB::select("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public' AND table_name = ?
            ", [$table]);

            if (empty($tableExists)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tabel '{$table}' tidak ditemukan di database.",
                ], 404);
            }

            // Mengambil kolom-kolom yang ada pada tabel
            $columns = DB::select("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_schema = 'public' AND table_name = ?
            ", [$table]);

            $columnNames = array_map(fn($col) => $col->column_name, $columns);

            // Membuat data dimensi dan kolom
            $data = [
                'table' => $table,
                'dimensions' => [
                    'dimensi' => $columnNames, // dimensi sebagai daftar kolom di tabel
                ]
            ];

            return response()->json([
                'success' => true,
                'message' => "Detail tabel '{$table}' berhasil diambil.",
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getTableData(Request $request, $table)
    {
        try {
            $columns = $request->query('columns', []);

            if (empty($columns)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kolom tidak boleh kosong.',
                ], 400);
            }

            // Periksa apakah tabel ada di database
            $tableExists = DB::select("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public' AND table_name = ?
            ", [$table]);

            if (empty($tableExists)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tabel '{$table}' tidak ditemukan di database.",
                ], 404);
            }

            // Ambil data dari tabel berdasarkan kolom yang dipilih
            $data = DB::table($table)->select($columns)->get();

            return response()->json([
                'success' => true,
                'message' => "Data berhasil diambil dari tabel '{$table}'.",
                'data' => $data,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
