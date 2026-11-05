@extends('errors::minimal')

@section('title', __('You don’t have access to this page'))
@section('code', '403')
@section('message', __('Your account is not allowed to open this page or do this action.'))
@section('hint', __('If you need access, ask your administrator to update your role.'))
