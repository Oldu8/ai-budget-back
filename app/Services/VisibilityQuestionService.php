<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VisibilityQuestion;

final class VisibilityQuestionService
{
    /**
     * Load from DB, sort by order, return funnel questions (boolean type only).
     */
    public function getFunnelQuestions(): array
    {
        return VisibilityQuestion::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->map(fn (VisibilityQuestion $q) => [
                'visibility_tag' => $q->visibility_tag,
                'question_key' => $q->question_key,
                'type' => 'boolean',
                'order' => $q->order,
                'translation_key' => $q->translation_key,
            ])
            ->all();
    }

    /**
     * Derive unique visibility tags from DB for /visibility-tags endpoint.
     */
    public function getVisibilityTags(): array
    {
        return VisibilityQuestion::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->unique('visibility_tag')
            ->values()
            ->map(fn (VisibilityQuestion $q) => [
                'visibility_tag' => $q->visibility_tag,
                'translation_key' => 'onboarding.tags.'.$q->visibility_tag,
            ])
            ->all();
    }

    /**
     * Get allowed visibility_tag values for validation.
     */
    public function getAllowedTags(): array
    {
        return array_column($this->getVisibilityTags(), 'visibility_tag');
    }
}
