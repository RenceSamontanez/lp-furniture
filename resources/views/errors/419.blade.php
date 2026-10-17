@extends('layouts.app')

@section('title', 'Your session expired, please refresh and try again — LP Furniture')

@php
  $code = '419';
  $heading = 'Your session expired, please refresh and try again';
  $text = 'For your safety we close pages that stay open for a long time. Refresh the page and try again.';
@endphp
@include('errors._page')
