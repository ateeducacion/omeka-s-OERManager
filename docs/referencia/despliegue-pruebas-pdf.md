# Verificación de extracción de PDF y despliegue de pruebas con glibc

> Procedimiento operativo, no decisión de gobierno. Nace de **TASK-024(b)**: en Alpine/musl
> los PDF pierden texto sin avisar. Sirve para (a) comprobar cualquier despliegue en un
> comando y (b) rehacer una pila de pruebas con glibc cuando toquen las pruebas funcionales
> del final del desarrollo.
>
> **La configuración Docker NO forma parte del módulo** (CLAUDE.md): lo de aquí son
> instrucciones para el propietario, no ficheros que el módulo cree o mantenga.

## 1. Verificar un despliegue: `make pdf-check`

Se ejecuta **dentro** del despliegue que se quiere verificar, porque lo que mide es la
plataforma, no el código:

```
docker compose exec omekas php /var/www/html/volume/modules/OERManager/tools/pdf-check.php
```

o, desde un clon del módulo con `vendor/` instalado:

```
make pdf-check                    # directorio estándar de ficheros de Omeka
make pdf-check DIR=/ruta/al/pdf   # un fichero suelto u otra carpeta
```

**Lo que hay que mirar es la cabecera**, no el listado:

```
iconv        : glibc        <- musl/unknown = plataforma rota
//TRANSLIT   : SÍ           <- NO = todos los PDF degradados
memory_limit : 512M
```

Código de salida: **0** plataforma sana · **1** plataforma incapaz · **2** error de uso.
Apto para un *smoke test* de despliegue.

### Por qué la cabecera manda sobre el listado

El módulo marca `pdf_iconv_unsupported` **solo cuando el texto queda completamente vacío**.
La pérdida **parcial** no dispara ningún motivo de descarte: el fichero aparece como `OK`
con una fracción del texto y nada lo señala. Medido sobre los 10 PDF de los REA:

| | Alpine (musl) | Debian (glibc) |
| --- | ---: | ---: |
| PDF vacíos (`pdf_iconv_unsupported`) | 2 | 0 |
| PDF con `OK` pero **texto mutilado** | 5 | — |
| Peor caso individual | 105 chars | 1451 chars (**93 % perdido**) |
| Total extraído | 10.525 | 39.274 |

Ese es el motivo de que el fallo sobreviviera tanto: la mayoría de los ficheros *parecían*
funcionar. **El único diagnóstico fiable es la sonda de plataforma.**

### Motivos de descarte y qué significan

| Motivo | ¿Es fallo de plataforma? | Lectura |
| --- | --- | --- |
| `pdf_iconv_unsupported` | **Sí** | musl sin `//TRANSLIT`. Arreglo de imagen base |
| `pdf_empty` | No necesariamente | PDF sin capa de texto (escaneado) **si** la sonda da `SÍ`; si da `NO`, desconfía: puede ser la plataforma |
| `pdf_too_large` | No | Tope propio del módulo (`max_pdf_bytes`, 20 MB). Ese REA se cataloga sin contenido |
| `pdf_unreadable` | No | El parser no pudo con el fichero |

## 2. Rehacer la pila de pruebas con glibc

Validado el 2026-07-30 con `ghcr.io/erseco/omeka-s-docker:master` (Debian 13 trixie,
PHP 8.4.22, Apache 2.4, **glibc**), que es el sucesor de `erseco/alpine-omeka-s`.

Levanta una pila **en paralelo** sobre copias de los volúmenes: el entorno de trabajo del
8080 no se toca en ningún momento.

