<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use App\Services\FirebaseService;

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
            // 2. Verifikasi ID Token Google menggunakan Firebase Auth Admin
            $verifiedIdToken = $this->firebaseAuth->verifyIdToken($request->id_token);
            
            // Ambil data profil dari token Google
            $uid = $verifiedIdToken->claims()->get('sub'); 
            $email = $verifiedIdToken->claims()->get('email');
            $name = $verifiedIdToken->claims()->get('name');

            // 3. Cek apakah user sudah ada di Firestore
            $userRef = $this->firebaseDb->db()->collection('users')->document($uid);
            $snapshot = $userRef->snapshot();

            // Jika user belum ada (pendaftaran pertama kali via Google), simpan ke database
            if (!$snapshot->exists()) {
                $userRef->set([
                    'name' => $name,
                    'email' => $email,
                    'role' => 'user', // Default role
                    'assist_preference' => null, // Disiapkan untuk update preferensi UI (audio/visual) nantinya
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ]);
            }

            // 4. Buat Firebase Custom Token
            $customToken = $this->firebaseAuth->createCustomToken($uid);

            // 5. Kembalikan Custom Token ke Flutter
            return response()->json([
                'status' => 'Sukses',
                'message' => 'Login berhasil',
                'custom_token' => $customToken->toString(),
                'user' => [
                    'uid' => $uid,
                    'email' => $email,
                    'name' => $name
                ]
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