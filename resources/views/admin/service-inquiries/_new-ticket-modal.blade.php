{{-- New Service Ticket: one wide modal (shared x-modal) on the Other Services list. Posts to admin.service-inquiries.store; the server validates.
     $openModal = the modal to reopen after a rejected submit, $linkedCustomer = the account that was linked when the submit was rejected (or null). --}}
@php
  $modalId = 'ticket-new';
  $mine    = old('_modal') === $modalId;                      // errors and typed values belong to this modal only
  $val     = fn (string $k, $default = null) => $mine ? old($k, $default) : $default;
  $err     = fn (string $k) => $mine ? $errors->first($k) : '';
  $today   = \App\Support\ServiceInquiryDesk::today();
  $maxDay  = $today->copy()->addDays(\App\Support\ServiceInquiryDesk::DATE_HORIZON_DAYS);
  $linked  = $mine ? ($linkedCustomer ?? null) : null;
@endphp
<x-modal :id="$modalId" title="New Service Ticket" size="wide" :data-guard="true" :data-open-on-load="($openModal ?? null) === $modalId" :data-force-dirty="$mine">
  <form method="POST" id="ticket-new-form" data-modal-form action="{{ route('admin.service-inquiries.store') }}" novalidate>
    @csrf
    <input type="hidden" name="_modal" value="{{ $modalId }}">
    <p class="am-help" style="margin:0 0 14px;">Enter a ticket for a walk-in or phone customer.</p>
    <div class="am-grid">
      <div class="am-field am-full si-link {{ $err('customer_id') ? 'is-invalid' : '' }}" id="nt-link">
        <label class="form-label" for="nt-search">Link an Existing Customer Account (Optional)</label>
        <input type="search" id="nt-search" class="form-input" placeholder="Search by name, phone or email" autocomplete="off" aria-controls="nt-results" @if($linked) hidden @endif/>
        <ul class="si-results" id="nt-results" role="listbox" hidden></ul>
        <input type="hidden" name="customer_id" id="nt-customer-id" value="{{ $linked ? $linked->id : '' }}"/>
        <div class="si-linked" id="nt-linked" @unless($linked) hidden @endunless>
          <span>Linked to <strong id="nt-linked-name">{{ $linked ? $linked->display_name . ($linked->email ? ' (' . $linked->email . ')' : '') : '' }}</strong></span>
          <button type="button" class="lp-btn lp-btn--secondary" id="nt-unlink">Unlink</button>
        </div>
        <p class="am-err">{{ $err('customer_id') }}</p>
      </div>

      <div class="am-field am-full {{ $err('service_type') ? 'is-invalid' : '' }}">
        <span class="form-label" id="nt-service-label">Service</span>
        <div class="si-radio-list" role="radiogroup" aria-labelledby="nt-service-label" style="margin-bottom:0;">
          @foreach(\App\Models\ServiceInquiry::SERVICE_TYPES as $value => $label)
            <label><input type="radio" name="service_type" value="{{ $value }}" @checked($val('service_type') === $value)/> {{ $label }}</label>
          @endforeach
        </div>
        <p class="am-err" data-client="Choose a service.">{{ $err('service_type') }}</p>
      </div>

      <div class="am-field am-full {{ $err('furniture_type') ? 'is-invalid' : '' }}">
        <label class="form-label" for="nt-furniture">Furniture Type (Optional)</label>
        <select id="nt-furniture" name="furniture_type" class="form-input">
          <option value="">Not sure or not listed</option>
          @foreach(\App\Support\FurnitureTypes::LIST as $f)
            <option value="{{ $f }}" @selected($val('furniture_type') === $f)>{{ $f }}</option>
          @endforeach
        </select>
        <p class="am-err">{{ $err('furniture_type') }}</p>
      </div>

      <div class="am-field am-full {{ $err('concern') ? 'is-invalid' : '' }}">
        <label class="form-label" for="nt-concern">Concern</label>
        <textarea id="nt-concern" name="concern" class="form-input" rows="4" maxlength="2000" data-required>{{ $val('concern') }}</textarea>
        <p class="am-err" data-client="Describe the concern.">{{ $err('concern') }}</p>
      </div>

      <div class="am-field {{ $err('name') ? 'is-invalid' : '' }}">
        <label class="form-label" for="nt-name">Name</label>
        <input type="text" id="nt-name" name="name" class="form-input" maxlength="120" data-required value="{{ $val('name') }}"/>
        <p class="am-err" data-client="Enter the customer's name.">{{ $err('name') }}</p>
      </div>

      <div class="am-field {{ $err('phone') ? 'is-invalid' : '' }}">
        <label class="form-label" for="nt-phone">Mobile Number</label>
        <input type="tel" id="nt-phone" name="phone" class="form-input" maxlength="16" data-required placeholder="09171234567" value="{{ $val('phone') }}"/>
        <p class="am-err" data-client="Enter a mobile number.">{{ $err('phone') }}</p>
      </div>

      <div class="am-field am-full {{ $err('email') ? 'is-invalid' : '' }}">
        <label class="form-label" for="nt-email">Email (Optional)</label>
        <input type="email" id="nt-email" name="email" class="form-input" maxlength="150" value="{{ $val('email') }}"/>
        <p class="am-err">{{ $err('email') }}</p>
      </div>

      <div class="am-field {{ $err('preferred_call_window') ? 'is-invalid' : '' }}">
        <label class="form-label" for="nt-window">Preferred Call Time</label>
        <select id="nt-window" name="preferred_call_window" class="form-input">
          <option value="">Any time</option>
          @foreach(\App\Models\CustomDesignRequest::CALL_WINDOWS as $value => $label)
            <option value="{{ $value }}" @selected($val('preferred_call_window') === $value)>{{ $label }}</option>
          @endforeach
        </select>
        <p class="am-err">{{ $err('preferred_call_window') }}</p>
      </div>

      <div class="am-field {{ $err('preferred_call_date') ? 'is-invalid' : '' }}">
        <label class="form-label" for="nt-date">Preferred Date (Optional)</label>
        <input type="date" id="nt-date" name="preferred_call_date" class="form-input"
               min="{{ $today->toDateString() }}" max="{{ $maxDay->toDateString() }}" value="{{ $val('preferred_call_date') }}"/>
        <div class="am-help">Weekdays only. Calls are made Monday to Friday, 8 AM to 5 PM.</div>
        <p class="am-err">{{ $err('preferred_call_date') }}</p>
      </div>

      <p class="am-help am-full" id="nt-hint" style="margin:0;">{{ $linked ? 'This ticket belongs to the linked account, so the customer is notified in the app. You can still edit the contact details above.' : 'With no account linked, the customer is not notified in the app.' }}</p>
    </div>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="ticket-new-form" class="lp-btn lp-btn--primary" data-lp-confirm>Create Ticket</button>
  </x-slot:footer>
