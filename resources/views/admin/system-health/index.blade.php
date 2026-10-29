@extends('layouts.admin')

@section('title', 'System health — Admin')
@section('page-title', 'System Health')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}?v={{ @filemtime(public_path('assets/css/admin-modals.css')) }}"/>
@endpush

@php
  use App\Support\UploadFolders;
  $canResize = \App\Support\UploadedImage::canResize();
@endphp

@section('content')

<p class="page-lead">A quick look at stored files and routine cleanup. Nothing here needs attention unless the orphan count goes up.</p>

<div class="admin-stats-grid">
  <div class="admin-stat-card">
    <div><div class="admin-stat-value">{{ UploadFolders::human($report['bytes']) }}</div><div class="admin-stat-label">Disk Used by Uploads ({{ $report['files'] }} files)</div></div>
  </div>
  <div class="admin-stat-card {{ $report['orphans'] > 0 ? 'accent-red' : '' }}">
    <div><div class="admin-stat-value">{{ $report['orphans'] }}</div><div class="admin-stat-label">Orphan Files ({{ UploadFolders::human($report['orphan_bytes']) }})</div></div>
  </div>
  <div class="admin-stat-card">
    <div>
      <div class="admin-stat-value" style="font-size:18px;">{{ $lastCleanup ? $lastCleanup['at']->timezone('Asia/Manila')->format('M j, Y g:i A') : 'Never' }}</div>
      <div class="admin-stat-label">Last Storage Cleanup</div>
    </div>
  </div>
</div>

<div class="sh-grid">
  <div class="admin-panel" style="margin:0;">
    <div class="admin-panel-header"><div class="admin-panel-title">Uploads</div></div>
    <div class="admin-panel-body" style="padding:0;">
      <table class="admin-table">
        <thead><tr><th>Folder</th><th class="sh-num">Files</th><th class="sh-num">Size</th></tr></thead>
        <tbody>
          @foreach($report['folders'] as $f)
            <tr><td data-label="Folder">{{ $f['label'] }}</td><td data-label="Files" class="sh-num">{{ $f['files'] }}</td><td data-label="Size" class="sh-num">{{ UploadFolders::human($f['bytes']) }}</td></tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="sh-side">
    <div class="admin-panel" style="margin:0;">
      <div class="admin-panel-header"><div class="admin-panel-title">Maintenance</div></div>
      <div class="admin-panel-body">
        <p class="sh-note">Clears the application cache, the config cache and the compiled views. No orders, accounts or uploads are touched. Everyone stays signed in.</p>
        <button type="button" class="admin-btn admin-btn-gold" data-lp-modal-open="clear-cache">Clear Cache</button>
      </div>
    </div>

    <div class="admin-panel" style="margin:0;">
      <div class="admin-panel-header"><div class="admin-panel-title">About Orphan Files</div></div>
      <div class="admin-panel-body">
        <p class="sh-note is-flush">
          An orphan is an uploaded file with no database row that is more than 24 hours old. It is removed every day at 03:00 (Manila time)
          by <code>storage:cleanup</code>, together with expired sessions and failed jobs older than 14 days. Product photos are not counted here and are never removed.
        </p>
        @unless($canResize)
          <p class="sh-note" style="color:#B3261E;margin:10px 0 0;">The PHP <code>gd</code> extension is not enabled on this server, so large photos are saved at full size instead of being shrunk to 1600 px.</p>
        @endunless
      </div>
    </div>
  </div>
</div>

<x-modal id="clear-cache" title="Clear the Cache?">
  <p>This clears the cache, config and compiled views. The site may be a little slower for the next few visits while they rebuild.</p>
  <form method="POST" action="{{ route('admin.system-health.clear-cache') }}" id="clear-cache-form">@csrf</form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go Back</button>
    <button type="submit" form="clear-cache-form" class="lp-btn lp-btn--primary" data-lp-confirm>Clear Cache</button>
  </x-slot:footer>
</x-modal>

@endsection
