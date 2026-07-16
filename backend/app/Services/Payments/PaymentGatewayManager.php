<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayInterface;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $drivers = [];

    /**
     * @param  iterable<string, PaymentGatewayInterface>  $drivers
     */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $gateway => $driver) {
            $this->register($gateway, $driver);
        }
    }

    public function register(string $gateway, PaymentGatewayInterface $driver): void
    {
        $this->drivers[$gateway] = $driver;
    }

    public function driver(string $gateway): PaymentGatewayInterface
    {
        if (! isset($this->drivers[$gateway])) {
            throw new InvalidArgumentException("Payment gateway [{$gateway}] is not configured.");
        }

        return $this->drivers[$gateway];
    }
}
