<?php

namespace Database\Seeders;

use App\Models\VisibilityQuestion;
use Illuminate\Database\Seeder;

class VisibilityQuestionSeeder extends Seeder
{
    public function run(): void
    {
        $questions = [
            ['visibility_tag' => 'kids', 'question_key' => 'has_children', 'type' => 'boolean', 'order' => 1, 'translation_key' => 'onboarding.preferences.has_children'],
            ['visibility_tag' => 'car', 'question_key' => 'has_car', 'type' => 'boolean', 'order' => 2, 'translation_key' => 'onboarding.preferences.has_car'],
            ['visibility_tag' => 'rent', 'question_key' => 'pays_rent', 'type' => 'boolean', 'order' => 3, 'translation_key' => 'onboarding.preferences.pays_rent'],
            ['visibility_tag' => 'insurance', 'question_key' => 'has_insurance', 'type' => 'boolean', 'order' => 4, 'translation_key' => 'onboarding.preferences.has_insurance'],
            ['visibility_tag' => 'loan', 'question_key' => 'has_loans', 'type' => 'boolean', 'order' => 5, 'translation_key' => 'onboarding.preferences.has_loans'],
            ['visibility_tag' => 'mortgage', 'question_key' => 'has_mortgage', 'type' => 'boolean', 'order' => 6, 'translation_key' => 'onboarding.preferences.has_mortgage'],
        ];

        foreach ($questions as $q) {
            VisibilityQuestion::updateOrCreate(
                ['question_key' => $q['question_key']],
                $q
            );
        }
    }
}
