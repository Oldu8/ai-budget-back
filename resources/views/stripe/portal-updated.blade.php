@extends('stripe.layout')

@php
    $heading = 'Plan updated!';
    $message = 'Your subscription has been changed successfully. You can close this window and return to the app to enjoy your new plan.';
    $accentBg = '#9deee8';
    $accentFg = '#006a66';
    $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    $appLink = 'vtrata://billing?status=updated';
@endphp
