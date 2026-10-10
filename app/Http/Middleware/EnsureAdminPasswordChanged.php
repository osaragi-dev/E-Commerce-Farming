<?php

namespace App\Http\Middleware;

use App\Filament\Pages\ChangePassword;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use STS\FilamentImpersonate\Facades\Impersonation;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::isImpersonating()) {
            if ($request->routeIs(ChangePassword::getRouteName(Filament::getPanel('admin')))) {
                return redirect()->to(Filament::getPanel('admin')->getUrl());
            }

            return $next($request);
        }

        $user = $request->user('web');

        if (! $user instanceof User || ! $user->requiresPasswordChange()) {
            return $next($request);
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        if (
            $request->routeIs(ChangePassword::getRouteName($panel))
            || $request->routeIs("filament.{$panel->getId()}.auth.logout")
        ) {
            return $next($request);
        }

        return redirect()->to(ChangePassword::getUrl(panel: $panel->getId()));

    }
}
