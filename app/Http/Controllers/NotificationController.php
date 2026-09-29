<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Latest notifications for the dropdown list — JSON, not an Inertia page,
     * so opening the bell doesn't trigger a full page swap.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->notifications()->latest()->limit(20)->get()
        );
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->first()?->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
