@extends('errors::minimal')

@section('title', __('Your session expired'))
@section('code', '419')
@section('message', __('Your session expired. Log in again to continue.'))
@section('hint', __('For your security you are signed out after a period of inactivity. Anything you had not saved will need to be entered again.'))

@section('actions')
    <a class="button button-primary" href="{{ \Illuminate\Support\Facades\Route::has('filament.admin.auth.login') ? route('filament.admin.auth.login') : url('/admin/login') }}">{{ __('Log in again') }}</a>
@endsection
