<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
@include('partials.favicon')
<meta name="robots" content="noindex"/>
<title>Agreement unavailable</title>
<link rel="stylesheet" href="{{ asset('assets/css/agreement-print.css') }}"/>
</head>
<body class="ag-body">
  <main class="ag-sheet ag-blocked">
    <h1>This Agreement Can't Be Shown Right Now</h1>
    @if(!empty($admin))
      <p>Its recorded SHA-256 no longer matches its contents. It has been blocked and every admin was notified. Do not rely on it until it has been investigated.</p>
    @else
      <p>We couldn't verify this record, so it has been withheld and our team has been alerted. Please contact us and quote your agreement number.</p>
    @endif
  </main>
</body>
</html>
