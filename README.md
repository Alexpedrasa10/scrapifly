# Scrapifly - API de Scraping de Vuelos (Kayak)

API REST que scrapea la página de resultados de vuelos de Kayak usando un proxy de scraping, implementa caché de 1 hora y devuelve los datos en formato JSON normalizado.

## Requisitos

- PHP 8.2+
- Composer
- Extensiones PHP: curl, mbstring, xml

## Instalación

1. **Clonar el repositorio**

```bash
git clone https://github.com/Alexpedrasa10/scrapifly.git
cd scrapifly
```

2. **Instalar dependencias**

```bash
composer install
```

3. **Configurar variables de entorno**

```bash
cp .env.example .env
php artisan key:generate
```

4. **Configurar las variables de entorno en `.env`**

```env
# Scraping Configuration
SCRAPING_PROVIDER=scrapingbee
SCRAPINGBEE_API_KEY=tu_api_key_aqui

# Cache Configuration (en segundos, default 1 hora)
FLIGHTS_CACHE_TTL=3600
```

5. **Limpiar caché de configuración**

```bash
php artisan config:clear
php artisan cache:clear
```

## Ejecución

### Servidor de desarrollo

```bash
php artisan serve
```

La API estará disponible en `http://localhost:8000`

### Con Apache/Nginx

Configurar el document root apuntando a la carpeta `public/`

## Endpoints

### GET /api/flights

Obtiene los vuelos de Kayak para una ruta específica.

**Parámetros (query string):**

- `origin` (requerido): Código IATA del aeropuerto origen (ej: AGP)
- `destination` (requerido): Código IATA del aeropuerto destino (ej: MAD)
- `departure_date` (requerido): Fecha de salida en formato YYYY-MM-DD
- `return_date` (requerido): Fecha de regreso en formato YYYY-MM-DD

**Ejemplo de llamada:**

```bash
curl "http://localhost:8000/api/flights?origin=AGP&destination=MAD&departure_date=2026-01-12&return_date=2026-01-15"
```

**Respuesta exitosa (200):**

```json
{
  "flights": [
    {
      "price": 71,
      "currency": "USD",
      "origin": {
        "code": "AGP",
        "city": "Málaga"
      },
      "destination": {
        "code": "MAD",
        "city": "Madrid"
      },
      "departure": "2026-01-12T05:00:00",
      "arrival": "2026-01-12T06:15:00",
      "durationInMinutes": 75,
      "stopCount": 0,
      "flightNumber": null,
      "marketingCarrier": {
        "code": "UX",
        "name": "Air Europa"
      },
      "operatingCarrier": {
        "code": "UX",
        "name": "Air Europa"
      }
    }
  ]
}
```

**Errores de validación (422):**

```json
{
  "error": "Validation failed",
  "messages": {
    "origin": ["The origin field is required."]
  }
}
```

**Error de scraping (502):**

```json
{
  "error": "Failed to fetch flight data",
  "message": "Proxy request failed with status 429"
}
```

### GET /api/health

Endpoint de health check para monitoreo.

**Ejemplo:**

```bash
curl "http://localhost:8000/api/health"
```

**Respuesta:**

```json
{
  "status": "ok",
  "timestamp": "2024-01-15T10:30:00+00:00",
  "cache": {
    "last_scrape_age_seconds": 1234,
    "ttl_seconds": 3600
  }
}
```

## Decisiones Técnicas

### Elección del Proxy de Scraping

#### Intento inicial: Bright Data

Inicialmente se eligió **Bright Data** por las siguientes razones:

1. **Líder del mercado**: Es uno de los proveedores de proxy más reconocidos y robustos
2. **Proxies residenciales**: Ofrece IPs de usuarios reales, lo cual es ideal para evitar bloqueos
3. **Web Unlocker**: Producto especializado que maneja automáticamente CAPTCHAs, rotación de IPs y fingerprinting
4. **Alta tasa de éxito**: Especialmente efectivo para sitios con protecciones anti-bot como Kayak
5. **Documentación extensa**: Facilita la integración

