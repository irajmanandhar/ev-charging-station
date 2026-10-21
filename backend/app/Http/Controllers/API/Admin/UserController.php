<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\API\BaseController;
use App\Http\Resources\UserResource;
use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends BaseController
{
    /** Query: q, role, page */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);

        $page = User::query()
            ->withCount(['stations', 'bookings'])
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->when($data['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->sendResponse([
            'items' => UserResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::enum(UserRole::class)]]);

        if ($user->is($request->user())) {
            return $this->sendError('You cannot change your own role.', 422);
        }

        $from = $user->role->value;
        $user->role = UserRole::from($data['role']);
        $user->save();

        AdminLog::record($request->user(), 'user.role_changed', $user, ['from' => $from, 'to' => $data['role']]);

        return $this->sendResponse(new UserResource($user->loadCount(['stations', 'bookings'])), 'Role updated.');
    }
}
