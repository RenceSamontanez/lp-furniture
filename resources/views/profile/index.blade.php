@extends('layouts.app')

@section('title', 'My Account — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/password-toggle.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/my-account.css') }}?v={{ @filemtime(public_path('assets/css/my-account.css')) }}"/>
@endpush

@section('content')
@php
  // Identity state: is_validated on the user is the single source of truth (every other page checks it too).
  $v = $validation;
  $vs = $user->is_validated ? 'approved' : ($v && $v->isPending() ? 'pending' : ($v && $v->isRejected() ? 'rejected' : 'none'));
  $statText = ['none' => 'Not verified', 'pending' => 'Under review', 'rejected' => 'Not verified', 'approved' => 'Verified'][$vs];
  $statCls  = ['none' => 'warn', 'pending' => 'warn', 'rejected' => 'bad', 'approved' => 'ok'][$vs];
  $dotHtml  = ['none' => '!', 'pending' => '&hellip;', 'rejected' => '!', 'approved' => '&#10003;'][$vs];
  $chipText = ['none' => 'Not submitted', 'pending' => 'Under review', 'rejected' => 'Rejected', 'approved' => 'Verified'][$vs];
  $chipCls  = ['none' => 'warn', 'pending' => 'warn', 'rejected' => 'bad', 'approved' => 'ok'][$vs];
  $idText   = ['none' => 'Unverified', 'pending' => 'Under review', 'rejected' => 'Unverified', 'approved' => 'Verified'][$vs];
  $idCls    = ['none' => 'bad', 'pending' => 'warn', 'rejected' => 'bad', 'approved' => 'ok'][$vs];
  $name     = $user->display_name;
  $initials = strtoupper(mb_substr($user->first_name, 0, 1) . mb_substr($user->last_name, 0, 1)) ?: 'U';
  $titles = [
    'personal' => ['Personal information', 'Update your name, phone number and delivery address.'],
    'security' => ['Security', 'Manage how you sign in.'],
    'verify'   => ['Identity verification', 'Required to place orders and use all features.'],
    'account'  => ['Account', 'Your account status at a glance.'],
  ];
  $addr = $savedAddress ?? [];
  $nameErr = fn ($f) => $errors->first($f);
  $sym = fn (string $id) => '<svg aria-hidden="true"><use href="#i-' . $id . '"/></svg>';
  $locked = !$googleOnly && $pwAvailableAt !== null;
  $pwErrors = $errors->hasAny(['current_password', 'password', 'password_form']);
  if ($pwErrors) { $tab = 'security'; }
  elseif ($errors->hasAny(['id_photo', 'selfie_photo', 'confirm'])) { $tab = 'verify'; }
  elseif ($errors->any()) { $tab = 'personal'; }
  $maData = [
    'tab' => $tab, 'googleOnly' => $googleOnly, 'locked' => $locked, 'hasErrors' => $errors->any(),
    'countries' => $countries, 'phoneRules' => \App\Support\PhoneNumber::rulesForScript(),
  ];
  $init = [
    'first_name' => $user->first_name, 'last_name' => $user->last_name,
    'phone_country' => $phoneCountry, 'phone_number' => $phoneNational,
    'province_id' => $addr['province_id'] ?? '', 'province' => $addr['province'] ?? '',
    'city_id' => $addr['city_id'] ?? '', 'city' => $addr['city'] ?? '',
    'barangay_id' => $addr['barangay_id'] ?? '', 'barangay' => $addr['barangay'] ?? '',
    'street' => $addr['street'] ?? '', 'postal_code' => $addr['postal_code'] ?? '', 'landmark' => $addr['landmark'] ?? '',
  ];
