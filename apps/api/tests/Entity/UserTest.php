<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DomainExpertise;
use App\Entity\TreeNode;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testRolesAlwaysIncludeUser(): void
    {
        $user = (new User('a@b.c', 'Alice'))->setRoles(['ROLE_COMITE']);

        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertContains('ROLE_COMITE', $user->getRoles());
    }

    public function testDisplayNamePrefersPseudo(): void
    {
        $user = new User('a@b.c', 'Alice Réelle');
        self::assertSame('Alice Réelle', $user->getDisplayName());

        $user->setPseudo('alice42');
        self::assertSame('alice42', $user->getDisplayName());
    }

    public function testDomainExpertiseScope(): void
    {
        $physics = new TreeNode('physics', 'Physics');
        $biology = new TreeNode('biology', 'Biology');

        $user = new User('curie@labo.fr', 'Curie');
        $user->addExpertise(new DomainExpertise($physics));

        self::assertTrue($user->hasExpertiseOn($physics));
        self::assertFalse($user->hasExpertiseOn($biology));
    }

    public function testIdentityVerification(): void
    {
        $user = new User('a@b.c', 'Alice');
        self::assertFalse($user->isIdentityVerified());

        $user->verifyIdentity('orcid');
        self::assertTrue($user->isIdentityVerified());
        self::assertSame('orcid', $user->getVerificationMethod());
    }
}
