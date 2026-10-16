{{--
  Accessible toggle switch: a real checkbox with role="switch", styled as a switch.
  Keyboard: Tab to focus, Space to flip. The whole label is clickable.
  Params: id, name, checked (bool), label, hint (optional), value (default 1).
--}}
<label class="lp-switch" for="{{ $id }}">
  <input type="checkbox" role="switch" class="lp-switch-input" id="{{ $id }}" name="{{ $name }}" value="{{ $value ?? 1 }}" @checked($checked ?? false)/>
  <span class="lp-switch-track" aria-hidden="true"><span class="lp-switch-thumb"></span></span>
  <span class="lp-switch-text">
    <span class="lp-switch-label">{{ $label }}</span>
    @isset($hint)<span class="lp-switch-hint">{{ $hint }}</span>@endisset
  </span>
</label>
