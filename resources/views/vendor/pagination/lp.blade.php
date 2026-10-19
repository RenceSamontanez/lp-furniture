@if ($paginator->hasPages())
<nav class="lp-pager" role="navigation" aria-label="Pagination">
  <p class="lp-pager__info">Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }}</p>

  <div class="lp-pager__row">
    @if ($paginator->onFirstPage())
      <span class="lp-btn lp-btn--secondary" aria-disabled="true">Previous</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="lp-btn lp-btn--secondary">Previous</a>
    @endif

    <span class="lp-pager__compact">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

    <span class="lp-pager__pages">
      @foreach ($elements as $element)
        @if (is_string($element))
          <span class="lp-pager__gap" aria-hidden="true">&hellip;</span>
        @endif
        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span class="lp-pager__num" aria-current="page" aria-label="Page {{ $page }}, current page">{{ $page }}</span>
            @else
              <a href="{{ $url }}" class="lp-pager__num" aria-label="Go to page {{ $page }}">{{ $page }}</a>
            @endif
          @endforeach
        @endif
      @endforeach
    </span>

    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="lp-btn lp-btn--secondary">Next</a>
    @else
      <span class="lp-btn lp-btn--secondary" aria-disabled="true">Next</span>
    @endif
  </div>
</nav>
@endif
