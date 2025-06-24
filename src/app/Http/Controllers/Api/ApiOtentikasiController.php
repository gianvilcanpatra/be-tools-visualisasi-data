<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApiOtentikasiController extends Controller
{
    public function registerUser(Request $request)
    {
        try{
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi Gagal',
                    'errors' => $validator->errors()->all()
                ], 422);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;
            $projectAccess = ProjectAccess::where('id_user', $user->id_user)->first();
            $accessData = $projectAccess ? $projectAccess->access : 'none';

            return response()->json([
                'success' => true,
                'message' => 'Data User berhasil disimpan',
                'data' => [
                    'user' => [
                        'id_user' => $user->id_user,
                        'name' => $user->name,
                        'email' => $user->email,
                        'created_time' => $user->created_time,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'access' => $accessData
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Registration failed ' . $e->getMessage()
            ], 500);
        }
    }

    public function loginUser(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            if ($validator->fails())
            {
                return response()->json([
                    'success' => false,
                    'message' => 'Login Gagal, silahkan coba lagi',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('email', $request->email)
                        ->where('is_deleted', false)
                        ->first();

            if (!$user || !Hash::check($request->password, $user->password))
            {
                return response()->json([
                    'success' => false,
                    'message' => 'Password salah, silahkan coba lagi'
                ], 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            $projectAccess = ProjectAccess::where('id_user', $user->id_user)->first();
            $accessData = $projectAccess ? $projectAccess->access : 'none';

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil',
                'data' => [
                    'user' => [
                        'id_user' => $user->id_user,
                        'name' => $user->name,
                        'email' => $user->email
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'access' => $accessData
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                    'success' => false,
                    'message' => 'Login gagal, silahkan coba lagi ' . $e->getMessage()
            ], 500);
        }
    }

    public function logoutUser(Request $request)
    {
        try {
            // Menghapus token yang sedang digunakan
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Berhasil logout',
            ], 200);
        } catch (\Exception $e) {
            // Catat error untuk debug jika diperlukan
            Log::error('Logout Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal logout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getUser()
    {
        try {
            // Ambil semua user yang tidak dihapus
            $users = DB::table('users')
                ->select('id_user', 'name', 'email')
                ->where('is_deleted', false)
                ->get();

            $usersWithAccess = [];

            foreach ($users as $user) {
                // Ambil semua akses project untuk user ini
                $projectsAccess = DB::table('projects_access')
                    ->select('id_project', 'access')
                    ->where('id_user', $user->id_user)
                    ->get();

                $usersWithAccess[] = [
                    'id_user' => $user->id_user,
                    'name' => $user->name,
                    'email' => $user->email,
                    'projects_access' => $projectsAccess->toArray()
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Data users berhasil diambil.',
                'data' => $usersWithAccess,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data users.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Method untuk update akses user
    public function updateAccess(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_user' => 'required|integer|exists:users,id_user',
                'id_project' => 'required|integer',
                'access' => 'required|string|in:view,edit,admin,none',
                'created_by' => 'required|string|exists:users,name',
                'modified_by' => 'required|string|exists:users,name',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->id_user;
            $projectId = $request->id_project;
            $access = $request->access;
            $createdBy = $request->created_by;
            $modifiedBy = $request->modified_by;

            // Jika access adalah 'none', hapus record dari projects_access
            if ($access === 'none') {
                DB::table('projects_access')
                    ->where('id_user', $userId)
                    ->where('id_project', $projectId)
                    ->delete();
            } else {
                // Cek apakah record sudah ada
                $existingAccess = DB::table('projects_access')
                    ->where('id_user', $userId)
                    ->where('id_project', $projectId)
                    ->first();

                if ($existingAccess) {
                    // Update record yang sudah ada
                    DB::table('projects_access')
                        ->where('id_user', $userId)
                        ->where('id_project', $projectId)
                        ->update([
                            'access' => $access,
                            'modified_time' => now(),
                            'modified_by' => $modifiedBy
                        ]);
                } else {
                    // Insert record baru
                    DB::table('projects_access')->insert([
                        'id_user' => $userId,
                        'id_project' => $projectId,
                        'access' => $access,
                        'created_by' => $createdBy,
                        'created_time' => now(),
                        'modified_by' => $modifiedBy,
                        'modified_time' => now()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Akses user berhasil diperbarui',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui akses user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Method lama untuk backward compatibility
    public function getEmails()
    {
        return $this->getUser();
    }
}