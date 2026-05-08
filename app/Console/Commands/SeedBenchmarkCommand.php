<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates a realistic dataset for EXPLAIN ANALYZE and performance testing.
 *
 * Usage:
 *   php artisan benchmark:seed                         # 10k tx for one user
 *   php artisan benchmark:seed --count=50000           # 50k tx for one user
 *   php artisan benchmark:seed --background-users=500  # + 500 bg users (~5k tx each → 2.5M items total)
 *   php artisan benchmark:seed --fresh                 # wipe all benchmark data first
 *
 * Never run against production.
 */
class SeedBenchmarkCommand extends Command
{
    protected $signature = 'benchmark:seed
                            {--count=10000        : Transactions for the main benchmark user}
                            {--background-users=0 : Extra users to inflate the table (simulates multi-tenant scale)}
                            {--tx-per-bg-user=5000: Transactions per background user}
                            {--fresh              : Wipe all existing benchmark data before seeding}';

    protected $description = 'Generate benchmark dataset for EXPLAIN ANALYZE (dev only)';

    private const EMAIL = 'benchmark@local.test';
    private const BG_EMAIL_PATTERN = 'bg-%d@bench.local';
    private const BG_EMAIL_LIKE = 'bg-%@bench.local';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('benchmark:seed must not run in production.');

