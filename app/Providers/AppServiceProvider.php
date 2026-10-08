<?php

namespace App\Providers;

use App\Models\Commission;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\User;
use App\Policies\CommissionPolicy;
use App\Policies\FollowUpPolicy;
use App\Policies\LeadPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\ReservationPolicy;
use App\Policies\SalePolicy;
use App\View\Composers\AppSidebarComposer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        View::composer('*', AppSidebarComposer::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        Model::preventLazyLoading(! app()->isProduction());

        Gate::policy(Commission::class, CommissionPolicy::class);
        Gate::policy(FollowUp::class, FollowUpPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Property::class, PropertyPolicy::class);
        Gate::policy(Reservation::class, ReservationPolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);

        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->isAdministrador()) {
                return true;
            }

            if (str_contains($ability, '.')) {
                return $user->hasPermission($ability);
            }

            return null;
        });

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
