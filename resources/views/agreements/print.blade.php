<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="robots" content="noindex"/>
<title>Agreement {{ $agreement->agreement_number }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/agreement-print.css') }}"/>
</head>
<body class="ag-body">
  <div class="ag-toolbar">
    <a href="{{ $backUrl }}">Back</a>
    <button type="button" onclick="window.print()">Print</button>
  </div>

  <main class="ag-sheet">
    <header class="ag-head">
      <div class="ag-brand">LP Furniture</div>
      <h1>Custom Furniture Agreement</h1>
      <div class="ag-number">{{ $agreement->agreement_number }}</div>
    </header>

    <table class="ag-meta">
      <tr><th>Customer</th><td>{{ \App\Support\NameFormat::proper($terms['people']['customer']['name'] ?? '') }}</td></tr>
      <tr><th>Agreed on</th><td>{{ $agreement->accepted_at->timezone('Asia/Manila')->format('M d, Y h:i A') }} (Philippine time)</td></tr>
      <tr><th>Terms and Conditions</th><td>Version {{ $agreement->policy_version }}</td></tr>
      <tr><th>Prepared by</th><td>{{ \App\Support\NameFormat::proper($terms['people']['finalized_by']['name'] ?? '') }}</td></tr>
    </table>

    @if($imageUrl)
      <div class="ag-image"><img src="{{ $imageUrl }}" alt="Reference design"/></div>
    @endif

    @include('agreements._terms', ['terms' => $terms, 'paymentDue' => $terms['payment_due_at'] ?? null, 'showPolicyVersion' => true])

    <footer class="ag-foot">
      Record integrity (SHA-256): <code>{{ $agreement->snapshot_sha256 }}</code>
    </footer>
  </main>
</body>
</html>
