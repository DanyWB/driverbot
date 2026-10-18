<?php

namespace App\Http\Controllers\Vehicles;

use App\Domain\Shared\Services\AdminAuditService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\RentalPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RentalPolicyController extends Controller
{
    use HandlesAdminContext;

    public function __invoke(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'minimum_days' => ['required', 'integer', 'min:1', 'max:366'],
            'categories' => ['present', 'array', 'max:1000'],
            'categories.*.id' => ['required', 'integer', 'distinct', Rule::exists(Category::class, 'id')],
            'categories.*.minimum_days' => ['nullable', 'integer', 'min:1', 'max:366'],
        ]);

        DB::transaction(function () use ($data, $request, $audit): void {
            $policy = RentalPolicy::query()->firstOrCreate(['id' => 1], ['minimum_days' => 1]);
            $policy = RentalPolicy::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $old = $policy->toArray();
            $overrides = [];
            foreach ($data['categories'] as $category) {
                if (($category['minimum_days'] ?? null) !== null) {
                    $overrides[(int) $category['id']] = (int) $category['minimum_days'];
                }
            }
            $policy->update(['minimum_days' => $data['minimum_days'], 'category_minimum_days' => $overrides]);
            $audit->record($this->admin($request), $policy, 'rental_policy.updated', $old, $policy->toArray(), $this->requestId($request));
        });

        return to_route('categories.index');
    }
}
