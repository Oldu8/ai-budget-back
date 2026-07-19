<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\AppUserCategorySetting;
use App\Models\AppUserIdentity;
use App\Models\AppUserRefreshToken;
use App\Models\Payment;
use App\Models\RecurringExpense;
use App\Models\Status;
use App\Models\StripeCheckoutLog;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_user_category_setting_relations(): void
    {
        $user = $this->makeAppUser();
        $category = $this->makeCategory();
        $setting = AppUserCategorySetting::create([
            'app_user_id' => $user->id, 'category_id' => $category->id, 'visibility_tag' => 'kids',
        ]);

        $this->assertSame($user->id, $setting->appUser->id);
        $this->assertSame($category->id, $setting->category->id);
    }

    public function test_app_user_identity_relation(): void
    {
        $user = $this->makeAppUser();
        $identity = AppUserIdentity::create([
            'app_user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-1',
        ]);

        $this->assertSame($user->id, $identity->appUser->id);
    }

    public function test_app_user_refresh_token_relation_and_usability(): void
    {
        $user = $this->makeAppUser();
        $token = AppUserRefreshToken::create([
            'app_user_id' => $user->id, 'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDay(), 'revoked_at' => null,
        ]);

        $this->assertSame($user->id, $token->appUser->id);
        $this->assertTrue($token->isUsable());
    }

    public function test_category_transaction_items_relation(): void
    {
        $leaf = $this->makeCategory();
        $user = $this->makeAppUser();
        $tx = Transaction::create([
            'app_user_id' => $user->id, 'total_amount' => 100, 'date' => '2026-01-01',
            'type' => TransactionType::Expense, 'created_by' => TransactionCreatedBy::User, 'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $leaf->id, 'amount' => 100]);

        $this->assertCount(1, $leaf->transactionItems);
    }

    public function test_payment_relations(): void
    {
        $user = $this->makeAppUser();
        $status = Status::firstOrCreate(['name' => 'active']);
        $payment = Payment::create([
            'app_user_id' => $user->id, 'currency' => 'usd', 'payment_invoice_id' => 'in_1',
            'status_id' => $status->id, 'amount' => 999,
        ]);

        $this->assertSame($user->id, $payment->appUser->id);
        $this->assertSame($status->id, $payment->status->id);
    }

    public function test_status_relations(): void
    {
        $status = Status::firstOrCreate(['name' => 'active']);
        $user = $this->makeAppUser();
        $plan = $this->makePlan(PlanType::Basic);
        $this->subscribe($user, $plan);
        Payment::create([
            'app_user_id' => $user->id, 'currency' => 'usd', 'status_id' => $status->id, 'amount' => 100,
        ]);

        $this->assertGreaterThan(0, $status->userSubscriptions()->count());
        $this->assertGreaterThan(0, $status->payments()->count());
    }

    public function test_stripe_checkout_log_relation(): void
    {
        $user = $this->makeAppUser();
        $log = StripeCheckoutLog::create([
            'stripe_checkout_session_id' => 'cs_1', 'stripe_customer_id' => 'cus_1',
            'app_user_id' => $user->id, 'plan_type' => 'basic', 'currency' => 'usd',
        ]);

        $this->assertSame($user->id, $log->appUser->id);
    }

    public function test_recurring_expense_transactions_relation(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();
        $recurring = RecurringExpense::create([
            'app_user_id' => $user->id, 'category_id' => $leaf->id, 'amount' => 500, 'label' => 'Rent',
            'starts_at' => now(), 'frequency' => 'monthly', 'interval' => 1, 'is_active' => true, 'next_run_at' => now()->addMonth(),
        ]);
        Transaction::create([
            'app_user_id' => $user->id, 'total_amount' => 500, 'date' => '2026-01-01',
            'type' => TransactionType::Expense, 'created_by' => TransactionCreatedBy::Recurring,
            'confirmed_at' => now(), 'recurring_expense_id' => $recurring->id,
        ]);

        $this->assertCount(1, $recurring->transactions);
    }

    public function test_transaction_recurring_expense_relation(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();
        $recurring = RecurringExpense::create([
            'app_user_id' => $user->id, 'category_id' => $leaf->id, 'amount' => 500, 'label' => 'Rent',
            'starts_at' => now(), 'frequency' => 'monthly', 'interval' => 1, 'is_active' => true, 'next_run_at' => now()->addMonth(),
        ]);
        $tx = Transaction::create([
            'app_user_id' => $user->id, 'total_amount' => 500, 'date' => '2026-01-01',
            'type' => TransactionType::Expense, 'created_by' => TransactionCreatedBy::Recurring,
            'confirmed_at' => now(), 'recurring_expense_id' => $recurring->id,
        ]);

        $this->assertSame($recurring->id, $tx->recurringExpense->id);
    }

    public function test_transaction_item_transaction_relation(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();
        $tx = Transaction::create([
            'app_user_id' => $user->id, 'total_amount' => 100, 'date' => '2026-01-01',
            'type' => TransactionType::Expense, 'created_by' => TransactionCreatedBy::User, 'confirmed_at' => now(),
        ]);
        $item = TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $leaf->id, 'amount' => 100]);

        $this->assertSame($tx->id, $item->transaction->id);
    }

    public function test_plan_user_subscriptions_relation(): void
    {
        $plan = $this->makePlan(PlanType::Basic);
        $user = $this->makeAppUser();
        $this->subscribe($user, $plan);

        $this->assertCount(1, $plan->userSubscriptions);
    }

    public function test_app_user_identities_refresh_tokens_and_payments_relations(): void
    {
        $user = $this->makeAppUser();
        AppUserIdentity::create(['app_user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-2']);
        AppUserRefreshToken::create([
            'app_user_id' => $user->id, 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay(),
        ]);
        $status = Status::firstOrCreate(['name' => 'active']);
        Payment::create(['app_user_id' => $user->id, 'currency' => 'usd', 'status_id' => $status->id, 'amount' => 100]);

        $this->assertCount(1, $user->identities);
        $this->assertCount(1, $user->refreshTokens);
        $this->assertCount(1, $user->payments);
    }
}
