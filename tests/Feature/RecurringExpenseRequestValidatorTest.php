<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\StoreRecurringExpenseRequest;
use App\Http\Requests\UpdateRecurringExpenseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class RecurringExpenseRequestValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_after_hook_is_a_no_op_without_an_authenticated_user(): void
    {
        $leaf = $this->makeCategory();
        $request = StoreRecurringExpenseRequest::create('/api/recurring-expenses', 'POST', ['category_id' => $leaf->id]);
        $request->setUserResolver(fn () => null);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);
        $validator->passes();

        $this->assertNotContains('Category must be a Level 2 category.', $validator->errors()->get('category_id'));
        $this->assertNotContains('Category is disabled for this user.', $validator->errors()->get('category_id'));
    }

    public function test_store_after_hook_is_a_no_op_when_category_id_is_falsy(): void
    {
        $user = $this->makeAppUser();
        $request = StoreRecurringExpenseRequest::create('/api/recurring-expenses', 'POST', ['category_id' => 0]);
        $request->setUserResolver(fn () => $user);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);
        $validator->passes();

        $this->assertNotContains('Category must be a Level 2 category.', $validator->errors()->get('category_id'));
        $this->assertNotContains('Category is disabled for this user.', $validator->errors()->get('category_id'));
    }

    public function test_update_after_hook_is_a_no_op_without_an_authenticated_user(): void
    {
        $leaf = $this->makeCategory();
        $request = UpdateRecurringExpenseRequest::create('/api/recurring-expenses/1', 'PUT', ['category_id' => $leaf->id]);
        $request->setUserResolver(fn () => null);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);
        $validator->passes();

        $this->assertNotContains('Category must be a Level 2 category.', $validator->errors()->get('category_id'));
        $this->assertNotContains('Category is disabled for this user.', $validator->errors()->get('category_id'));
    }
}
