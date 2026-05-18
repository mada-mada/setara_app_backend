<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Illuminate\Http\Request;

class OrderController extends Controller
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
                'user_id' => 'required|string',
                'place_id' => 'required|string',
                'total_amount' => 'required|numeric',
                'status' => 'required|string|in:pending,processing,completed,cancelled',
                'items' => 'required|array',
                'items.*.menu_id' => 'required|string',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.subtotal' => 'required|numeric',
            ]);

            $orderRef = $this->firebase->db()->collection('orders')->newDocument();

            $dataToSave = [
                'user_id' => $request->user_id,
                'place_id' => $request->place_id,
                'total_amount' => (float) $request->total_amount,
                'status' => $request->status,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
                'items' => $request->items,
            ];

            $orderRef->set($dataToSave);

            return response()->json([
                'status' => 'Sukses',
                'message' => 'Data order berhasil dibuat!',
                'order_id' => $orderRef->id()
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'Gagal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // 2. Ambil Semua Data Order
    public function index()
    {
        try {
            $orders = $this->firebase->db()->collection('orders')->documents();
            $data = [];

            foreach ($orders as $order) {
                if ($order->exists()) {
                    $data[] = array_merge(['id' => $order->id()], $order->data());
                }
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}