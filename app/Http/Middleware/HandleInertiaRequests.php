<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $isImpersonating = $request->session()->has('impersonating_user_id');
        $originalUser = null;
        
        if ($isImpersonating) {
            $originalUserId = $request->session()->get('impersonating_user_id');
            $originalUserData = $request->session()->get('impersonating_original_user');
            if ($originalUserData) {
                $originalUser = $originalUserData;
            } elseif ($originalUserId) {
                $originalUserModel = \App\Models\User::find($originalUserId);
                if ($originalUserModel) {
                    $originalUser = [
                        'id' => $originalUserModel->id,
                        'name' => $originalUserModel->name,
                        'email' => $originalUserModel->email,
                        'role' => $originalUserModel->role,
                    ];
                }
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'impersonation' => [
                'isImpersonating' => $isImpersonating,
                'originalUser' => $originalUser,
            ],
            'csrf_token' => csrf_token(),
        ];
    }
}
