<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsletterSignup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsletterSignup>
 */
class NewsletterSignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterSignup::class);
    }

    public function existsForEmail(string $email): bool
    {
        return null !== $this->findOneBy(['email' => mb_strtolower($email)]);
    }
}
