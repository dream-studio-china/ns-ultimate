<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

if (!\class_exists(__NAMESPACE__ . '\\Kernel', false)) {
    class Kernel extends BaseKernel
    {
        use MicroKernelTrait;
    }
}
