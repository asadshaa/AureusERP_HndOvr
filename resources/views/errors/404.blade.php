@extends('errors::minimal')

@section('title', __('Page not found'))
@section('code', '404')
@section('message', __('We couldn’t find this page. The link may be old, or the record may have been deleted.'))
@section('hint', __('Go back to the dashboard and open it again from the menu.'))
