<?php

/**
 * Render de la página de configuración en contenedor (TASK-029).
 *
 * Por defecto SOLO LEE: renderiza el formulario. Con `--write` añade la ida y
 * vuelta del guardado, que es idempotente —reenvía los mismos valores— pero
 * escribe en los settings del módulo.
 *
 * Al salir del listado de Módulos, el módulo pasa a poner por su cuenta lo que
 * Omeka daba hecho —el `<form>`, el CSRF y el rellenado de campos—, y nada de
 * eso lo puede ver el arnés del host: `configAction()` necesita el contenedor
 * de servicios, el `FormElementManager` y el renderer de vistas.
 *
 * Comprueba lo que rompería en silencio: que la plantilla existe y rinde, que
 * el CSRF viaja en el formulario, que **la clave API se pinta vacía** aunque
 * esté guardada (write-only), y que no falta ningún campo por el camino.
 *
 * Uso (dentro del contenedor):
 *   php modules/OERManager/test/container/config-page-check.php            # solo render
 *   php modules/OERManager/test/container/config-page-check.php --write    # + guardado
 */

chdir('/var/www/html');
require 'bootstrap.php';

use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use OERManager\Controller\Admin\IndexController;
use OERManager\Service\ConfigPayload;
use OERManager\Service\Llm\LlmSettings;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();

$controller = $services->get('ControllerManager')->get(IndexController::class);
$event = new MvcEvent();
$event->setApplication($application);
$event->setRouteMatch(new RouteMatch(['action' => 'config']));
$event->setRequest($services->get('Request'));
$event->setResponse($services->get('Response'));
// El router hay que dárselo a mano: en CLI no hay uno enrutado y el plugin
// `redirect()->toRoute()` lo necesita para ensamblar la URL. Sin esto el
// guardado revienta al final por un artefacto del arnés, no del módulo.
$event->setRouter($services->get('HttpRouter'));
$controller->setEvent($event);

// `dispatch()` y no `configAction()` a secas: `getRequest()` del controlador lo
// fija el despacho, no `setEvent()`. Invocando la acción directamente, un POST
// se leería como GET y la rama de guardado no se ejercitaría nunca.
$view = $controller->dispatch($services->get('Request'), $services->get('Response'));
$renderer = $services->get('ViewRenderer');
$view->setTemplate('oer-manager/admin/index/config');
$html = $renderer->render($view);

$failures = 0;
$checks = 0;

function check(string $label, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s\n", $ok ? ' OK ' : 'FALLA', $label);
}

check('la plantilla rinde algo', '' !== trim($html));
check('lleva formulario y botón de guardar', str_contains($html, '<form') && str_contains($html, 'type="submit"'));
preg_match('/name="csrf"[^>]*value="([^"]+)"/', $html, $csrfMatch);
$csrfToken = $csrfMatch[1] ?? '';
check('lleva token CSRF', '' !== $csrfToken);

// El campo de la clave API tiene que salir SIEMPRE vacío: es la garantía de que
// abrir la configuración no filtra la credencial al HTML de la página.
$settings = $services->get('Omeka\Settings');
$storedKey = (string) $settings->get(LlmSettings::API_KEY);
preg_match('/name="' . preg_quote(LlmSettings::API_KEY, '/') . '"[^>]*>/', $html, $field);
$fieldHtml = $field[0] ?? '';
check('el campo de la clave API está en la página', '' !== $fieldHtml);
check(
    'el campo de la clave API se pinta vacío' . ('' === $storedKey ? ' (no hay clave guardada)' : ''),
    !preg_match('/value="[^"]+"/', $fieldHtml)
);
if ('' !== $storedKey) {
    check('la clave guardada NO aparece en ninguna parte del HTML', !str_contains($html, $storedKey));
} else {
    printf("  [SALTA] no hay clave API guardada: no se puede probar que no se filtre\n");
}

// Ningún campo se ha quedado por el camino al mover el formulario de sitio.
$missing = [];
foreach (array_keys(ConfigPayload::read(static fn ($k, $d = null) => $settings->get($k, $d))) as $key) {
    if (!str_contains($html, 'name="' . $key . '"')) {
        $missing[] = $key;
    }
}
check('rinde los ' . count(ConfigPayload::read(static fn ($k, $d = null) => $settings->get($k, $d)))
    . ' campos del formulario' . ($missing ? ' — faltan: ' . implode(', ', $missing) : ''), [] === $missing);

// --- Guardado (opcional): la ida y vuelta completa ------------------------
// Reproduce el gesto que más daño haría: abrir la configuración y darle a
// Guardar sin tocar nada. Como el campo de la clave se pinta vacío, un guardado
// que escribiera ese vacío dejaría el módulo sin credenciales. Es idempotente
// —reenvía los mismos valores—, pero escribe, así que exige el flag.
if (($argv[1] ?? '') === '--write') {
    echo "\nGUARDADO (ida y vuelta, mismos valores)\n";
    $before = ConfigPayload::read(static fn ($k, $d = null) => $settings->get($k, $d));
    $before[LlmSettings::API_KEY] = $storedKey;

    $post = ConfigPayload::read(static fn ($k, $d = null) => $settings->get($k, $d));
    $post['csrf'] = $csrfToken;
    // Los checkboxes desmarcados NO viajan en un POST real.
    foreach ([LlmSettings::ENABLED, LlmSettings::VISION_ENABLED] as $flag) {
        if (!$post[$flag]) {
            unset($post[$flag]);
        }
    }

    $request = new \Laminas\Http\Request();
    $request->setMethod('POST');
    $request->getPost()->fromArray($post);
    $saved = $controller->dispatch($request, $services->get('Response'));

    // Discriminar las dos ramas: si el formulario NO valida, la acción devuelve
    // la vista de nuevo y no escribe. Sin esta comprobación, las dos de abajo
    // pasarían en vacío justo cuando el guardado estuviera roto.
    check(
        'el formulario valida y la acción redirige (o sea: ha guardado)',
        $saved instanceof \Laminas\Http\Response
    );

    $after = ConfigPayload::read(static fn ($k, $d = null) => $settings->get($k, $d));
    $after[LlmSettings::API_KEY] = (string) $settings->get(LlmSettings::API_KEY);

    check('la clave API sobrevive a guardar con el campo en blanco', $after[LlmSettings::API_KEY] === $storedKey);
    $changed = [];
    foreach ($before as $key => $value) {
        if ($after[$key] !== $value) {
            $changed[] = sprintf('%s (%s → %s)', $key, var_export($value, true), var_export($after[$key], true));
        }
    }
    check('ningún otro setting cambia' . ($changed ? ': ' . implode('; ', $changed) : ''), [] === $changed);
}

printf("\n%d comprobaciones, %d fallos\n", $checks, $failures);
exit($failures ? 1 : 0);
