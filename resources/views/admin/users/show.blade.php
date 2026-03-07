@extends('admin.layout')

@section('content')
    <div class="mb-4">
        <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Users</a>
    </div>
    <h1 class="text-2xl font-semibold mb-6">User #{{ $user->id }}</h1>

    <div class="space-y-6">
        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-medium mb-4">Profile</h2>
            <dl class="grid gap-2 sm:grid-cols-2 text-sm">
                <dt class="text-gray-500">Email</dt>
                <dd>{{ $user->email }}</dd>
                <dt class="text-gray-500">First name</dt>
                <dd>{{ $user->first_name ?? '—' }}</dd>
                <dt class="text-gray-500">Last name</dt>
                <dd>{{ $user->last_name ?? '—' }}</dd>
                <dt class="text-gray-500">Email verified</dt>
                <dd>{{ $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i') : '—' }}</dd>
                <dt class="text-gray-500">Created</dt>
                <dd>{{ $user->created_at->format('Y-m-d H:i') }}</dd>
            </dl>
        </div>

        @if ($user->settings)
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-lg font-medium mb-4">Settings</h2>
                <dl class="grid gap-2 sm:grid-cols-2 text-sm">
                    <dt class="text-gray-500">Currency</dt>
                    <dd>{{ $user->settings->currency ?? '—' }}</dd>
                    <dt class="text-gray-500">Language</dt>
                    <dd>{{ $user->settings->language ?? '—' }}</dd>
                    <dt class="text-gray-500">Timezone</dt>
                    <dd>{{ $user->settings->timezone ?? '—' }}</dd>
                </dl>
            </div>
        @endif

        <div class="bg-white rounded-lg border border-gray-200 p-6">
            <h2 class="text-lg font-medium mb-4">Subscriptions</h2>
            @php
                $subs = $user->userSubscriptions->sortByDesc('current_period_end');
            @endphp
            @if ($subs->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="text-left px-4 py-2 font-medium">Plan</th>
                                <th class="text-left px-4 py-2 font-medium">Status</th>
                                <th class="text-left px-4 py-2 font-medium">Period end</th>
                                <th class="text-left px-4 py-2 font-medium">Scans used</th>
                                <th class="text-left px-4 py-2 font-medium">Cancel at period end</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subs as $sub)
                                <tr class="border-b border-gray-100">
                                    <td class="px-4 py-2">{{ $sub->plan ? $sub->plan->type->value : '—' }}</td>
                                    <td class="px-4 py-2">{{ $sub->status ? $sub->status->name : '—' }}</td>
                                    <td class="px-4 py-2">{{ $sub->current_period_end ? $sub->current_period_end->format('Y-m-d') : '—' }}</td>
                                    <td class="px-4 py-2">{{ $sub->used_checks_count ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $sub->cancel_at_period_end ? 'Yes' : 'No' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-gray-500 text-sm">No subscriptions.</p>
            @endif
        </div>
    </div>
@endsection
