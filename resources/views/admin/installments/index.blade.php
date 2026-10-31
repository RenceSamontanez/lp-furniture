@extends('layouts.admin')

@section('title', 'Installments — Admin')
@section('page-title', 'Installments')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}?v={{ @filemtime(public_path('assets/css/admin-modals.css')) }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-installments.css') }}?v={{ @filemtime(public_path('assets/css/admin-installments.css')) }}"/>
@endpush
@push('scripts')
  <script src="{{ asset('assets/js/admin-installments.js') }}?v={{ @filemtime(public_path('assets/js/admin-installments.js')) }}"></script>
@endpush

@section('content')
@php
  use App\Http\Controllers\Admin\InstallmentAdminController as IA;
  $cls = ['pending' => 'i-pending', 'paid' => 'i-paid', 'failed' => 'i-failed', 'processing' => 'i-link', 'link_sent' => 'i-link', 'overdue' => 'i-over'];
  $peso = fn ($n) => '₱' . number_format((float) $n, 2);
@endphp
<div class="ia">

  @if($testMode)
    <div class="ia-tmode"><span><b>Test mode is on.</b> PayMongo test keys are active, so no real money moves. <b>Simulate</b> buttons are for demos and disappear in live mode.@if($sandbox) <b>Sandbox gateway:</b> card installments are charged by a simulated saved card, not by PayMongo.@endif</span></div>
  @endif
  @if($errors->has('simulate') || $errors->has('payment'))
    <div class="ia-err" role="alert">{{ $errors->first('simulate') ?: $errors->first('payment') }}</div>
  @endif

  <p class="ia-sub">Installments are charged automatically on their due date. A declined card is retried the next day, then the customer gets a Pay Now link, and after 3 days it becomes Overdue.</p>

  <div class="ia-sumc">
    <div><b>{{ $counts['up'] }}</b><span>Upcoming</span></div>
    <div class="lo"><b>{{ $counts['at'] }}</b><span>Needs Attention</span></div>
    <div class="sh"><b>{{ $overdue }}</b><span>Overdue</span></div>
    <div class="ok"><b>{{ $counts['pd'] }}</b><span>Paid</span></div>
  </div>

  <div class="ia-tabs" role="tablist">
    @foreach($tabs as $k => [$label])
      <a class="ia-tab {{ $tab === $k ? 'on' : '' }}" role="tab" aria-selected="{{ $tab === $k ? 'true' : 'false' }}" href="{{ route('admin.installments.index', ['tab' => $k]) }}">{{ $label }}<span class="c">{{ $counts[$k] }}</span></a>
    @endforeach
  </div>

  <div class="admin-panel"><div class="admin-panel-body" style="padding:0;overflow-x:auto;">
    @if($rows->isEmpty())
      <div class="ia-empty"><b>Nothing here</b>No installments match this tab.</div>
    @else
      <table class="admin-table ia-table" id="ia-rows">
        <thead><tr><th>Order</th><th>Customer</th><th>Installment</th><th>Amount</th><th>Due</th><th>Method</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach($rows as $r)
          @php
            $of = $totals[$r->payment_id] ?? 1;
            $cust = trim(($r->order->user->first_name ?? '') . ' ' . ($r->order->user->last_name ?? ''));
            $canSim = $testMode && $r->payment?->installments_started_at && in_array($r->status, ['pending', 'failed', 'link_sent'], true);
            $simLabel = ['pending' => 'Simulate Due Date', 'failed' => 'Simulate Next Day', 'link_sent' => 'Simulate What Happens'][$r->status] ?? '';
            $detail = [
              'title' => ($r->order->order_number ?? '') . ', installment ' . $r->installment_no . ' of ' . $of,
              'line' => $cust . ' · ' . $peso($r->amount_due) . ' · due ' . $r->due_date->format('M d, Y'),
              'method' => IA::methodLabel($r->payment), 'status' => $r->statusLabel(), 'statusClass' => $cls[$r->status] ?? '',
              'due' => $r->due_date->format('M d, Y'),
              'steps' => $r->attemptsLog->map(fn ($a) => ['k' => $a->result === 'ok' ? 'ok' : ($a->result === 'failed' ? 'bad' : ''), 't' => $a->message . ($a->simulated ? ($sandbox ? ' · Sandbox: simulated charge' : ' (simulated)') : ''), 'w' => $a->created_at->timezone('Asia/Manila')->format('M d, Y g:i A')])->values(),
              'canMark' => $r->status !== 'paid',
            ];
          @endphp
          <tr>
            <td data-l="Order"><b>{{ $r->order->order_number ?? 'N/A' }}</b></td>
            <td data-l="Customer"><a class="ia-link" href="{{ route('admin.installments.customer', $r->user_id) }}">{{ $cust ?: 'N/A' }}</a></td>
            <td data-l="Installment">{{ $r->installment_no }} of {{ $of }}</td>
            <td data-l="Amount">{{ $peso($r->amount_due) }}</td>
            <td data-l="Due">{{ $r->due_date->format('M d, Y') }}</td>
            <td data-l="Method">{{ IA::methodLabel($r->payment) }}@if(\App\Support\Installments\PlanMode::showSandboxLabel($r->payment)) <span class="ia-sbx">Sandbox: simulated charge</span>@endif</td>
            <td data-l="Status"><span class="ist {{ $cls[$r->status] ?? '' }}">{{ $r->statusLabel() }}</span></td>
            <td>
              <div class="ia-acts">
                @if($canSim)
                  <button type="button" class="admin-btn admin-btn-gold" data-lp-modal-open="ia-sim" data-ia-sim="{{ route('admin.installments.simulate', $r->id) }}" data-ia-title="{{ $simLabel }}" data-ia-outcome="{{ $sandbox && in_array($r->status, ['pending', 'failed'], true) && \App\Support\Installments\PlanMode::autoCharges($r->payment) ? '1' : '0' }}" data-ia-info="{{ $detail['title'] . '|' . $detail['line'] }}">@titlecase($simLabel)</button>
                @endif
                @if($r->status === 'overdue')
                  <button type="button" class="admin-btn admin-btn-success" data-lp-modal-open="ia-mark" data-ia-mark="{{ route('admin.installments.markPaid', $r->id) }}" data-ia-info="{{ $detail['title'] . '|' . $detail['line'] }}">Mark Paid</button>
                @endif
                <button type="button" class="admin-btn admin-btn-outline" data-lp-modal-open="ia-det" data-ia-detail="{{ json_encode($detail) }}" data-ia-mark="{{ route('admin.installments.markPaid', $r->id) }}">Details</button>
              </div>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endif
  </div></div>

  <div style="margin-top:16px;">{{ $rows->links() }}</div>

  <p class="ia-cnote"><b>How a charge works:</b> every day at 8:00 AM (Manila time) the system finds installments due today. Cards are charged from the saved card. GCash installments get a notification with a Pay Now button. Results arrive by webhook, update the order, and send the customer a receipt.</p>
</div>

@if($testMode)
<x-modal id="ia-sim" title="Simulate Due Date" size="mid">
  <form method="POST" id="ia-sim-form" action="#">
    @csrf
    <div class="ia-ex" data-ia-info-box></div>
    @if($sandbox)
    <fieldset class="ia-outcome" data-ia-outcome-box>
      <legend class="form-label">How Should The Charge End?</legend>
      @foreach(\App\Support\Installments\SandboxInstallmentGateway::OUTCOMES as $key => $label)
        <label class="ia-oc"><input type="radio" name="outcome" value="{{ $key }}" {{ $loop->first ? 'checked' : '' }}><span>{{ $label }}</span></label>
      @endforeach
      <p class="ia-hint">Sandbox: simulated charge. The result is recorded like a real one, but PayMongo is not called and no money moves.</p>
    </fieldset>
    @endif
    <p class="ia-hint">This runs the same charge job that runs every day, for this installment only. Only available in test mode.</p>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="ia-sim-form" class="lp-btn lp-btn--primary">Run It</button>
  </x-slot:footer>
</x-modal>

@endif

<x-modal id="ia-mark" title="Mark Paid" size="mid">
  <form method="POST" id="ia-mark-form" action="#">
    @csrf
    <div class="ia-ex" data-ia-info-box></div>
    <label class="form-label" for="ia-mark-method">How Was It Paid?</label>
    <select id="ia-mark-method" name="method" class="form-input">
      <option value="cash">Cash</option><option value="gcash_direct">GCash (Direct)</option><option value="bank_transfer">Bank Transfer</option>
    </select>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="ia-mark-form" class="lp-btn lp-btn--primary">Mark As Paid</button>
  </x-slot:footer>
</x-modal>

<x-modal id="ia-det" title="Installment Details" size="mid">
  <div id="ia-det-body"></div>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" id="ia-det-mark" data-lp-modal-open="ia-mark" hidden>Mark Paid</button>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Close</button>
  </x-slot:footer>
</x-modal>
@endsection
