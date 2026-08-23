<?php

/**
 * Overrides Imagick::convolveImage()'s bundled PHPStan stub, whose $kernel
 * parameter is still typed as bare `array`. Real ext-imagick requires an
 * ImagickKernel object since 3.5.0 (raw-array support is not accepted) --
 * a convolveImage(array) call throws a TypeError, and this environment's
 * own installed extension's native reflection
 * (ReflectionMethod::getParameters() reports `ImagickKernel $kernel`, not
 * `array`) confirms it. PHPStan's bundled stub lives inside
 * phpstan.phar itself (vendor/jetbrains/phpstorm-stubs/imagick/imagick.stub
 * within the phar), not the top-level jetbrains/phpstorm-stubs composer
 * dependency, so it can't be fixed with a composer-patches patch against
 * vendor/ -- this project-local stubFiles override (see phpstan.neon) is
 * PHPStan's own supported mechanism for exactly this situation.
 */
class Imagick
{
    /**
     * @param array<int, array<int, float>>|ImagickKernel $kernel
     */
    public function convolveImage(array|ImagickKernel $kernel, int $channel = Imagick::CHANNEL_ALL): bool
    {
    }
}

/**
 * Overrides ImagickKernel::fromMatrix()'s bundled stub, whose $origin
 * parameter is declared required. This environment's own installed
 * extension's native reflection (ReflectionMethod::getParameters() reports
 * `?array $origin = null`) confirms the real signature makes it optional,
 * defaulting to null (the kernel's own center). Same PHPStan-bundled-stub
 * limitation as Imagick::convolveImage() above.
 */
class ImagickKernel
{
    /**
     * @param array<int, array<int, float>> $matrix
     * @param array<int, int>|null $origin
     */
    public static function fromMatrix(array $matrix, ?array $origin = null): ImagickKernel
    {
    }
}
