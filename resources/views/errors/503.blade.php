@extends('errors::minimal')

@section('title', __('We’ll be right back'))
@section('code', '503')
@section('message', __('The system is being updated and is briefly unavailable.'))
@section('hint', __('Please try again in a few minutes. Your saved work is safe.'))

@section('actions')
    <a class="button button-primary" href="javascript:location.reload()">{{ __('Try again') }}</a>
@endsection
