<?php

namespace App\Providers;

use App\Application\Listeners\Observability\RecordAppointmentCreatedDomainEvent;
use App\Application\Listeners\Observability\RecordOrderClosedDomainEvent;
use App\Domain\Appointment\Events\AppointmentCreated;
use App\Domain\Order\Events\OrderClosed;
use App\Support\Observability\LandlordTenantIndexPerformanceTracker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(LandlordTenantIndexPerformanceTracker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(AppointmentCreated::class, RecordAppointmentCreatedDomainEvent::class);
        Event::listen(OrderClosed::class, RecordOrderClosedDomainEvent::class);
    }
}
