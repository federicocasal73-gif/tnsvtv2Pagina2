<?php

namespace App\Entity;

use App\Repository\LikedPostRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LikedPostRepository::class)]
#[ORM\Table(name: 'liked_posts')]
class LikedPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?FeedPost $post = null;

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getPost(): ?FeedPost { return $this->post; }
    public function setPost(?FeedPost $post): static { $this->post = $post; return $this; }
}
