<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\AppUserCategorySetting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpenseExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_returns_csv_with_correct_headers(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));

        $response = $this->apiAs($user)->get('/api/transactions/export?from=2026-05-01&to=2026-05-31');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('expenses_2026-05-01_2026-05-31.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_export_includes_bom_and_column_headers(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));

        $content = $this->apiAs($user)
            ->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Date,Category,Amount,Currency,Label', $content);
    }

    public function test_export_formats_amount_as_decimal(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'eur');
        $this->subscribe($user, $this->makePlan(PlanType::Basic));
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 2450, '2026-05-01', 'Coffee');

        $content = $this->apiAs($user)
            ->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->streamedContent();

        $this->assertStringContainsString('24.50', $content);
    }

    public function test_export_includes_correct_row_data(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'eur');
        $this->subscribe($user, $this->makePlan(PlanType::Basic));
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 1000, '2026-05-15', 'Groceries');

        $content = $this->apiAs($user)
            ->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->streamedContent();

        $this->assertStringContainsString('2026-05-15', $content);
        $this->assertStringContainsString($leaf->label, $content);
        $this->assertStringContainsString('10.00', $content);
        $this->assertStringContainsString('eur', $content);
        $this->assertStringContainsString('Groceries', $content);
    }

    public function test_export_excludes_unconfirmed_transactions(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 5000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $leaf->id, 'amount' => 5000, 'label' => 'Draft']);

        $content = $this->apiAs($user)
            ->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->streamedContent();

        $this->assertStringNotContainsString('Draft', $content);
    }

    public function test_export_excludes_income_transactions(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 5000,
            'date' => '2026-05-01',
            'type' => TransactionType::Income,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create(['transaction_id' => $tx->id, 'category_id' => $leaf->id, 'amount' => 5000, 'label' => 'Salary']);

        $content = $this->apiAs($user)
            ->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->streamedContent();

        $this->assertStringNotContainsString('Salary', $content);
    }

    public function test_export_excludes_disabled_categories(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 1000, '2026-05-01', 'Hidden');
        AppUserCategorySetting::create(['app_user_id' => $user->id, 'category_id' => $leaf->id]);

        $content = $this->apiAs($user)
            ->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->streamedContent();

        $this->assertStringNotContainsString('Hidden', $content);
    }

    public function test_export_requires_basic_or_higher_plan(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free));

        $this->apiAs($user)->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->assertForbidden();
    }

    public function test_export_null_item_label_outputs_empty_string(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Basic));
        $leaf = $this->makeCategory();

        $this->makeConfirmedExpense($user->id, $leaf->id, 500, '2026-05-01', null);

        $content = $this->apiAs($user)
            ->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->streamedContent();

        // Should have a row with empty label field (no error)
        $lines = explode("\n", trim($content));
        $this->assertCount(2, $lines); // header + 1 data row
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeConfirmedExpense(int $userId, int $categoryId, int $amount, string $date, ?string $itemLabel = null): Transaction
    {
        $tx = Transaction::create([
            'app_user_id' => $userId,
            'total_amount' => $amount,
            'date' => $date,
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);
        TransactionItem::create([
            'transaction_id' => $tx->id,
            'category_id' => $categoryId,
            'amount' => $amount,
            'label' => $itemLabel,
        ]);

        return $tx;
    }
}