**Problema encontrado:** Bright Data requiere completar un proceso de KYC (Know Your Customer / verificación de identidad) para acceder a ciertos sitios "sensibles" como Kayak. Esto es una política de compliance de Bright Data para evitar mal uso de sus proxies. El error específico fue:

```
Residential Failed (bad_endpoint): Requested site is not available for immediate residential
(no KYC) access mode in accordance with robots.txt.
```

La configuración de Bright Data se mantiene en el código (`app/Services/BrightDataService.php`) para demostrar la implementación y puede activarse completando el KYC en: https://brightdata.com/cp/kyc

#### Solución final: ScrapingBee

Se migró a **ScrapingBee** como proveedor activo por:

1. **Sin KYC requerido**: Permite acceso inmediato a cualquier sitio con el plan gratuito
2. **API simple**: Integración sencilla mediante URL con parámetros
3. **JavaScript rendering**: Ejecuta JavaScript para obtener contenido dinámico
4. **Premium proxies**: Incluye proxies residenciales en el plan
5. **Trial gratuito**: 1000 requests sin costo para pruebas

**Configuración utilizada:**

- `render_js=true`: Renderiza JavaScript (necesario para Kayak)
- `premium_proxy=true`: Usa proxies residenciales
- `country_code=us`: Solicita desde Estados Unidos

### Sistema de Caché

Se utiliza el sistema de **caché de archivos de Laravel** por las siguientes razones:

1. **Simplicidad**: No requiere servicios externos (Redis, Memcached)
2. **Persistencia**: Los datos sobreviven reinicios del servidor
3. **Suficiente para el caso de uso**: Para una API de bajo volumen, el caché en archivos es adecuado

**Comportamiento del caché:**

- TTL por defecto: 1 hora (3600 segundos)
- Clave de caché: Hash MD5 de `origin_destination_departure_return`
- **Caché stale**: Si el scraping falla pero existe caché expirado, se devuelve el caché viejo como fallback

**Para producción con alto volumen**, se recomienda cambiar a Redis:

```env
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Manejo de Errores

**Estrategia cuando el scraping falla:**

1. Primero intenta obtener datos frescos de Kayak
2. Si falla y existe caché stale (expirado pero guardado), devuelve el caché viejo
3. Si no hay caché de ningún tipo, devuelve error 502 (Bad Gateway)

**Códigos HTTP utilizados:**

- `200`: Éxito
- `422`: Error de validación de parámetros
- `500`: Error interno del servidor
- `502`: Error del proxy o Kayak no responde

### Parsing del HTML

Kayak utiliza clases CSS ofuscadas y contenido dinámico, lo que hace el parsing desafiante. El parser implementa múltiples estrategias:

1. **Extracción de precios**: Busca patrones `$XXX` y filtra valores razonables ($30-$2000)
2. **Extracción de horarios**: Busca patrones `HH:MM` y construye fechas ISO 8601
3. **Extracción de duraciones**: Busca patrones `Xh Ym` y calcula minutos totales
4. **Extracción de aerolíneas**: Busca nombres conocidos (Air Europa, Iberia, Vueling, Ryanair, EasyJet)
5. **Extracción de escalas**: Busca "nonstop" o "X stop"

**Campos del JSON:**

| Campo | Fuente | Notas |
|-------|--------|-------|
| `price` | Extraído del HTML | Precios reales de Kayak |
| `currency` | Fijo | USD (moneda por defecto de Kayak) |
| `origin/destination` | Parámetros de entrada | Incluye código IATA y nombre de ciudad |
| `departure/arrival` | Extraído o calculado | Horarios en ISO 8601, calculados si no se encuentran |
| `durationInMinutes` | Extraído o calculado | Basado en horarios de salida/llegada |
| `stopCount` | Extraído del HTML | 0 para vuelos directos |
| `flightNumber` | null | No disponible en la página de resultados de Kayak |
| `marketingCarrier` | Extraído del HTML | Código IATA y nombre de la aerolínea |
| `operatingCarrier` | Igual que marketing | Kayak no distingue en resultados |

## Tests

Ejecutar todos los tests:

```bash
php artisan test
```

### FlightApiTest

Ejecutar solo tests de la API de vuelos:

```bash
php artisan test --filter=FlightApiTest
```

**Tests incluidos:**

- Estructura correcta del endpoint `/health`
- Validación de parámetros requeridos
- Validación de formato de fechas
- Validación de códigos de aeropuerto
- Comportamiento del caché (una sola llamada al scraper)
- Estructura correcta de la respuesta de vuelos

### MultipleFlightSearchesTest

Test completo que valida 5 búsquedas diferentes de vuelos:

```bash
php artisan test --filter=MultipleFlightSearchesTest
```

**Qué valida:**
- 5 rutas diferentes (AGP→MAD, BCN→MAD, MAD→BCN, SVQ→MAD, VLC→BCN)
- Cada búsqueda devuelve al menos 1 vuelo
- Estructura correcta de los datos (price, origin, destination, departure, arrival, etc.)
- Validación de valores (precios positivos, códigos IATA correctos)
- Persistencia del caché (segunda llamada significativamente más rápida)

**Métricas mostradas:**
- Número de vuelos encontrados por ruta
- Tiempo de respuesta en milisegundos
- Rango de precios por ruta
- Factor de aceleración del caché

## Estructura del Proyecto

```
app/
├── Exceptions/
│   └── ScrapingException.php          # Excepción personalizada para errores de scraping
├── Http/
│   └── Controllers/
│       └── Api/
│           └── FlightController.php   # Controlador de la API
└── Services/
    ├── BrightDataService.php          # Servicio de proxy Bright Data (requiere KYC)
    ├── ScrapingBeeService.php         # Servicio de proxy ScrapingBee (activo)
    ├── FlightService.php              # Servicio principal con lógica de caché
    └── KayakParserService.php         # Parser del HTML de Kayak