            return self::FAILURE;
        }

        $count      = (int) $this->option('count');
        $bgUsers    = (int) $this->option('background-users');
        $txPerBg    = (int) $this->option('tx-per-bg-user');

        $this->info(sprintf(
            'benchmark:seed — main user: %s tx, background users: %s × %s tx',
            number_format($count),
            number_format($bgUsers),
            number_format($txPerBg),
        ));

        if ($this->option('fresh')) {
            $this->cleanUp();
        }

        $userId = $this->resolveMainUser();
        $this->insertTransactions($userId, $count);
        $this->insertItems($userId);

        if ($bgUsers > 0) {
            $this->seedBackgroundUsers($bgUsers, $txPerBg);
        }

        $this->printStats($userId);

        return self::SUCCESS;
    }

    // ── main benchmark user ────────────────────────────────────────────────────

    private function cleanUp(): void
    {
        $mainDeleted = DB::table('app_users')->where('email', self::EMAIL)->delete();
        $bgDeleted   = DB::table('app_users')->where('email', 'like', self::BG_EMAIL_LIKE)->delete();

        if ($mainDeleted || $bgDeleted) {
            $this->line("  Dropped {$mainDeleted} main + {$bgDeleted} background users (cascade).");
        }
    }

    private function resolveMainUser(): int
    {
        $existing = DB::table('app_users')->where('email', self::EMAIL)->first();

        if ($existing) {
            DB::table('transactions')->where('app_user_id', $existing->id)->delete();
            $this->line("  Cleared transactions for existing main user id={$existing->id}.");

            return (int) $existing->id;
        }

        $userId = DB::table('app_users')->insertGetId([
            'supabase_user_id' => (string) Str::uuid(),
            'email'            => self::EMAIL,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->line("  Created main benchmark user id={$userId}.");

        return $userId;
    }

    private function insertTransactions(int $userId, int $count): void
    {
        DB::statement("
            INSERT INTO transactions
                (app_user_id, date, type, created_by, total_amount, confirmed_at, created_at, updated_at)
            SELECT
                :user_id,
                DATE '2023-01-01' + (random() * 730)::int,
                CASE WHEN random() < 0.15 THEN 'income' ELSE 'expense' END,
                'user',
                (500 + random() * 49500)::int,
                CASE WHEN random() < 0.10 THEN NULL ELSE NOW() END,
                NOW(),
                NOW()
            FROM generate_series(1, :count)
        ", ['user_id' => $userId, 'count' => $count]);

        $this->line("  Inserted {$count} transactions.");
    }

    private function insertItems(int $userId): void
    {
        DB::statement("
            INSERT INTO transaction_items
                (transaction_id, category_id, amount, created_at, updated_at)
            SELECT
                t.id,
                c.id,
                t.total_amount,
                NOW(),
                NOW()
            FROM transactions t
            JOIN (
                SELECT id, (ROW_NUMBER() OVER (ORDER BY id) - 1) AS rn
                FROM categories
                WHERE category_id IS NOT NULL
            ) c ON (t.id % (SELECT COUNT(*) FROM categories WHERE category_id IS NOT NULL)) = c.rn
            WHERE t.app_user_id = :user_id
        ", ['user_id' => $userId]);

        $this->line('  Inserted transaction items.');
    }

    // ── background users ───────────────────────────────────────────────────────

    private function seedBackgroundUsers(int $bgCount, int $txPerUser): void
    {
        $this->line("  Seeding {$bgCount} background users × {$txPerUser} tx …");

        // Drop previous background users if they exist (idempotent re-run without --fresh)
        DB::table('app_users')->where('email', 'like', self::BG_EMAIL_LIKE)->delete();

        // Create all background users in one INSERT … SELECT
        DB::statement("
            INSERT INTO app_users (supabase_user_id, email, created_at, updated_at)
            SELECT
                gen_random_uuid(),
                'bg-' || n || '@bench.local',
                NOW(),
                NOW()
            FROM generate_series(1, :count) n
        ", ['count' => $bgCount]);

        $this->line("  Created {$bgCount} background users.");

        // Create transactions for all background users in one shot.
        // CROSS JOIN with generate_series gives tx_per_user rows per user.
        DB::statement("
            INSERT INTO transactions
                (app_user_id, date, type, created_by, total_amount, confirmed_at, created_at, updated_at)
            SELECT
                u.id,
                DATE '2023-01-01' + (random() * 730)::int,
                'expense',
                'user',
                (500 + random() * 9500)::int,
                NOW(),
                NOW(),
                NOW()
            FROM app_users u
            CROSS JOIN generate_series(1, :tx_per_user)
            WHERE u.email LIKE 'bg-%@bench.local'
        ", ['tx_per_user' => $txPerUser]);

        $bgTxCount = $bgCount * $txPerUser;
        $this->line("  Inserted ~{$bgTxCount} background transactions.");

        // Insert items for all background transactions in one shot
        DB::statement("
            INSERT INTO transaction_items
                (transaction_id, category_id, amount, created_at, updated_at)
            SELECT
                t.id,
                c.id,
                t.total_amount,
                NOW(),
                NOW()
            FROM transactions t
            JOIN app_users u ON u.id = t.app_user_id AND u.email LIKE 'bg-%@bench.local'
            JOIN (
                SELECT id, (ROW_NUMBER() OVER (ORDER BY id) - 1) AS rn
                FROM categories WHERE category_id IS NOT NULL
            ) c ON (t.id % (SELECT COUNT(*) FROM categories WHERE category_id IS NOT NULL)) = c.rn
        ");

        $this->line("  Inserted background transaction items.");

        DB::statement('ANALYZE transactions');
        DB::statement('ANALYZE transaction_items');
        $this->line('  ANALYZE done — planner stats updated.');
    }

    // ── stats ──────────────────────────────────────────────────────────────────

    private function printStats(int $userId): void
    {
        $user = DB::selectOne("
            SELECT
                COUNT(t.id)                                          AS tx_total,
                COUNT(t.id) FILTER (WHERE t.type = 'expense')        AS tx_expense,
                COUNT(t.id) FILTER (WHERE t.type = 'income')         AS tx_income,
                COUNT(t.id) FILTER (WHERE t.confirmed_at IS NULL)    AS tx_drafts,
                COUNT(ti.id)                                         AS items_total
            FROM transactions t
            LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
            WHERE t.app_user_id = :user_id
        ", ['user_id' => $userId]);

        $global = DB::selectOne('
            SELECT
                COUNT(*) AS total_transactions,
                (SELECT COUNT(*) FROM transaction_items) AS total_items
            FROM transactions
        ');

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['── Main benchmark user ──', ''],
                ['  Transactions',                    number_format((int) $user->tx_total)],
                ['    — expenses',                    number_format((int) $user->tx_expense)],
                ['    — income',                      number_format((int) $user->tx_income)],
                ['    — drafts (no confirmed_at)',     number_format((int) $user->tx_drafts)],
                ['  Transaction items',               number_format((int) $user->items_total)],
                ['── Global table sizes ──', ''],
                ['  transactions (all users)',         number_format((int) $global->total_transactions)],
                ['  transaction_items (all users)',    number_format((int) $global->total_items)],
            ],
        );
    }
}
