{{-- How to measure: used in the panel on the design form and on the public /guide page. --}}
@php
  $guidePhone = \App\Support\NotificationTemplates::phone();
  $guideEmail = \App\Support\NotificationTemplates::email();
@endphp
<div class="gm">
  <figure class="gm-figure">
    <svg class="gm-svg" viewBox="-26 0 406 270" role="img" aria-labelledby="gm-title gm-desc" focusable="false">
      <title id="gm-title">A plain box with its three measurements labelled</title>
      <desc id="gm-desc">A box seen from the corner. Length runs along the long bottom edge at the front, Width runs along the short bottom edge going back, and Height runs up the front left edge from the floor to the top.</desc>

      <polygon points="145,157 285,157 285,47 145,47" fill="#EFE2C8" opacity=".55"/>
      <polygon points="70,95 210,95 285,47 145,47" fill="#F9EFD9" stroke="#5C4630" stroke-width="1.6" stroke-linejoin="round"/>
      <polygon points="210,205 285,157 285,47 210,95" fill="#E2CBA3" stroke="#5C4630" stroke-width="1.6" stroke-linejoin="round"/>
      <polygon points="70,205 210,205 210,95 70,95" fill="#F1E2C6" stroke="#5C4630" stroke-width="1.6" stroke-linejoin="round"/>
      <g stroke="#5C4630" stroke-width="1" stroke-dasharray="4 4" opacity=".45" fill="none">
        <path d="M70 205 L145 157 L285 157"/><path d="M145 157 L145 47"/>
      </g>

      <g stroke="#B07A12" stroke-width="1.8" stroke-linecap="round" fill="none">
        <path d="M70 226 H210"/><path d="M70 220 V232"/><path d="M210 220 V232"/>
        <path d="M225 215 L300 167"/><path d="M222 211 L228 219"/><path d="M297 163 L303 171"/>
        <path d="M49 95 V205"/><path d="M43 95 H55"/><path d="M43 205 H55"/>
      </g>
      <g font-family="DM Sans, Arial, sans-serif" font-size="16" font-weight="600" fill="#2A1810">
        <text x="140" y="251" text-anchor="middle">Length</text>
        <text x="270" y="213">Width</text>
        <text x="38" y="155" text-anchor="end">Height</text>
      </g>
    </svg>
    <figcaption class="gm-caption">Length is the longest side across, Width is the shorter side across, and Height is from the floor to the top.</figcaption>
  </figure>

  <div class="gm-text">
    <h3 class="gm-h">Measure in three steps</h3>
    <ol class="gm-steps">
      <li><strong>Use a tape measure</strong> and stay in one unit, such as centimetres or inches. Choose the same unit in the form.</li>
      <li><strong>Length first.</strong> Measure the longest side across the top or the front.</li>
      <li><strong>Then Width and Height.</strong> Width is the shorter side across. Height goes from the floor to the highest point.</li>
    </ol>
    <p class="gm-p">Measure the place it will go as well, and check the doors and corners it has to pass through. A close number is fine: we confirm the exact size with you on the call.</p>

    <h3 class="gm-h">What the three sizes mean for each piece</h3>
    <p class="gm-p gm-small">The typical sizes are only suggestions to help you start. Your piece can be any size we can build.</p>
    <dl class="gm-types">
      @foreach(\App\Support\FurnitureTypes::GUIDE as $type => $g)
        <div class="gm-type">
          <dt>{{ $type }}</dt>
          <dd>
            <span><strong>Length:</strong> {{ $g['length'] }}</span>
            <span><strong>Width:</strong> {{ $g['width'] }}</span>
            <span><strong>Height:</strong> {{ $g['height'] }}</span>
            <span class="gm-hint">{{ $g['hint'] }}</span>
          </dd>
        </div>
      @endforeach
    </dl>

    <p class="gm-ask">
      Not sure? Ask us before you send the request.
      @if($guidePhone && $guideEmail)
        Call {{ $guidePhone }} or email <a href="mailto:{{ $guideEmail }}">{{ $guideEmail }}</a>.
      @elseif($guidePhone)
        Call {{ $guidePhone }}.
      @elseif($guideEmail)
        Email <a href="mailto:{{ $guideEmail }}">{{ $guideEmail }}</a>.
      @else
        You can reach our team through our website.
      @endif
    </p>
  </div>
</div>
