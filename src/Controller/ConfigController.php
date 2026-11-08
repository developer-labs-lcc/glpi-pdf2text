<?php

/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 */

namespace GlpiPlugin\Pdf2text\Controller;

use Config;
use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Pdf2text\Cast;
use GlpiPlugin\Pdf2text\PdfTextExtractor;
use GlpiPlugin\Pdf2text\Settings;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Plugin configuration (Setup > Plugins > PDF2Text), only for users who may change
 * the GLPI configuration.
 */
final class ConfigController extends AbstractController
{
    #[Route('/config', name: 'config')]
    public function config(Request $request): Response
    {
        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;

        if (!Session::haveRight(Config::$rightname, UPDATE)) {
            throw new AccessDeniedHttpException();
        }
        $action = Cast::string($CFG_GLPI['root_doc']) . '/plugins/pdf2text/config';

        if ($request->getMethod() === 'POST') {
            $values = [
                'is_private' => $request->request->getBoolean('is_private') ? 1 : 0,
                'max_chars'  => max(1000, min(1000000, $request->request->getInt('max_chars', Settings::DEFAULTS['max_chars']))),
            ];
            foreach (array_keys(Settings::ITEMTYPES) as $key) {
                $values[$key] = $request->request->getBoolean($key) ? 1 : 0;
            }
            // Only an absolute path is accepted; the program is started without a shell.
            $path = trim((string) $request->request->get('pdftotext_path', ''));
            $values['pdftotext_path'] = str_starts_with($path, '/') ? mb_substr($path, 0, 255) : '';

            Settings::set($values);
            Session::addMessageAfterRedirect(__('The configuration was saved.', 'pdf2text'));

            return new RedirectResponse($action);
        }
        if ($request->getMethod() !== 'GET') {
            throw new MethodNotAllowedHttpException(['GET', 'POST']);
        }

        $settings = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $settings[$key] = Settings::get($key);
        }

        return $this->render('@pdf2text/config.html.twig', [
            'action'   => $action,
            'settings' => $settings,
            'method'   => (new PdfTextExtractor(Cast::string($settings['pdftotext_path'])))->getMethod(),
            'csrf_token' => self::getCsrfToken(),
        ]);
    }

    /**
     * GLPI 11 checks a token sent with every form. GLPI 12 checks the origin of the request
     * instead and no longer needs (or offers) the token.
     */
    private static function getCsrfToken(): string
    {
        if (version_compare(GLPI_VERSION, '12.0.0-dev', '<')) {
            // Called by name: the method is deprecated in GLPI 12, where this line is not reached.
            return Cast::string(call_user_func([Session::class, 'getNewCSRFToken']));
        }

        return '';
    }
}
