@extends('layouts.app')

@section('title', 'Customize — LP Furniture')

@php
  $ver  = fn (string $p) => asset($p) . '?v=' . (@filemtime(public_path($p)) ?: 1);
  $icons = config('customize.icons');
  $svg  = fn (string $k) => '<svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">' . ($icons[$k] ?? '') . '</svg>';

  $oldType   = old('furniture_type');
  $oldWood   = old('preferred_material');
  $oldStyle  = old('style_preference');
  $isOther   = $oldType === \App\Support\FurnitureTypes::OTHER;
  $isSpecial = $oldWood === \App\Support\WoodChoices::SPECIAL;
  $outOfDesigns = $dailyUsed >= $dailyLimit;

  // which step each field lives on (0 type, 1 wood, 2 style and photo, 3 size, 4 describe): the page opens on the first with an error
  $stepFields = [
    0 => ['furniture_type', 'furniture_type_other'],
    1 => ['preferred_material', 'wood_special_details', 'wood_supply'],
    2 => ['style_preference', 'reference_image'],
    3 => ['dimension_length', 'dimension_width', 'dimension_height', 'dimension_unit', 'additional_info'],
    4 => ['prompt'],
  ];
  $stepErrors = [];
  foreach ($stepFields as $i => $fields) {
    $stepErrors[$i] = collect($fields)->flatMap(fn ($f) => $errors->get($f))->unique()->values()->all();
  }
  $errorStep = collect($stepErrors)->filter()->keys()->first();

  // up to three recent designs with a picture, newest first
  $recent = collect();
  if ($latestImage) { $recent->push(['request' => $latestWithImages, 'image' => $latestImage]); }
  foreach ($historyThumbs as $entry) { $recent->push($entry); }
  $recent = $recent->take(3);

  $studioData = [
    'icons'      => $icons,
    'ideas'      => \App\Models\DesignIdea::forStudio(),
    'woods'      => collect(config('customize.woods'))->map(fn ($w) => [$w[0], $w[1]]),
    'lookupUrl'  => route('design.ideaAnswer'),
    'errorStep'  => $errorStep,
    'other'      => \App\Support\FurnitureTypes::OTHER,
    'special'    => \App\Support\WoodChoices::SPECIAL,
    'outOfDesigns' => $outOfDesigns,
    'hadErrors'  => $errors->any(),
  ];
@endphp

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/guide.css') }}"/>
  <link rel="stylesheet" href="{{ $ver('assets/css/customize-studio.css') }}"/>
@endpush

