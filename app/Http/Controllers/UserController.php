<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    protected $firebase;

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'email' => 'required|email',
                'role' => 'required|string|in:super_admin,resto_admin,user',
                'assist_preference' => 'nullable|string|in:audio,visual',
            ]);

            $userRef = $this->firebase->db()->collection('users')->newDocument();
            
            $dataToSave = [
                'name' => $request->name,
                'email' => $request->email,
                'role' => $request->role,
                'assist_preference' => $request->assist_preference ?? null,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];

            $userRef->set($dataToSave);

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Data user berhasil disimpan!',
                'user_id' => $userRef->id()
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // 2. Menguji Ambil Semua Data User
    public function index()
    {
        try {
            $users = $this->firebase->db()->collection('users')->documents();
            $data = [];

            foreach ($users as $user) {
                if ($user->exists()) {
                    $data[] = array_merge(['id' => $user->id()], $user->data());
                }
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}