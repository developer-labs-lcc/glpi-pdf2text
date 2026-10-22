<?php

/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 */

namespace GlpiPlugin\Pdftext\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Pdftext\PdfTextExtractor;
use GlpiPlugin\Pdftext\Settings;
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
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!Session::haveRight('config', UPDATE)) {
            throw new AccessDeniedHttpException();
        }
        $action = $CFG_GLPI['root_doc'] . '/plugins/pdftext/config';

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
            Session::addMessageAfterRedirect(__('The configuration was saved.', 'pdftext'));

            return new RedirectResponse($action);
        }
        if ($request->getMethod() !== 'GET') {
            throw new MethodNotAllowedHttpException(['GET', 'POST']);
        }

        $settings = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $settings[$key] = Settings::get($key);
        }

        return $this->render('@pdftext/config.html.twig', [
            'action'   => $action,
            'settings' => $settings,
            'method'   => (new PdfTextExtractor((string) $settings['pdftotext_path']))->getMethod(),
        ]);
    }
}
