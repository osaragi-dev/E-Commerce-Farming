<?php

namespace App\Filament\Pages\Auth;

use App\Actions\AuthenticateBackOfficeUser;
use Caresome\FilamentAuthDesigner\Pages\Auth\Login as BaseLogin;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class Login extends BaseLogin
{
    public function mount(): void
    {
        $user = Filament::auth()->user();

        if ($user !== null && ! $user->canAccessPanel(Filament::getPanel('admin'))) {
            Filament::auth()->logout();
            session()->regenerate();
        }

        parent::mount();
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        $user = app(AuthenticateBackOfficeUser::class)->execute($data['login'], $data['password']);

        Filament::auth()->login($user, $data['remember'] ?? false);

        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getLoginFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label(__('Email'))
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus();
    }
}
