{{-- Short summary of the Installment Terms (shared modal); the full terms are the public page /installment-terms. --}}
@php $stop = config('installments.terms.stop_auto_debit_days'); @endphp
<x-modal id="inst-terms" title="Installment Terms">
  <ul class="inst-terms-list">
    <li><b>Down payment.</b> {{ config('installments.down_payment_percent') }}% of the order is charged right away. The rest is split into equal installments. No interest, no late fees.</li>
    <li><b>Automatic card charges.</b> We charge your saved card on each due date through PayMongo. We never store your card number. GCash installments get a Pay Now button instead.</li>
    <li><b>Reminders.</b> A notification {{ config('installments.reminder_days_before') }} days before every due date and a receipt after every payment.</li>
    <li><b>Paying early.</b> You can pay any installment, or the whole balance, early at any time.</li>
    <li><b>Late payments.</b> A declined charge is retried the next day, then you get a Pay Now link. After {{ config('installments.grace_days') }} days unpaid, it becomes Overdue.</li>
    <li><b>Installment Standing.</b> Late or overdue payments lower your standing score and can limit or remove installments on future orders. On-time payments raise it.</li>
  </ul>
  <p class="inst-terms-hint">This is a short summary. The full terms open as a page.</p>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Close</button>
    <a class="lp-btn lp-btn--primary" href="{{ route('installments.terms') }}" target="_blank" rel="noopener">Read The Full Terms</a>
  </x-slot:footer>
</x-modal>