@endphp

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
 <symbol id="i-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1-5 4-7 8-7s7 2 8 7"/></symbol>
 <symbol id="i-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 018 0v3"/></symbol>
 <symbol id="i-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></symbol>
 <symbol id="i-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></symbol>
 <symbol id="i-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H5a2 2 0 00-2 2v12a2 2 0 002 2h4M16 8l4 4-4 4M20 12H9"/></symbol>
 <symbol id="i-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V5M7 10l5-5 5 5M5 19h14"/></symbol>
 <symbol id="i-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></symbol>
 <symbol id="i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></symbol>
 <symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
 <symbol id="i-mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 8l9 6 9-6"/></symbol>
 <symbol id="i-cal" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4"/></symbol>
 <symbol id="i-key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/></symbol>
 <symbol id="i-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></symbol>
 <symbol id="i-file" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/></symbol>
 <symbol id="i-spark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.2 5.8L20 11l-5.8 2.2L12 19l-2.2-5.8L4 11l5.8-2.2z"/></symbol>
 <symbol id="i-bell" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0112 0c0 6 2 7 2 7H4s2-1 2-7"/><path d="M10 20a2 2 0 004 0"/></symbol>
 <symbol id="i-star" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></symbol>
 <symbol id="i-pin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.2 7-11.5A7 7 0 005 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></symbol>
 <symbol id="i-badge" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="9" cy="11" r="2"/><path d="M14 10h4M14 14h3M6 16c1-2 5-2 6 0"/></symbol>
</svg>

