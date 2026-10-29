{{-- The customer's reference photo: thumbnail that opens larger in the shared modal, plus a download link.
     Served only by the admin route (private storage), never by a public URL. --}}
@if($r->reference_image && \App\Support\ReferencePhoto::locate($r->reference_image))
  <div class="co-block-label">Customer's Reference Photo</div>
  <p class="co-flag-note" style="margin:0 0 6px;">Uploaded by the customer for the team. The picture was drawn from their words, not from this photo.</p>
  <button type="button" class="ref-thumb" data-lp-modal-open="ref-photo-{{ $r->id }}" aria-label="Open the reference photo larger">
    <img src="{{ route('admin.reference-photo', $r->id) }}" alt="Customer's reference photo" loading="lazy"/>
  </button>
  <div class="ref-actions">
    <a href="{{ route('admin.reference-photo', ['id' => $r->id, 'download' => 1]) }}" class="lp-btn lp-btn--secondary lp-btn--chip" download>Download Photo</a>
  </div>
  <x-modal id="ref-photo-{{ $r->id }}" title="Reference Photo">
    <img class="ref-big" src="{{ route('admin.reference-photo', $r->id) }}" alt="Customer's reference photo, larger" loading="lazy"/>
    <x-slot:footer>
      <a href="{{ route('admin.reference-photo', ['id' => $r->id, 'download' => 1]) }}" class="lp-btn lp-btn--secondary" download>Download Photo</a>
      <button type="button" class="lp-btn lp-btn--primary" data-lp-modal-close>Close</button>
    </x-slot:footer>
  </x-modal>
@elseif($r->reference_image)
  <div class="co-block-label">Customer's Reference Photo</div>
  <p class="co-flag-note">The file is no longer on the server.</p>
@endif
