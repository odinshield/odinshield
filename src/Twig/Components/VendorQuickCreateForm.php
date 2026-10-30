<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 André Gommlich, www.odinshield.net
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Twig\Components;

use App\Entity\Vendor;
use App\Form\VendorQuickCreateType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

// SEC-006: Gate the component-level endpoint — mirrors SoftwareVoter::MODIFY_BYPASS.
// ROLE_ISB is accepted alongside ROLE_SOFTWARE_OWNER because role_hierarchy is
// intentionally absent (SEC-013); the project treats ISB as a peer of the
// resource-owner roles for write operations.
#[AsLiveComponent]
#[IsGranted(new Expression("is_granted('ROLE_SOFTWARE_OWNER') or is_granted('ROLE_ISB')"))]
final class VendorQuickCreateForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public ?Vendor $initialFormData = null;

    // priority > 0 so this runs before ComponentWithFormTrait::initializeForm() (priority 0)
    #[PostMount(priority: 10)]
    public function postMount(): void
    {
        if (null === $this->initialFormData) {
            $this->initialFormData = new Vendor();
        }
    }

    /** @return FormInterface<Vendor> */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(VendorQuickCreateType::class, $this->initialFormData ?? new Vendor());
    }

    #[LiveAction]
    public function save(EntityManagerInterface $em, TranslatorInterface $translator): void
    {
        $this->submitForm();

        /** @var Vendor $vendor */
        $vendor = $this->getForm()->getData();
        $em->persist($vendor);
        $em->flush();

        $this->emit('vendor:created', ['vendor' => $vendor->getId()]);
        $this->dispatchBrowserEvent('modal:close');
        $this->dispatchBrowserEvent('toast:spawn', [
            'level' => 'success',
            'message' => $translator->trans('vendor.quick_create.created'),
            'closeLabel' => $translator->trans('common.close'),
        ]);
        $this->initialFormData = new Vendor();
        $this->resetForm();
    }
}
