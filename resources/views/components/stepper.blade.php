@props(['steps', 'current' => 1, 'variant' => 'normal', 'label' => 'Progress'])
{{-- Progress stepper (.lp-stepper, Stage 21h). Equal-width columns; each has a circle with the number or an icon exactly centred
     and the label centred under it; the connector runs between circle centres.
     $steps: list of [long label, short label]. $current: 1-based step the request is at (steps before it are done).
     $variant: normal | problem (current step shows a warning, e.g. refund problem) | stopped (last step ended early: declined, closed)
               | complete (every step done). --}}
@php
  $variant = in_array($variant, ['normal', 'problem', 'stopped', 'complete'], true) ? $variant : 'normal';
  $total   = count($steps);
@endphp
<ol {{ $attributes->class(['lp-stepper', 'lp-stepper--' . $variant]) }} aria-label="{{ $label }}" style="--steps: {{ $total }}">
  @foreach($steps as $i => $step)
    @php
      $n     = $i + 1;
      $long  = is_array($step) ? $step[0] : $step;
      $short = is_array($step) ? ($step[1] ?? $step[0]) : $step;
      $state = $variant === 'complete' || $n < $current ? 'done' : ($n === $current ? 'current' : 'todo');
      $kind  = $state === 'current' && $variant === 'problem' ? 'problem' : ($state === 'current' && $variant === 'stopped' ? 'stopped' : $state);
    @endphp
    <li class="lp-stepper__step is-{{ $kind }}" @if($state === 'current') aria-current="step" @endif>
      <span class="lp-stepper__dot" aria-hidden="true">
        @if($kind === 'done')
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
        @elseif($kind === 'problem')
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6v7"/><path d="M12 17.5h.01"/></svg>
        @elseif($kind === 'stopped')
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7l10 10M17 7 7 17"/></svg>
        @else
          <span class="lp-stepper__num">{{ $n }}</span>
        @endif
      </span>
      <span class="lp-stepper__label"><span class="lp-stepper__long">{{ $long }}</span><span class="lp-stepper__short">{{ $short }}</span></span>
      <span class="lp-sr">@if($kind === 'done'), done@elseif($kind === 'current'), current step@elseif($kind === 'problem'), needs your attention@elseif($kind === 'stopped'), ended @else, not yet @endif</span>
    </li>
  @endforeach
</ol>
