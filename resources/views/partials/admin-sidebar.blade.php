{{-- Admin sidebar (spec docs/admin/admin-menu-v1.html). Text only, grouped, collapsible; behaviour in public/assets/js/admin.js.
     Every admin sees every item (the only gate is the `admin` middleware), so the groups are the same for everyone. --}}
@php
  $adminId = Auth::guard('admin')->id();
  $counts = [
    'notifications'  => \App\Models\Notification::where('user_id', $adminId)->where('is_read', false)->count(),
    'custom-orders'  => \App\Models\CustomDesignRequest::where('pipeline_status', 'submitted')->count(),
    'customize'      => \App\Models\CustomDesignRequest::where('status', 'approved')->count(),
    'services'       => \App\Models\ServiceInquiry::where('status', 'new')->count(),
    'refunds'        => \App\Models\ServiceRefundRequest::attentionCount(),
    'identity'       => \App\Models\UserValidation::where('status', 'pending')->count(),
    'reviews'        => \App\Models\Feedback::where('is_approved', false)->count(),
    'ideas'          => \App\Models\UnansweredIdea::where('status', 'new')->count(),
  ];
  // [label, route, route pattern that marks it active, red count]
  $menuGroups = [
    ['id' => 'overview', 'name' => 'Overview', 'items' => [
      ['Dashboard', 'admin.dashboard', 'admin.dashboard', 0],
      ['Notifications', 'admin.notifications.index', 'admin.notifications.*', $counts['notifications']],
    ]],
    ['id' => 'orders', 'name' => 'Orders & Payments', 'items' => [
      ['Orders', 'admin.orders.index', 'admin.orders.*', 0],
      ['Custom Orders', 'admin.custom-orders.index', 'admin.custom-orders.*', $counts['custom-orders']],
      ['Customize Requests', 'admin.design-requests.index', 'admin.design-requests.*', $counts['customize']],
      ['Other Services', 'admin.service-inquiries.index', 'admin.service-inquiries.*', $counts['services']],
      ['Installments', 'admin.installments.index', 'admin.installments.*', 0],
      ['Cancellations & Refunds', 'admin.refunds.index', 'admin.refunds.*', $counts['refunds']],
    ]],
    ['id' => 'catalog', 'name' => 'Catalog & Stock', 'items' => [
      ['Products', 'admin.products.index', 'admin.products.*', 0],
      ['Materials Inventory', 'admin.inventory.index', ['admin.inventory.*', 'admin.material-estimates.*'], 0],
    ]],
    ['id' => 'customers', 'name' => 'Customers', 'items' => [
      ['Customers', 'admin.customers.index', 'admin.customers.*', 0],
      ['Identity Validation', 'admin.validations.index', 'admin.validations.*', $counts['identity']],
      ['Reviews', 'admin.reviews.index', 'admin.reviews.*', $counts['reviews']],
    ]],
    ['id' => 'luma', 'name' => 'Luma & Customize Studio', 'items' => [
      ['Chatbot Training', 'admin.chatbot.index', 'admin.chatbot.*', 0],
      ['Ideas to Review', 'admin.ideas.index', 'admin.ideas.*', $counts['ideas']],
      ['Quick Ideas', 'admin.quick-ideas.index', 'admin.quick-ideas.*', 0],
    ]],
    ['id' => 'team', 'name' => 'Team & Reports', 'items' => [
      ['Employees', 'admin.employees.index', 'admin.employees.*', 0],
      ['Activity Log', 'admin.activity-log', 'admin.activity-log*', 0],
      ['Reports', 'admin.reports', 'admin.reports', 0],
    ]],
    ['id' => 'site', 'name' => 'Site Settings', 'items' => [
      ['Contact Settings', 'admin.settings.contact.edit', 'admin.settings.*', 0],
      ['System Health', 'admin.system-health.index', 'admin.system-health.*', 0],
    ]],
  ];
@endphp
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Admin menu">
  <div class="admin-sidebar-brand">
    <span>LP <em>Admin</em></span>
    <button type="button" class="admin-sidebar-close" id="admin-sidebar-close" aria-label="Close menu">&times;</button>
  </div>

  <div class="mnu-find">
    <input type="search" id="mnu-find" class="mnu-find__input" placeholder="Find a page" aria-label="Find a page" autocomplete="off">
  </div>

  <nav class="admin-nav mnu" id="mnu" aria-label="Pages">
    @foreach($menuGroups as $group)
      @php
        $hasCurrent = collect($group['items'])->contains(fn ($i) => request()->routeIs($i[2]));
        $sum = collect($group['items'])->sum(fn ($i) => $i[3]);
      @endphp
      <div class="mnu-g" data-group="{{ $group['id'] }}" @if($hasCurrent) data-current @endif>
        <button type="button" class="mnu-h" id="mnu-h-{{ $group['id'] }}" aria-expanded="true" aria-controls="mnu-l-{{ $group['id'] }}">
          <span class="mnu-h__name">{{ $group['name'] }}</span>
          <span class="mnu-h__tail">
            @if($sum > 0)<span class="mnu-pill mnu-g-pill" data-sum="{{ $sum }}">{{ $sum }}</span>@endif
            <span class="mnu-chev" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></span>
          </span>
        </button>
        <div class="mnu-l" id="mnu-l-{{ $group['id'] }}" role="group" aria-labelledby="mnu-h-{{ $group['id'] }}">
          <div class="mnu-l__in">
            @foreach($group['items'] as [$label, $route, $pattern, $count])
              @php $on = request()->routeIs($pattern); @endphp
              <a href="{{ route($route) }}" class="admin-nav-link mnu-i {{ $on ? 'active' : '' }}" data-label="{{ $label }}" @if($on) aria-current="page" @endif>
                <span class="mnu-i__t">{{ $label }}</span>
                @if($count > 0)<span class="mnu-pill admin-nav-badge">{{ $count }}</span>@endif
              </a>
            @endforeach
          </div>
        </div>
      </div>
    @endforeach
    <p class="mnu-none" id="mnu-none" hidden>No page matches that.</p>
  </nav>

  <form method="POST" action="{{ route('admin.logout') }}" class="admin-sidebar-signout">
    @csrf
    <button type="submit" class="mnu-signout">Sign Out</button>
  </form>
</aside>
