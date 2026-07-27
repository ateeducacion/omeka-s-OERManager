# Requisitos del servidor — módulo OERManager

Qué exige el módulo al servidor de Omeka-S, **más allá** de un Omeka-S estándar.
Extraído del código (no inventado); verificado en el contenedor de desarrollo el
2026-07-23. Fuentes: `composer.json`, `config/module.ini`, `src/`, factorías de
`config/module.config.php`.

## 1. Plataforma base

| Requisito | Valor | Fuente |
| --- | --- | --- |
| Omeka-S | `^4.2.0` | `module.ini` `omeka_version_constraint` |
| PHP | **≥ 8.4** | `module.ini` + `composer.json` (`php: >=8.4`) |
| Módulos Omeka de los que depende | **ninguno** | `Module.php` no declara `$dependencies` |
| Tablas de base de datos propias | **ninguna** (NFR-002) | usa settings nativos + anotaciones RDF |

No hay migraciones de esquema: la instalación no crea ni altera tablas.

## 2. Extensiones PHP

Usadas por el código del módulo y por la dependencia empaquetada
`smalot/pdfparser`.

| Extensión | Para qué | ¿Obligatoria? |
| --- | --- | --- |
| `ext-zip` | `ZipArchive`: extraer contenido de paquetes SCORM/ZIP | Sí |
| `ext-mbstring` | `mb_substr`/`mb_strlen`/`mb_convert_encoding` | Sí |
| **`ext-iconv` con soporte `//TRANSLIT`** | decodificar el texto de los PDF (smalot) | Sí — **ver §5** |
| `ext-openssl` | HTTPS hacia el proveedor LLM (Laminas Http + TLS) | Sí (si se usa IA) |
| `ext-zlib` | FlateDecode de flujos internos de PDF | Sí |
| `ext-json`, `ext-pcre` | núcleo de PHP 8.4 | ya presentes |

**No** requiere `gd` ni `imagick`: la visión envía el binario al proveedor, no
procesa imágenes en el servidor.

## 3. Dependencia empaquetada

- **`smalot/pdfparser ^2.12`** debe viajar en el `vendor/` del módulo. El módulo
  lo autocarga desde `Module::init()` (implementa `InitProviderInterface`): Omeka
  **no** autocarga el `vendor/` de un módulo y el core no trae pdfparser.
- Si se empaqueta **sin** `vendor/` (el ZIP de release excluye `/vendor` por
  `composer.json` §archive.exclude), la extracción de texto de PDF se degrada,
  pero el módulo **carga igual** (el `require` está guardado). Para lectura de PDF
  en producción, el `vendor/` debe estar presente (p. ej. `composer install
  --no-dev` en el despliegue).

## 4. Red y proveedor LLM (solo si se usa la asistencia IA)

- **Salida HTTPS** desde el servidor PHP al proveedor configurado: Anthropic
  (`api.anthropic.com`), un endpoint OpenAI-compatible, OpenRouter, o un endpoint
  local. **Sin conexiones de entrada.**
- Una **clave de API** del proveedor, en settings nativos (write-only, nunca en
  logs ni en el repo — ADR-0008).
- Transporte: `Laminas\Http\Client`, **timeout 60 s por llamada** (fijo),
  `maxredirects = 0` (sin redirecciones → sin SSRF por redirect).

## 5. `iconv` y `//TRANSLIT` — el requisito sutil (glibc vs musl)

`smalot/pdfparser` decodifica el texto de los PDF con
`iconv($enc, 'UTF-8//TRANSLIT//IGNORE', ...)`. El soporte de `//TRANSLIT` depende
de la **implementación de `iconv` del sistema**, no de PHP:

| libc del servidor | Ejemplos | `//TRANSLIT` | Lectura de texto de PDF |
| --- | --- | --- | --- |
| **glibc** | Debian, Ubuntu, RHEL/CentOS, Rocky, Alma | ✅ soportado | **Funciona de fábrica** |
| **musl** | Alpine (imágenes `*-alpine`) | ❌ `iconv(...)` devuelve `false` | **NO** — el PDF vuelve vacío |

