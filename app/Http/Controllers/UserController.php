<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;

class UserController extends Controller
{
    protected $firebase;
    protected $firebaseAuth;

    public function __construct(FirebaseService $firebase, FirebaseAuth $firebaseAuth)
    {
        $this->firebase = $firebase;
        $this->firebaseAuth = $firebaseAuth;
    }

    /**
     * Buat akun admin/super_admin via Postman.
     * Mendaftarkan ke Firebase Auth terlebih dahulu agar bisa login,
     * kemudian menyimpan profil ke Firestore dengan UID sebagai document ID.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name'             => 'required|string',
                'email'            => 'required|email',
                'password'         => 'required|string|min:8',
                'role'             => 'required|string|in:super_admin,resto_admin,user',
                'cafe_name'        => 'required_if:role,resto_admin|nullable|string',
                'assist_preference' => 'nullable|string|in:audio,visual',
            ]);

            // 1. Cek apakah email sudah terdaftar di Firebase Auth
            try {
                $this->firebaseAuth->getUserByEmail($request->email);
                return response()->json([
                    'status'  => 'Gagal',
                    'message' => 'Email sudah terdaftar.',
                ], 400);
            } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
                // Lanjut — email belum terdaftar
            }

            // 2. Buat akun di Firebase Auth (inilah yang memungkinkan login)
            $userRecord = $this->firebaseAuth->createUser([
                'email'         => $request->email,
                'emailVerified' => true,          // Admin tidak perlu verifikasi email
                'password'      => $request->password,
                'displayName'   => $request->name,
            ]);

            $uid = $userRecord->uid;

            // 3. Jika resto_admin, buat place secara otomatis
            $placeId = null;
            if ($request->role === 'resto_admin') {
                $placeRef = $this->firebase->db()->collection('places')->newDocument();
                $placeId = $placeRef->id();
                $placeRef->set([
                    'admin_id'           => $uid,
                    'name'               => $request->cafe_name,
                    'description'        => '',
                    'image_url'          => '',
                    'has_audio_support'  => true,
                    'has_haptic_support' => true,
                    'created_at'         => now()->toDateTimeString(),
                    'updated_at'         => now()->toDateTimeString(),
                ]);
            }

            // 4. Simpan profil ke Firestore (UID sebagai document ID agar sinkron dengan Auth)
            $this->firebase->db()
                ->collection('users')
                ->document($uid)
                ->set([
                    'name'             => $request->name,
                    'email'            => $request->email,
                    'role'             => $request->role,
                    'cafe_name'        => $request->cafe_name ?? null,
                    'place_id'         => $placeId,
                    'assist_preference' => $request->assist_preference ?? null,
                    'created_at'       => now()->toDateTimeString(),
                    'updated_at'       => now()->toDateTimeString(),
                ]);

            return response()->json([
                'status'   => 'Sukses',
                'message'  => 'Akun berhasil dibuat dan bisa digunakan untuk login.',
                'uid'      => $uid,
                'email'    => $request->email,
                'role'     => $request->role,
                'cafe_name' => $request->cafe_name ?? null,
            ], 201);

        } catch (\Kreait\Firebase\Exception\Auth\EmailExists $e) {
            return response()->json([
                'status'  => 'Gagal',
                'message' => 'Email sudah terdaftar.',
            ], 400);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'Gagal',
                'message' => 'Validasi gagal.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'error'  => $e->getMessage(),
            ], 500);
        }
    }

    // Ambil semua data user dari Firestore
    public function index()
    {
        try {
            $users = $this->firebase->db()->collection('users')->documents();
            $data  = [];

            foreach ($users as $user) {
                if ($user->exists()) {
                    $userData = $user->data();
                    // Jangan tampilkan field sensitif
                    unset($userData['password']);
                    $data[] = array_merge(['uid' => $user->id()], $userData);
                }
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}