config/
└── scraping.php                       # Configuración de scraping y caché

routes/
└── api.php                            # Rutas de la API

tests/
└── Feature/
    └── FlightApiTest.php              # Tests de la API
```

## Configuración de Proveedores

### Cambiar entre proveedores

En el archivo `.env`, cambiar `SCRAPING_PROVIDER`:

```env
# Para usar ScrapingBee (default, no requiere KYC)
SCRAPING_PROVIDER=scrapingbee
SCRAPINGBEE_API_KEY=tu_api_key

# Para usar Bright Data (requiere KYC completado)
SCRAPING_PROVIDER=brightdata
BRIGHTDATA_PROXY_HOST=brd.superproxy.io
BRIGHTDATA_PROXY_PORT=33335
BRIGHTDATA_PROXY_USER=tu_usuario
BRIGHTDATA_PROXY_PASS=tu_password
```

## Limitaciones Conocidas

1. **Rate limiting**: Kayak puede bloquear requests frecuentes. El caché de 1 hora mitiga esto.

2. **Cambios en Kayak**: Si Kayak cambia su estructura HTML, el parser necesitará actualizaciones.

3. **`flightNumber` siempre null**: Kayak no muestra números de vuelo en la página de resultados de búsqueda.

4. **Horarios estimados**: Cuando no se pueden extraer horarios exactos del HTML, se generan horarios realistas basados en el índice del vuelo.

5. **Tiempos de respuesta**: El scraping con JavaScript rendering puede tardar 10-30 segundos en la primera llamada (sin caché).

6. **Zonas horarias**: Las horas de vuelo se devuelven tal como aparecen en Kayak (hora local del aeropuerto).

7. **Bright Data KYC**: Para usar Bright Data con Kayak, es necesario completar la verificación de identidad.

## Mejoras Futuras

- [ ] Implementar queue para scraping asíncrono
- [ ] Agregar fallback automático entre proveedores
- [ ] Implementar rate limiting en la API
- [ ] Agregar autenticación para el endpoint
- [ ] Dashboard de monitoreo con métricas de caché hits/misses
- [ ] Soporte para búsqueda de solo ida
- [ ] Mejorar parsing con selectores CSS específicos de Kayak

## Autor
Alex Pedrasa
