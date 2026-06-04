# URLME API usage

Guia rapida para usar el acortador de URLs de `https://urlme.es`.

## Base

```text
Base URL: https://urlme.es
Content-Type: application/json
Header auth: X-Api-Key: <API_KEY>
```

La API key no debe publicarse en chats, repositorios ni documentacion compartida. Si hace falta usar la API desde Codex/Postman, proporcionar la key en el momento o leerla desde `config.php` en el servidor.

## Healthcheck

No requiere API key para comprobar estado general.

```http
GET /api/health
```

Con API key devuelve detalle tecnico si falla la base de datos.

```http
GET /api/health
X-Api-Key: <API_KEY>
```

Respuesta OK esperada:

```json
{
  "ok": true,
  "database": {
    "ok": true
  }
}
```

## Crear enlace corto

```http
POST /api/links
X-Api-Key: <API_KEY>
Content-Type: application/json
```

Body minimo:

```json
{
  "url": "https://example.com/app/recurso?id=123"
}
```

Body completo:

```json
{
  "url": "https://example.com/app/recurso?id=123",
  "title": "Recurso interno",
  "code": "codigo-opcional",
  "expires_at": "2026-12-31 23:59:59"
}
```

Notas:

- `url` es obligatorio y solo acepta `http` o `https`.
- `title` es opcional.
- `code` es opcional. Si no se envia, la API genera uno automaticamente.
- `code` permite letras, numeros, guion y guion bajo, de 3 a 64 caracteres.
- `expires_at` es opcional y debe ser una fecha interpretable por PHP.

Respuesta esperada:

```json
{
  "data": {
    "code": "jy2LpeY",
    "short_url": "https://urlme.es/jy2LpeY",
    "target_url": "https://example.com/app/recurso?id=123",
    "title": "Recurso interno",
    "click_count": 0,
    "expires_at": null,
    "created_at": "2026-06-03 18:00:00",
    "updated_at": "2026-06-03 18:00:00"
  }
}
```

## Listar enlaces

```http
GET /api/links?limit=50&offset=0
X-Api-Key: <API_KEY>
```

`limit` maximo: `100`.

## Ver un enlace

```http
GET /api/links/{codigo}
X-Api-Key: <API_KEY>
```

Ejemplo:

```http
GET /api/links/jy2LpeY
X-Api-Key: <API_KEY>
```

## Borrar un enlace

```http
DELETE /api/links/{codigo}
X-Api-Key: <API_KEY>
```

Respuesta esperada:

```json
{
  "data": {
    "deleted": true
  }
}
```

## Redireccion

Los enlaces cortos se abren con:

```http
GET /{codigo}
```

Ejemplo:

```text
https://urlme.es/jy2LpeY
```

Si el codigo existe y no ha caducado, redirige con HTTP `302` y aumenta `click_count`.

## Flujo recomendado para acortar una URL

1. Comprobar que `GET https://urlme.es/api/health` devuelve `ok: true`.
2. Crear el enlace con `POST https://urlme.es/api/links`.
3. Devolver al usuario el campo `data.short_url`.
4. Si se quiere confirmar, consultar `GET https://urlme.es/api/links/{codigo}`.

