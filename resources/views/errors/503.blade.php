@extends('errors::minimal')

@section('title', __('Service Unavailable'))
@section('code', '503')
{{-- Say why (for example the student database server is off), not just "Service Unavailable". --}}
@section('message', __($exception?->getMessage() ?: 'Service Unavailable'))
