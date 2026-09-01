<?php

namespace Dudev\YdbDoctrine\Tests\App\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * Owning-side OneToOne fixture, distinct from Post's ManyToOne - used to pin
 * that ReferentialIntegrityListener's ToOneOwningSideMapping check covers
 * OneToOneOwningSideMapping too, not just ManyToOneAssociationMapping.
 */
#[Table(name: 'profile')]
#[Entity]
class Profile
{
    #[Id()]
    #[Column(type: 'integer')]
    public int $id;

    #[Column(type: 'text')]
    public string $bio;

    #[OneToOne(targetEntity: User::class)]
    #[JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    public User $user;

    public function __construct(int $id, string $bio, User $user)
    {
        $this->id = $id;
        $this->bio = $bio;
        $this->user = $user;
    }
}
