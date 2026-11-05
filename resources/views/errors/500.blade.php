@extends('errors::minimal')

@section('title', __('Something went wrong'))
@section('code', '500')
@section('message', __('Something went wrong on our side. It has been logged.'))
@section('hint', __('Please try again. If it keeps happening, contact the administrator.'))
