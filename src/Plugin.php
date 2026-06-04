<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use larsmarkusstudio\moneybird\models\Settings;

/**
 * Moneybird plugin.
 *
 * Provides Moneybird OAuth authentication and API service classes as a general
 * foundation for building Moneybird integrations on top of Craft CMS.
 *
 * @author Lars Markus
 * @license MIT
 *
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    /**
     * Service components are registered here. Implementations land in Phase 1
     * (MoneybirdAuthService, MoneybirdContactsService, MoneybirdDocumentsService).
     */
    public static function config(): array
    {
        return [
            'components' => [],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@craft-moneybird', __DIR__);

        // OAuth URL rules, callback handling and event wiring are added in Phase 1.
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('craft-moneybird/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }
}
