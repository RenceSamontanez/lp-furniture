{{-- Add / Edit intent: one wide modal (shared x-modal). Posts to the existing store / update routes; the server validates.
     $intent = ChatbotIntent (new or saved), $modalId = "intent-add" or "intent-edit-{id}", $openModal = the modal to reopen after a rejected submit. --}}
@php
  $mine   = old('_modal') === $modalId;                       // errors and typed values belong to this modal only
  $val    = fn (string $k, $default = null) => $mine ? old($k, $default) : $default;
  $err    = fn (string $k) => $mine ? $errors->first($k) : '';
  $formId = $modalId . '-form';
  $active = $mine ? (bool) old('is_active') : (bool) ($intent->exists ? $intent->is_active : true);
@endphp
<x-modal :id="$modalId" :title="$intent->exists ? 'Edit Intent' : 'Add Intent'" size="wide" :data-guard="true" :data-open-on-load="($openModal ?? null) === $modalId" :data-force-dirty="$mine">
  <form method="POST" id="{{ $formId }}" data-modal-form action="{{ $intent->exists ? route('admin.chatbot.update', $intent->id) : route('admin.chatbot.store') }}" novalidate>
    @csrf
    @if($intent->exists) @method('PUT') @endif
    <input type="hidden" name="_modal" value="{{ $modalId }}">
    <div class="am-grid">
      <div class="am-field am-full {{ $err('keywords') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-keywords">Keywords (Comma-Separated)</label>
        <textarea id="{{ $formId }}-keywords" name="keywords" class="form-input" rows="2" data-required
                  placeholder="e.g. how to order, place an order, ordering process, paano mag order">{{ $val('keywords', $intent->keywords) }}</textarea>
        <div class="am-help">The chatbot matches user messages against these keywords. Separate each phrase with a comma.</div>
        <p class="am-err" data-client="Enter at least one keyword.">{{ $err('keywords') }}</p>
      </div>

      <div class="am-field {{ $err('response') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-response">Response</label>
        <textarea id="{{ $formId }}-response" name="response" class="form-input" rows="4" data-required
                  placeholder="The message the chatbot will reply with when matched">{{ $val('response', $intent->response) }}</textarea>
        <div class="am-help">
          Use <code>@{{phone}}</code>, <code>@{{email}}</code> or <code>@{{facebook}}</code> to insert the current values from
          <a href="{{ route('admin.settings.contact.edit') }}">Contact Settings</a> — don't type the number or address directly.
        </div>
        <p class="am-err" data-client="Enter the response.">{{ $err('response') }}</p>
      </div>

      <div class="am-field {{ $err('answer_tl') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-tl">Tagalog Response <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
        <textarea id="{{ $formId }}-tl" name="answer_tl" class="form-input" rows="4"
                  placeholder="Used when the customer writes in Tagalog. Leave empty to reply in English.">{{ $val('answer_tl', $intent->answer_tl) }}</textarea>
        <div class="am-help">Write it politely, with "po". Luma adds her closing line herself, so don't type one.</div>
        <p class="am-err">{{ $err('answer_tl') }}</p>
      </div>

      <div class="am-field am-full {{ $err('suggestions') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-chips">Suggestion Chips (Comma-Separated) <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
        <input type="text" id="{{ $formId }}-chips" name="suggestions" class="form-input"
               placeholder="e.g. Browse catalog, Payment options, Track my order"
               value="{{ $val('suggestions', $intent->suggestions) }}"/>
        <p class="am-err">{{ $err('suggestions') }}</p>
      </div>

      <div class="am-field {{ $err('category') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-category">Category <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
        <input type="text" id="{{ $formId }}-category" name="category" class="form-input" maxlength="100"
               placeholder="e.g. Ordering, Payment, Delivery"
               value="{{ $val('category', $intent->category) }}"/>
        <p class="am-err">{{ $err('category') }}</p>
      </div>

      <label class="am-check">
        <input type="checkbox" name="is_active" value="1" {{ $active ? 'checked' : '' }}/>
        <span>Active (chatbot will use this)</span>
      </label>
    </div>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="{{ $formId }}" class="lp-btn lp-btn--primary" data-lp-confirm>Save Intent</button>
  </x-slot:footer>
</x-modal>
