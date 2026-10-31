@extends('layouts.admin')

@section('title', 'Customer Profile — Admin')
@section('page-title', 'Customer Profile')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}?v={{ @filemtime(public_path('assets/css/admin-modals.css')) }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-installments.css') }}?v={{ @filemtime(public_path('assets/css/admin-installments.css')) }}"/>
@endpush
@push('scripts')
  <script src="{{ asset('assets/js/admin-modal-forms.js') }}?v={{ @filemtime(public_path('assets/js/admin-modal-forms.js')) }}"></script>
@endpush

@section('content')
@php
  $name = trim($customer->first_name . ' ' . $customer->last_name);
  $ini = strtoupper(mb_substr($customer->first_name, 0, 1) . mb_substr($customer->last_name, 0, 1));
  $peso = fn ($n) => '₱' . number_format((float) $n, 2);
  $cls = ['pending' => 'i-pending', 'paid' => 'i-paid', 'failed' => 'i-failed', 'processing' => 'i-link', 'link_sent' => 'i-link', 'overdue' => 'i-over'];
  $mine = old('_modal') === 'ia-adjust';
  $result = function ($r) {
      if ($r->status === 'paid') {
          return match (true) {
              $r->paid_via === 'admin' => ['Marked Paid', 'i-link'],
              (bool) $r->charge_failed => ['Paid After Retry', 'i-link'],
              $r->paid_via === 'pay_now' && $r->paid_at && $r->paid_at->toDateString() < $r->due_date->toDateString() => ['Paid Early', 'i-paid'],
              default => ['Paid On Time', 'i-paid'],
          };
      }
      return $r->status === 'pending' ? ['Scheduled', 'i-pending'] : [$r->statusLabel(), $r->status === 'overdue' ? 'i-over' : 'i-failed'];
  };
@endphp
<div class="ia">
  <p style="margin:0 0 12px;"><x-back-link :href="route('admin.installments.index')" label="Installments" /></p>

  <div class="cpx-head">
    <div class="cpx-av">{{ $ini }}</div>
    <div style="min-width:240px;">
      <h2 class="cpx-name">{{ $name }}</h2>
      <div class="cpx-stand">
        <span class="lvl l-{{ $level['name'] }}">{{ $level['name'] }}</span>
        <span style="font-weight:600;">{{ $score }} / 100</span>
        <span class="ist {{ $customer->is_validated ? 'i-paid' : 'i-failed' }}">{{ $customer->is_validated ? 'ID Verified' : 'ID Not Verified' }}</span>
      </div>
    </div>
    <div class="cpx-gaugewrap">
      <div class="cpx-gauge"><i style="left:{{ $score }}%"></i></div>
      <div class="cpx-gl">@foreach(array_reverse($levels) as $l)<span>{{ $l['min'] }} {{ $l['name'] }}</span>@endforeach</div>
    </div>
    <div><button type="button" class="admin-btn admin-btn-gold" data-lp-modal-open="ia-adjust">Adjust Standing</button></div>
  </div>

  <div class="cpx-stats">
    <div><b>{{ $paidCount }} of {{ $installments->count() }}</b><span>Installments Paid</span></div>
    <div><b>{{ $overdueNow }}</b><span>Overdue Now</span></div>
    <div>
      <b style="font-size:16px;line-height:1.5;">{{ $level['months'] ? 'Up to ' . $level['months'] . ' month' . ($level['months'] > 1 ? 's' : '') : 'Full payment only' }}</b>
      <span>{{ $level['months'] ? ($level['cap'] ? 'Orders up to ' . $peso($level['cap']) : 'Any order amount') : 'Installments paused' }}</span>
    </div>
  </div>

  <div class="admin-panel">
    <div class="cpx-tabs" role="tablist">
      <a class="cpx-tab {{ $tab === 'installments' ? 'on' : '' }}" role="tab" href="{{ route('admin.installments.customer', ['id' => $customer->id]) }}">Installment History</a>
      <a class="cpx-tab {{ $tab === 'standing' ? 'on' : '' }}" role="tab" href="{{ route('admin.installments.customer', ['id' => $customer->id, 'tab' => 'standing']) }}">Standing History</a>
    </div>
    <div style="overflow-x:auto;">
    @if($tab === 'installments')
      <table class="admin-table">
        <thead><tr><th>Order</th><th>Installment</th><th class="r">Amount</th><th>Due</th><th>Paid On</th><th>Method</th><th>Result</th></tr></thead>
        <tbody>
        @forelse($installments as $r)
          @php [$rl, $rc] = $result($r); @endphp
          <tr>
            <td><b>{{ $r->order->order_number ?? '' }}</b></td>
            <td>{{ $r->installment_no }} of {{ $totals[$r->payment_id] ?? 1 }}</td>
            <td class="r">{{ $peso($r->amount_due) }}</td>
            <td>{{ $r->due_date->format('M d, Y') }}</td>
            <td>{{ $r->paid_at ? $r->paid_at->timezone('Asia/Manila')->format('M d, Y') : '—' }}</td>
            <td>{{ \App\Http\Controllers\Admin\InstallmentAdminController::methodLabel($r->payment) }}@if(\App\Support\Installments\PlanMode::showSandboxLabel($r->payment)) <span class="ia-sbx">Sandbox: simulated charge</span>@endif</td>
            <td><span class="ist {{ $rc }}">{{ $rl }}</span></td>
          </tr>
        @empty
          <tr><td colspan="7" style="text-align:center;padding:26px;color:#6e6157;"><b>No installments yet</b></td></tr>
        @endforelse
        </tbody>
      </table>
    @else
      <table class="admin-table">
        <thead><tr><th>Date</th><th>What Happened</th><th class="r">Points</th><th class="r">Score After</th></tr></thead>
        <tbody>
        @forelse($history as $h)
          <tr>
            <td>{{ $h->created_at->timezone('Asia/Manila')->format('M d, Y') }}</td>
            <td>{{ $h->reason }}</td>
            <td class="r {{ $h->points > 0 ? 'ptp' : ($h->points < 0 ? 'ptm' : '') }}">{{ $h->points > 0 ? '+' : '' }}{{ $h->points ?: '—' }}</td>
            <td class="r">{{ $h->score_after }}</td>
          </tr>
        @empty
          <tr><td colspan="4" style="text-align:center;padding:26px;color:#6e6157;"><b>Standing started at {{ config('installments.start_score') }}</b> with no changes yet.</td></tr>
        @endforelse
        </tbody>
      </table>
    @endif
    </div>
  </div>
</div>

<x-modal id="ia-adjust" title="Adjust Installment Standing" size="mid" :data-guard="true" :data-open-on-load="$mine" :data-force-dirty="$mine">
  <form method="POST" id="ia-adjust-form" data-modal-form action="{{ route('admin.installments.adjust', $customer->id) }}" novalidate>
    @csrf
    <input type="hidden" name="_modal" value="ia-adjust">
    <div class="ia-ex"><b>{{ $name }}</b><br>Now {{ $score }} / 100 ({{ $level['name'] }})</div>
    <div class="ia-field">
      <label class="form-label" for="ia-mode">What To Do</label>
      <select id="ia-mode" name="mode" class="form-input">
        @foreach(['add' => 'Add Points', 'sub' => 'Remove Points', 'set' => 'Set Score To'] as $k => $l)
          <option value="{{ $k }}" @selected(($mine ? old('mode') : 'add') === $k)>{{ $l }}</option>
        @endforeach
      </select>
    </div>
    <div class="ia-field {{ $mine && $errors->has('points') ? 'is-invalid' : '' }}">
      <label class="form-label" for="ia-points">Points</label>
      <input id="ia-points" name="points" type="number" min="0" max="100" class="form-input" value="{{ $mine ? old('points') : 10 }}" data-required>
      <p class="am-err" data-client="Enter points between 0 and 100.">{{ $mine ? $errors->first('points') : '' }}</p>
    </div>
    <div class="ia-field {{ $mine && $errors->has('reason') ? 'is-invalid' : '' }}">
      <label class="form-label" for="ia-reason">Reason (Required)</label>
      <textarea id="ia-reason" name="reason" rows="3" class="form-input" data-required maxlength="200" placeholder="e.g. The card was declined because of a bank error on our side">{{ $mine ? old('reason') : '' }}</textarea>
      <p class="am-err" data-client="Please write a reason. It is saved with the change.">{{ $mine ? $errors->first('reason') : '' }}</p>
    </div>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="ia-adjust-form" class="lp-btn lp-btn--primary">Save Change</button>
  </x-slot:footer>
</x-modal>
@endsection
