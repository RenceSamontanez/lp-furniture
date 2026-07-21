@extends('layouts.admin')

@section('title', 'Reviews — Admin')
@section('page-title', 'Reviews')

@section('content')

<div class="admin-filter-tabs">
  <a href="{{ route('admin.reviews.index', ['status'=>'pending']) }}" class="admin-filter-tab {{ $status==='pending'?'active':'' }}">Pending</a>
  <a href="{{ route('admin.reviews.index', ['status'=>'approved']) }}" class="admin-filter-tab {{ $status==='approved'?'active':'' }}">Approved</a>
  <a href="{{ route('admin.reviews.index', ['status'=>'all']) }}" class="admin-filter-tab {{ $status==='all'?'active':'' }}">All</a>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($reviews->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>
        </svg>
        <p>No {{ $status !== 'all' ? $status : '' }} reviews found.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Customer</th><th>Rating</th><th>Comment</th><th>Order</th><th>Date</th><th></th></tr></thead>
        <tbody>
          @foreach($reviews as $review)
            <tr>
              <td data-label="Customer">{{ $review->user->first_name ?? 'N/A' }} {{ $review->user->last_name ?? '' }}</td>
              <td data-label="Rating">
                <span style="color:var(--gold);">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</span>
              </td>
              <td data-label="Comment" style="max-width:280px;">
                <span style="display:block;white-space:normal;line-height:1.4;">{{ Str::limit($review->comment, 100) }}</span>
              </td>
              <td data-label="Order">{{ $review->order->order_number ?? 'N/A' }}</td>
              <td data-label="Date">{{ $review->created_at->format('M d, Y') }}</td>
              <td data-label="">
                @if(!$review->is_approved)
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <form method="POST" action="{{ route('admin.reviews.approve', $review->id) }}">
                      @csrf
                      <button type="submit" class="admin-btn admin-btn-success" onclick="return confirm('Approve this review to show publicly?')">✓ Approve</button>
                    </form>
                    <form method="POST" action="{{ route('admin.reviews.reject', $review->id) }}">
                      @csrf
                      <button type="submit" class="admin-btn admin-btn-danger" onclick="return confirm('Reject and delete this review permanently?')">✕ Reject</button>
                    </form>
                  </div>
                @else
                  <span class="admin-pill approved">Approved</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $reviews->links() }}</div>

@endsection