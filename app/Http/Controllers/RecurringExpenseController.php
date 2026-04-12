<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRecurringExpenseRequest;
use App\Http\Requests\UpdateRecurringExpenseRequest;
use App\Models\AppUser;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseSchedulerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringExpenseController extends Controller
{
    public function __construct(
        private RecurringExpenseSchedulerService $scheduler,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        $recurring = $user->recurringExpenses()
            ->with('category')
            ->orderBy('next_run_at')
            ->get();

        return response()->json($recurring);
    }

    public function store(StoreRecurringExpenseRequest $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        $data = $request->validated();
        $interval = (int) ($data['interval'] ?? 1);

        $timezone = $user->settings?->timezone ?? config('app.timezone'); // @phpstan-ignore nullsafe.neverNull
        $startsAt = Carbon::parse($data['starts_at'], $timezone)->startOfDay();
        $endsAt = isset($data['ends_at'])
            ? Carbon::parse($data['ends_at'], $timezone)->startOfDay()
            : null;

        $now = now($timezone);
        $nextRunAt = $this->scheduler->calculateInitialNextRunAt($startsAt, $data['frequency'], $interval, $now);

        $recurring = RecurringExpense::create([
            'app_user_id' => $user->id,
            'category_id' => $data['category_id'],
            'amount' => $data['amount'],
            'label' => $data['label'] ?? null,
            'starts_at' => $startsAt,
            'frequency' => $data['frequency'],
            'interval' => $interval,
            'ends_at' => $endsAt,
            'is_active' => true,
            'next_run_at' => $nextRunAt,
        ]);

        return response()->json($recurring->load('category'), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        $recurring = $user->recurringExpenses()
            ->with('category')
            ->findOrFail($id);

        return response()->json($recurring);
    }

    public function update(UpdateRecurringExpenseRequest $request, int $id): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        $recurring = $user->recurringExpenses()->findOrFail($id);

        $data = $request->validated();

        $timezone = $user->settings?->timezone ?? config('app.timezone'); // @phpstan-ignore nullsafe.neverNull

        $startsAt = array_key_exists('starts_at', $data)
            ? Carbon::parse($data['starts_at'], $timezone)->startOfDay()
            : $recurring->starts_at;

        $interval = array_key_exists('interval', $data)
            ? (int) $data['interval']
            : $recurring->interval;

        $frequency = $data['frequency'] ?? $recurring->frequency;

        $endsAt = array_key_exists('ends_at', $data)
            ? (isset($data['ends_at']) ? Carbon::parse($data['ends_at'], $timezone)->startOfDay() : null)
            : $recurring->ends_at;

        $fillData = [
            'category_id' => $data['category_id'] ?? $recurring->category_id,
            'amount' => $data['amount'] ?? $recurring->amount,
            'starts_at' => $startsAt,
            'frequency' => $frequency,
            'interval' => $interval,
            'ends_at' => $endsAt,
        ];

        if (array_key_exists('label', $data)) {
            $fillData['label'] = $data['label'];
        }

        $recurring->fill($fillData);

        if (array_key_exists('starts_at', $data) || array_key_exists('frequency', $data) || array_key_exists('interval', $data)) {
            $now = now($timezone);
            $recurring->next_run_at = $this->scheduler->calculateInitialNextRunAt(
                $startsAt,
                $frequency,
                $interval,
                $now,
            );
        }

        $recurring->save();

        return response()->json($recurring->load('category'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        $recurring = $user->recurringExpenses()->findOrFail($id);
        $recurring->delete();

        return response()->json(null, 204);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $recurring = $user->recurringExpenses()->findOrFail($id);

        $recurring->is_active = (bool) $data['is_active'];

        if ($recurring->is_active) {
            $timezone = $user->settings?->timezone ?? config('app.timezone'); // @phpstan-ignore nullsafe.neverNull
            $this->scheduler->normalizeNextRunAtOnEnable($recurring, now($timezone));
        }

        $recurring->save();

        return response()->json($recurring->load('category'));
    }
}
