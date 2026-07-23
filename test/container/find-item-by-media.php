<?php

/**
 * Localiza el item dueño de un media por su storageId (hash del fichero). Ayuda de
 * verificación en contenedor. SOLO LECTURA.
 *
 * Uso: php modules/OERManager/test/container/find-item-by-media.php <storageId>
 */

chdir('/var/www/html');
require 'bootstrap.php';

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$em = $services->get('Omeka\EntityManager');

$storageId = (string) ($argv[1] ?? '');
if ('' === $storageId) {
    fwrite(STDERR, "uso: php find-item-by-media.php <storageId>\n");
    exit(2);
}

$media = $em->getRepository(\Omeka\Entity\Media::class)->findOneBy(['storageId' => $storageId]);
if (null === $media) {
    fwrite(STDERR, "no hay media con storageId $storageId\n");
    exit(1);
}
printf(
    "item %d | media %d | %s | %s bytes\n",
    $media->getItem()->getId(),
    $media->getId(),
    (string) $media->getSource(),
    (string) $media->getSize()
);
