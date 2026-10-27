<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Order {{ $order->order_number }}</title></head>
<body style="margin:0;padding:0;background:#f6f0e6;font-family:Arial,Helvetica,sans-serif;color:#2a1d10;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f0e6;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;padding:28px;">
        <tr><td>
          <p style="margin:0 0 4px;font-size:13px;color:#5f4d39;">LP Furniture · Order {{ $order->order_number }}</p>
          <h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#2a1d10;">
            {{ $kind === 'update' ? 'An update on your order' : 'Your order is now ' . $stageLabel }}
          </h1>
          <p style="margin:0 0 16px;font-size:16px;line-height:1.5;">{{ $stageText }}</p>

          @if($adminMessage)
            <div style="margin:0 0 16px;padding:12px 14px;background:#faf0d7;border-radius:10px;font-size:15px;line-height:1.5;">
              <strong style="display:block;font-size:12px;color:#5f4d39;margin-bottom:4px;">{{ $stage === 'cancelled' ? 'Reason' : 'Message from our team' }}</strong>
              {!! nl2br(e($adminMessage)) !!}
            </div>
          @endif

          @if($photoUrl)
            <p style="margin:0 0 16px;"><img src="{{ $photoUrl }}" alt="Progress photo from our team" style="max-width:100%;border-radius:10px;border:1px solid #e3d8c5;"></p>
          @endif

          <p style="margin:0 0 20px;">
            <a href="{{ $trackUrl }}" style="display:inline-block;background:#2a1d10;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 20px;border-radius:10px;">Track my order</a>
          </p>
          <p style="margin:0;font-size:12.5px;color:#5f4d39;line-height:1.5;">You are receiving this because you placed an order with LP Furniture. If the button does not work, open {{ $trackUrl }}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
