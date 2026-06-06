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

    public function update(Request $request, $uid)
    {
        try {
            $request->validate([
                'name'             => 'required|string',
                'email'            => 'required|email',
                'password'         => 'nullable|string|min:8',
                'role'             => 'required|string|in:super_admin,resto_admin,user',
                'cafe_name'        => 'required_if:role,resto_admin|nullable|string',
                'assist_preference' => 'nullable|string|in:audio,visual',
            ]);

            // 1. Cek apakah user ada di Firebase Auth
            try {
                $userRecord = $this->firebaseAuth->getUser($uid);
            } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
                return response()->json([
                    'status'  => 'Gagal',
                    'message' => 'User tidak ditemukan.',
                ], 404);
            }

            // 2. Update Firebase Auth (email, displayName, password jika diisi)
            $authData = [
                'displayName' => $request->name,
                'email'       => $request->email,
            ];
            if (!empty($request->password)) {
                $authData['password'] = $request->password;
            }
            $this->firebaseAuth->updateUser($uid, $authData);

            // 3. Cari document user di Firestore
            $userRef = $this->firebase->db()->collection('users')->document($uid);
            $userSnap = $userRef->snapshot();

            $placeId = null;
            if ($userSnap->exists()) {
                $userData = $userSnap->data();
                $placeId = $userData['place_id'] ?? null;
            }

            // 4. Update / Buat data place jika role-nya resto_admin
            if ($request->role === 'resto_admin') {
                if ($placeId) {
                    // Update place yang ada
                    $placeRef = $this->firebase->db()->collection('places')->document($placeId);
                    $placeRef->update([
                        ['path' => 'name', 'value' => $request->cafe_name],
                        ['path' => 'updated_at', 'value' => now()->toDateTimeString()],
                    ]);
                } else {
                    // Buat place baru jika sebelumnya belum ada placeId
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
            }

            // 5. Update data di Firestore
            $userRef->set([
                'name'             => $request->name,
                'email'            => $request->email,
                'role'             => $request->role,
                'cafe_name'        => $request->cafe_name ?? null,
                'place_id'         => $placeId,
                'assist_preference' => $request->assist_preference ?? null,
                'updated_at'       => now()->toDateTimeString(),
            ], ['merge' => true]);

            return response()->json([
                'status'   => 'Sukses',
                'message'  => 'Akun berhasil diperbarui.',
                'uid'      => $uid,
                'email'    => $request->email,
                'role'     => $request->role,
                'cafe_name' => $request->cafe_name ?? null,
            ], 200);

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

    public function destroy($uid)
    {
        try {
            // 1. Cek & hapus di Firebase Auth
            try {
                $this->firebaseAuth->deleteUser($uid);
            } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
                // Lanjut jika user tidak ada di Auth, barangkali hanya ada di Firestore
            }

            // 2. Cari data user di Firestore untuk mendapatkan place_id jika resto_admin
            $userRef = $this->firebase->db()->collection('users')->document($uid);
            $userSnap = $userRef->snapshot();

            if ($userSnap->exists()) {
                $userData = $userSnap->data();
                $placeId = $userData['place_id'] ?? null;

                // 3. Hapus place jika ada
                if ($placeId) {
                    $this->firebase->db()->collection('places')->document($placeId)->delete();
                }

                // 4. Hapus data user di Firestore
                $userRef->delete();
            }

            return response()->json([
                'status'  => 'Sukses',
                'message' => 'User berhasil dihapus.',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'error'  => $e->getMessage(),
            ], 500);
        }
    }
}