<?php

namespace App\Entity;

use App\Repository\FeedPostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeedPostRepository::class)]
#[ORM\Table(name: 'feed_posts')]
class FeedPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(length: 50)]
    private ?string $category = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $likes = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $comments = null;

    #[ORM\Column(name: 'signal_data', type: Types::JSON, nullable: true)]
    private ?array $signal = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(name: 'link_previews', type: Types::JSON, nullable: true)]
    private ?array $linkPreviews = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $author): static { $this->author = $author; return $this; }

    public function getContent(): ?string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(string $category): static { $this->category = $category; return $this; }

    public function getLikes(): int { return $this->likes; }
    public function setLikes(int $likes): static { $this->likes = $likes; return $this; }

    public function getComments(): ?array { return $this->comments; }
    public function setComments(?array $comments): static { $this->comments = $comments; return $this; }

    public function getSignal(): ?array { return $this->signal; }
    public function setSignal(?array $signal): static { $this->signal = $signal; return $this; }

    public function getPhoto(): ?string { return $this->photo; }
    public function setPhoto(?string $photo): static { $this->photo = $photo; return $this; }

    public function getLinkPreviews(): ?array { return $this->linkPreviews; }
    public function setLinkPreviews(?array $linkPreviews): static { $this->linkPreviews = $linkPreviews; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}
