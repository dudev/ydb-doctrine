<?php

namespace Dudev\YdbDoctrine\ORM\Hack;

class Setter
{
    /** @param class-string $parentClassName */
    public function __construct(
        private object $object,
        private string $parentClassName
    ) {
    }

    private function execute(\Closure $func): mixed
    {
        $bound = $func->bindTo($this->object, $this->parentClassName)
            ?? throw new \Exception("Could not bind closure to scope {$this->parentClassName}");

        return $bound();
    }

    public function setValue(string $property, mixed $value): void
    {
        $this->execute(function () use ($property, $value) {
            /* @var \stdClass $this */
            $this->{$property} = $value;
        });
    }

    public function getValue(string $property): mixed
    {
        return $this->execute(function () use ($property) {
            /* @var \stdClass $this */
            return $this->{$property};
        });
    }
}
