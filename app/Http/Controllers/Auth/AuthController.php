<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use App\Services\FirebaseService;
use GuzzleHttp\Client; // Import Guzzle

class AuthController extends Controller
{
    protected $firebaseAuth;
    protected $firebaseDb;

    public function __construct(FirebaseAuth $firebaseAuth, FirebaseService $firebaseDb)
    {
        $this->firebaseAuth = $firebaseAuth;
        $this->firebaseDb = $firebaseDb;
    }

    public function googleLogin(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        try {
            // 1. Verifikasi ID Token via Google API (Tanpa butuh google/apiclient)
            $client = new Client();
            $response = $client->get('https://oauth2.googleapis.com/tokeninfo', [
                'query' => [
                    'id_token' => $request->id_token
                ]
            ]);

            $payload = json_decode($response->getBody(), true);

            // Validasi: Pastikan Client ID cocok
            // Masukkan WEB_CLIENT_ID Anda di sini atau lewat .env
            $expectedAudience = env('GOOGLE_CLIENT_ID'); 
            if ($payload['aud'] !== $expectedAudience) {
                return response()->json(['message' => 'Client ID tidak cocok!'], 401);
            }

            // Ambil data profil
            $uid = $payload['sub']; // ID unik Google
            $email = $payload['email'];
            $name = $payload['name'] ?? 'User';

            // 2. Cek/Simpan user ke Firebase Auth (agar email masuk ke Identifier Firebase Auth)
            try {
                $this->firebaseAuth->getUser($uid);
            } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
                // Daftarkan user ke Firebase Auth agar email & namanya terdaftar di Firebase Console
                $this->firebaseAuth->createUser([
                    'uid' => $uid,
                    'email' => $email,
                    'emailVerified' => true,
                    'displayName' => $name,
                ]);
            }

            // 3. Cek/Simpan user ke Firestore
            $userRef = $this->firebaseDb->db()->collection('users')->document($uid);
            $snapshot = $userRef->snapshot();

            if (!$snapshot->exists()) {
                $userRef->set([
                    'name' => $name,
                    'email' => $email,
                    'role' => 'user',
                    'created_at' => now()->toDateTimeString(),
                ]);
            }

            // 3. Buat Custom Token Firebase
            $customToken = $this->firebaseAuth->createCustomToken($uid);

            return response()->json([
                'status' => 'Sukses',
                'custom_token' => $customToken->toString(),
                'user' => ['uid' => $uid, 'email' => $email, 'name' => $name]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'message' => 'Autentikasi token gagal',
                'error' => $e->getMessage()
            ], 401);
        }
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
        ]);

        try {
            $name = $request->name;
            $email = $request->email;
            $password = $request->password;

            // 1. Cek apakah user sudah terdaftar di Firebase Auth
            try {
                $this->firebaseAuth->getUserByEmail($email);
                return response()->json([
                    'status' => 'Gagal',
                    'message' => 'Email sudah terdaftar.',
                ], 400);
            } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
                // Lanjut jika user belum terdaftar
            }

            // 2. Daftarkan user ke Firebase Auth
            $userRecord = $this->firebaseAuth->createUser([
                'email' => $email,
                'emailVerified' => false,
                'password' => $password,
                'displayName' => $name,
            ]);

            $uid = $userRecord->uid;

            // 3. Simpan data user ke Firestore
            $userRef = $this->firebaseDb->db()->collection('users')->document($uid);
            $userRef->set([
                'name' => $name,
                'email' => $email,
                'role' => 'user',
                'created_at' => now()->toDateTimeString(),
            ]);

            // 4. Buat Custom Token Firebase
            $customToken = $this->firebaseAuth->createCustomToken($uid);

            return response()->json([
                'status' => 'Sukses',
                'custom_token' => $customToken->toString(),
                'user' => [
                    'uid' => $uid,
                    'email' => $email,
                    'name' => $name
                ]
            ], 201);

        } catch (\Kreait\Firebase\Exception\Auth\EmailExists $e) {
            return response()->json([
                'status' => 'Gagal',
                'message' => 'Email sudah terdaftar.',
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'message' => 'Registrasi gagal.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        try {
            $email = $request->email;
            $password = $request->password;

            // 1. Verifikasi kredensial via signInWithEmailAndPassword
            try {
                $signInResult = $this->firebaseAuth->signInWithEmailAndPassword($email, $password);
                $uid = $signInResult->firebaseUserId();
            } catch (\Kreait\Firebase\Exception\Auth\InvalidPassword $e) {
                return response()->json([
                    'status' => 'Gagal',
                    'message' => 'Kata sandi salah.',
                ], 401);
            } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
                return response()->json([
                    'status' => 'Gagal',
                    'message' => 'Email tidak ditemukan.',
                ], 404);
            } catch (\Exception $e) {
                $errMessage = $e->getMessage();
                if (str_contains(strtolower($errMessage), 'invalid_login_credentials') || 
                    str_contains(strtolower($errMessage), 'invalid_credential')) {
                    return response()->json([
                        'status' => 'Gagal',
                        'message' => 'Email atau kata sandi salah.',
                    ], 401);
                }
                throw $e;
            }

            // 2. Ambil data user dari Firestore
            $userRef = $this->firebaseDb->db()->collection('users')->document($uid);
            $snapshot = $userRef->snapshot();
            
            $name     = 'User';
            $role     = 'user';
            $cafeName = null;
            $placeId  = null;

            if ($snapshot->exists()) {
                $name     = $snapshot->get('name') ?? 'User';
                $role     = $snapshot->get('role') ?? 'user';
                $cafeName = $snapshot->get('cafe_name') ?? null;
                $placeId  = $snapshot->get('place_id') ?? null;
            } else {
                $userRecord = $this->firebaseAuth->getUser($uid);
                $name = $userRecord->displayName ?? 'User';
                $userRef->set([
                    'name'       => $name,
                    'email'      => $email,
                    'role'       => 'user',
                    'created_at' => now()->toDateTimeString(),
                ]);
            }

            // 3. Buat Custom Token Firebase
            $customToken = $this->firebaseAuth->createCustomToken($uid);

            return response()->json([
                'status'       => 'Sukses',
                'custom_token' => $customToken->toString(),
                'user'         => [
                    'uid'       => $uid,
                    'email'     => $email,
                    'name'      => $name,
                    'role'      => $role,
                    'cafe_name' => $cafeName,
                    'place_id'  => $placeId,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'message' => 'Autentikasi gagal.',
                'error' => $e->getMessage()
            ], 401);
        }
    }

    public function logout(Request $request)
    {
        try {
            $idToken = $request->bearerToken();

            if (!$idToken) {
                return response()->json([
                    'status' => 'Gagal',
                    'message' => 'Token autentikasi tidak ditemukan.',
                ], 401);
            }

            $verifiedToken = $this->firebaseAuth->verifyIdToken($idToken);
            $uid = $verifiedToken->claims()->get('sub');

            $this->firebaseAuth->revokeRefreshTokens($uid);

            $this->firebaseDb->db()
                ->collection('users')
                ->document($uid)
                ->set([
                    'last_logout_at' => now()->toDateTimeString(),
                ], ['merge' => true]);

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Logout berhasil.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'message' => 'Logout gagal.',
                'error' => $e->getMessage()
            ], 401);
        }
    }
}
