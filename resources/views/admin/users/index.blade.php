@extends('admin.layout')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Users</h1>
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium">ID</th>
                        <th class="text-left px-4 py-3 font-medium">Email</th>
                        <th class="text-left px-4 py-3 font-medium">First name</th>
                        <th class="text-left px-4 py-3 font-medium">Last name</th>
                        <th class="text-left px-4 py-3 font-medium">Email verified</th>
                        <th class="text-left px-4 py-3 font-medium">Created</th>
                        <th class="text-left px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-3">{{ $user->id }}</td>
                            <td class="px-4 py-3">{{ $user->email }}</td>
                            <td class="px-4 py-3">{{ $user->first_name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $user->last_name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i') : '—' }}</td>
                            <td class="px-4 py-3">{{ $user->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.users.show', $user->id) }}" class="text-blue-600 hover:underline">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">No users.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="px-4 py-3 border-t border-gray-200">
                {{ $users->links() }}
            </div>
        @endif
    </div>
@endsection
