<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VisibilityQuestion;
use Illuminate\View\View;

class VisibilityQuestionController extends Controller
{
    public function index(): View
    {
        $questions = VisibilityQuestion::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        return view('admin.visibility-questions.index', ['questions' => $questions]);
    }
}
