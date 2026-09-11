<?php

namespace Dudev\YdbDoctrine\Tests\App\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Table(name: 'tmp_autoincrement_entity')]
#[Entity]
class AutoincrementEntity
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    public int $id;

    #[Column(type: 'string')]
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
