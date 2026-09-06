<?php

namespace App\Entity;

use App\Entity\Trait\UserAuthTrait;
use App\Entity\Trait\UserEconomyTrait;
use App\Entity\Trait\UserTierTrait;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use UserAuthTrait;
    use UserEconomyTrait;
    use UserTierTrait;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: WalletTransaction::class)]
    private Collection $walletTransactions;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: DiaryEntry::class)]
    private Collection $diaryEntries;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Connection::class)]
    private Collection $connections;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: JournalSetting::class)]
    private ?JournalSetting $journalSetting = null;

    #[ORM\OneToMany(mappedBy: 'assignedTo', targetEntity: Task::class)]
    private Collection $tasksAssigned;

    #[ORM\OneToMany(mappedBy: 'assignedBy', targetEntity: Task::class)]
    private Collection $tasksCreated;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: TaskSubmission::class)]
    private Collection $taskSubmissions;

    #[ORM\OneToMany(mappedBy: 'author', targetEntity: TaskComment::class)]
    private Collection $taskComments;

    #[ORM\OneToMany(mappedBy: 'grader', targetEntity: TaskFeedback::class)]
    private Collection $taskFeedbacksGiven;

    public function __construct()
    {
        $this->walletTransactions = new ArrayCollection();
        $this->diaryEntries = new ArrayCollection();
        $this->connections = new ArrayCollection();
        $this->tasksAssigned = new ArrayCollection();
        $this->tasksCreated = new ArrayCollection();
        $this->taskSubmissions = new ArrayCollection();
        $this->taskComments = new ArrayCollection();
        $this->taskFeedbacksGiven = new ArrayCollection();
    }

    public function getWalletTransactions(): Collection { return $this->walletTransactions; }
    public function getDiaryEntries(): Collection { return $this->diaryEntries; }
    public function getConnections(): Collection { return $this->connections; }
    public function getJournalSetting(): ?JournalSetting { return $this->journalSetting; }
    public function getTasksAssigned(): Collection { return $this->tasksAssigned; }
    public function getTasksCreated(): Collection { return $this->tasksCreated; }
    public function getTaskSubmissions(): Collection { return $this->taskSubmissions; }
    public function getTaskComments(): Collection { return $this->taskComments; }
    public function getTaskFeedbacksGiven(): Collection { return $this->taskFeedbacksGiven; }

    public function getAvatarUrl(): ?string
    {
        $code = $this->code;
        if (!$code) return null;
        $avatarDir = dirname(__DIR__, 2) . '/public/uploads/avatars';
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            if (is_file("$avatarDir/$code.$ext")) {
                return "/uploads/avatars/$code.$ext";
            }
        }
        return null;
    }

    public function getAvatarColor(): ?string
    {
        // ⛧ FIX BUG-5: Color determinístico basado en el código del usuario
        // (siempre retorna null antes → todos los avatares eran violeta)
        $colors = [
            '#9353ff', '#34c759', '#ffb300', '#3b9eff', '#ff6b6b',
            '#c327fb', '#00bfa5', '#ff4081', '#7c4dff', '#69f0ae',
        ];
        $code = $this->code ?? 'X';
        $idx = abs(crc32($code)) % count($colors);
        return $colors[$idx];
    }
}