```bash
# 1. Copiar los volúmenes (ficheros ~6 GB + base de datos)
docker volume create oer_test_omekas_data
docker volume create oer_test_mariadb_data
docker run --rm -v omeka-s-moduletemplate_omekas_data:/from:ro -v oer_test_omekas_data:/to \
  alpine sh -c 'cp -a /from/. /to/'
docker run --rm -v omeka-s-moduletemplate_mariadb_data:/from:ro -v oer_test_mariadb_data:/to \
  alpine sh -c 'cp -a /from/. /to/'

# 2. DOS ARREGLOS OBLIGATORIOS sobre la copia (ver §3)
docker run --rm -v oer_test_omekas_data:/v alpine sh -c \
  'printf "<?php\nreturn [];\n" > /v/config/local.config.php && chown -R 33:33 /v'

# 3. Red y base de datos
docker network create oer-test-net
docker run -d --name oer-test-mariadb --network oer-test-net --network-alias mariadb \
  -e MYSQL_ROOT_PASSWORD=omeka -e MYSQL_DATABASE=omeka \
  -e MYSQL_USER=omeka -e MYSQL_PASSWORD=omeka \
  -v oer_test_mariadb_data:/var/lib/mysql mariadb:latest

# 4. Omeka con la imagen glibc, en el 8081
MT=../omeka-s-ModuleTemplate            # ajusta la ruta si hace falta
docker run -d --name oer-test-omekas --network oer-test-net -p 8081:80 \
  -e DB_HOST=mariadb -e DB_NAME=omeka -e DB_USER=omeka -e DB_PASSWORD=omeka -e DB_PORT=3306 \
  -e APPLICATION_ENV=development -e SITE_URL=http://localhost:8081 \
  -v oer_test_omekas_data:/var/www/html/volume \
  -v "$PWD":/var/www/html/volume/modules/OERManager \
  -v "$MT/config/custom.ini":/usr/local/etc/php/conf.d/custom.ini:ro \
  ghcr.io/erseco/omeka-s-docker:master

# 5. Comprobar
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8081/         # 200
docker exec oer-test-omekas php /var/www/html/volume/modules/OERManager/tools/pdf-check.php
```

Los usuarios son los de la copia de la base de datos: se entra en
`http://localhost:8081/admin` con las credenciales de siempre.

### Desmontar

```bash
docker rm -f oer-test-omekas oer-test-mariadb
docker network rm oer-test-net
docker volume rm oer_test_omekas_data oer_test_mariadb_data
docker rmi ghcr.io/erseco/omeka-s-docker:master     # opcional, ~1 GB
```

## 3. Escollos verificados al migrar de Alpine a Debian

Los tres primeros **rompen el arranque, la escritura o la mitad del catálogo**; no son
opcionales.

| Escollo | Síntoma | Arreglo |
| --- | --- | --- |
| **`local.config.php` ausente** | Fatal al arrancar: `RuntimeException: Filename "/var/www/html/config/local.config.php" cannot be found` | Crear `volume/config/local.config.php` con `<?php return [];` |
| **UID distinto** | Arranca y se ve bien, pero **no puede subir ficheros** | `chown -R 33:33` sobre el volumen |
| **Extensión `zip` ausente** | `Error: Class "ZipArchive" not found` → el propose IA **muere** en todo REA empaquetado (9 de los 19) | Añadir la extensión `zip` a la imagen (`docker-php-ext-install zip`) |
| Puerto | No responde en 8080 | La imagen expone **80**, no 8080 → `-p 8080:80` |
| Ruta del `.ini` de PHP | El `custom.ini` se ignora en silencio | `/usr/local/etc/php/conf.d/`, no `/etc/php84/conf.d/` |
| `nginx.conf` | Montaje inútil | La imagen usa **Apache 2**, no nginx: quitar ese volumen |

**Por qué falla `local.config.php`:** las dos imágenes resuelven la configuración de forma
distinta. En Alpine `config` es un symlink a `volume/config`, que solo contiene
`database.ini`, y el `glob` de Omeka (`application/config/application.config.php:37`) no
casa nada. En Debian `config` es un directorio real con `local.config.php` **como symlink**
a `volume/config/local.config.php`; si el destino no existe, el symlink queda colgando,
`glob()` lo casa por nombre y Laminas revienta al leerlo. El entrypoint **no** lo crea.

