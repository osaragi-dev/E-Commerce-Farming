<?php

namespace App\Filament\Pages;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use STS\FilamentImpersonate\Facades\Impersonation;

class ChangePassword extends Page
{
    use WithRateLimiting;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    protected static ?string $slug = 'password-change';

    protected static ?string $title = 'Change Password';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.change-password';

    public function mount(): void
    {
        $user = $this->getAuthenticatedUser();

        if (Impersonation::isImpersonating()) {
            $this->redirect(Filament::getUrl(), navigate: false);

            return;
        }

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('current_password')
                    ->label('Current Password')
                    ->password()
                    ->revealable()
                    ->autocomplete('current-password')
                    ->currentPassword('web')
                    ->required(),
                TextInput::make('password')
                    ->label('New Password')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->minLength(8)
                    ->different('current_password')
                    ->same('password_confirmation'),
                TextInput::make('password_confirmation')
                    ->label('Confirm New Password')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required(),
            ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function save(): void
    {
        $user = $this->getAuthenticatedUser();

        if (Impersonation::isImpersonating()) {
            $this->redirect(Filament::getUrl(), navigate: false);

            return;
        }

        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            throw ValidationException::withMessages([
                'data.current_password' => "Too many attempts. Please try again in {$exception->secondsUntilAvailable} seconds.",
            ]);
        }

        $data = $this->form->getState();

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
            'must_change_password' => false,
        ])->save();

        $this->data = [];
        session()->regenerate();
        session()->put('password_hash_web', $user->getAuthPassword());

        Notification::make()
            ->title('Password changed successfully.')
            ->success()
            ->send();

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Change Password')
                ->submit('save'),
        ];
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    protected function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('form-actions'),
            ]);
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Change Password';
    }

    private function getAuthenticatedUser(): User
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof User && $user->canAccessPanel(Filament::getPanel('admin')), 403);

        return $user;
    }
}
