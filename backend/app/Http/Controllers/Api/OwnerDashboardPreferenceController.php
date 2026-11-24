<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerDashboardPreferenceController extends Controller
{
    private const DEFAULT_PREFERENCES = [
        'sales_summary' => true,
        'financial_summary' => true,
        'order_status' => true,
        'best_sellers' => true,
        'grabfood_payout' => true,
    ];

    public function show(Request $request): JsonResponse
    {
        $record = DB::table('owner_dashboard_preferences')
            ->where('user_id', $request->user()->id)
            ->first();

        $preferences = $record
            ? json_decode($record->preferences, true)
            : self::DEFAULT_PREFERENCES;

        return response()->json([
            'preferences' => array_merge(
                self::DEFAULT_PREFERENCES,
                is_array($preferences) ? $preferences : []
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.sales_summary' => ['required', 'boolean'],
            'preferences.financial_summary' => ['required', 'boolean'],
            'preferences.order_status' => ['required', 'boolean'],
            'preferences.best_sellers' => ['required', 'boolean'],
            'preferences.grabfood_payout' => ['required', 'boolean'],
        ]);

        $preferences = $validated['preferences'];

        DB::table('owner_dashboard_preferences')->updateOrInsert(
            ['user_id' => $request->user()->id],
            [
                'preferences' => json_encode($preferences),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Pengaturan Dashboard berhasil disimpan.',
            'preferences' => $preferences,
        ]);
    }
}