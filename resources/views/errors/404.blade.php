@extends('layouts.app')

@section('title', 'We could not find that page — LP Furniture')

@php
  $code = '404';
  $heading = 'We could not find that page';
  $text = 'The link may be old or the page may have moved.';
@endphp
@include('errors._page')
