<?php

namespace App\Entity;

use App\Repository\ShopItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShopItemRepository::class)]
#[ORM\Table(name: 'shop_items')]
class ShopItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $itemId = '';

    #[ORM\Column(length: 32)]
    private string $category = 'misc'; // frame|avatar|theme|effect|background|bundle

    #[ORM\Column(length: 128)]
    private string $name = '';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $coinCost = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $xpCost = null;

    #[ORM\Column(length: 16, options: ['default' => 'common'])]
    private string $rarity = 'common'; // common|rare|epic|legendary

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getItemId(): string { return $this->itemId; }
    public function setItemId(string $v): static { $this->itemId = $v; return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $v): static { $this->category = $v; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }
    public function getCoinCost(): int { return $this->coinCost; }
    public function setCoinCost(int $v): static { $this->coinCost = max(0, $v); return $this; }
    public function getXpCost(): ?int { return $this->xpCost; }
    public function setXpCost(?int $v): static { $this->xpCost = $v; return $this; }
    public function getRarity(): string { return $this->rarity; }
    public function setRarity(string $v): static { $this->rarity = $v; return $this; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $v): static { $this->imageUrl = $v; return $this; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $v): static { $this->metadata = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $v): static { $this->active = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function toArray(): array
    {
        return [
            'id' => $this->itemId,
            'category' => $this->category,
            'name' => $this->name,
            'description' => $this->description,
            'coinCost' => $this->coinCost,
            'xpCost' => $this->xpCost,
            'rarity' => $this->rarity,
            'imageUrl' => $this->imageUrl,
            'metadata' => $this->metadata,
        ];
    }
}
