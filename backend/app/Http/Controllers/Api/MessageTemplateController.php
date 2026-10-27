<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;

class MessageTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            MessageTemplate::query()
                ->where('is_active', true)
                ->orderBy('type')
                ->orderBy('id')
                ->get(['id', 'type', 'body']),
        );
    }
}
