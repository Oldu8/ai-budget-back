<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\VisibilityQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VisibilityQuestionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_active_questions_ordered(): void
    {
        VisibilityQuestion::create([
            'visibility_tag' => 'has_kids', 'question_key' => 'q_kids', 'translation_key' => 'funnel.q_kids',
            'order' => 2, 'is_active' => true,
        ]);
        VisibilityQuestion::create([
            'visibility_tag' => 'has_pets', 'question_key' => 'q_pets', 'translation_key' => 'funnel.q_pets',
            'order' => 1, 'is_active' => true,
        ]);
        VisibilityQuestion::create([
            'visibility_tag' => 'has_car', 'question_key' => 'q_car', 'translation_key' => 'funnel.q_car',
            'order' => 0, 'is_active' => false,
        ]);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password123']);
        $response = $this->actingAs($admin)->get('/admin/visibility-questions');

        $response->assertOk()->assertViewIs('admin.visibility-questions.index');
        $questions = $response->viewData('questions');
        $this->assertCount(2, $questions);
        $this->assertSame('q_pets', $questions->first()->question_key);
    }

    public function test_requires_authentication(): void
    {
        $this->get('/admin/visibility-questions')->assertRedirect(route('login'));
    }
}
