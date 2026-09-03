<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

class SubscriptionAccessService
{
    public const STATE_ACTIVE = 'active';

    public const STATE_CANCELED_PENDING = 'canceled_pending';

    public const STATE_EXPIRED = 'expired';

    public const STATE_WARNING = 'warning';

    public const STATE_BLOCKED = 'blocked';

    /**
     * Cancelación voluntaria: se bloquea 1 día después del corte.
     * Impago / no renovación: se bloquea 2 días después del corte.
     */
    public const BLOCK_AFTER_DAYS_CANCEL = 1;

    public const BLOCK_AFTER_DAYS_LAPSE = 2;

    /**
     * Recalcula block_POS del titular y, si acaba de bloquearse, revoca tokens del staff.
     */
    public function applyForUser(User $user): User
    {
        $user->refresh();

        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'block_POS')) {
            return $user;
        }

        if ($user->isArdoVip()) {
            if ($user->isPosBlocked()) {
                $this->setBlockPos($user, 0);
            }

            return $user->refresh();
        }

        $subscription = $this->currentSubscription($user);

        if ($user->isPosBlocked()) {
            if ($this->canUnblock($subscription)) {
                $this->setBlockPos($user, 0);
            }

            return $user->refresh();
        }

        if ($this->shouldBlockFromSubscription($subscription)) {
            $this->setBlockPos($user, 1);
            $this->revokeStaffTokens($user);
        }

        return $user->refresh();
    }

    /**
     * Recorre titulares con suscripción (no VIP) y sincroniza block_POS.
     */
    public function syncAll(): int
    {
        $updated = 0;

        User::query()
            ->where('user_ardo_vip', 0)
            ->whereHas('subscriptions')
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$updated) {
                foreach ($users as $user) {
                    $before = (int) $user->block_POS;
                    $this->applyForUser($user);
                    if ((int) $user->block_POS !== $before) {
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    public function currentSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->orderByDesc('id')
            ->first();
    }

    public function ownerForActor(User|Staff $actor): ?User
    {
        if ($actor instanceof User) {
            return $actor;
        }

        $actor->loadMissing('negocio.masterUser');

        return $actor->negocio?->masterUser;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $user = $this->applyForUser($user);
        $subscription = $this->currentSubscription($user);
        $daysPast = $subscription ? $this->daysPastCutoff($subscription) : null;
        $state = $this->accessState($user, $subscription, $daysPast);
        $blocked = $state === self::STATE_BLOCKED;
        $cutoff = $subscription?->accessUntil();

        return [
            'block_POS' => (int) $user->block_POS,
            'block_pos' => (int) $user->block_POS,
            'pos_blocked' => $blocked,
            'staff_blocked' => $blocked,
            'access_state' => $state,
            'status' => $subscription?->status,
            'cancel_at_period_end' => (bool) ($subscription?->cancel_at_period_end ?? false),
            'cutoff_at' => $cutoff?->toIso8601String(),
            'days_past_cutoff' => $daysPast,
            'allowed_sections' => $blocked ? ['negocio', 'billing'] : ['all'],
            'message' => $this->messageForState($state, $cutoff),
        ];
    }

    public function shouldBlockFromSubscription(?Subscription $subscription): bool
    {
        if (! $subscription) {
            return false;
        }

        $daysPast = $this->daysPastCutoff($subscription);
        if ($daysPast === null) {
            return false;
        }

        return $daysPast >= $this->blockAfterDays($subscription);
    }

    public function daysPastCutoff(Subscription $subscription): ?int
    {
        $until = $subscription->accessUntil();
        if (! $until) {
            return null;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $cutoff = $until->copy()->timezone($timezone)->startOfDay();
        $today = Carbon::now($timezone)->startOfDay();

        if ($today->equalTo($cutoff)) {
            return 0;
        }

        $days = (int) $cutoff->diffInDays($today);

        return $today->greaterThan($cutoff) ? $days : -$days;
    }

    public function blockAfterDays(Subscription $subscription): int
    {
        return $subscription->isUserCanceled()
            ? self::BLOCK_AFTER_DAYS_CANCEL
            : self::BLOCK_AFTER_DAYS_LAPSE;
    }

    private function canUnblock(?Subscription $subscription): bool
    {
        if (! $subscription || ! in_array($subscription->status, ['active', 'trialing'], true)) {
            return false;
        }

        return ! $this->shouldBlockFromSubscription($subscription);
    }

    private function accessState(User $user, ?Subscription $subscription, ?int $daysPast): string
    {
        if ($user->isPosBlocked()) {
            return self::STATE_BLOCKED;
        }

        if (! $subscription) {
            return self::STATE_ACTIVE;
        }

        if ($this->shouldBlockFromSubscription($subscription)) {
            return self::STATE_BLOCKED;
        }

        if ($subscription->isUserCanceled() && ($daysPast === null || $daysPast < self::BLOCK_AFTER_DAYS_CANCEL)) {
            return self::STATE_CANCELED_PENDING;
        }

        if (! $subscription->isUserCanceled() && $daysPast !== null && $daysPast >= 0) {
            if ($daysPast >= 1) {
                return self::STATE_WARNING;
            }

            return self::STATE_EXPIRED;
        }

        return self::STATE_ACTIVE;
    }

    private function messageForState(string $state, ?Carbon $cutoff): ?string
    {
        $fecha = $cutoff?->timezone((string) config('app.timezone', 'UTC'))->format('d/m/Y');

        return match ($state) {
            self::STATE_CANCELED_PENDING => $fecha
                ? "Tu suscripción se cancelará el {$fecha}. Seguirás teniendo acceso hasta esa fecha. No hay reembolso."
                : 'Tu suscripción está programada para cancelarse al final del periodo. No hay reembolso.',
            self::STATE_EXPIRED => 'Tu suscripción ha vencido. Renueva o el acceso será bloqueado.',
            self::STATE_WARNING => 'Tu suscripción se bloqueará mañana. Renueva para mantener el acceso.',
            self::STATE_BLOCKED => 'Tu suscripción está bloqueada. Entra a Mi Negocio para reactivar tu cuenta.',
            default => null,
        };
    }

    private function setBlockPos(User $user, int $value): void
    {
        $user->forceFill(['block_POS' => $value])->save();
    }

    private function revokeStaffTokens(User $user): void
    {
        $negocio = $user->negocio;
        if (! $negocio) {
            return;
        }

        $staffIds = Staff::query()
            ->where('negocio_id', $negocio->id)
            ->pluck('id');

        if ($staffIds->isEmpty()) {
            return;
        }

        PersonalAccessToken::query()
            ->where('tokenable_type', Staff::class)
            ->whereIn('tokenable_id', $staffIds)
            ->delete();
    }
}
