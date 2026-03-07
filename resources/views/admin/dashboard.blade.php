@extends('admin.layout')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Dashboard</h1>
    <div class="grid gap-4 md:grid-cols-2">
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <p class="text-sm text-gray-500 mb-1">App users</p>
            <p class="text-3xl font-semibold">{{ $usersCount }}</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <p class="text-sm text-gray-500 mb-1">Active subscriptions</p>
            <p class="text-3xl font-semibold">{{ $activeSubscriptionsCount }}</p>
        </div>
    </div>
@endsection
