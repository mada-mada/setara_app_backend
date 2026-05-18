<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Google\Cloud\Core\GeoPoint;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    protected $firebase;

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    public function store()
    {
        try {
            $placeRef = $this->firebase->db()->collection('places')->document();

            $placeRef->set([
                'admin_id' => 'user_id_dummy_123',
                'name' => 'Ethos Coffee Lab',
                'description' => 'Restoran inklusif dengan pelayanan ramah disabilitas.',
                'image_url' => 'https://link-foto.com/ethos.jpg',
                'coordinates' => new GeoPoint(-6.345, 108.324), 
                'has_audio_support' => true,
                'has_haptic_support' => false,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Data tempat dummy berhasil disimpan!',
                'place_id' => $placeRef->id()
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function storeMenu($placeId)
    {
        try {
            $db = $this->firebase->db();
            
            // Masuk ke sub-koleksi: places/{placeId}/menus
            $menuRef = $db->collection('places')->document($placeId)->collection('menus')->document();

            $menuRef->set([
                'category_name' => 'Makanan', // Pengganti tabel menu_categories
                'name' => 'Truffle Mushroom Pasta',
                'description' => 'Komposisi atau detail hidangan pasta saus krim.',
                'price' => 95000,
                'image_url' => 'https://link-foto.com/pasta.jpg',
                'is_available' => true,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Data menu dummy berhasil disimpan ke dalam sub-koleksi!',
                'menu_id' => $menuRef->id()
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

  
    public function index()
    {
        try {
            $places = $this->firebase->db()->collection('places')->documents();
            $data = [];

            foreach ($places as $place) {
                if ($place->exists()) {
                    $placeData = $place->data();
                    
                    // Ambil koordinat agar bisa dibaca dengan mudah di JSON response
                    if (isset($placeData['coordinates']) && $placeData['coordinates'] instanceof GeoPoint) {
                        $placeData['coordinates'] = [
                            'latitude' => $placeData['coordinates']->latitude(),
                            'longitude' => $placeData['coordinates']->longitude(),
                        ];
                    }

                    // Ambil data sub-koleksi menu milik tempat ini
                    $menus = $place->reference()->collection('menus')->documents();
                    $menuList = [];
                    foreach ($menus as $menu) {
                        if ($menu->exists()) {
                            $menuList[] = array_merge(['id' => $menu->id()], $menu->data());
                        }
                    }

                    $placeData['id'] = $place->id();
                    $placeData['menus'] = $menuList; // Gabungkan menu ke dalam tempat
                    $data[] = $placeData;
                }
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}