<?php

namespace App\Services;

use Google\Cloud\Firestore\FirestoreClient;

class FirebaseService
{
    protected $firestore;

    public function __construct()
    {
        // 1. Path ke file JSON (Pastikan namanya sesuai dengan file kamu)
        $path = storage_path('app/setara-app-ab081-firebase-adminsdk-fbsvc-129ec6d4c2.json');

        if (!file_exists($path)) {
            throw new \Exception("File konfigurasi Firebase tidak ditemukan di: " . $path);
        }

        // 2. Baca isi file untuk mengambil Project ID
        $credentials = json_decode(file_get_contents($path), true);

       
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $path);

        // 4. Inisialisasi FirestoreClient dengan mode REST (HTTP)
        $this->firestore = new FirestoreClient([
            'projectId' => $credentials['project_id'],
            'transport' => 'rest'
        ]);
    }

    /**
     * Mendapatkan instance database Firestore
     */
    public function db()
    {
        return $this->firestore;
    }
    
    /**
     * Mendapatkan instance Firebase Messaging
     */
    public function messaging()
    {
        $path = storage_path('app/setara-app-ab081-firebase-adminsdk-fbsvc-129ec6d4c2.json');
        $factory = (new \Kreait\Firebase\Factory)->withServiceAccount($path);
        return $factory->createMessaging();
    }
    
    /**
     * Contoh fungsi tambahan untuk mempermudah penulisan data
     */
    public function setDocument(string $collection, string $documentId, array $data)
    {
        return $this->firestore->collection($collection)->document($documentId)->set($data);
    }
}