**Por qué el `chown`:** el volumen viene de Alpine con `nobody:nobody` (65534) y modo 755;
la imagen Debian corre como `www-data` (33), que con 755 puede leer pero **no escribir**. El
entrypoint solo hace `chown` de `database.ini`.

**Por qué la extensión `zip` es bloqueante:** `ghcr.io/erseco/omeka-s-docker:master` **no la
trae** (`php -m` sin `zip`, `class_exists('ZipArchive') === false`), mientras que Alpine sí. El
`ContentExtractor` descomprime en memoria con `ZipArchive` (TASK-010), así que sin la extensión
ningún REA empaquetado aporta contenido; y **9 de los 19 REA son `application/zip`** (SCORM
Netex y similares), o sea que el cambio de imagen tal cual **cambia un agujero de plataforma
por otro del mismo tamaño**: Alpine pierde el texto de los PDF, Debian pierde el de los ZIP.
Detectado el 2026-08-02 al medir TASK-022 (§6). Sonda: `php -r 'var_dump(class_exists("ZipArchive"));'`.
Agravante del lado del módulo: la clase se instancia sin guarda, así que el fallo **no degrada**
—como sí hace el PDF con `pdf_iconv_unsupported`— sino que propaga un `Error` que tumba el
propose entero (→ TASK-031).

**`memory_limit`:** con los 128 MB por defecto, algún PDF del catálogo aborta el proceso con
un fatal de memoria **no capturable** que no aparece como motivo de descarte. Los 512 MB del
`custom.ini` bastan; si esa línea se pierde en la migración, los fallos parecerán otra cosa.

## 4. Vías descartadas (no reintentar)

- **`gnu-libiconv` + `LD_PRELOAD` en Alpine**: vía muerta. Alpine 3.23 empaqueta
  `gnu-libiconv` 1.18-r0 **sin** `preloadable_libiconv.so`, y exporta solo símbolos con
  prefijo (`libiconv_open`…), así que no hay interposición posible. Probado en el
  contenedor: `ICONV_IMPL` seguía en `unknown`.
- **Esperar arreglo upstream**: `smalot/pdfparser` 2.12.5 (2026-04-17) sigue con el
  `//TRANSLIT` en `Font.php:651`. No hay versión publicada que lo corrija.
- **Parchear la librería desde el módulo**: técnicamente viable —en el contenedor
  `mb_convert_encoding($t,'UTF-8','Windows-1252')` da salida idéntica a `iconv` plano— pero
  supone alterar el comportamiento de una dependencia de terceros desde nuestro código.
  Queda como último recurso si el cambio de imagen se descarta.

## Fuentes

- TASK-024 en [../backlog.md](../backlog.md); medición A/B del 2026-07-22 y del 2026-07-30.
- `src/Service/Content/ContentExtractor.php` (`parsePdf()`, `supportsIconvTranslit()`).
- `tools/pdf-check.php` (esta sonda).

## 5. Medición sobre la pila glibc levantada (2026-08-01)

Pila de pruebas levantada según el §2 y medida contra el entorno Alpine del 8080 **sobre los
mismos 129 PDF** del volumen, con el `ContentExtractor` real del módulo:

| | Alpine (musl) | Debian (glibc) |
| --- | ---: | ---: |
| Total de caracteres extraídos | 242.356 | **271.105** (+28.749) |
| Ficheros que mejoran / empeoran | — | **9 / 0** |
| `pdf_iconv_unsupported` | 2 | **0** |
| `pdf_too_large` (tope del módulo, no plataforma) | 14 | 14 |

**Ocho REA recuperan contenido**, y en dos de ellos el texto no existía en absoluto:

| REA | Antes | Después |
| --- | ---: | ---: |
| #40437 Guía de desayunos y recreos saludables | **0** | 23.345 |
| #4676 Cuerpos geométricos | 105 | 1.451 |
| #4674 Figuras Planas | **0** | 875 |
| #5045 Resumen de fórmulas de áreas y perímetros | 57 | 838 |
| #5051 Acebiño · #40422 Guaydil · #40425 Lentisco · #40439 Embarcaciones | 1.793–2.998 | 1.822–3.866 |

