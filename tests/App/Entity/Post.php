<?php

namespace Dudev\YdbDoctrine\Tests\App\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

#[Table(name: 'post')]
#[Entity]
class Post
{
    #[Id()]
    #[Column(type: 'integer')]
    public int $id;

    #[Column(type: 'text')]
    public string $title;

    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    public User $author;

    public function __construct(int $id, string $title, User $author)
    {
        $this->id = $id;
        $this->title = $title;
        $this->author = $author;
    }
}
