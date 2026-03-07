@extends('admin.layout')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Subscriptions</h1>

    <form method="GET" action="{{ route('admin.subscriptions.index') }}" class="mb-4 flex gap-2 items-end flex-wrap">
        <div>
            <label for="plan_id" class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
            <select name="plan_id" id="plan_id" class="border border-gray-300 rounded px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}" {{ request('plan_id') == (string) $plan->id ? 'selected' : '' }}>
                        {{ $plan->type->value }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-gray-800 text-white rounded px-3 py-2 text-sm font-medium hover:bg-gray-700">Filter</button>
    </form>

    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium">ID</th>
                        <th class="text-left px-4 py-3 font-medium">User</th>
                        <th class="text-left px-4 py-3 font-medium">Plan</th>
                        <th class="text-left px-4 py-3 font-medium">Status</th>
                        <th class="text-left px-4 py-3 font-medium">Scans used</th>
                        <th class="text-left px-4 py-3 font-medium">Period end</th>
                        <th class="text-left px-4 py-3 font-medium">Cancel at period end</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $sub)
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-3">{{ $sub->id }}</td>
                            <td class="px-4 py-3">
                                @if ($sub->appUser)
                                    <a href="{{ route('admin.users.show', $sub->appUser->id) }}" class="text-blue-600 hover:underline">{{ $sub->appUser->email }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $sub->plan ? $sub->plan->type->value : '—' }}</td>
                            <td class="px-4 py-3">{{ $sub->status ? $sub->status->name : '—' }}</td>
                            <td class="px-4 py-3">{{ $sub->used_checks_count ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $sub->current_period_end ? $sub->current_period_end->format('Y-m-d') : '—' }}</td>
                            <td class="px-4 py-3">{{ $sub->cancel_at_period_end ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">No subscriptions.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($subscriptions->hasPages())
            <div class="px-4 py-3 border-t border-gray-200">
                {{ $subscriptions->links() }}
            </div>
        @endif
    </div>
@endsection
