<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    protected $firebase;

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    // 1. Menyimpan Menu Baru (Create)
    public function store(Request $request, $placeId)
    {
        try {
            $request->validate([
                'category_name' => 'required|string',
                'name'          => 'required|string',
                'description'   => 'nullable|string',
                'price'         => 'required|numeric',
                'is_available'  => 'required|boolean',
                'image'         => 'nullable|image|max:5120',
            ]);

            $db = $this->firebase->db();
            $placeRef = $db->collection('places')->document($placeId);
            
            if (!$placeRef->snapshot()->exists()) {
                return response()->json([
                    'status' => 'Gagal',
                    'message' => 'Restoran tidak ditemukan!'
                ], 404);
            }

            $imageUrl = '';
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('menus', 'public');
                $imageUrl = url('storage/' . $path);
            }

            $dataToSave = [
                'category_name' => $request->category_name,
                'name'          => $request->name,
                'description'   => $request->description ?? '',
                'price'         => (int) $request->price,
                'is_available'  => (bool) $request->is_available,
                'image_url'     => $imageUrl,
                'created_at'    => now()->toDateTimeString(),
                'updated_at'    => now()->toDateTimeString(),
            ];

            // Menyimpan dengan newDocument()
            $menuRef = $placeRef->collection('menus')->newDocument();
            $menuRef->set($dataToSave);

            return response()->json([
                'status'  => 'Sukses',
                'message' => 'Menu baru berhasil ditambahkan!',
                'menu_id' => $menuRef->id()
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'error'  => $e->getMessage()
            ], 500);
        }
    }

    // 2. Mengambil Semua Menu dari Restoran (Read All)
    public function index($placeId)
    {
        try {
            $db = $this->firebase->db();
            $menus = $db->collection('places')->document($placeId)->collection('menus')->documents();
            
            $data = [];
            foreach ($menus as $menu) {
                if ($menu->exists()) {
                    $data[] = array_merge(['id' => $menu->id()], $menu->data());
                }
            }

            return response()->json([
                'status' => 'Sukses',
                'data'   => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => 'Gagal', 'error' => $e->getMessage()], 500);
        }
    }

    // 3. Mengambil Satu Menu Spesifik (Read One)
    public function show($placeId, $menuId)
    {
        try {
            $menuRef = $this->firebase->db()->collection('places')->document($placeId)->collection('menus')->document($menuId);
            $snapshot = $menuRef->snapshot();

            if (!$snapshot->exists()) {
                return response()->json(['status' => 'Gagal', 'message' => 'Menu tidak ditemukan!'], 404);
            }

            $data = array_merge(['id' => $snapshot->id()], $snapshot->data());

            return response()->json(['status' => 'Sukses', 'data' => $data], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'Gagal', 'error' => $e->getMessage()], 500);
        }
    }

    // 4. Memperbarui Data Menu (Update)
    public function update(Request $request, $placeId, $menuId)
    {
        try {
            $menuRef = $this->firebase->db()->collection('places')->document($placeId)->collection('menus')->document($menuId);
            $snapshot = $menuRef->snapshot();

            if (!$snapshot->exists()) {
                return response()->json(['status' => 'Gagal', 'message' => 'Menu tidak ditemukan!'], 404);
            }

            $request->validate([
                'category_name' => 'required|string',
                'name'          => 'required|string',
                'description'   => 'nullable|string',
                'price'         => 'required|numeric',
                'is_available'  => 'required|boolean',
                'image'         => 'nullable|image|max:5120',
            ]);

            $dataToUpdate = [
                'category_name' => $request->category_name,
                'name'          => $request->name,
                'description'   => $request->description ?? '',
                'price'         => (int) $request->price,
                'is_available'  => (bool) $request->is_available,
                'updated_at'    => now()->toDateTimeString(),
            ];

            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('menus', 'public');
                $dataToUpdate['image_url'] = url('storage/' . $path);
            }

            // Menggunakan opsi merge agar field lain tetap aman
            $menuRef->set($dataToUpdate, ['merge' => true]);

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Menu berhasil diperbarui!',
                'menu_id' => $menuId
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['status' => 'Gagal', 'error' => $e->getMessage()], 500);
        }
    }

    // 5. Menghapus Menu (Delete)
    public function destroy($placeId, $menuId)
    {
        try {
            $menuRef = $this->firebase->db()->collection('places')->document($placeId)->collection('menus')->document($menuId);
            
            if (!$menuRef->snapshot()->exists()) {
                return response()->json(['status' => 'Gagal', 'message' => 'Menu tidak ditemukan!'], 404);
            }

            $menuRef->delete();

            return response()->json(['status' => 'Sukses', 'message' => 'Menu berhasil dihapus!'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => 'Gagal', 'error' => $e->getMessage()], 500);
        }
    }
}