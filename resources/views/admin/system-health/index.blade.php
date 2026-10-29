@extends('layouts.admin')

@section('title', 'System health — Admin')
@section('page-title', 'System Health')

@php
  use App\Support\UploadFolders;
  $canResize = \App\Support\UploadedImage::canResize();
@endphp

@section('content')

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

<div class="admin-panel" style="max-width:820px;">
  <div class="admin-panel-header"><div class="admin-panel-title">Uploads</div></div>
  <div class="admin-panel-body">
    <table class="admin-table">
      <thead><tr><th>Folder</th><th>Files</th><th>Size</th></tr></thead>
      <tbody>
        @foreach($report['folders'] as $f)
          <tr><td>{{ $f['label'] }}</td><td>{{ $f['files'] }}</td><td>{{ UploadFolders::human($f['bytes']) }}</td></tr>
        @endforeach
      </tbody>
    </table>
    <p style="font-size:12px;color:var(--text-muted);margin:14px 0 0;">
      An orphan is an uploaded file with no database row that is more than 24 hours old. It is removed every day at 03:00 (Manila time)
      by <code>storage:cleanup</code>, together with expired sessions and failed jobs older than 14 days. Product photos are not counted here and are never removed.
    </p>
    @unless($canResize)
      <p style="font-size:12px;color:#B3261E;margin:10px 0 0;">The PHP <code>gd</code> extension is not enabled on this server, so large photos are saved at full size instead of being shrunk to 1600 px.</p>
    @endunless
  </div>
</div>

<div class="admin-panel" style="max-width:820px;margin-top:18px;">
  <div class="admin-panel-header"><div class="admin-panel-title">Cache</div></div>
  <div class="admin-panel-body">
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">Clears the application cache, the config cache and the compiled views. No orders, accounts or uploads are touched. Everyone stays signed in.</p>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-open="clear-cache">Clear Cache</button>
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
