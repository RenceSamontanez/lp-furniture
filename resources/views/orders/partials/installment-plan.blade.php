{{-- The customer's installment plan on the order page: progress, next payment, Pay Now, Pay Remaining Balance In Full,
     Update Card, the schedule, and their Installment Standing. Shown only for a started catalog installment plan. --}}
@php
  $plan = $order->payment;
  $show = $plan && $plan->payment_type === 'installment' && $plan->installments_started_at && !$order->design_agreement_id;
@endphp
@if($show)
@php
  $rows = \App\Models\InstallmentSchedule::where('payment_id', $plan->id)->orderBy('installment_no')->get();
  $of = $rows->count();
  $peso = fn ($n) => '₱' . number_format((float) $n, 2);
  $total = (float) $order->total_amount;
  $paid = min($total, (float) $order->amount_paid);
  $open = $rows->whereIn('status', \App\Models\InstallmentSchedule::OPEN);
  $next = $open->first();
  $paidCount = $rows->where('status', 'paid')->count();
  $auto = \App\Support\Installments\PlanMode::autoCharges($plan);
  $sbx = \App\Support\Installments\PlanMode::showSandboxLabel($plan); // gone by itself once the real gateway is on
  $cancelled = $order->order_status === 'cancelled';
  $score = \App\Support\Installments\InstallmentStanding::score(auth()->user());
  $lvl = \App\Support\Installments\InstallmentStanding::levelFor($score);
  $labels = ['pending' => 'Pending', 'failed' => 'Payment Failed', 'link_sent' => 'Pay Link Sent', 'processing' => 'Processing', 'overdue' => 'Overdue', 'paid' => 'Paid'];
@endphp
@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/installments.css') }}?v={{ @filemtime(public_path('assets/css/installments.css')) }}"/>
@endpush

<section class="inst-plan" aria-labelledby="inst-plan-t">
  <h2 id="inst-plan-t">Payment Plan</h2>
  <p class="inst-hh">{{ $plan->installment_duration_months }} month{{ $plan->installment_duration_months > 1 ? 's' : '' }}, every {{ $plan->installment_frequency_days }} days. {{ $of }} payment{{ $of > 1 ? 's' : '' }} after the down payment.</p>

  <div class="inst-sumline"><span>Order total</span><b>{{ $peso($total) }}</b></div>
  <div class="inst-sumline"><span>Paid so far</span><b>{{ $peso($paid) }}</b></div>
  <div class="inst-pbar"><i style="width:{{ $total > 0 ? round($paid / $total * 100) : 0 }}%"></i></div>
  <div class="inst-note" style="margin:0 0 4px;">{{ $paidCount }} of {{ $of }} installments paid. Remaining: {{ $peso(max(0, $total - $paid)) }}</div>

  @if(!$cancelled)
    <div class="inst-next">
      @if($next)
        <div>
          <span>Next payment: installment {{ $next->installment_no }} of {{ $of }}</span>
          <b>{{ $peso($next->amount_due) }}</b>
          <span>{{ $next->status === 'overdue' ? 'Overdue since' : 'Due' }} {{ $next->due_date->format('M d, Y') }}{{ $next->status === 'failed' ? '. We will retry soon.' : ($next->status === 'link_sent' ? '. Please pay now.' : ($auto ? '. Charged to ' . \App\Support\Installments\PlanMode::cardLabel($plan) . '.' : '.')) }}</span>
        </div>
        @if($next->status !== 'processing')
          <button type="button" class="lp-btn lp-btn--primary" data-lp-modal-open="inst-paynow" data-inst-action="{{ route('installments.pay', [$order->id, $next->id]) }}" data-inst-info="Installment {{ $next->installment_no }} of {{ $of }}|{{ $peso($next->amount_due) }} for order {{ $order->order_number }}">Pay Now</button>
        @endif
      @else
        <div><b>All paid</b><span>Thank you. Your plan is complete.</span></div>
      @endif
    </div>

    @if($open->isNotEmpty())
      <p style="margin:12px 0 0;"><button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-open="inst-payfull" data-inst-action="{{ route('installments.payFull', $order->id) }}" data-inst-info="Remaining balance|{{ $peso($open->sum('amount_due')) }} for order {{ $order->order_number }}">Pay Remaining Balance In Full</button></p>
    @endif

    @if($plan->payment_method === 'paymongo_card' && $open->isNotEmpty())
      <div class="inst-card-on">
        <span><b>Card On File:</b> {{ $auto ? \App\Support\Installments\PlanMode::cardLabel($plan) : 'None yet' }}@if($sbx && $auto) <em class="inst-sbx">Sandbox: simulated charge</em>@endif</span>
        <a class="lp-btn lp-btn--secondary lp-btn--chip" href="{{ route('installments.card', $order->id) }}">Update Card</a>
      </div>
    @endif
  @endif

  <table class="inst-table">
    <thead><tr><th>Payment</th><th>Due</th><th>Status</th><th class="r">Amount</th></tr></thead>
    <tbody>
      <tr><td>Down payment ({{ config('installments.down_payment_percent') }}%)</td><td>{{ $plan->installments_started_at->timezone('Asia/Manila')->format('M d, Y') }}</td><td><span class="inst-st paid">Paid</span></td><td class="r">{{ $peso($plan->amount) }}</td></tr>
      @foreach($rows as $r)
        <tr><td>Installment {{ $r->installment_no }} of {{ $of }}</td><td>{{ $r->due_date->format('M d, Y') }}</td><td><span class="inst-st {{ $r->status }}">{{ $labels[$r->status] ?? $r->status }}</span></td><td class="r">{{ $peso($r->amount_due) }}</td></tr>
      @endforeach
    </tbody>
  </table>
  <p class="inst-note">{{ $auto ? 'Your card is charged automatically on each due date. ' : 'We will send you a Pay Now notification on each due date. ' }}You can pay any unpaid installment early with Pay Now.@if($sbx) <em class="inst-sbx">Sandbox: simulated charge</em> This is a test: the saved card is simulated and no real card is charged.@endif</p>
