<?php

namespace Dudev\YdbDoctrine\Tests\App\Entity;

use DateTimeInterface;
use Dudev\YdbDoctrine\Tests\App\Repository\UserRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Table(name: 'user')]
#[Entity(repositoryClass: UserRepository::class)]
class User
{
    #[Id()]
    #[Column(type: 'integer')]
    public int $id;

    #[Column(type: 'text', nullable: true)]
    public string $name;

    #[Column(type: 'integer', nullable: true)]
    public int $age;

    // ydb-doctrine's DateTimeTzType converter hydrates a mutable \DateTime (see
    // src/Type/DateTimeTzType.php::convertToPHPValue()) - not DateTimeImmutable,
    // even though the type name says "tz". Typed as the interface so both the
    // constructor's DateTimeImmutable arg and hydration's DateTime are valid.
    #[Column(type: 'datetimetz', nullable: true)]
    public DateTimeInterface $createAt;

    #[Column(type: 'boolean', nullable: true)]
    public bool $active;

    #[Column(type: 'boolean', nullable: true)]
    public bool $delete;

    public function __construct(
        int $id,
        string $name,
        int $age,
        DateTimeInterface $createAt,
        bool $active,
        bool $delete,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->age = $age;
        $this->createAt = $createAt;
        $this->active = $active;
        $this->delete = $delete;
    }
}
