<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Exceptions;

use Exception;

/**
 * A mapping file for the readable method names could not be had or had another form than expected (see
 * MethodNameService). Not shown to users: the profile then keeps the names the server runs with.
 */
class MappingException extends Exception
{
    /** Not published for this Minecraft version, e.g. Mojang's mappings before 1.14.4. Not worth asking again soon. */
    public bool $unavailable = false;

    public static function unavailable(string $message): self
    {
        $exception = new self($message);
        $exception->unavailable = true;

        return $exception;
    }
}
