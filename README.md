# Scrapifly - API de Scraping de Vuelos (Kayak)

API REST que scrapea la página de resultados de vuelos de Kayak usando Bright Data como proxy, implementa caché de 1 hora y devuelve los datos en formato JSON normalizado.

## Requisitos

- PHP 8.2+
- Composer
- Extensiones PHP: curl, mbstring, xml

## Instalación

1. **Clonar el repositorio**
```bash
git clone <repository-url>
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
SCRAPING_PROVIDER=brightdata
BRIGHTDATA_API_KEY=tu_api_key_aqui

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

### Proxy de Scraping: Bright Data

Se eligió **Bright Data** por las siguientes razones:

1. **Web Unlocker**: Maneja automáticamente CAPTCHAs, rotación de IPs y fingerprinting del navegador
2. **Alta tasa de éxito**: Especialmente efectivo para sitios con protecciones anti-bot como Kayak
3. **API simple**: Integración sencilla mediante API REST
4. **Documentación completa**: Facilita la implementación y debugging

**Configuración utilizada:**
- Zone: `unblocker` (Web Unlocker)
- Format: `raw` (HTML sin procesar)
- Timeout: 60 segundos (configurable)

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

El parser intenta múltiples estrategias:

1. **JSON embebido**: Busca datos de vuelos en `__NEXT_DATA__` o similar
2. **DOM parsing**: Usa XPath para extraer datos de elementos HTML
3. **Regex fallback**: Como último recurso, extrae precios con expresiones regulares

**Limitaciones conocidas:**
- Kayak cambia frecuentemente su estructura HTML
- Algunos campos pueden quedar en `null` si no se encuentran
- El número de vuelo (`flightNumber`) generalmente no está disponible en la página de resultados

## Tests

Ejecutar todos los tests:
```bash
php artisan test
```

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

## Estructura del Proyecto

```
app/
├── Exceptions/
│   └── ScrapingException.php      # Excepción personalizada para errores de scraping
├── Http/
│   └── Controllers/
│       └── Api/
│           └── FlightController.php   # Controlador de la API
└── Services/
    ├── BrightDataService.php      # Servicio de proxy Bright Data
    ├── FlightService.php          # Servicio principal con lógica de caché
    └── KayakParserService.php     # Parser del HTML de Kayak

config/
└── scraping.php                   # Configuración de scraping y caché

routes/
└── api.php                        # Rutas de la API

tests/
└── Feature/
    └── FlightApiTest.php          # Tests de la API
```

## Limitaciones Conocidas

1. **Rate limiting**: Kayak puede bloquear requests frecuentes. El caché de 1 hora mitiga esto.

2. **Cambios en Kayak**: Si Kayak cambia su estructura HTML, el parser necesitará actualizaciones.

3. **Campos incompletos**: Algunos campos pueden ser `null` debido a:
   - Información no disponible en la página
   - Cambios en la estructura HTML de Kayak
   - Bloqueos del proxy

4. **Tiempos de respuesta**: El scraping puede tardar 10-30 segundos en la primera llamada (sin caché).

5. **Zonas horarias**: Las horas de vuelo se devuelven tal como aparecen en Kayak (hora local del aeropuerto).

## Mejoras Futuras

- [ ] Implementar queue para scraping asíncrono
- [ ] Agregar más proveedores de proxy (ScrapingBee como fallback)
- [ ] Implementar rate limiting en la API
- [ ] Agregar autenticación para el endpoint
- [ ] Dashboard de monitoreo con métricas de caché hits/misses
- [ ] Soporte para búsqueda de solo ida

## Autor

Test técnico - API de Scraping de Vuelos
