<?php

namespace VanOns\LaravelEnvironmentImporter\Support;

use Symfony\Component\Process\Process;

class AsyncProcess extends Process
{
    public function __destruct()
    {
        // We override the __destruct method to prevent the process from being killed when the object is destroyed.
        // We take care of this ourselves.
    }
}
