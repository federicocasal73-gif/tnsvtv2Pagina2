<?php

namespace App\Entity;

use App\Repository\AdminAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Auditoria de acciones admin / intentos de acceso.
 * Cada llamada a un endpoint admin (create/close/cancel tournament, etc)
 * o intento de login admin (exitoso o fallido) queda registrada aca.
 */
#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class)]
#[ORM\Table(name: 'admin_audit_log')]
#[ORM\Index(name: 'idx_audit_admin', columns: ['admin_code'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
#[ORM\Index(name: 'idx_audit_ip', columns: ['ip'])]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
class AdminAuditLog
{
    public const ACTION_TOURNAMENT_CREATE   = 'tournament.create';
    public const ACTION_TOURNAMENT_CLOSE    = 'tournament.close';
    public const ACTION_TOURNAMENT_CANCEL   = 'tournament.cancel';
    public const ACTION_LOGIN_SUCCESS       = 'admin.login.success';
    public const ACTION_LOGIN_FAIL          = 'admin.login.fail';
    public const ACTION_WALLET_ADJUST       = 'wallet.adjust';

    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAIL    = 'fail';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $adminCode = '';

    #[ORM\Column(length: 64)]
    private string $action = '';

    #[ORM\Column(length: 16)]
    private string $result = self::RESULT_SUCCESS;

    #[ORM\Column(length: 64)]
    private string $ip = '';

    #[ORM\Column(length: 255)]
    private string $userAgent = '';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAdminCode(): string { return $this->adminCode; }
    public function setAdminCode(string $v): self { $this->adminCode = $v; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $v): self { $this->action = $v; return $this; }
    public function getResult(): string { return $this->result; }
    public function setResult(string $v): self { $this->result = $v; return $this; }
    public function getIp(): string { return $this->ip; }
    public function setIp(string $v): self { $this->ip = $v; return $this; }
    public function getUserAgent(): string { return $this->userAgent; }
    public function setUserAgent(string $v): self { $this->userAgent = $v; return $this; }
    public function getPayload(): ?array { return $this->payload; }
    public function setPayload(?array $v): self { $this->payload = $v; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): self { $this->notes = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $v): self { $this->createdAt = $v; return $this; }
}