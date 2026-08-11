<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\LoadAvailability;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\TruckType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The freight vocabulary: categories, truck types and availability options.
 *
 * Public and unauthenticated because the marketing site's quote form needs it
 * before anyone signs in. Serving it from here keeps one vocabulary — the
 * Angular client previously carried its own hardcoded lists, which had already
 * drifted from what customers actually select.
 */
class TaxonomyController extends Controller
{
    /**
     * GET /api/v1/public/taxonomy
     */
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'categories' => Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug']),
            'truck_types' => TruckType::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug']),
            'availability' => array_map(
                fn (LoadAvailability $case) => ['value' => $case->value, 'label' => $case->label()],
                LoadAvailability::cases(),
            ),
        ]);
    }
}