<div class="ma mx" id="ma">
 <div class="ma-wrap">
  <div class="acct">
    {{-- ───────── left: dark chocolate glass ───────── --}}
    <aside class="side" aria-label="Account settings">
      <div class="who">
        <div class="mono" aria-hidden="true">{{ $initials }}</div>
        <div class="txt"><h2>{{ $name }}</h2><div class="em">{{ $user->email }}</div><span class="stat {{ $statCls }}">{{ $statText }}</span></div>
        <form method="POST" action="{{ route('logout') }}" class="signout">@csrf
          <button type="submit" class="out mini" aria-label="Sign out">{!! $sym('out') !!}</button>
        </form>
      </div>
      <ul class="tabs" id="tabs" role="tablist" aria-label="Account sections">
        <li><button type="button" class="tab" data-t="personal" role="tab" id="tab-personal" aria-controls="v-personal" aria-selected="{{ $tab === 'personal' ? 'true' : 'false' }}" @if($tab === 'personal') aria-current="page" @endif>{!! $sym('user') !!}Personal info</button></li>
        <li><button type="button" class="tab" data-t="security" role="tab" id="tab-security" aria-controls="v-security" aria-selected="{{ $tab === 'security' ? 'true' : 'false' }}" @if($tab === 'security') aria-current="page" @endif>{!! $sym('lock') !!}Security</button></li>
        <li><button type="button" class="tab" data-t="verify" role="tab" id="tab-verify" aria-controls="v-verify" aria-selected="{{ $tab === 'verify' ? 'true' : 'false' }}" @if($tab === 'verify') aria-current="page" @endif>{!! $sym('shield') !!}Verification<span class="dot{{ $vs === 'approved' ? ' ok' : '' }}" aria-hidden="true">{!! $dotHtml !!}</span></button></li>
        <li><button type="button" class="tab" data-t="account" role="tab" id="tab-account" aria-controls="v-account" aria-selected="{{ $tab === 'account' ? 'true' : 'false' }}" @if($tab === 'account') aria-current="page" @endif>{!! $sym('gear') !!}Account</button></li>
      </ul>
      <span class="sp"></span>
      <form method="POST" action="{{ route('logout') }}" class="signout">@csrf
        <button type="submit" class="out full">{!! $sym('out') !!}Sign out</button>
      </form>
    </aside>

    {{-- ───────── right: white/cream glass pane ───────── --}}
    <section class="pane glass">
      <div class="ph"><div><h1 id="pT">{{ $titles[$tab][0] }}</h1><p id="pS">{{ $titles[$tab][1] }}</p></div><span class="chip {{ $chipCls }}" id="pChip" @if($tab !== 'verify') hidden @endif>{{ $chipText }}</span></div>

      <div class="pb">
        {{-- PERSONAL --}}
        <form class="view{{ $tab === 'personal' ? ' on' : '' }}" id="v-personal" role="tabpanel" aria-labelledby="tab-personal" data-title="{{ $titles['personal'][0] }}" data-sub="{{ $titles['personal'][1] }}"
              method="POST" action="{{ route('profile.update') }}" novalidate data-init='@json($init)'>
          @csrf @method('PUT')
          <div class="g12">
            <div class="fld c6"><label for="fn">First name <span class="rq" aria-hidden="true">*</span></label>
              <input class="inp{{ $errors->has('first_name') ? ' bad' : '' }}" id="fn" name="first_name" value="{{ old('first_name', $user->first_name) }}" maxlength="60" autocomplete="given-name" data-name aria-required="true">
              <p class="err{{ $errors->has('first_name') ? ' show' : '' }}">{{ $errors->first('first_name') }}</p></div>
            <div class="fld c6"><label for="ln">Last name <span class="rq" aria-hidden="true">*</span></label>
              <input class="inp{{ $errors->has('last_name') ? ' bad' : '' }}" id="ln" name="last_name" value="{{ old('last_name', $user->last_name) }}" maxlength="60" autocomplete="family-name" data-name aria-required="true">
              <p class="err{{ $errors->has('last_name') ? ' show' : '' }}">{{ $errors->first('last_name') }}</p></div>
            <div class="fld c6"><label for="em">Email</label>
              <div class="lock"><input class="inp" id="em" value="{{ $user->email }}" readonly aria-describedby="emh">{!! $sym('lock') !!}</div>
              <p class="hint" id="emh">This is your sign-in email. It cannot be changed here.</p></div>
            <div class="fld c6">
              @include('partials.phone-field', ['idp' => 'p', 'numName' => 'phone_number', 'ccName' => 'phone_country', 'country' => $phoneCountry, 'national' => $phoneNational])
            </div>
          </div>
          <div class="asec">
            <div class="ash"><h2>Delivery address</h2><span>Required to place an order. You can also add it when you order. <span class="rq">*</span> required</span></div>
            @if($legacyAddress)
              <p class="prevnote" id="prevAddress">{!! $sym('pin') !!}<span><b>Previous address (read only):</b> {{ $legacyAddress }}<br>Please choose the province, city and barangay below before your next order.</span></p>
            @endif
            @include('partials.address-fields', ['p' => '', 'idp' => 'p', 'v' => $addr])
          </div>
        </form>

        {{-- SECURITY --}}
        <div class="view{{ $tab === 'security' ? ' on' : '' }}" id="v-security" role="tabpanel" aria-labelledby="tab-security" data-title="{{ $titles['security'][0] }}" data-sub="{{ $titles['security'][1] }}">
          @if($googleOnly)
            <div class="two">
              <div class="glass card"><span class="ico ok">{!! $sym('shield') !!}</span><h3>Signed in with Google</h3><p>Google checks who you are every time you sign in, so your account is protected without a password from us.</p><a class="lp-btn lp-btn--secondary" href="https://myaccount.google.com/security" target="_blank" rel="noopener" style="margin-top:auto;align-self:flex-start">Manage on Google</a></div>
              <div class="glass card"><span class="ico">{!! $sym('key') !!}</span><h3>Password</h3><p>No password is set on this account, so there is nothing to change here. If you ever lose access, recover your Google account.</p></div>
            </div>
          @else
            @php
              $daysLeft = $locked ? (int) ceil(now()->floatDiffInDays($pwAvailableAt, false)) : 0;
              $daysLeft = max($locked ? 1 : 0, $daysLeft);
            @endphp
            <div class="glass pwage" id="pwAge" data-locked="{{ $locked ? '1' : '0' }}">
              <span class="ico">{!! $sym('clock') !!}</span>
              <div class="t">
                @if($locked)
                  <b>Password changed {{ $pwDaysAgo === 0 ? 'today' : ($pwDaysAgo === 1 ? '1 day ago' : $pwDaysAgo . ' days ago') }}</b>
                  <small>You can change it again on <span class="mx-num">{{ $pwAvailableAt->format('M j, Y') }}</span>. This limit protects your account.</small>
                @else
                  <b>You can change your password</b>
                  <small>Passwords can be changed once a week. There is no deadline to change it.</small>
                @endif
              </div>
              <div class="pbar" aria-hidden="true"><i style="width:{{ $locked ? min(100, round(($pwDaysAgo ?? 0) / 7 * 100)) : 100 }}%;background:{{ $locked ? '#C9973F' : 'var(--ma-ok)' }}"></i></div>
              <span class="chip {{ $locked ? 'warn' : 'ok' }}">{{ $locked ? ($daysLeft === 1 ? '1 day to wait' : $daysLeft . ' days to wait') : 'Available' }}</span>
            </div>
            @if($errors->has('password_form'))
              <x-notice variant="problem" title="Your password was not changed">{{ $errors->first('password_form') }}</x-notice>
            @endif
            <form class="two" id="f-password" method="POST" action="{{ route('profile.password') }}" novalidate>
              @csrf @method('PUT')
              <div class="glass card"><h3>Change password</h3>
                <div class="fld"><label for="p0">Current password</label><div class="input-password-wrap"><input class="inp{{ $errors->has('current_password') ? ' bad' : '' }}" id="p0" name="current_password" type="password" autocomplete="current-password" @disabled($locked)><x-password-toggle for="p0"/></div><p class="err{{ $errors->has('current_password') ? ' show' : '' }}">{{ $errors->first('current_password') }}</p></div>
                <div class="fld"><label for="p1">New password</label><div class="input-password-wrap"><input class="inp{{ $errors->has('password') ? ' bad' : '' }}" id="p1" name="password" type="password" autocomplete="new-password" @disabled($locked)><x-password-toggle for="p1"/></div><p class="err{{ $errors->has('password') ? ' show' : '' }}">{{ $errors->first('password') }}</p></div>
                <div class="fld"><label for="p2">Confirm new password</label><div class="input-password-wrap"><input class="inp" id="p2" name="password_confirmation" type="password" autocomplete="new-password" @disabled($locked)><x-password-toggle for="p2"/></div></div>
              </div>
              <div class="glass card"><h3>Your new password needs</h3>
                <ul class="rules" id="rules"><li data-r="len">At least 8 characters</li><li data-r="case">Upper and lower case letters</li><li data-r="num">A number</li><li data-r="diff">Different from your current password</li><li data-r="same">Both new passwords match</li></ul>
                <p style="margin-top:auto">You can change your password once a week. Changing it signs you out of other devices.</p>
              </div>
            </form>
          @endif
        </div>

        {{-- VERIFICATION --}}
        <div class="view{{ $tab === 'verify' ? ' on' : '' }}" id="v-verify" role="tabpanel" aria-labelledby="tab-verify" data-title="{{ $titles['verify'][0] }}" data-sub="{{ $titles['verify'][1] }}" data-vs="{{ $vs }}"
             data-chip="{{ $chipText }}" data-chip-class="{{ $chipCls }}">
          @php $n = in_array($vs, ['pending', 'approved'], true) ? 4 : 1; @endphp
          <ol class="steps" id="steps">
            @foreach([['1', 'Photo of your ID', 'ID'], ['2', 'Selfie with your ID', 'Selfie'], ['3', 'We review it', 'Review']] as [$num, $long, $short])
              <li data-s="{{ $num }}" class="{{ (int) $num < $n ? 'done' : ((int) $num === $n ? 'now' : '') }}"><b>{!! (int) $num < $n ? '&#10003;' : $num !!}</b><span class="l">{{ $long }}</span><span class="sh">{{ $short }}</span></li>
            @endforeach
          </ol>
          <div class="vg">
            <div class="vl">
              @if($vs === 'pending')
                <div class="glass state">
                  <span class="ico">{!! $sym('clock') !!}</span><h3>We are reviewing your photos</h3>
                  <p>You do not need to do anything. We will notify you here as soon as there is a decision.</p>
                  <div class="photos">
                    <figure><img src="{{ route('validation.photo', ['id' => $v->id, 'type' => 'id']) }}" alt="Your ID photo" loading="lazy"><figcaption>Photo of your ID</figcaption></figure>
                    <figure><img src="{{ route('validation.photo', ['id' => $v->id, 'type' => 'selfie']) }}" alt="Your selfie holding your ID" loading="lazy"><figcaption>Selfie holding your ID</figcaption></figure>
                  </div>
                  <div class="kv"><span>Submitted <b class="mx-num">{{ $v->submitted_at ? $v->submitted_at->format('M j, Y') : 'today' }}</b></span><span>Attempt <b class="mx-num">{{ $v->attempt_number }}</b></span></div>
                </div>
              @elseif($vs === 'approved')
                <div class="glass state">
                  <span class="ico ok">{!! $sym('shield') !!}</span><h3>You are verified</h3>
                  <p>You can place orders and use every feature of LP Furniture. Nothing more to do.</p>
                  <div class="kv"><span>Identity verified</span></div>
                </div>
              @else
                @if($vs === 'rejected')
                  <div class="banner bad">Your last submission was rejected.<small>Reason: {{ rtrim($v->rejection_reason ?: 'Please send clearer photos', '.') }}. @if($v->follow_up_requested_at) We asked you to send a new submission. @endif Add new photos using the tips on the right. This will be attempt {{ $attempt }}.</small></div>
                @endif
                @if($errors->hasAny(['id_photo', 'selfie_photo', 'confirm']))
                  <x-notice variant="problem" title="Please fix this and try again"><ul style="margin:0;padding-left:18px">@foreach(collect(['id_photo', 'selfie_photo', 'confirm'])->flatMap(fn ($k) => $errors->get($k)) as $m)<li>{{ $m }}</li>@endforeach</ul></x-notice>
                @endif
                <form id="f-verify" method="POST" action="{{ route('validation.submit') }}" enctype="multipart/form-data" novalidate style="display:flex;flex-direction:column;gap:14px;flex:1;min-height:0">
                  @csrf <input type="hidden" name="return_to" value="profile">
                  <div class="slots">
                    @foreach([['id_photo', 'Photo of your ID', 'Upload photo of your ID'], ['selfie_photo', 'Selfie holding your ID', 'Upload selfie holding your ID']] as $i => [$field, $title, $label])
                      <div class="slot"><h3>{{ $title }}</h3>
                        <label class="drop" id="d{{ $i }}"><input type="file" name="{{ $field }}" accept="image/jpeg,image/png" data-s="{{ $i }}" aria-label="{{ $label }}" aria-describedby="m{{ $i }}"><img alt="" hidden><span><svg aria-hidden="true"><use href="#i-up"/></svg><p>Tap to add<small>JPEG or PNG, up to 5 MB</small></p></span><span class="chg">Change</span></label>
                        <p class="err" id="m{{ $i }}" role="alert"></p></div>
                    @endforeach
                  </div>
                  <label class="chkrow"><input type="checkbox" name="confirm" value="1" id="gen"><span>These photos are genuine and show me.</span></label>
                  <button class="lp-btn lp-btn--primary" type="submit" id="vSub" aria-disabled="true">Submit for review</button>
                </form>
              @endif
            </div>
            <aside class="glass vr" aria-label="What we check">
              <h2>What we check</h2>
              <ul>
                @foreach($checks as $key => $label)
                  <li><i><svg aria-hidden="true"><use href="#i-ok"/></svg></i><span><b>{{ $label }}</b><small>{{ ['name' => 'Use the full name you registered with.', 'face' => 'Whole face visible, no mask, filters or sunglasses.', 'id' => 'Not expired. All four corners show, no glare or blur.'][$key] ?? '' }}</small></span></li>
                @endforeach
              </ul>
              <div><b style="font-size:.92rem">IDs we accept</b><div class="tags" style="margin-top:6px">@foreach($acceptedIds as $idName)<span>{{ $idName }}</span>@endforeach</div></div>
              <div class="dd">
                <ul><li><i><svg aria-hidden="true"><use href="#i-ok"/></svg></i><span>Bright, even light</span></li><li><i><svg aria-hidden="true"><use href="#i-ok"/></svg></i><span>The original ID</span></li></ul>
                <ul><li><i class="x"><svg aria-hidden="true"><use href="#i-x"/></svg></i><span>Edited or cropped</span></li><li><i class="x"><svg aria-hidden="true"><use href="#i-x"/></svg></i><span>Screenshots, copies</span></li></ul>
              </div>
              <p class="note">Only LP Furniture admins can see your photos. We use them to verify you and for nothing else.</p>
            </aside>
          </div>
        </div>

        {{-- ACCOUNT --}}
        <div class="view{{ $tab === 'account' ? ' on' : '' }}" id="v-account" role="tabpanel" aria-labelledby="tab-account" data-title="{{ $titles['account'][0] }}" data-sub="{{ $titles['account'][1] }}">
          <div class="tiles">
            <div class="glass tile"><span class="k">{!! $sym('user') !!}Account type</span><span class="v">Customer</span></div>
            <div class="glass tile"><span class="k">{!! $sym('cal') !!}Member since</span><span class="v mx-num">{{ $user->created_at ? $user->created_at->format('M j, Y') : '' }}</span></div>
            <div class="glass tile"><span class="k">{!! $sym('mail') !!}Email</span><span class="v {{ $user->email_verified_at ? 'ok' : 'warn' }}">{{ $user->email_verified_at ? 'Verified' : 'Not verified' }}</span></div>
            <div class="glass tile"><span class="k">{!! $sym('pulse') !!}Account status</span><span class="v {{ $user->account_status === 'active' ? 'ok' : 'bad' }}">{{ ucfirst($user->account_status ?: 'active') }}</span></div>
            <div class="glass tile"><span class="k">{!! $sym('key') !!}Sign-in method</span><span class="v">{{ $googleOnly ? 'Google' : 'Email and password' }}</span></div>
            <div class="glass tile"><span class="k">{!! $sym('badge') !!}Identity</span><span class="v {{ $idCls }}">{{ $idText }}</span>@if(!in_array($vs, ['approved', 'pending'], true))<a href="#v-verify" class="lp-link" data-go="verify">Verify now</a>@endif</div>
          </div>
          <div class="jump"><h2>Go to</h2><div class="jl">
            <a class="glass" href="{{ route('orders.index') }}">{!! $sym('file') !!}My orders</a>
            <a class="glass" href="{{ route('design.gallery') }}">{!! $sym('spark') !!}My designs</a>
            <a class="glass" href="{{ route('notifications.index') }}">{!! $sym('bell') !!}Notifications</a>
            <a class="glass" href="{{ route('feedback.index') }}">{!! $sym('star') !!}Reviews</a>
          </div></div>
        </div>
      </div>

      <div class="foot" id="foot">
        <span class="msg" id="fMsg" aria-live="polite">Your changes are saved to your account only.</span>
        <button class="lp-btn lp-btn--secondary ghost" type="button" id="fDisc" hidden>Discard</button>
        <button class="lp-btn lp-btn--primary" type="submit" id="fSave" form="f-personal" aria-disabled="true">Save changes</button>
      </div>
    </section>
  </div>
 </div>
</div>

<script type="application/json" id="ma-data">@json($maData)</script>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/password-toggle.js') }}"></script>
  <script src="{{ asset('assets/js/my-account.js') }}?v={{ @filemtime(public_path('assets/js/my-account.js')) }}"></script>
@endpush
