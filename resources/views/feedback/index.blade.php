@extends('layouts.app')

@section('title', 'Customer Reviews — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/feedback.css') }}"/>
@endpush

@section('content')
<div class="reviews-wrap">

  <div class="reviews-header">
    <p class="gallery-eyebrow" style="margin-bottom:8px;">What Our Customers Say</p>
    <h1 class="reviews-title">Customer Reviews</h1>
    <p class="reviews-subtitle">Real feedback from customers who've ordered handcrafted furniture from LP Furniture.</p>

    <div class="reviews-stats">
      <div class="reviews-stat">
        <div class="reviews-stat-value">
          {{ $stats['average'] }} <span style="color:var(--gold);font-size:20px;">★</span>
        </div>
        <div class="reviews-stat-label">Average Rating</div>
      </div>
      <div class="reviews-stat-divider"></div>
      <div class="reviews-stat">
        <div class="reviews-stat-value">{{ $stats['total'] }}</div>
        <div class="reviews-stat-label">Total Reviews</div>
      </div>
    </div>
  </div>


  @if($errors->any())
    <div class="auth-errors" style="max-width:720px;margin:0 auto 24px;">
      @foreach($errors->all() as $error)
        <div class="auth-error-item">{{ $error }}</div>
      @endforeach
    </div>
  @endif

  {{-- Prompt logged-in customers to review their completed orders --}}
  @auth
    @if($reviewableOrders->count() > 0)
      <div class="review-prompt-card">
        <div class="review-prompt-title">You have {{ $reviewableOrders->count() }} completed {{ Str::plural('order', $reviewableOrders->count()) }} to review</div>
        <div class="review-prompt-text">Your feedback helps other customers and helps us improve.</div>

        @foreach($reviewableOrders as $order)
          <div class="review-prompt-order">
            @if($order->items->first()?->product)
              <div class="review-prompt-thumb">
                <img src="{{ $order->items->first()->image_url }}"
                     alt="Product" onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
              </div>
            @endif
            <div class="review-prompt-order-info">
              {{ $order->order_number }}
              <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">
                {{ $order->items->pluck('product.product_name')->filter()->take(2)->join(', ') }}
              </div>
              <div class="review-prompt-order-date">Received {{ $order->updated_at->format('M d, Y') }}</div>
            </div>
            <button type="button" class="btn btn-gold" style="font-size:12.5px;padding:8px 18px;"
                    onclick="openReviewModal({{ $order->id }}, '{{ $order->order_number }}')">
              Leave a Review
            </button>
          </div>
        @endforeach
      </div>
    @endif
  @endauth

  {{-- Review submission modal --}}
  <div class="review-modal-overlay" id="review-modal-overlay">
    <div class="review-modal">
      <div class="review-modal-title">Rate Your Experience</div>
      <div class="review-modal-sub" id="review-modal-order-label">Order</div>

      <form method="POST" action="{{ route('feedback.store') }}" id="review-form">
        @csrf
        <input type="hidden" name="order_id" id="review-order-id" value=""/>
        <input type="hidden" name="rating" id="review-rating-input" value="0"/>

        <div class="star-rating-input" id="star-rating-input">
          <span class="star" data-value="1">★</span>
          <span class="star" data-value="2">★</span>
          <span class="star" data-value="3">★</span>
          <span class="star" data-value="4">★</span>
          <span class="star" data-value="5">★</span>
        </div>

        <label class="form-label">Your Review</label>
        <textarea name="comment" class="form-input" rows="4" required minlength="10" maxlength="1000"
                  placeholder="Tell us about the quality, delivery, and your overall experience..."></textarea>

        <div class="review-modal-actions">
          <button type="button" class="btn btn-outline" onclick="closeReviewModal()">Cancel</button>
          <button type="submit" class="btn btn-gold">Submit Review</button>
        </div>
      </form>
    </div>
  </div>

  {{-- Public reviews grid --}}
  @if($feedbacks->isEmpty())
    <div class="reviews-empty">
      <div class="reviews-empty-icon">💬</div>
      <h3>No Reviews Yet</h3>
      <p>Be the first to share your experience with LP Furniture!</p>
    </div>
  @else
    <div class="reviews-grid">
    @foreach($feedbacks as $review)
        <div class="review-card">

          {{-- Product info from the reviewed order --}}
          @if($review->order && $review->order->items->count() > 0)
            <div class="review-product-strip">
              @foreach($review->order->items->take(3) as $item)
                <div class="review-product-thumb">
                  <img src="{{ $item->image_url }}"
                       alt="{{ $item->product->product_name ?? 'Product' }}"
                       onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                </div>
              @endforeach
              @if($review->order->items->count() > 3)
                <div class="review-product-more">+{{ $review->order->items->count() - 3 }}</div>
              @endif
              <div class="review-product-names">
                {{ $review->order->items->pluck('product.product_name')->filter()->take(2)->join(', ') }}
                @if($review->order->items->count() > 2)
                  and {{ $review->order->items->count() - 2 }} more
                @endif
              </div>
            </div>

            <div class="review-order-meta">
              <span class="admin-pill approved" style="font-size:10px;">{{ title_case(str_replace('_',' ', $review->order->order_status)) }}</span>
              <span class="review-order-ref">{{ $review->order->order_number }}</span>
            </div>
          @endif

          <div class="review-card-stars">
            {{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}
          </div>
          <div class="review-card-comment">{{ $review->comment }}</div>

          {{-- Timeline: ordered → received → reviewed --}}
          @if($review->order)
            <div class="review-timeline">
              <div class="review-timeline-item">
                <span class="review-timeline-label">Ordered</span>
                <span class="review-timeline-value">{{ $review->order->created_at->format('M d, Y') }}</span>
              </div>
              <div class="review-timeline-item">
                <span class="review-timeline-label">Received</span>
                <span class="review-timeline-value">{{ $review->order->updated_at->format('M d, Y') }}</span>
              </div>
              <div class="review-timeline-item">
                <span class="review-timeline-label">Reviewed</span>
                <span class="review-timeline-value">{{ $review->created_at->format('M d, Y') }}</span>
              </div>
            </div>
          @endif

          <div class="review-card-footer">
            <div class="review-card-avatar">
              {{ strtoupper(substr($review->user->first_name ?? 'A', 0, 1)) }}{{ strtoupper(substr($review->user->last_name ?? '', 0, 1)) }}
            </div>
            <div>
              <div class="review-card-name">{{ $review->user->first_name ?? 'Anonymous' }} {{ substr($review->user->last_name ?? '', 0, 1) }}.</div>
              <div class="review-card-date">{{ $review->created_at->format('M Y') }}</div>
            </div>
          </div>
        </div>
      @endforeach
    </div>

    <div style="max-width:1200px;margin:24px auto 0;">
      {{ $feedbacks->links() }}
    </div>
  @endif

</div>
@endsection

@push('scripts')
<script>
function openReviewModal(orderId, orderNumber) {
  document.getElementById('review-order-id').value = orderId;
  document.getElementById('review-modal-order-label').textContent = 'Reviewing ' + orderNumber;
  document.getElementById('review-modal-overlay').classList.add('show');
  resetStars();
}

function closeReviewModal() {
  document.getElementById('review-modal-overlay').classList.remove('show');
}

function resetStars() {
  document.getElementById('review-rating-input').value = 0;
  document.querySelectorAll('#star-rating-input .star').forEach(s => s.classList.remove('active'));
}

// Star rating click handling
document.querySelectorAll('#star-rating-input .star').forEach(star => {
  star.addEventListener('click', function() {
    const value = parseInt(this.dataset.value);
    document.getElementById('review-rating-input').value = value;

    document.querySelectorAll('#star-rating-input .star').forEach(s => {
      s.classList.toggle('active', parseInt(s.dataset.value) <= value);
    });
  });
});

// Prevent submit without a rating
document.getElementById('review-form').addEventListener('submit', function(e) {
  const rating = parseInt(document.getElementById('review-rating-input').value);
  if (!rating || rating < 1) {
    e.preventDefault();
    alert('Please select a star rating before submitting.');
  }
});

// Close modal on overlay click
document.getElementById('review-modal-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeReviewModal();
});
</script>
@endpush