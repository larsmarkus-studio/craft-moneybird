<?php

declare(strict_types=1);

namespace larsmarkusstudio\moneybird;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\View;
use larsmarkusstudio\moneybird\models\Settings;
use larsmarkusstudio\moneybird\services\ApiClient;
use larsmarkusstudio\moneybird\services\AuthService;
use larsmarkusstudio\moneybird\services\ContactsService;
use larsmarkusstudio\moneybird\services\DocumentsService;
use yii\base\Event;

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
 * @property-read AuthService $auth
 * @property-read ApiClient $api
 * @property-read ContactsService $contacts
 * @property-read DocumentsService $documents
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'auth' => AuthService::class,
                'api' => ApiClient::class,
                'contacts' => ContactsService::class,
                'documents' => DocumentsService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@craft-moneybird', __DIR__);

        // Make plugin templates (e.g. the onboarding picker) resolvable on the
        // front end. The host app can override them under templates/craft-moneybird/.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['craft-moneybird'] = __DIR__ . '/templates';
            },
        );
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
