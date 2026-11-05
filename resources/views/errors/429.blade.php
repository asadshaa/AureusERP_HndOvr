@extends('errors::minimal')

@section('title', __('Too many attempts'))
@section('code', '429')
@section('message', __('You tried this too many times in a short period.'))
@section('hint', __('Please wait a minute, then try again.'))
