# Agent Instructions for Sinclear Beyond API

## OpenAPI Documentation
It is elementarily important for the collaboration of SinclearAPI with all clients (and especially for the migration of the existing Next.js project) that the `openapi.yaml` is always up to date.

**Requirement:**
After every change to the API (routes, controllers, DTOs, or ResourceRegistry), you MUST:
1. Verify the accuracy and completeness of the `openapi.yaml` file.
2. Ensure that all moment-by-moment existing API endpoints and functions are fully and correctly reflected in the specification.

## Documentation
The `docs/` directory contains developer-facing documentation for the API.

**Requirement:**
After every change to the API (routes, controllers, DTOs, or ResourceRegistry), you MUST:
1. Update the relevant documentation files in `docs/` to reflect the changes.
2. Ensure that all flows, endpoints, and configuration are accurately documented.

## Coding Standards
- Use PHP 8.4 features where appropriate.
- Follow the established CRUD pattern using `ResourceRegistry.php` for standard resources.
- Ensure all endpoints are secured with the appropriate Policy classes.
