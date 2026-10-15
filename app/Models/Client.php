<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Client extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'client_code',
        'name',
        'email',
        'phone',
        'address',
        'status',
        'balance',
        'notes',
        'portal_password',
        'portal_password_set_at',
    ];

    protected $hidden = [
        'portal_password',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'portal_password_set_at' => 'datetime',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->where('status', 'active');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Auto-generate sequential client code.
     */
    public static function generateClientCode(): string
    {
        $latest = static::latest('id')->first();
        $nextNum = $latest ? ($latest->id + 1001) : 1001;
        return 'CLI-' . $nextNum;
    }
}
