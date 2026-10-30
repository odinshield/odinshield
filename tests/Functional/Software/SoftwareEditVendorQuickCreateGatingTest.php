<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Tests\Functional\Software;

use App\Entity\Software;
use App\Entity\User;
use App\Entity\Vendor;
use App\Tests\Functional\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Regression: users with per-entity SoftwareVoter::MODIFY access (via
 * responsibleUser) but without ROLE_SOFTWARE_OWNER or ROLE_ISB used to see
 * the Vendor quick-create button and modal — clicking through caused
 * AccessDeniedHttpException 403 on the Live Component AJAX endpoint and
 * spammed ops mails. The trigger and modal must be hidden for users
 * who can't actually use the quick-create flow.
 */
final class SoftwareEditVendorQuickCreateGatingTest extends AbstractWebTestCase
{
    use InteractsWithLiveComponents;

    public function testEditPageHidesQuickCreateForResponsibleUserWithoutBypassRole(): void
    {
        $user = $this->createResponsibleUserWithoutBypassRole();
        $software = $this->createSoftwareOwnedBy($user);

        $this->client->loginUser($user);

        $this->client->request('GET', '/software/'.$software->getUuid()->toRfc4122().'/edit');

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('id="modal-vendor-quick-create"', $html);
        $this->assertStringNotContainsString('data-bs-target="#modal-vendor-quick-create"', $html);
    }

    public function testEditPageShowsQuickCreateForSoftwareOwner(): void
    {
        $em = $this->em();

        $this->loginAsSoftwareOwner();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Some Software');
        $software->setVendor($vendor);
        $software->setProductKey('some');
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $em->persist($software);
        $em->flush();

        $this->client->request('GET', '/software/'.$software->getUuid()->toRfc4122().'/edit');

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('id="modal-vendor-quick-create"', $html);
        $this->assertStringContainsString('data-bs-target="#modal-vendor-quick-create"', $html);
    }

    public function testIsbCanMountVendorQuickCreateLiveComponent(): void
    {
        $isb = $this->createIsbUser();
        $this->client->loginUser($isb);

        // Exactly what Stimulus does on page load — mount the component via the
        // Live Component endpoint. Pre-fix this 403'd because the class-level
        // IsGranted demanded ROLE_SOFTWARE_OWNER literally.
        $component = $this->createLiveComponent('VendorQuickCreateForm', [], $this->client);
        $rendered = (string) $component->render();

        $this->assertNotEmpty($rendered);
        $this->assertStringContainsString('vendor', strtolower($rendered));
    }

    public function testSoftwareOwnerCanMountVendorQuickCreateLiveComponent(): void
    {
        $this->loginAsSoftwareOwner();

        $component = $this->createLiveComponent('VendorQuickCreateForm', [], $this->client);
        $rendered = (string) $component->render();

        $this->assertNotEmpty($rendered);
    }

    // NOTE: InteractsWithLiveComponents bypasses the HTTP controller dispatcher,
    // so a class-level IsGranted attribute is NOT invoked through that helper.
    // The two `*CanMount*` tests above prove the component renders structurally
    // — actual authorization for ISB/owner/plain users is covered by the three
    // `testIsGrantedExpression*` tests below, which run the same Expression
    // through the real security.authorization_checker service.

    /**
     * Directly evaluates the Expression used in VendorQuickCreateForm's
     * class-level IsGranted attribute. Validates the security side of the
     * fix without dragging in the Live Component hydration + checksum
     * machinery (which would need a Stimulus-signed payload to even reach
     * the IsGrantedAttributeListener).
     */
    public function testIsGrantedExpressionAcceptsIsb(): void
    {
        $isb = $this->createIsbUser();
        $this->client->loginUser($isb);

        $this->assertTrue($this->checkVendorQuickCreateExpression());
    }

    public function testIsGrantedExpressionAcceptsSoftwareOwner(): void
    {
        $this->loginAsSoftwareOwner();

        $this->assertTrue($this->checkVendorQuickCreateExpression());
    }

    public function testIsGrantedExpressionRejectsPlainUser(): void
    {
        $user = $this->createResponsibleUserWithoutBypassRole();
        $this->client->loginUser($user);

        $this->assertFalse($this->checkVendorQuickCreateExpression());
    }

    private function checkVendorQuickCreateExpression(): bool
    {
        // Mirrors src/Twig/Components/VendorQuickCreateForm.php — keep in sync.
        $expression = new \Symfony\Component\ExpressionLanguage\Expression(
            "is_granted('ROLE_SOFTWARE_OWNER') or is_granted('ROLE_ISB')",
        );

        /** @var \Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface $checker */
        $checker = $this->client->getContainer()->get('security.authorization_checker');

        return $checker->isGranted($expression);
    }

    public function testIsbCanSaveNewSoftwareViaLiveComponent(): void
    {
        // Regression: SoftwareForm::save() used to hard-code
        // denyAccessUnlessGranted('ROLE_SOFTWARE_OWNER') for the create path,
        // so ISB users hit a 403 on /_components/SoftwareForm/save even
        // though SoftwareVoter::CREATE bypasses ROLE_ISB. The action now
        // delegates to the voter — both paths must agree.
        $em = $this->em();
        $isb = $this->createIsbUser();
        $this->client->loginUser($isb);

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);
        $em->flush();

        $component = $this->createLiveComponent(
            'SoftwareForm',
            ['initialFormData' => null, 'isNew' => true, 'returnParams' => []],
            $this->client,
        );

        $component->submitForm([
            'software' => [
                'name' => 'New ISB Software',
                'vendor' => (string) $vendor->getId(),
                'productKey' => 'new-isb',
                'version' => '1.0.0',
                'maintenanceSource' => 'manual',
                'active' => '1',
            ],
        ], 'save');

        $this->assertResponseRedirects();
        $persisted = $em->getRepository(Software::class)->findOneBy(['productKey' => 'new-isb']);
        $this->assertNotNull($persisted);
    }

    public function testEditPageShowsQuickCreateForIsb(): void
    {
        $em = $this->em();
        $isb = $this->createIsbUser();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Some Software');
        $software->setVendor($vendor);
        $software->setProductKey('some');
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $em->persist($software);
        $em->flush();

        $this->client->loginUser($isb);
        $this->client->request('GET', '/software/'.$software->getUuid()->toRfc4122().'/edit');

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('id="modal-vendor-quick-create"', $html);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function createResponsibleUserWithoutBypassRole(): User
    {
        $em = $this->em();
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('plainuser');
        $user->setFirstName('Plain');
        $user->setLastName('User');
        $user->setEmail('plain@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([]); // ROLE_USER added implicitly by User::getRoles()
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createIsbUser(): User
    {
        $em = $this->em();
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('isbuser');
        $user->setFirstName('Isb');
        $user->setLastName('User');
        $user->setEmail('isb@example.com');
        $user->setPassword($hasher->hashPassword($user, 'TestPass123!'));
        $user->setIsActive(true);
        $user->setLocale('en');
        $user->setMustChangePassword(false);
        $user->setRoles([User::ROLE_ISB]);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createSoftwareOwnedBy(User $user): Software
    {
        $em = $this->em();

        $vendor = new Vendor();
        $vendor->setName('Acme');
        $vendor->setVendorKey('acme');
        $vendor->setActive(true);
        $em->persist($vendor);

        $software = new Software();
        $software->setName('Owned Software');
        $software->setVendor($vendor);
        $software->setProductKey('owned');
        $software->setVersion('1.0.0');
        $software->setActive(true);
        $software->setResponsibleUser($user);
        $em->persist($software);
        $em->flush();

        return $software;
    }
}
