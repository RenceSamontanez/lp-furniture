@extends('layouts.app')

@section('title', 'You do not have access to this page — LP Furniture')

@php
  $code = '403';
  $heading = 'You do not have access to this page';
  $text = 'If you think you should, please contact us and we will help.';
@endphp
@include('errors._page')
