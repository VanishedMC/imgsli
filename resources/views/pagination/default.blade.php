@if ($paginator->hasPages())
    <nav class="pagination" aria-label="{{ $label }} pagination">
        <p class="pagination-summary">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </p>
        <div class="pagination-controls">
            @if ($paginator->onFirstPage())
                <span class="page-link is-disabled" aria-disabled="true">Previous</span>
            @else
                <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="page-ellipsis" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page-link is-current" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span>
                        @else
                            <a class="page-link" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
            @else
                <span class="page-link is-disabled" aria-disabled="true">Next</span>
            @endif
        </div>
    </nav>
@endif
