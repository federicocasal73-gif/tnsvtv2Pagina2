<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LinkPreviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LinkPreviewRepository::class)]
#[ORM\Table(name: 'link_previews')]
#[ORM\Index(name: 'idx_lp_expires', columns: ['expires_at'])]
#[ORM\Index(name: 'idx_lp_domain', columns: ['domain'])]
class LinkPreview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $urlHash = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $url = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $imageExternal = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageLocal = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $faviconExternal = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $faviconLocal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteName = null;

    #[ORM\Column(length: 255)]
    private ?string $domain = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mime = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $enriched = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawMetadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastUpdate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    public function getId(): ?int { return $this->id; }

    public function getUrlHash(): ?string { return $this->urlHash; }
    public function setUrlHash(string $h): static { $this->urlHash = $h; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(string $u): static { $this->url = $u; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $t): static { $this->title = $t; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }

    public function getImageExternal(): ?string { return $this->imageExternal; }
    public function setImageExternal(?string $u): static { $this->imageExternal = $u; return $this; }

    public function getImageLocal(): ?string { return $this->imageLocal; }
    public function setImageLocal(?string $p): static { $this->imageLocal = $p; return $this; }

    public function getFaviconExternal(): ?string { return $this->faviconExternal; }
    public function setFaviconExternal(?string $u): static { $this->faviconExternal = $u; return $this; }

    public function getFaviconLocal(): ?string { return $this->faviconLocal; }
    public function setFaviconLocal(?string $p): static { $this->faviconLocal = $p; return $this; }

    public function getSiteName(): ?string { return $this->siteName; }
    public function setSiteName(?string $s): static { $this->siteName = $s; return $this; }

    public function getDomain(): ?string { return $this->domain; }
    public function setDomain(string $d): static { $this->domain = $d; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $t): static { $this->type = $t; return $this; }

    public function getMime(): ?string { return $this->mime; }
    public function setMime(?string $m): static { $this->mime = $m; return $this; }

    public function getEnriched(): ?array { return $this->enriched; }
    public function setEnriched(?array $e): static { $this->enriched = $e; return $this; }

    public function getRawMetadata(): ?array { return $this->rawMetadata; }
    public function setRawMetadata(?array $r): static { $this->rawMetadata = $r; return $this; }

    public function getLastUpdate(): ?\DateTimeImmutable { return $this->lastUpdate; }
    public function setLastUpdate(\DateTimeImmutable $d): static { $this->lastUpdate = $d; return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $d): static { $this->expiresAt = $d; return $this; }

    public function getError(): ?string { return $this->error; }
    public function setError(?string $e): static { $this->error = $e; return $this; }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'url_hash' => $this->urlHash,
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->imageLocal ?: $this->imageExternal,
            'image_external' => $this->imageExternal,
            'image_local' => $this->imageLocal,
            'favicon' => $this->faviconLocal ?: $this->faviconExternal,
            'site' => $this->siteName,
            'domain' => $this->domain,
            'type' => $this->type,
            'mime' => $this->mime,
            'enriched' => $this->enriched,
            'last_update' => $this->lastUpdate?->format('c'),
            'expires_at' => $this->expiresAt?->format('c'),
            'error' => $this->error,
        ];
    }
}