</x-modal>

@push('scripts')
<script>
(function () {
  var url = @json(route('admin.service-inquiries.customer-search'));
  var search = document.getElementById('nt-search');
  var list = document.getElementById('nt-results');
  var idField = document.getElementById('nt-customer-id');
  var linked = document.getElementById('nt-linked');
  var linkedName = document.getElementById('nt-linked-name');
  var hint = document.getElementById('nt-hint');
  var timer = null;

  function setLinked(c) {
    idField.value = c ? c.id : '';
    linked.hidden = !c;
    search.hidden = !!c;
    list.hidden = true;
    if (c) {
      linkedName.textContent = c.name + (c.email ? ' (' + c.email + ')' : '');
      [['nt-name', c.name], ['nt-phone', c.phone], ['nt-email', c.email]].forEach(function (p) {
        var el = document.getElementById(p[0]);
        if (el && p[1]) { el.value = p[1]; }
      });
    }
    hint.textContent = c
      ? 'This ticket belongs to the linked account, so the customer is notified in the app. You can still edit the contact details above.'
      : 'With no account linked, the customer is not notified in the app.';
  }

  function show(results) {
    list.innerHTML = '';
    if (!results.length) {
      var none = document.createElement('li');
      none.className = 'si-results-none';
      none.textContent = 'No customer found';
      list.appendChild(none);
    }
    results.forEach(function (c) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.tabIndex = 0;
      li.textContent = c.name + ' · ' + (c.phone || 'no phone') + ' · ' + (c.email || 'no email');
      li.addEventListener('click', function () { setLinked(c); });
      li.addEventListener('keydown', function (e) { if (e.key === 'Enter') { setLinked(c); } });
      list.appendChild(li);
    });
    list.hidden = false;
  }

  search.addEventListener('input', function () {
    clearTimeout(timer);
    var q = search.value.trim();
    if (q.length < 2) { list.hidden = true; return; }
    timer = setTimeout(function () {
      fetch(url + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { show(d.results || []); })
        .catch(function () { list.hidden = true; });
    }, 250);
  });
  search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
  document.getElementById('nt-unlink').addEventListener('click', function () { setLinked(null); search.value = ''; search.focus(); });
})();
</script>
@endpush
