@extends('admin.layout')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Funnel questions</h1>
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium">Order</th>
                        <th class="text-left px-4 py-3 font-medium">Visibility tag</th>
                        <th class="text-left px-4 py-3 font-medium">Question key</th>
                        <th class="text-left px-4 py-3 font-medium">Translation key</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($questions as $q)
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-3">{{ $q->order }}</td>
                            <td class="px-4 py-3">{{ $q->visibility_tag }}</td>
                            <td class="px-4 py-3">{{ $q->question_key }}</td>
                            <td class="px-4 py-3 font-mono text-gray-600">{{ $q->translation_key }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">No funnel questions configured.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
