<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\UserSubscription;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $usersCount = AppUser::count();
        $activeSubscriptionsCount = UserSubscription::query()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['active', 'trialing']))
            ->count();

        return view('admin.dashboard', [
            'usersCount' => $usersCount,
            'activeSubscriptionsCount' => $activeSubscriptionsCount,
        ]);
    }
}
