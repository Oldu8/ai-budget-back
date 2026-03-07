<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use Illuminate\View\View;

class AppUserController extends Controller
{
    public function index(): View
    {
        $users = AppUser::query()
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('admin.users.index', ['users' => $users]);
    }

    public function show(int $id): View
    {
        $user = AppUser::with(['settings', 'userSubscriptions.plan', 'userSubscriptions.status'])
            ->findOrFail($id);

        return view('admin.users.show', ['user' => $user]);
    }
}
