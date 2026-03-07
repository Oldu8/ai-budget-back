<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\UserSubscription;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $query = UserSubscription::query()
            ->with(['appUser', 'plan', 'status'])
            ->orderByDesc('current_period_end');

        if ($request->filled('plan_id')) {
            $query->where('plan_id', $request->integer('plan_id'));
        }

        $subscriptions = $query->paginate(15)->withQueryString();
        $plans = Plan::orderBy('id')->get();

        return view('admin.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'plans' => $plans,
        ]);
    }
}