</section>

<section class="inst-plan" aria-labelledby="inst-stand-t">
  <h2 id="inst-stand-t">Your Installment Standing</h2>
  <p class="inst-hh">It decides which installment plans you can use on future orders.</p>
  <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
    <span class="inst-lvl l-{{ $lvl['name'] }}" style="font-size:15px;">{{ $lvl['name'] }}</span>
    <span class="inst-note" style="margin:0;font-size:13.5px;">
      @if($lvl['months']) Up to {{ $lvl['months'] }} month{{ $lvl['months'] > 1 ? 's' : '' }}{{ $lvl['cap'] ? ', on orders up to ' . $peso($lvl['cap']) : ', any order amount' }}
      @else Installments are paused. You can pay in full. @endif
    </span>
  </div>
  <p class="inst-note">Pay on time or early, and finishing a plan, raise it. A late or overdue payment lowers it and can limit or remove installments on later orders. You can always pay the full balance. <a href="{{ route('installments.terms') }}" target="_blank" rel="noopener">Read The Installment Terms</a></p>
</section>

@if(!$cancelled && $open->isNotEmpty())
  @foreach([['inst-paynow', 'Pay Now', 'Pay Now'], ['inst-payfull', 'Pay Remaining Balance', 'Pay In Full']] as [$mid, $mtitle, $mbtn])
    <x-modal :id="$mid" :title="$mtitle">
      <form method="POST" id="{{ $mid }}-form" action="#">
        @csrf
        <div class="inst-ex" data-inst-info-box></div>
        <div class="inst-opts">
          @if($auto)
            <label><input type="radio" name="method" value="card" checked><span><b>{{ \App\Support\Installments\PlanMode::cardLabel($plan) }}</b>@if($sbx) <em class="inst-sbx">Sandbox: simulated charge</em>@endif<small>Charged right away from your saved card.</small></span></label>
          @endif
          <label><input type="radio" name="method" value="gcash" @checked(!$auto)><span><b>GCash</b><small>You will approve the payment in GCash.</small></span></label>
        </div>
        @if($mid === 'inst-payfull')<p class="inst-note">No fee. Paying the balance early counts in your favor.</p>@endif
      </form>
      <x-slot:footer>
        <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
        <button type="submit" form="{{ $mid }}-form" class="lp-btn lp-btn--primary">{{ $mbtn }}</button>
      </x-slot:footer>
    </x-modal>
  @endforeach
  <script>
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-inst-action]');
    if (!b) { return; }
    var m = document.getElementById(b.getAttribute('data-lp-modal-open'));
    if (!m) { return; }
    m.querySelector('form').setAttribute('action', b.getAttribute('data-inst-action'));
    var p = (b.getAttribute('data-inst-info') || '').split('|'), box = m.querySelector('[data-inst-info-box]');
    box.textContent = '';
    var bold = document.createElement('b'); bold.textContent = p[0]; box.appendChild(bold);
    box.appendChild(document.createElement('br')); box.appendChild(document.createTextNode(p[1] || ''));
  }, true);
  </script>
@endif
@endif
