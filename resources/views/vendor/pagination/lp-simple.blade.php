@if ($paginator->hasPages())
<nav class="lp-pager" role="navigation" aria-label="Pagination">
  <p class="lp-pager__info">Page {{ $paginator->currentPage() }}</p>
  <div class="lp-pager__row">
    @if ($paginator->onFirstPage())
      <span class="lp-btn lp-btn--secondary" aria-disabled="true">Previous</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="lp-btn lp-btn--secondary">Previous</a>
    @endif
    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="lp-btn lp-btn--secondary">Next</a>
    @else
      <span class="lp-btn lp-btn--secondary" aria-disabled="true">Next</span>
    @endif
  </div>
</nav>
@endif
