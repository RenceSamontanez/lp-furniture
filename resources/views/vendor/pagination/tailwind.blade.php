@if ($paginator->hasPages())
<nav class="lp-pagination" role="navigation" aria-label="Pagination Navigation">

  <div class="lp-pagination-info">
    Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} results
  </div>

  <div class="lp-pagination-links">

    {{-- Previous --}}
    @if ($paginator->onFirstPage())
      <span class="lp-page-btn lp-page-disabled" aria-disabled="true">‹ Prev</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}" class="lp-page-btn" rel="prev">‹ Prev</a>
    @endif

    {{-- Page Numbers --}}
    @foreach ($elements as $element)

      {{-- "..." separator --}}
      @if (is_string($element))
        <span class="lp-page-dots">{{ $element }}</span>
      @endif

      {{-- Actual page number links --}}
      @if (is_array($element))
        @foreach ($element as $page => $url)
          @if ($page == $paginator->currentPage())
            <span class="lp-page-btn lp-page-active">{{ $page }}</span>
          @else
            <a href="{{ $url }}" class="lp-page-btn">{{ $page }}</a>
          @endif
        @endforeach
      @endif

    @endforeach

    {{-- Next --}}
    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}" class="lp-page-btn" rel="next">Next ›</a>
    @else
      <span class="lp-page-btn lp-page-disabled" aria-disabled="true">Next ›</span>
    @endif

  </div>

</nav>
@endif