Verificado con el mismo PDF (`WinAnsiEncoding`, la codificación más común):
**glibc → 901 chars; musl → 0 chars.** Como `WinAnsiEncoding` es tan común, en
musl se pierde el texto de casi cualquier PDF estándar.

**Un despliegue de Omeka-S «clásico» sobre Debian/Ubuntu (glibc) lee los PDF sin
tocar nada.** El problema es exclusivo de Alpine/musl.

### Qué hace el módulo cuando la plataforma está degradada (musl)

- Detecta en runtime que `//TRANSLIT` no funciona y reporta el motivo honesto
  `pdf_iconv_unsupported` (no un `pdf_empty` engañoso) — TASK-024b.
- Ese PDF se **rescata por visión** (se manda el binario al proveedor
  vision-capable). Requiere `vision_enabled = on` y un proveedor con soporte de
  PDF/imagen. Coste: tokens y latencia; no recupera el texto localmente.

### Si producción va sobre Alpine/musl y se quiere lectura de texto de PDF

Opciones, de menos a más intrusiva:

1. **Imagen base con glibc** (Debian/Ubuntu). Lo resuelve de raíz; no hay imagen
   oficial de Omeka-S 4.2 sobre Debian mantenida → construir una propia desde
   `php:8.4-fpm`.
2. **Parche de `smalot/pdfparser`** (vía `composer-patches`): sustituir la llamada
   `iconv //TRANSLIT` por un fallback a `mb_convert_encoding` (salida idéntica
   para CP1252/ISO-8859-1, verificado). Elimina la dependencia de `iconv` del
   sistema → el módulo lee PDF en **cualquier** plataforma. Coste: dependencia dev
   de gestión de parches + mantenimiento al actualizar smalot.
3. **Dejar `vision_enabled = on`** y asumir que los PDF se catalogan por visión
   (tokens + latencia), sin recuperar el texto.

Sonda para validar cualquier imagen candidata (debe devolver un string, no
`false`):

```sh
php -r 'var_dump(ICONV_IMPL, @iconv("CP1252","UTF-8//TRANSLIT//IGNORE","a"));'
```

## 6. Límites de recursos (propose síncrono)

El «Proponer con IA» es **síncrono** y encadena 7-9 llamadas al LLM en una sola
petición HTTP (destilación → cascada curricular → ejes, más visión si aplica).

- **`memory_limit`**: recomendado **≥ 256–512 MB**. El parseo de PDF está acotado
  (tope de 20 MB, memoria de decodificación limitada) y la visión codifica en
  base64 hasta 32 MB de binario en memoria.
- **`max_execution_time`** y **el timeout del proxy inverso / php-fpm**
  (`proxy_read_timeout`, `fastcgi_read_timeout`): deben acomodar el tiempo de
  pared acumulado. Con un PDF grande por visión, una propuesta llegó a **~600 s**
  en pruebas. **Hasta TASK-020** (ejecutar el propose como Job en segundo plano),
  o se suben esos timeouts o se usan modelos rápidos; si no, el proxy devuelve
  **504**. Esta capa **no forma parte del módulo** (es infraestructura del host).

## 7. Almacén de ficheros

El módulo lee los medios por **ruta local** (`Omeka\File\Store::getLocalPath`). El
store debe resolver a rutas locales (el store local por defecto vale). Un store
**solo-remoto** (p. ej. S3 sin caché local) rompería la extracción de contenido
de medios.

## 8. Datos/configuración previos (no es software, pero el módulo lo necesita)

- El **currículo** debe existir en Omeka como items enlazados (marco LOMLOE,
  ADR-0009): el módulo lo consume, no lo posee.
- Configurar el `DefinedTermSet` de ejes temáticos (id de item) y el marco
  educativo, más los literales `dcterms:type` por dimensión (ADR-0006/0009).
- Para la IA: proveedor, modelo(s), clave, y `vision_enabled` según §5.
