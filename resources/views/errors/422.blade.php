@extends('errors::minimal')

@section('title', __('This could not be completed'))
@section('code', '422')
@section('message', $message ?? __('The request could not be completed because some information is not valid.'))
@section('hint', __('Check the details and try again.'))