> **Cuidado al comparar con la tabla del §1**, que da 10.525 vs 39.274: aquella medía **los 10
> PDF de los REA**, y esta los **129 del volumen**. La proporción global es menor sencillamente
> porque la mayoría de los PDF del volumen no están afectados; el daño en los REA es el de §1.

**Trampa de medición, por si se repite:** ejecutar `pdf-check` en las dos pilas y comparar los
totales **no vale**. La sonda toma una muestra de 25 ficheros y el orden de `glob` difiere entre
contenedores, así que se comparan conjuntos distintos: da ~40.000 en ambas y parece que no hay
diferencia. Hay que medir **fichero a fichero sobre una lista fija**.

**El módulo funciona íntegro sobre la pila nueva:** `acl-check` 25/25, `config-page-check` 7/7 y
`preview-harness` en verde dentro del contenedor Debian.

### Estado

La pila del 8081 es **temporal y desechable**; el entorno de trabajo del 8080 no se ha tocado.
El cambio **permanente** —apuntar el `docker-compose` de `omeka-s-ModuleTemplate` a
`ghcr.io/erseco/omeka-s-docker:master` con los **seis** escollos del §3, incluida la extensión
`zip`— sigue siendo del propietario: la configuración Docker no forma parte del módulo.

## 6. Fichas destiladas de los PDF sobre glibc (2026-08-02, cierre de TASK-022)

Medición del residuo de TASK-022 sobre esta misma pila: `propose-harness.php` con LLM real
(OpenRouter, `openai/gpt-4o-mini`, visión activada), **3 repeticiones** por item, sobre los tres
casos PDF del corpus (`test/fixtures/distiller-corpus/`). Los tres casos ZIP/SCORM ya se habían
verificado en Alpine el 2026-07-22, y esta pila **no puede rehacerlos** por la extensión `zip`
ausente (§3), así que se dejan como estaban.

| Item | Texto extraído | Tema ↔ referencia | «Nivel citado textualmente» |
| --- | --- | --- | --- |
| #4674 Figuras Planas | PDF, sin truncar (en Alpine: 0 chars) | **3/3** | 3/3 presente; `«1º ESO», «MATEMÁTICAS»` en r1, `«1º ESOMATEMÁTICAS»` (literal del PDF, sin separar) en r2/r3 |
| #40437 Guía de desayunos | PDF 15 MB, sin truncar (en Alpine: 0 chars) | **3/3** | **3/3** `«Educación Primaria»`; r2/r3 añaden `«los tres ciclos de Educación Primaria»`, también literal |
| #40442 Lámina: la cocina | **sin medios** (ver abajo) | **3/3** | 3/3 `«1º Primaria»`, `«Conocimiento del Medio…»`, **literales en los metadatos del item** |

Criterio del corpus (§Protocolo, coherente con ADR-0012): **tasa de acuerdo sobre repeticiones**,
no identidad literal. Se cumple: el Tema y el «Qué enseña» concuerdan con la ficha de referencia
en 9/9 ejecuciones, y la sección «Nivel citado textualmente» aparece siempre, siempre copiada del
recurso o de sus metadatos y **nunca inferida** (invariante de ADR-0011). Lo que varía entre
repeticiones es la forma de la cita, no su origen.

**#40442 ya no ejercita lo que decía ejercitar:** el item **no tiene medios** en el catálogo
(comprobado en las dos pilas, 8080 y 8081) — es el único de los 19 REA sin ninguno. El corpus lo
recogió el 2026-07-07 como «PDF A3 escaneado → `pdf_empty` → solo visión», y ese PDF ya no está
adjunto. Su ficha sale hoy solo de los metadatos, que son ricos, así que el caso mide *fallback
a metadatos*, no *rescate por visión*. **Consecuencia:** la rasterización local de PDF escaneados
(TASK-026) sigue **sin ejercitarse sobre un REA real**; hace falta otro item para esa cobertura
(→ TASK-030).
