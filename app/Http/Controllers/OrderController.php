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

            // Notify Admin
            $users = $this->firebase->db()->collection('users')->where('place_id', '=', $request->place_id)->where('role', '=', 'resto_admin')->documents();
            $messaging = $this->firebase->messaging();
            foreach ($users as $user) {
                $userData = $user->data();
                if (isset($userData['fcm_token'])) {
                    $message = \Kreait\Firebase\Messaging\CloudMessage::new()
                        ->withToken($userData['fcm_token'])
                        ->withNotification(\Kreait\Firebase\Messaging\Notification::create('Pesanan Baru', 'Anda mendapatkan order baru!'));
                    try {
                        $messaging->send($message);
                    } catch (\Exception $e) {}
                }
            }

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

    // 3. Update Status Order
    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|string|in:pending,accepted,completed,cancelled'
            ]);

            $orderRef = $this->firebase->db()->collection('orders')->document($id);
            if (!$orderRef->snapshot()->exists()) {
                return response()->json(['error' => 'Order tidak ditemukan'], 404);
            }

            $orderData = $orderRef->snapshot()->data();
            $orderRef->update([
                ['path' => 'status', 'value' => $request->status],
                ['path' => 'updated_at', 'value' => now()->toDateTimeString()]
            ]);

            // Notify User if status is accepted
            if ($request->status === 'accepted') {
                $userDoc = $this->firebase->db()->collection('users')->document($orderData['user_id'])->snapshot();
                if ($userDoc->exists()) {
                    $userData = $userDoc->data();
                    if (isset($userData['fcm_token'])) {
                        $messaging = $this->firebase->messaging();
                        $message = \Kreait\Firebase\Messaging\CloudMessage::new()
                            ->withToken($userData['fcm_token'])
                            ->withNotification(\Kreait\Firebase\Messaging\Notification::create('Order Diterima', 'Order Anda telah diterima dan siap untuk diantar!'));
                        try {
                            $messaging->send($message);
                        } catch (\Exception $e) {}
                    }
                }
            }

            return response()->json(['status' => 'Sukses', 'message' => 'Status order diperbarui']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}