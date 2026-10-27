<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\OrderStages;
use App\Support\OrderTracking;
use App\Support\OrderTrackingException;
use App\Support\UploadedImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin: the actions on a catalog order's Update progress card. Every rule lives in App\Support\OrderTracking;
 * this controller only validates the form and turns a refusal into a message. Routes sit inside ['admin', 'admin.timeout'].
 */
class OrderTrackingController extends Controller
{
    public function __construct(private OrderTracking $tracking)
    {
    }

    /** Move one stage forward. */
    public function advance(Request $request, $id)
    {
        $data = $request->validate([
            'current_status' => 'required|string|max:20',
            'confirmed'      => 'nullable|in:0,1',
            'message'        => 'nullable|string|max:' . (OrderTracking::MESSAGE_MAX * 2),
            'photo'          => array_merge(['nullable'], UploadedImage::rules()),
        ]);

        return $this->run($id, function () use ($id, $data, $request) {
            $order = $this->tracking->advance(
                (int) $id, Auth::guard('admin')->user(), $data['current_status'],
                ($data['confirmed'] ?? '0') === '1', $data['message'] ?? null, $request->file('photo')
            );

            return 'Order moved to ' . OrderStages::labelFor($order) . '. Customer notified.';
        });
    }

    /** A message and/or photo for the customer, no stage change. */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'current_status' => 'required|string|max:20',
            'message'        => 'nullable|string|max:' . (OrderTracking::MESSAGE_MAX * 2),
            'photo'          => array_merge(['nullable'], UploadedImage::rules()),
        ]);

        return $this->run($id, function () use ($id, $data, $request) {
            $this->tracking->sendUpdate((int) $id, Auth::guard('admin')->user(), $data['current_status'], $data['message'] ?? null, $request->file('photo'));

            return 'Update sent. The stage did not change.';
        });
    }

    /** One stage back, with a reason; the customer is not told. */
    public function correct(Request $request, $id)
    {
        $data = $request->validate([
            'current_status' => 'required|string|max:20',
            'confirmed'      => 'nullable|in:0,1',
            'reason'         => 'nullable|string|max:' . (OrderTracking::REASON_MAX * 2),
        ]);

        return $this->run($id, function () use ($id, $data) {
            if (($data['confirmed'] ?? '0') !== '1') {
                throw new OrderTrackingException('Tick the confirmation box to continue.');
            }
            $order = $this->tracking->correct((int) $id, Auth::guard('admin')->user(), $data['current_status'], $data['reason'] ?? null);

            return 'Order moved back to ' . OrderStages::labelFor($order) . '. Customer was not notified.';
        });
    }

    public function cancel(Request $request, $id)
    {
        $data = $request->validate([
            'current_status' => 'required|string|max:20',
            'confirmed'      => 'nullable|in:0,1',
            'reason'         => 'nullable|string|max:' . (OrderTracking::REASON_MAX * 2),
        ]);

        return $this->run($id, function () use ($id, $data) {
            if (($data['confirmed'] ?? '0') !== '1') {
                throw new OrderTrackingException('Tick the confirmation box to continue.');
            }
            $this->tracking->cancel((int) $id, Auth::guard('admin')->user(), $data['current_status'], $data['reason'] ?? null);

            return 'Order cancelled. Customer notified.';
        });
    }

    private function run($id, \Closure $action)
    {
        \App\Models\Order::findOrFail($id);

        try {
            $message = $action();
        } catch (OrderTrackingException $e) {
            return redirect()->route('admin.orders.show', $id)->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.orders.show', $id)->with('success', $message);
    }
}
