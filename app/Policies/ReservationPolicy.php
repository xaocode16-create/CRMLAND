<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sales.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('sales.view')
            && $user->canAccessAssigned($reservation->user_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('sales.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('sales.create')
            && $user->canAccessAssigned($reservation->user_id);
    }

    /**
     * Determine whether the user can approve the reservation.
     */
    public function approve(User $user, Reservation $reservation): bool
    {
        return ($user->isAdministrador() || $user->isSupervisor())
            && $user->hasPermission('appointments.edit')
            && $user->canAccessAssigned($reservation->user_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Reservation $reservation): bool
    {
        return $this->update($user, $reservation);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Reservation $reservation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Reservation $reservation): bool
    {
        return false;
    }
}
