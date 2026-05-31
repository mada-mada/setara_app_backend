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
}