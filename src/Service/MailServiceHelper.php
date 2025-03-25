<?php

namespace Compumess\ProductMediaMonitor\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class MailServiceHelper
{
    private $mailTemplateRepository;
    private $mailService;
    private $mailTemplateTypeRepo;
    private $systemConfigService;
    private $salesChannelRepository;


    public const PRODUCT_MEDIA_MONITOR_REPORT = "product_media_monitor_report";
    public function __construct(
        EntityRepository $mailTemplateRepository,
        MailService $mailService,
        EntityRepository $mailTemplateTypeRepo,
        SystemConfigService $systemConfigService,
        EntityRepository $salesChannelRepository
    ) {
        $this->mailTemplateRepository = $mailTemplateRepository;
        $this->mailService = $mailService;
        $this->mailTemplateTypeRepo = $mailTemplateTypeRepo;
        $this->systemConfigService = $systemConfigService;
        $this->salesChannelRepository = $salesChannelRepository;
    }
    public function sendReportEmail($multipleMedia, $noMedia, $context)
    {
        $mailRecepients = $this->systemConfigService->get('CompumessProductMediaMonitor.config.mediaReportRecepients');
        $recipientArray = [];

        if (is_string($mailRecepients)) {
            $emails = array_map('trim', explode(',', $mailRecepients));

            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipientArray[$email] = $email;
                }
            }
        }

        $mailTemplateTypeId = $this->fetchMailTemplateType(self::PRODUCT_MEDIA_MONITOR_REPORT, $context);

        $mailTemplate = $this->mailTemplateRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('mailTemplateTypeId', $mailTemplateTypeId))->setLimit(1),
            $context
        )->first();

        if ($mailTemplate === null) {
            return false;
        }

        $data = new DataBag();

        $data->set('recipients', $recipientArray);
        $data->set('senderName', $mailTemplate->getTranslation('senderName'));
        $data->set('salesChannelId', $this->getSalesChannelIdByName('CME - CompuMess Elektronik GmbH', $context));
        $data->set('contentHtml', $mailTemplate->getTranslation('contentHtml'));
        $data->set('contentPlain', $mailTemplate->getTranslation('contentPlain'));
        $data->set('subject', $mailTemplate->getTranslation('subject'));

        try {
            $this->mailService->send(
                $data->all(),
                $context,
                ['multipleMedia' => $multipleMedia, 'noMedia' => $noMedia]
            );
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }
    private function fetchMailTemplateType(string $technicalName, $context): string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('mail_template_type.technicalName', $technicalName));

        $result = $this->mailTemplateTypeRepo->search($criteria, $context);

        $mailTemplateTypeDetails = $result->first();

        return ($mailTemplateTypeDetails) ? $mailTemplateTypeDetails->getId() : null;
    }

    private function getSalesChannelIdByName(string $name, $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        $criteria->setLimit(1);

        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        return $salesChannel ? $salesChannel->getId() : null;
    }
}
