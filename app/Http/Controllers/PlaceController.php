<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Google\Cloud\Core\GeoPoint;
use Illuminate\Http\Request;

class PlaceController extends Controller
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
                'admin_id' => 'required|string',
                'name' => 'required|string',
                'description' => 'nullable|string',
                'image_url' => 'nullable|image|mimes:jpeg,png,jpg|max:2048', 
                'has_audio_support' => 'required|boolean',
                'has_haptic_support' => 'required|boolean',
            ]);

            $imageUrlToSave = '';

            // 2. PROSES UPLOAD KE LAPTOP LOKAL
            if ($request->hasFile('image_url')) {
                $file = $request->file('image_url');
                $path = $file->store('places', 'public'); 
                $imageUrlToSave = asset('storage/' . $path);
            }

            // 3. SIMPAN TEKS & URL GAMBAR KE FIRESTORE
            $placeRef = $this->firebase->db()->collection('places')->newDocument();

            $dataToSave = [
                'admin_id' => $request->admin_id,
                'name' => $request->name,
                'description' => $request->description ?? '',
                'image_url' => $imageUrlToSave, 
                'has_audio_support' => (bool) $request->has_audio_support,
                'has_haptic_support' => (bool) $request->has_haptic_support,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];

            $placeRef->set($dataToSave);

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Data tempat berhasil disimpan!',
                'place_id' => $placeRef->id(),
                'image_url_lokal' => $imageUrlToSave
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $db = $this->firebase->db();
            $placeRef = $db->collection('places')->document($id);

            // 1. Pastikan data restoran yang mau di-edit memang ada
            $snapshot = $placeRef->snapshot();
            if (!$snapshot->exists()) {
                return response()->json([
                    'status' => 'Gagal',
                    'message' => 'Data tempat tidak ditemukan!'
                ], 404);
            }

            // 2. Validasi inputan baru
            
            $request->validate([
                'name' => 'required|string',
                'description' => 'nullable|string',
                'image_url' => 'nullable|image|mimes:jpeg,png,jpg|max:2048', 
                'has_audio_support' => 'required|boolean',
                'has_haptic_support' => 'required|boolean',
            ]);

            // Ambil data lama untuk berjaga-jaga (terutama untuk URL gambar lama)
            $oldData = $snapshot->data();
            $imageUrlToSave = $oldData['image_url'] ?? '';

            // 3. Cek apakah admin mengunggah file foto yang baru
            if ($request->hasFile('image_url')) {
                $file = $request->file('image_url');
                $path = $file->store('places', 'public');
                
                // Timpa URL lama dengan URL lokal yang baru
                $imageUrlToSave = asset('storage/' . $path);
            }

            // 4. Siapkan data yang diperbarui
            $dataToUpdate = [
                'name' => $request->name,
                'description' => $request->description ?? '',
                'image_url' => $imageUrlToSave, // Pakai gambar baru atau pertahankan gambar lama
                'has_audio_support' => (bool) $request->has_audio_support,
                'has_haptic_support' => (bool) $request->has_haptic_support,
                'updated_at' => now()->toDateTimeString(), // Waktu update diperbarui
            ];

            // 5. Simpan ke Firestore dengan opsi MERGE
            // Merge = hanya mengubah field yang ada di $dataToUpdate, sisanya (seperti admin_id, created_at, dll) dibiarkan utuh.
            $placeRef->set($dataToUpdate, ['merge' => true]);

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Data tempat berhasil diperbarui!',
                'place_id' => $id,
                'image_url' => $imageUrlToSave
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'error' => $e->getMessage()
            ], 500);
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

    
    public function destroy($id)
    {
        try {
            $placeRef = $this->firebase->db()->collection('places')->document($id);

            // Cek apakah data restorannya ada di Firestore
            if (!$placeRef->snapshot()->exists()) {
                return response()->json([
                    'status' => 'Gagal', 
                    'message' => 'Data tempat tidak ditemukan!'
                ], 404);
            }

            // 1. Bersihkan dulu semua dokumen di dalam sub-koleksi 'menus'
            $menus = $placeRef->collection('menus')->documents();
            foreach ($menus as $menu) {
                if ($menu->exists()) {
                    $menu->reference()->delete();
                }
            }

            // 2. Hapus dokumen restoran utama
            $placeRef->delete();

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Data tempat beserta seluruh menunya berhasil dihapus!'
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}