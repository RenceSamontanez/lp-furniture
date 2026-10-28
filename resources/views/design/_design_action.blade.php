{{-- The one primary action for a Ready design. Already sent to the team: View concept
     (the existing concept page). Otherwise: Order this design (the existing select ->
     "Send to our team" flow). Needs $req (CustomDesignRequest) and $suggestion. --}}
@if($req->isSentToTeam())
  <a href="{{ route('design.show', $req->id) }}" class="lp-btn lp-btn--primary md-act-primary">View concept</a>
@else
  <form method="POST" action="{{ route('design.select', $req->id) }}" class="md-act-form">
    @csrf
    <input type="hidden" name="suggestion_id" value="{{ $suggestion->id }}"/>
    <button type="submit" class="lp-btn lp-btn--primary md-act-primary">Order This Design</button>
  </form>
@endif
