<?php declare(strict_types=1);

namespace Compumess\ProductMediaMonitor\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class Migration1742532315AddMailtemplateType extends MigrationStep
{
    public const PRODUCT_MEDIA_MONITOR_REPORT = "product_media_monitor_report";
    public function getCreationTimestamp(): int
    {
        return 1742532315;
    }

    public function update(Connection $connection): void
    {
        $mailTemplateTypeId = Uuid::randomBytes();
        $technicalName = self::PRODUCT_MEDIA_MONITOR_REPORT;
    
        $existingRecord = $connection->fetchOne(
            'SELECT id FROM mail_template_type WHERE technical_name = :technicalName',
            ['technicalName' => $technicalName]
        );
    
        if (!$existingRecord) {
            $connection->insert(
                'mail_template_type',
                [
                    'id' => $mailTemplateTypeId,
                    'technical_name' => $technicalName,
                    'available_entities' => json_encode(['salesChannel' => 'sales_channel']),
                    'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            );
    
            $defaultLangId = $this->getLanguageIdByLocale($connection, 'en-GB');
            $deLangId = $this->getLanguageIdByLocale($connection, 'de-DE');
    
            if ($deLangId) {
                $connection->insert(
                    'mail_template_type_translation',
                    [
                        'mail_template_type_id' => $mailTemplateTypeId,
                        'name' => 'Produktmedienmonitor Bericht',
                        'language_id' => $deLangId,
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );
            }
    
            if ($defaultLangId !== $deLangId) {
                $connection->insert(
                    'mail_template_type_translation',
                    [
                        'mail_template_type_id' => $mailTemplateTypeId,
                        'name' => 'Product media monitor report',
                        'language_id' => $defaultLangId,
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );
            }
    
            // Add system language fallback if needed
            $systemLangId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
            if ($defaultLangId !== $systemLangId) {
                $connection->insert(
                    'mail_template_type_translation',
                    [
                        'mail_template_type_id' => $mailTemplateTypeId,
                        'name' => 'Product media monitor report',
                        'language_id' => $systemLangId,
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );
            }
        }
    }

    private function getLanguageIdByLocale(Connection $connection, string $locale): ?string
    {
        $sql = <<<'SQL'
        SELECT `language`.`id`
        FROM `language`
        INNER JOIN `locale` ON `locale`.`id` = `language`.`locale_id`
        WHERE `locale`.`code` = :code
        SQL;

        /** @var string|false $languageId */
        $languageId = $connection->executeQuery($sql, ['code' => $locale])->fetchOne();
        if (!$languageId && $locale !== 'en-GB') {
            return null;
        }

        if (!$languageId) {
            return Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        }

        return $languageId;
    }


    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
