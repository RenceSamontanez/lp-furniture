{{-- Shared glass Quick view: used by the catalog, home and product pages. Any button with data-quick-view="{id}" data-name="{name}" opens it.
     Filled by assets/js/quickview.js from /product/{id}/quick-view (JSON, set with textContent only). --}}
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/css/quickview.css') }}?v={{ @filemtime(public_path('assets/css/quickview.css')) ?: 1 }}"/>
@endpush
<div class="cx-mod" id="cxMod" hidden>
  <div class="cx-qd" id="cxQd" role="dialog" aria-modal="true" aria-labelledby="cxName" data-state="loading">
    <div class="cx-qd-bg" id="cxBg"></div>
    <figure class="cx-ph" id="cxPh">
      <img id="cxImg" src="" alt="">
      <span class="cx-badge" id="cxBadge" hidden></span>
      <button type="button" class="cx-gal l" id="cxPrev" aria-label="Previous photo" hidden>
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      <button type="button" class="cx-gal r" id="cxNext" aria-label="Next photo" hidden>
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      <span class="cx-pos" id="cxPos" aria-live="polite" hidden></span>
    </figure>
    <div class="cx-pane">
      <button type="button" class="cx-x" id="cxClose" aria-label="Close quick view">
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></button>
      <div>
        <p class="cx-qd-k" id="cxCat"></p>
        <h2 id="cxName"></h2>
      </div>
      <div class="cx-state cx-state-loading" aria-live="polite"><span class="cx-spin" aria-hidden="true"></span><p>Loading this piece&hellip;</p></div>
      <div class="cx-state cx-state-error" aria-live="assertive"><p>We could not load this piece. Check your connection and try again.</p>
        <button type="button" class="lp-btn lp-btn--secondary" id="cxRetry">Retry</button></div>
      <div class="cx-body" id="cxBody">
        <div class="cx-price"><span id="cxPriceLbl">Price</span><strong id="cxPrice"></strong></div>
        <p class="cx-desc" id="cxDesc" hidden></p>
        <dl class="cx-info" id="cxInfo"></dl>
        <div id="cxSizeWrap" hidden><span class="cx-lbl">Size</span><div class="cx-dims" id="cxDims"></div></div>
        <div class="cx-act"><a class="lp-btn" id="cxMore">View full details</a></div>
      </div>
    </div>
  </div>
</div>
@push('scripts')
<script src="{{ asset('assets/js/quickview.js') }}?v={{ @filemtime(public_path('assets/js/quickview.js')) ?: 1 }}" defer></script>
@endpush
