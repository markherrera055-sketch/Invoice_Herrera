@if ($paginator->hasPages())
    <nav class="actions" style="justify-content:space-between;padding:12px 16px" aria-label="Pagination">
        <small>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</small>
        <span class="actions">
            @if ($paginator->onFirstPage())
                <span class="btn btn-sm" aria-disabled="true">‹ Previous</span>
            @else
                <a class="btn btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Previous</a>
            @endif
            <small>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</small>
            @if ($paginator->hasMorePages())
                <a class="btn btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>
            @else
                <span class="btn btn-sm" aria-disabled="true">Next ›</span>
            @endif
        </span>
    </nav>
@endif
