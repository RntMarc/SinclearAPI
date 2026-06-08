# Architektur

## Schichtenmodell

```
Request → Middleware → Controller → Service → Repository → MySQL
                         ↓
                      Policy (Autorisierung)
                         ↓
                        DTO (sichere Ausgabe)
```

| Schicht | Verzeichnis | Aufgabe |
|---------|-------------|---------|
| Controller | `src/Http/Controllers/` | HTTP-Request/Response, keine Businesslogik |
| Middleware | `src/Http/Middleware/` | Auth, Rate Limit, Security Headers, CORS |
| Service | `src/Service/` | Businesslogik, Transaktionen |
| Repository | `src/Repository/` | PDO, Prepared Statements |
| Policy | `src/Security/Policy/` | Autorisierung (`canView`, `canUpdate`, …) |
| DTO | `src/Dto/` | Ausgabe ohne sensitive Felder |

## Dependency Injection

PHP-DI Container in `config/dependencies.php`. Alle Services und Repositories werden per Constructor Injection bereitgestellt.

## Routing

- `config/routes.php` – Auth, Spezial-Endpunkte
- `config/ResourceRegistry.php` – Generisches CRUD für alle Tabellen
- `Application/ResourceRouteRegistrar.php` – Registriert CRUD-Routen automatisch

## Middleware-Stack

1. CORS
2. Security Headers
3. Request Size Limit (5 MB)
4. Rate Limiting (global)
5. Authentication (JWT) – auf geschützten Routen
6. Login Throttle – auf Auth-Routen

## Fehlerbehandlung

`Exception/HttpException.php` mappt Domain-Fehler auf HTTP-Status. Keine Stacktraces in Produktion (`APP_DEBUG=false`).
