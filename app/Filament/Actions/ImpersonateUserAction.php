<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Users\UserResource;
use Illuminate\Support\Facades\Gate;
use STS\FilamentImpersonate\Actions\Impersonate;

class ImpersonateUserAction
{
    public static function make(): Impersonate
    {
        return Impersonate::make()
            ->guard('web')
            ->redirectTo(fn (): string => filament()->getPanel('admin')->getUrl())
            ->backTo(fn (): string => UserResource::getUrl('index'))
            ->withoutSpa()
            ->authorize(fn (): bool => Gate::allows('Impersonate:User'));
    }
}