@section('content')
<div class="cz" id="cz">
  <div class="cz-grid">

    {{-- ═══════════ LEFT: the steps ═══════════ --}}
    <section class="cz-panel cz-glass" aria-labelledby="cz-title">
      <div class="cz-ph">
        <div class="cz-ph-text">
          <h1 id="cz-title">Customize your furniture</h1>
          <p class="cz-sub">Tell us what you want. We draw it, you send it to our team.</p>
        </div>
        <div class="cz-pills">
          <a href="{{ route('home') }}" class="lp-btn lp-btn--secondary lp-btn--chip">&larr; Back</a>
          <a href="{{ route('design.gallery', ['tab' => 'history']) }}" class="lp-btn lp-btn--secondary lp-btn--chip">My Designs</a>
          <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-lp-modal-open="cz-howitworks">? How it works</button>
        </div>
      </div>

      <form method="POST" action="{{ route('design.submit') }}" enctype="multipart/form-data" id="cz-form" class="cz-form" novalidate>
        @csrf

        <ol class="cz-steps" id="cz-steps" aria-label="Steps">
          @foreach(['Type', 'Wood', 'Style', 'Size', 'Describe'] as $i => $label)
            <li><button type="button" class="cz-st" data-i="{{ $i }}"><i aria-hidden="true"></i><span>{{ $i + 1 }}<span class="cz-lbl">. {{ $label }}</span></span></button></li>
          @endforeach
        </ol>

        @if($errors->any())
          <div class="cz-errors" id="cz-errors" role="alert">
            <strong>Please check these:</strong>
            <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
          </div>
        @endif

        <div class="cz-body">

          {{-- 1. TYPE --}}
          <fieldset class="cz-step" data-step="0">
            <legend class="cz-q">What do you want built?</legend>
            <p class="cz-hint">Pick the piece of furniture. The picture will be this type.</p>
            <div class="cz-tiles">
              @foreach(\App\Support\FurnitureTypes::CHOICES as $value => $label)
                <label class="cz-tile">
                  <input type="radio" name="furniture_type" value="{{ $value }}" data-label="{{ $label }}" required @checked($oldType === $value)/>
                  <span class="cz-tile-face">{!! $svg($value) !!}<span>{{ $label }}</span></span>
                </label>
              @endforeach
            </div>
            <label class="cz-wide">
              <input type="radio" name="furniture_type" value="{{ \App\Support\FurnitureTypes::OTHER }}" data-label="Something else" @checked($isOther)/>
              <span class="cz-wide-face">{!! $svg('Other') !!}<span>Something else<small>Not on the list? Tell us what you want built.</small></span></span>
            </label>
            <div class="cz-other" id="cz-type-other" @unless($isOther) hidden @endunless>
              <label for="cz-type-text">What is it?</label>
              <input type="text" id="cz-type-text" name="furniture_type_other" class="cz-field" maxlength="{{ \App\Support\FurnitureTypes::OTHER_MAX }}"
                     value="{{ old('furniture_type_other') }}" placeholder="e.g. Staircase railing, altar, TV wall panel" autocomplete="off" aria-describedby="cz-type-msg"/>
              <div class="cz-chips" aria-label="Examples">
                @foreach(config('customize.other_type_examples') as $example)
                  <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-example="{{ $example }}">{{ $example }}</button>
                @endforeach
              </div>
              <p class="cz-msg" id="cz-type-msg" role="alert"></p>
              <div class="cz-note-slot" id="cz-type-note" aria-live="polite"></div>
            </div>
          </fieldset>

          {{-- 2. WOOD --}}
          <fieldset class="cz-step" data-step="1">
            <legend class="cz-q">Choose your wood</legend>
            <p class="cz-hint">We build with these three woods. Want another wood? Pick "Other wood".</p>
            <div class="cz-woods">
              @foreach(config('customize.woods') as $wood => [$light, $dark, $blurb])
                <label class="cz-wood">
                  <input type="radio" name="preferred_material" value="{{ $wood }}" required @checked($oldWood === $wood)/>
                  <span class="cz-wood-face">
                    <span class="cz-sw" style="background:linear-gradient(135deg,{{ $light }},{{ $dark }})"></span>
                    <span class="cz-wood-tx"><b>{{ $wood }}</b><small>{{ $blurb }}</small></span>
                  </span>
                </label>
              @endforeach
              <label class="cz-wood cz-wood--other">
                <input type="radio" name="preferred_material" value="{{ \App\Support\WoodChoices::SPECIAL }}" required @checked($isSpecial)/>
                <span class="cz-wood-face">
                  <span class="cz-sw" aria-hidden="true">+</span>
                  <span class="cz-wood-tx"><b>{{ \App\Support\WoodChoices::SPECIAL_LABEL }}</b><small>Ask for a different wood</small></span>
                </span>
              </label>
            </div>

            <div class="cz-wreq" id="cz-wood-special" @unless($isSpecial) hidden @endunless>
              <label for="cz-wood-name">Which wood do you want?</label>
              <input type="text" id="cz-wood-name" name="wood_special_details" class="cz-field" maxlength="{{ \App\Support\WoodChoices::DETAILS_MAX }}"
                     value="{{ old('wood_special_details') }}" placeholder="e.g. Narra, Teak, Molave" autocomplete="off" aria-describedby="cz-wood-msg"/>
              <p class="cz-msg" id="cz-wood-msg" role="alert"></p>
              <div class="cz-note-slot" id="cz-wood-note" aria-live="polite"></div>
              <div class="cz-opts">
                <label class="cz-opt">
                  <input type="radio" name="wood_supply" value="{{ \App\Support\WoodChoices::SUPPLY_CUSTOMER }}" @checked(old('wood_supply') === \App\Support\WoodChoices::SUPPLY_CUSTOMER)/>
                  <span class="cz-opt-face">
                    <span class="cz-badge cz-badge--lo">LOWER COST</span>
                    <b>I will provide the wood</b>
                    <ul><li>Our team calls you to agree how much wood is needed</li><li>Production starts once the wood arrives</li></ul>
                  </span>
                </label>
                <label class="cz-opt">
                  <input type="radio" name="wood_supply" value="{{ \App\Support\WoodChoices::SUPPLY_SHOP }}" @checked(old('wood_supply') === \App\Support\WoodChoices::SUPPLY_SHOP)/>
                  <span class="cz-opt-face">
                    <span class="cz-badge cz-badge--hi">LONGER AND COSTS MORE</span>
                    <b>LP Furniture buys the wood</b>
                    <ul><li>We search for the wood first, which takes time</li><li>The wood cost is added to your quote</li></ul>
                  </span>
                </label>
              </div>
            </div>
          </fieldset>

          {{-- 3. STYLE AND PHOTO --}}
          <fieldset class="cz-step" data-step="2">
            <legend class="cz-q">Pick a style <span class="cz-opt-tag">(optional)</span></legend>
            <p class="cz-hint">Look at the photos. Not sure, or want something different? Upload your own reference photo (JPG or PNG).</p>
            <div class="cz-styles">
              @foreach(\App\Support\StyleChoices::LIST as $style)
                <label class="cz-style">
                  <input type="radio" name="style_preference" value="{{ $style }}" @checked($oldStyle === $style)/>
                  <span class="cz-style-face">
                    <span class="cz-style-ph">
                      <img src="{{ \App\Support\StyleChoices::photoUrl($style) }}" alt="" loading="lazy" decoding="async"/>
                      @unless(\App\Support\StyleChoices::hasRealPhoto($style))<span class="cz-ph-tag">PLACEHOLDER</span>@endunless
                    </span>
                    <span class="cz-style-tx"><b>{{ $style }}</b><small>{{ \App\Support\StyleChoices::BLURB[$style] }}</small></span>
                  </span>
                </label>
              @endforeach
              <div class="cz-style cz-up" id="cz-up">
                <input type="file" id="cz-photo" name="reference_image" accept="image/jpeg,image/png" aria-describedby="cz-up-msg"/>
                <label for="cz-photo" class="cz-up-face" id="cz-up-face">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                  <span class="cz-up-lab" id="cz-up-lab">Upload your own photo</span>
                </label>
                <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip cz-up-x" id="cz-up-x" hidden>Remove photo</button>
              </div>
            </div>
            <p class="cz-msg" id="cz-up-msg" role="alert">@error('reference_image'){{ $message }}@enderror</p>
            <p class="cz-fine">Your photo goes to our team to look at. The picture we draw is made from your words and choices, not from the photo.</p>
          </fieldset>

          {{-- 4. SIZE --}}
          <fieldset class="cz-step" data-step="3">
            <legend class="cz-q">How big should it be? <span class="cz-opt-tag">(optional)</span></legend>
            <p class="cz-hint" id="cz-size-hint"><strong>Length</strong> is the longest side across. <strong>Width</strong> is the shorter side across. <strong>Height</strong> is from the floor to the top. Not sure yet? Leave it empty.</p>
            <div class="cz-size">
              @foreach(['length' => ['Length', '120'], 'width' => ['Width', '60'], 'height' => ['Height', '75']] as $key => [$label, $example])
                <div class="cz-size-field">
                  <label for="cz-dim-{{ $key }}">{{ $label }}</label>
                  <input type="text" inputmode="decimal" autocomplete="off" id="cz-dim-{{ $key }}" name="dimension_{{ $key }}" class="cz-field"
                         value="{{ old('dimension_' . $key) }}" placeholder="{{ $example }}" aria-describedby="cz-size-hint"/>
                </div>
              @endforeach
              <div class="cz-size-field">
                <label for="cz-dim-unit">Unit</label>
                <select id="cz-dim-unit" name="dimension_unit" class="cz-field">
                  @foreach(\App\Support\Dimensions::UNITS as $unit => $unitLabel)
                    <option value="{{ $unit }}" @selected(old('dimension_unit', 'cm') === $unit)>{{ $unitLabel }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            <details class="cd-measure" id="cd-measure">
              <summary>Need help measuring?</summary>
              <div class="cd-measure-body">
                <p class="cd-measure-links"><a href="{{ route('guide') }}" target="_blank" rel="noopener" data-no-loading>Read the full guide: how to request a custom design</a></p>
                @include('guide._measure')
              </div>
            </details>
            <label class="cz-more" for="cz-more">Anything else we should know? <span class="cz-opt-tag">(optional)</span></label>
            <textarea id="cz-more" name="additional_info" class="cz-field cz-more-area" rows="2" maxlength="1000"
                      placeholder="Storage drawers, foldable mechanism, hidden compartments, special finish, etc.">{{ old('additional_info') }}</textarea>
          </fieldset>

          {{-- 5. DESCRIBE --}}
          <fieldset class="cz-step" data-step="4">
            <legend class="cz-q">Describe it in your own words <span class="cz-req" aria-hidden="true">*</span><span class="cz-sr"> (required)</span></legend>
            <p class="cz-hint">The picture is drawn only from what you write, so the more detail, the closer it gets.</p>
            <div class="cz-guide">
              <div class="cz-gc cz-gc--bad"><i>WEAK</i>"A table" has no size, shape or finish, so the picture is a guess.</div>
              <div class="cz-gc cz-gc--good"><i>GOOD</i>"Six-seater dining table, rectangular, thick rounded edges, natural matte finish."</div>
            </div>
            <textarea id="cz-desc" name="prompt" class="cz-desc" required minlength="15" maxlength="1000" aria-describedby="cz-desc-msg cz-count"
                      placeholder="Write what you want to see">{{ old('prompt', $prefillPrompt) }}</textarea>
            <div class="cz-meter">
              <span id="cz-mlabel">Describe what you want</span>
              <div class="cz-bar" aria-hidden="true"><i id="cz-mbar"></i></div>
              <span class="cz-count" id="cz-count">0 / 1000</span>
            </div>
            <p class="cz-msg" id="cz-desc-msg" role="alert"></p>
            <div class="cz-check" id="cz-check"></div>
            <div class="cz-note-slot" id="cz-desc-note" aria-live="polite"></div>
            <div class="cz-sug">
              <div class="cz-cap" id="cz-icap">QUICK IDEAS: TAP TO ADD</div>
              <div class="cz-chips" id="cz-ideas"></div>
            </div>
          </fieldset>

        </div>

        <div class="cz-foot">
          <button type="button" class="lp-btn lp-btn--secondary" id="cz-prev">&larr; Back</button>
          <span class="cz-need" id="cz-need" role="status"></span>
          <button type="button" class="lp-btn lp-btn--primary" id="cz-next">Next &rarr;</button>
          <button type="submit" class="lp-btn lp-btn--primary" id="cz-create" @disabled($outOfDesigns)>
            <svg class="lp-btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8z"/></svg>
            Create my design
          </button>
        </div>
      </form>
    </section>

    {{-- ═══════════ RIGHT: the live brief ═══════════ --}}
    <section class="cz-stage" id="cz-stage" aria-label="Your design brief" data-nav="dark">
      <div class="cz-sh"><span class="cz-live">YOUR DESIGN BRIEF</span><span id="cz-stcount"></span></div>
      <div class="cz-brief" aria-live="polite">
        <div class="cz-ring" id="cz-ring"></div>
        <div class="cz-bt" id="cz-bt">Your piece will appear here</div>
        <div class="cz-tags" id="cz-tags"></div>
        <p class="cz-bd cz-bd--empty" id="cz-bd">Start by choosing a furniture type on the left. Everything you pick shows up here.</p>
      </div>
      <div class="cz-film">
        <span class="cz-cap">RECENT</span>
        @forelse($recent as $entry)
          <a class="cz-f" href="{{ route('design.show', $entry['request']->id) }}" aria-label="Open your design from {{ $entry['request']->created_at->format('M j') }}">
            <img src="{{ $entry['image']->displayUrl() }}" alt="" loading="lazy" decoding="async" onerror="this.style.display='none'"/>
          </a>
        @empty
          <span class="cz-film-empty">Your first design will show here.</span>
        @endforelse
        <span class="cz-limit">
          @if($outOfDesigns)
            You have used all {{ $dailyLimit }} designs for today. The limit resets at midnight.
          @else
            {{ $dailyLimit - $dailyUsed }} of {{ $dailyLimit }} designs left today
          @endif
        </span>
      </div>
    </section>

  </div>

  <x-modal id="cz-howitworks" title="How it works">
    <p>Tell us the piece you are picturing and we will draw a few concepts for you to choose from. No design experience needed. The more specific you are, the closer the first draft will be to what is in your head.</p>
    <ul class="cz-how">
      <li><strong>Name the piece.</strong> A dining table, a bookshelf, a bed frame. Start with what it is.</li>
      <li><strong>Pick a wood.</strong> Gmelina, Mahogany or Acacia. Another wood? Choose "Other wood" and our team will call you.</li>
      <li><strong>Choose a style.</strong> Rustic, modern, classic. Pick a mood, or upload a photo you like.</li>
      <li><strong>Add size if it matters.</strong> Length, width and height help us get the proportions right.</li>
    </ul>
    <p><strong>Good:</strong> "A rustic dining table with turned legs and a distressed finish, seats six."<br/>
       <strong>Too vague:</strong> "A table".</p>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--primary" data-lp-modal-close>Got it, let's design</button>
    </x-slot:footer>
  </x-modal>
</div>

<script type="application/json" id="cz-data">{!! json_encode($studioData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
  <script src="{{ $ver('assets/js/customize-studio.js') }}" defer></script>
@endpush
