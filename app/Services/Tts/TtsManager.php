<?php

namespace App\Services\Tts;

use App\Contracts\TtsDriverInterface;
use InvalidArgumentException;

class TtsManager
{
    /**
     * @var array<string, TtsDriverInterface>
     */
    protected array $drivers = [];

    protected string $defaultDriver;

    public function __construct()
    {
        $this->defaultDriver = config('dubber.tts.default', 'edge');
    }

    /**
     * Register a TTS driver.
     */
    public function register(string $name, TtsDriverInterface $driver): self
    {
        $this->drivers[$name] = $driver;
        return $this;
    }

    /**
     * Get a TTS driver by name.
     */
    public function driver(?string $name = null): TtsDriverInterface
    {
        $name = $name ?? $this->defaultDriver;

        if (!isset($this->drivers[$name])) {
            throw new InvalidArgumentException("TTS driver [{$name}] not registered.");
        }

        return $this->drivers[$name];
    }

    /**
     * Get the default driver.
     */
    public function getDefault(): TtsDriverInterface
    {
        return $this->driver($this->defaultDriver);
    }

    /**
     * Set the default driver.
     */
    public function setDefault(string $name): self
    {
        $this->defaultDriver = $name;
        return $this;
    }

    /**
     * Get all registered drivers.
     *
     * @return array<string, TtsDriverInterface>
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    /**
     * Get drivers that support voice cloning.
     *
     * @return array<string, TtsDriverInterface>
     */
    public function getVoiceCloningDrivers(): array
    {
        return array_filter($this->drivers, fn($d) => $d->supportsVoiceCloning());
    }

    /**
     * Get drivers that support real emotions.
     *
     * @return array<string, TtsDriverInterface>
     */
    public function getEmotionDrivers(): array
    {
        return array_filter($this->drivers, fn($d) => $d->supportsEmotions());
    }

    /**
     * Check if a driver exists.
     */
    public function hasDriver(string $name): bool
    {
        return isset($this->drivers[$name]);
    }
}
